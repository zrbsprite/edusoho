<?php

namespace AppBundle\Component\Export;

class Factory
{
    protected $container;

    public function __construct($container)
    {
        $this->container = $container;
    }

    public function create($name, $conditions)
    {
        $export = $this->exportMap($name);

        return new $export($this->container, $conditions);
    }

    private function exportMap($name)
    {
        $map = array(
            'invite-records' => 'AppBundle\Component\Export\Invite\InviteRecordsExporter',
            'user-invite-records' => 'AppBundle\Component\Export\Invite\InviteUserRecordsExporter',
            'course-order' => 'AppBundle\Component\Export\Order\CourseOrderExporter',
            'classroom-order' => 'AppBundle\Component\Export\Order\ClassroomOrderExporter',
            'vip-order' => 'AppBundle\Component\Export\Order\VipOrderExporter',
            'course-overview-student-list' => 'AppBundle\Component\Export\Course\OverviewStudentExporter',
            'course-overview-task-list' => 'AppBundle\Component\Export\Course\OverviewTaskExporter',
            'course-overview-normal-task-detail' => 'AppBundle\Component\Export\Course\OverviewNormalTaskDetailExporter',
            'course-overview-testpaper-task-detail' => 'AppBundle\Component\Export\Course\OverviewTestpaperTaskDetailExporter',
        );

        return $map[$name];
    }
}
