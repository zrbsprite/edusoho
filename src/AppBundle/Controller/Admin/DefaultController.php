<?php
namespace AppBundle\Controller\Admin;

use Topxia\Common\CurlToolkit;
use Topxia\Common\ArrayToolkit;
use Vip\Service\Vip\VipService;
use Biz\CloudPlatform\CloudAPIFactory;
use Topxia\Component\Echats\EchartsBuilder;
use Symfony\Component\HttpFoundation\Request;

class DefaultController extends BaseController
{
    // public function indexAction(Request $request)
    // {
    //     $permissions = $this->container->get('permission.twig.permission_extension')->getSubPermissions('admin');
    //     if (empty($permissions)) {
    //         return $this->render('PermissionBundle:Admin:permission-error.html.twig');
    //     }

    //     $permissionNames = ArrayToolkit::column($permissions, 'code');
    //     if (in_array('admin_homepage', $permissionNames)) {
    //         return $this->forward('AppBundle:Admin/admin/default/homepage');
    //     }

    //     return $this->forward('AppBundle:Admin/admin/default/renderCurrentAdminHomepage', array(
    //         'permission' => $permissions[0]
    //     ));
    // }

    public function renderCurrentAdminHomepageAction($permission)
    {
        $tabMenu = $this->container->get('permission.twig.permission_extension')->getFirstChild($permission);
        $tabMenu = $this->container->get('permission.twig.permission_extension')->getFirstChild($tabMenu);

        if (!empty($tabMenu['mode']) && $tabMenu['mode'] == 'capsules') {
            $tabMenu = $this->container->get('permission.twig.permission_extension')->getFirstChild($tabMenu);
        }

        $permissionPath = $this->container->get('permission.twig.permission_extension')->getPermissionPath($this, array('needs_context' => true, 'needs_environment' => true), $tabMenu);
        return $this->redirect($permissionPath);
    }

    public function indexAction(Request $request)
    {
        $weekAndMonthDate = array('weekDate' => date('Y-m-d', time() - 6 * 24 * 60 * 60), 'monthDate' => date('Y-m-d', time() - 29 * 24 * 60 * 60));
        return $this->render('admin/default/index.html.twig', array(
            'dates' => $weekAndMonthDate
        ));
    }

    public function feedbackAction(Request $request)
    {
        $site  = $this->getSettingService()->get('site');
        $user  = $this->getUser();
        $token = CurlToolkit::request('POST', "http://www.edusoho.com/question/get/token", array());
        $site  = array('name' => $site['name'], 'url' => $site['url'], 'token' => $token, 'username' => $user->nickname);
        $site  = urlencode(http_build_query($site));
        return $this->redirect("http://www.edusoho.com/question?site=".$site."");
    }

    public function validateDomainAction(Request $request)
    {
        $inspectList = array(
            $this->addInspectRole('host', $this->domainInspect($request))
        );
        $inspectList = array_filter($inspectList);
        return $this->render('admin/default/domain.html.twig', array(
            'inspectList' => $inspectList
        ));
    }

    private function addInspectRole($name, $value)
    {
        if ($value['status'] == 'ok') {
            return array();
        }

        return array('name' => $name, 'value' => $value);
    }

    private function domainInspect($request)
    {
        $currentHost        = $request->server->get('HTTP_HOST');
        $siteSetting        = $this->getSettingService()->get('site');
        $settingUrl         = $this->generateUrl('admin_setting_site');
        $filter             = array('http://', 'https://');
        $siteSetting['url'] = rtrim($siteSetting['url']);
        $siteSetting['url'] = rtrim($siteSetting['url'], '/');

        if ($currentHost != str_replace($filter, "", $siteSetting['url'])) {
            return array(
                'status'       => 'warning',
                'errorMessage' => '当前域名和设置域名不符，为避免影响云短信功能的正常使用，请到【系统】-【站点设置】-【基础信息】-【网站域名】',
                'except'       => $siteSetting['url'],
                'actually'     => $currentHost,
                'settingUrl'   => $settingUrl
            );
        }

        return array('status' => 'ok', 'except' => $siteSetting['url'], 'actually' => $currentHost, 'settingUrl' => $settingUrl);
    }

