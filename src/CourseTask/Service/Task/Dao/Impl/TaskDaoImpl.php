<?php

namespace CourseTask\Service\Task\Dao\Impl;

use Topxia\Service\Common\BaseDao;
use CourseTask\Service\Task\Dao\TaskDao;

class TaskDaoImpl extends BaseDao implements TaskDao
{
    protected $table = 'course_task';

    public function get($id)
    {
        $sql = "SELECT * FROM {$this->getTable()} WHERE id = ? LIMIT 1";
        return $this->getConnection()->fetchAssoc($sql, array($id)) ?: null;
    }

    public function add($activity)
    {
        $activity['createdTime'] = time();
        $activity['updatedTime'] = $activity['createdTime'];
        $affected                = $this->getConnection()->insert($this->table, $activity);

        if ($affected <= 0) {
            throw $this->createDaoException('Insert activity error.');
        }

        return $this->get($this->getConnection()->lastInsertId());
    }

    public function update($id, $fields)
    {
        $fields['updatedTime'] = time();
        $this->getConnection()->update($this->table, $fields, array('id' => $id));
        return $this->get($id);
    }

    public function delete($id)
    {
        return $this->getConnection()->delete($this->table, array('id' => $id));
    }

    public function findByCourseId($courseId)
    {
        $sql = "SELECT * FROM {$this->getTable()} WHERE courseId = ? LIMIT 1";
        return $this->getConnection()->fetchAll($sql, array($courseId)) ?: null;
    }
}