    public function getCloudNoticesAction(Request $request)
    {
        if ($this->getWebExtension()->isTrial()) {
            $domain = $this->generateUrl('homepage', array(), true);
            $api    = CloudAPIFactory::create('root');
            $result = $api->get('/trial/remainDays', array('domain' => $domain));

            return $this->render('admin/default/cloud-notice.html.twig', array(
                "trialTime" => (isset($result)) ? $result : null
            ));
        } elseif ($this->getWebExtension()->isWithoutNetwork()) {
            $notices = array();
        } else {
            $notices = $this->getNoticesFromOpen();
        }

        return $this->render('admin/default/cloud-notice.html.twig', array(
            "notices" => $notices
        ));
    }

    private function getNoticesFromOpen()
    {
        $url = "http://open.edusoho.com/api/v1/context/notice";
        return CurlToolkit::request('GET', $url);
    }

    public function systemStatusAction()
    {
        $apps = $this->getAppService()->checkAppUpgrades();

        $upgradeAppCount = count($apps);

        $indexApps      = ArrayToolkit::index($apps, 'code');
        $mainAppUpgrade = empty($indexApps['MAIN']) ? array() : $indexApps['MAIN'];

        if ($mainAppUpgrade) {
            $upgradeAppCount = $upgradeAppCount - 1;
        }

        return $this->render('admin/default/system-status.html.twig', array(
            "mainAppUpgrade"            => $mainAppUpgrade,
            "upgradeAppCount"           => $upgradeAppCount,
            'disabledCloudServiceCount' => $this->getDisabledCloudServiceCount()
        ));
    }

    protected function getDisabledCloudServiceCount()
    {
        $disabledCloudServiceCount = 0;

        $settingKeys = array(
            'course.live_course_enabled'  => '',
            'cloud_sms.sms_enabled'       => '',
            'cloud_search.search_enabled' => '',
            'storage.upload_mode'         => 'cloud'
        );

        foreach ($settingKeys as $settingName => $expect) {
            $value = $this->setting($settingName);
            if (empty($expect)) {
                $disabledCloudServiceCount += empty($value) ? 1 : 0;
            } else {
                $disabledCloudServiceCount += empty($value) || $value != $expect ? 2 : 0;
            }
        }

        return $disabledCloudServiceCount;
    }

    public function operationAnalysisDashbordBlockAction(Request $request)
    {
        $todayTimeStart = strtotime(date("Y-m-d", time()));
        $todayTimeEnd   = strtotime(date("Y-m-d", time() + 24 * 3600));

        $onlineCount = $this->getStatisticsService()->countOnline(15 * 60);
        $loginCount  = $this->getStatisticsService()->countLogin(15 * 60);

        $todayRegisterNum = $this->getUserService()->searchUserCount(array("startTime" => $todayTimeStart, "endTime" => $todayTimeEnd));
        $totalRegisterNum = $this->getUserService()->searchUserCount(array());

        $todayCourseMemberNum    = $this->getOrderService()->countOrders(array("paidStartTime" => $todayTimeStart, "paidEndTime" => $todayTimeEnd, "targetType" => 'course', "status" => "paid"));
        $todayClassroomMemberNum = $this->getOrderService()->countOrders(array("paidStartTime" => $todayTimeStart, "paidEndTime" => $todayTimeEnd, "targetType" => 'classroom', "status" => "paid"));

        $totalCourseMemberNum    = $this->getOrderService()->countOrders(array("targetType" => 'course', "status" => "paid"));
        $totalClassroomMemberNum = $this->getOrderService()->countOrders(array("targetType" => 'classroom', "status" => "paid"));

        $todayVipNum = 0;
        $totalVipNum = 0;
        if ($this->isPluginInstalled('vip')) {
            $todayVipNum = $this->getVipService()->searchMembersCount(array("boughtTimeLessThan" => $todayTimeStart, "boughtTimeMoreThan" => $todayTimeEnd, "boughtType" => 'new'));
            $totalVipNum = $this->getVipService()->searchMembersCount(array());
        }

        $todayThreadUnAnswerNum = $this->getThreadService()->countThreads(array('startCreatedTime' => $todayTimeStart, 'endCreatedTime' => $todayTimeEnd, 'postNum' => 0, 'type' => 'question'));
        $totalThreadNum         = $this->getThreadService()->countThreads(array('postNum' => 0, 'type' => 'question'));

        return $this->render('admin/default/operation-analysis-dashbord.html.twig', array(
            'onlineCount'             => $onlineCount,
            'loginCount'              => $loginCount,

            'todayRegisterNum'        => $todayRegisterNum,
            'totalRegisterNum'        => $totalRegisterNum,

            'todayCourseMemberNum'    => $todayCourseMemberNum,
            'totalCourseMemberNum'    => $totalCourseMemberNum,

            'todayClassroomMemberNum' => $todayClassroomMemberNum,
            'totalClassroomMemberNum' => $totalClassroomMemberNum,

            'todayVipNum'             => $todayVipNum,
            'totalVipNum'             => $totalVipNum,

            'todayThreadUnAnswerNum'  => $todayThreadUnAnswerNum,
            'totalThreadNum'          => $totalThreadNum
        ));
    }

    public function userStatisticAction(Request $request, $period)
    {
        $series    = array();
        $days      = $this->getDaysDiff($period);
        $timeRange = $this->getTimeRange($period);

        //每日注册用户
        $series['registerCount'] = $this->getRegisterCount($timeRange);

        //活跃用户
        $series['activeUserCount'] = $this->getActiveuserCount($days);

        //每日注册总数
        $series['registerTotalCount'] = $this->getRegisterTotalCount($timeRange, $days);

        $userAnalysis = EchartsBuilder::createLineDefaultData($days, 'Y/m/d', $series);

        //流失用户
        $userAnalysis['series']['lostUserCount'] = $this->getLostUserCount($userAnalysis);

        return $this->createJsonResponse($userAnalysis);
    }

    public function completedTaskStatisticAction(Request $request, $period)
    {
        $days   = $this->getDaysDiff($period);
        $series = array();
        $timeRange                     = $this->getTimeRange($period);
        $finishedTaskData = $this->getTaskResultService()->analysisCompletedTaskDataByTime($timeRange['startTime'],$timeRange['endTime']);
        $series['finishedTaskCount'] = $finishedTaskData;
        return $this->createJsonResponse(EchartsBuilder::createBarDefaultData($days, 'Y/m/d', $series));
    }

    /**
     * 订单统计
     * @param  Request                                          $request
     * @param  $period
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function studyStatisticAction(Request $request, $period)
    {
        $series    = array();
        $days      = $this->getDaysDiff($period);
        $timeRange = $this->getTimeRange($period);

        $conditions              = array('paidStartTime' => $timeRange['startTime'], 'paidEndTime' => $timeRange['endTime'], 'status' => 'paid');
        $newOrders               = $this->getOrderService()->analysisOrderDate($conditions);
        $series['newOrderCount'] = $newOrders;

        $conditions['totalPriceGreaterThan'] = 0;
        $newPaidOrders                       = $this->getOrderService()->analysisOrderDate($conditions);
        $series['newPaidOrderCount']         = $newPaidOrders;

        $userAnalysis = EchartsBuilder::createLineDefaultData($days, 'Y/m/d', $series);
        return $this->createJsonResponse($userAnalysis);
    }

    public function orderStatisticAction(Request $request, $period)
    {
        $days = $this->getDaysDiff($period);

        $startTime = strtotime(date('Y-m-d', time() - $days * 24 * 60 * 60));

        $orderDatas = $this->getOrderService()->analysisPaidOrderGroupByTargetType($startTime, 'targetType');

        $defaults = array(
            'course'    => array('targetType' => 'course', 'value' => 0),
            'vip'       => array('targetType' => 'vip', 'value' => 0),
            'classroom' => array('targetType' => 'classroom', 'value' => 0)
        );
        $orderDatas = ArrayToolkit::index($orderDatas, 'targetType');
        $orderDatas = array_merge($defaults, $orderDatas);

        $names = array('course' => '课程订单', 'vip' => '会员订单', 'classroom' => '班级订单');
        array_walk($orderDatas, function (&$orderData) use ($names) {
            $orderData['name'] = $names[$orderData['targetType']];
            unset($orderData['targetType']);
        });
        return $this->createJsonResponse(array_values($orderDatas));
    }

    public function courseExploreAction(Request $request, $period)
    {
        $days      = $this->getDaysDiff($period);
        $startTime = strtotime(date('Y-m-d', time() - $days * 24 * 60 * 60));

        $memberCounts = $this->getCourseMemberService()->searchMemberCountGroupByFields(array('startTimeGreaterThan' => $startTime, 'classroomId' => 0, 'role' => 'student'), 'courseSetId', 0, 10);
        $courseSetIds    = ArrayToolkit::column($memberCounts, 'courseSetId');
        $courseSets      = $this->getCourseSetService()->findCourseSetsByIds($courseSetIds);
        $courseSets      = ArrayToolkit::index($courseSets, 'id');

        return $this->render('admin/default/parts/course-explore-table.html.twig', array(
            'memberCounts' => $memberCounts,
            'courseSets'      => $courseSets
        ));
    }

    public function courseReviewAction(Request $request)
    {
        $reviews = $this->getReviewService()->searchReviews(
            array('parentId' => 0),
            'latest',
            0,
            10
        );
        return $this->render('admin/default/parts/course-review-table.html.twig', array(
            'reviews' => $reviews
        ));
    }

    public function unsolvedQuestionsBlockAction(Request $request)
    {
        $questions = $this->getThreadService()->searchThreads(array('type' => 'question', 'postNum' => 0), 'createdNotStick', 0, 10);

        $courses = $this->getCourseService()->findCoursesByIds(ArrayToolkit::column($questions, 'courseId'));

        return $this->render('admin/default/unsolved-questions-block.html.twig', array(
            'questions' => $questions,
            'courses'   => $courses
        ));
    }

    public function questionRemindTeachersAction(Request $request, $courseId, $questionId)
    {
        $course   = $this->getCourseService()->getCourse($courseId);
        $question = $this->getThreadService()->getThread($courseId, $questionId);

        $message = array(
            'courseTitle'   => $course['title'],
            'courseId'      => $course['id'],
            'threadId'      => $question['id'],
            'questionTitle' => strip_tags($question['title'])
        );

        foreach ($course['teacherIds'] as $receiverId) {
            $result = $this->getNotificationService()->notify($receiverId, 'questionRemind', $message);
        }

        return $this->createJsonResponse(array('success' => true, 'message' => 'ok'));
    }

    public function cloudSearchRankingAction(Request $request)
    {
        $api           = CloudAPIFactory::create('root');
        $result        = $api->get('/search/words/ranking', array());
        $searchRanking = isset($result['items']) ? $result['items'] : array();
        return $this->render('admin/default/cloud-search-ranking.html.twig', array('searchRankings' => $searchRanking));
    }

    public function weekday($time)
    {
        if (is_numeric($time)) {
            $weekday = array('星期日', '星期一', '星期二', '星期三', '星期四', '星期五', '星期六');
            return $weekday[date('w', $time)];
        }

        return false;
    }

    private function getRegisterCount($timeRange)
    {
        $analysisRegister = $this->getUserService()->analysisRegisterDataByTime($timeRange['startTime'], $timeRange['endTime']);

        return $analysisRegister;
    }

    private function getActiveuserCount($days)
    {
        $active_days    = "30";
        $activeAnalysis = $this->getUserActiveService()->analysisActiveUser(strtotime(date('Y-m-d', time() - ($days + $active_days) * 24 * 60 * 60)), strtotime(date('Y-m-d', time() + 24 * 60 * 60)));
        $activeAnalysis = $this->fillActiveUserCount($days, $activeAnalysis);

        return $activeAnalysis;
    }

    private function getRegisterTotalCount($timeRange, $days)
    {
        $registerCount    = $this->getUserService()->findUsersCountByLessThanCreatedTime($timeRange['startTime']);
        $dayRegisterTotal = $this->getUserService()->analysisRegisterDataByTime($timeRange['startTime'], $timeRange['endTime']);
        $dayRegisterTotal = $this->fillAnalysisUserSum($registerCount, $dayRegisterTotal, $days);

        return $dayRegisterTotal;
    }

    private function getLostUserCount($userAnalysis)
    {
        $lostUserCount = array();

        $dayRegisterTotal = $userAnalysis['series']['registerTotalCount'];
        $activeUserCount  = $userAnalysis['series']['activeUserCount'];
        array_walk($dayRegisterTotal, function ($value, $index) use (&$lostUserCount, $activeUserCount) {
            $lostUserCount[] = $value - $activeUserCount[$index];
        });

        return $lostUserCount;
    }

    private function getDaysDiff($period)
    {
        $days = $period == 'week' ? 6 : 29;
        return $days;
    }

    protected function getTimeRange($period)
    {
        $days = $this->getDaysDiff($period);

        return array('startTime' => strtotime(date('Y-m-d', time() - $days * 24 * 60 * 60)), 'endTime' => strtotime(date('Y-m-d', time() + 24 * 3600)));
    }

    protected function makeDateRange($startTime, $endTime)
    {
        $dates = array();

        $currentTime = $startTime;

        while (true) {
            if ($currentTime >= $endTime) {
                break;
            }

            $currentDate = date('Y-m-d', $currentTime);
            $dates[]     = $currentDate;

            $currentTime = $currentTime + 3600 * 24;
        }

        return $dates;
    }

    protected function generateDateRange($days, $format = 'Y/m/d')
    {
        $dates = array();
        for ($i = $days; $i >= 0; $i--) {
            $dates[] = date($format, time() - $i * 24 * 60 * 60);
        }
        return $dates;
    }

    protected function fillActiveUserCount($days, $activeAnalysis)
    {
        $xAxisDate = $this->generateDateRange($days, 'Y-m-d');
        $result    = array();
        array_walk($xAxisDate, function ($date) use ($activeAnalysis, &$result) {
            foreach ($activeAnalysis as $index => $value) {
                //在30天内登录过系统的用户即为活跃用户
                $diff = (strtotime($date) - strtotime($value['date'])) / 86400;
                if ($diff >= 0 && $diff <= 30) {
                    $result[$date][] = $value['userId'];
                }
            }
            if (empty($result[$date])) {
                $result[$date] = array();
            }
        });

        array_walk($result, function (&$data, $key) {
            $data = array('count' => count(array_unique($data)), 'date' => $key);
        });

        return $result; //array_values($result);
    }

    //获取每天的注册总数
    protected function fillAnalysisUserSum($registerCount, $dayRegisterTotal, $days)
    {
        $dayRegisterTotal = ArrayToolkit::index($dayRegisterTotal, 'date');

        $xAxisDate = $this->generateDateRange($days, 'Y-m-d');
        foreach ($xAxisDate as $date) {
            $zeroAnalysis[$date] = array('count' => 0, 'date' => $date);
        }

        $dayRegisterTotal = array_merge($zeroAnalysis, $dayRegisterTotal);

        $previousRegisterTotalCount = 0;
        array_walk($dayRegisterTotal, function (&$data) use ($registerCount, &$previousRegisterTotalCount) {
            //累加前一天的计算总数
            $data['count'] += empty($previousRegisterTotalCount) ? $registerCount : $previousRegisterTotalCount;
            $previousRegisterTotalCount = $data['count'];
        });

        return $dayRegisterTotal;
    }

    protected function getSettingService()
    {
        return $this->createService('System:SettingService');
    }

    protected function getStatisticsService()
    {
        return $this->createService('System:StatisticsService');
    }

    /**
     * @return ThreadService
     */
    protected function getThreadService()
    {
        return $this->createService('Course:ThreadService');
    }

    /**
     * @return CourseService
     */
    protected function getCourseService()
    {
        return $this->createService('Course:CourseService');
    }

    protected function getCourseSetService()
    {
        return $this->createService('Course:CourseSetService');
    }

    /**
     * @return OrderService
     */
    protected function getOrderService()
    {
        return $this->createService('Order:OrderService');
    }

    protected function getNotificationService()
    {
        return $this->createService('User:NotificationService');
    }

    protected function getLogService()
    {
        return $this->createService('System:LogService');
    }

    /**
     * @return AppService
     */
    protected function getAppService()
    {
        return $this->createService('CloudPlatform:AppService');
    }

    protected function getCashService()
    {
        return $this->createService('Cash:CashService');
    }

    private function getWebExtension()
    {
        return $this->container->get('topxia.twig.web_extension');
    }

    protected function getUpgradeNoticeService()
    {
        return $this->createService('User:UpgradeNoticeService');
    }

    protected function getReviewService()
    {
        return $this->createService('Course:ReviewService');
    }

    protected function getUserActiveService()
    {
        return $this->createService('User:UserActiveService');
    }

    protected function getCourseMemberService()
    {
        return $this->createService('Course:MemberService');
    }

    /**
     * @return VipService
     */
    protected function getVipService()
    {
        return $this->createService('Vip:Vip.VipService');
    }

    protected function getTaskResultService()
    {
        return $this->createService('Task:TaskResultService');
    }

    protected function isPluginInstalled($name)
    {
        return $this->get('topxia.twig.web_extension')->isPluginInstalled($name);
    }
}
