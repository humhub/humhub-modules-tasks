<?php
/**
 * @link https://www.humhub.org/
 * @copyright Copyright (c) 2018 HumHub GmbH & Co. KG
 * @license https://www.humhub.com/licences
 *
 */

namespace humhub\modules\tasks\models\lists;

use humhub\modules\content\components\ActiveQueryContent;
use humhub\modules\content\components\ContentContainerActiveRecord;
use humhub\modules\content\models\ContentTag;
use humhub\modules\tasks\models\Sortable;
use humhub\modules\tasks\models\Task;
use yii\db\ActiveQuery;
use yii\db\Expression;

/**
 * Class TaskList
 * @property int $id
 * @property TaskListSettings $addition
 */
class TaskList extends ContentTag implements TaskListInterface, Sortable
{
    public $moduleId = 'tasks';

    public $additionClass = TaskListSettings::class;

    const FILTER_STATUS_INCLUDE = 'status';
    const FILTER_STATUS_EXCLUDE = 'status';

    public function afterSave($insert, $changedAttributes)
    {
        // TODO: this can be removed after v1.2.6
        $this->addition->setTag($this);

        if($insert) {
            $root = new TaskListRoot(['contentContainer' => $this->getContainer()]);
            $root->moveItemIndex($this->id, 0);
        }

        parent::afterSave($insert, $changedAttributes);
    }

    /**
     * @return ActiveQueryContent
     */
    public function getTasks()
    {
        return Task::find()->andWhere(['task_list_id' => $this->id])->readable();
    }

    public function load($data, $formName = null)
    {
        // TODO: this can be removed after v1.2.6
        $this->addition->load($data);
        return parent::load($data, $formName); // TODO: Change the autogenerated stub
    }

    /**
     * @return ActiveQueryContent
     */
    public function getNonCompletedTasks()
    {
        return $this->getTasks()->where(['!=', 'task.status', Task::STATUS_COMPLETED])->orderBy(['sort_order' => SORT_ASC, 'updated_at' => SORT_DESC]);
    }

    /**
     * @param $offset int
     * @param $limit int
     * @return static[]
     */
    public function getShowMoreCompletedTasks($offset, $limit)
    {
        return $this->getCompletedTasks()->orderBy(['task.updated_at' => SORT_DESC])->offset($offset)->limit($limit)->all();
    }

    /**
     * @return ActiveQuery
     */
    public function getCompletedTasks()
    {
        return $this->getTasksByStatus(Task::STATUS_COMPLETED)->orderBy(['task.updated_at' => SORT_DESC]);
    }

    /**
     * @param $status
     * @return ActiveQuery
     */
    public function getTasksByStatus($status)
    {
        return $this->getTasks()->where(['task.status' => $status]);
    }

    /**
     * @param $id
     * @param $container
     * @return TaskList|null
     */
    public static function findById($id, $container)
    {
        return static::findByContainer($container)->andWhere(['id' => $id])->one();
    }

    /**
     * @param $container
     * @param array $filters
     * @return ActiveQuery
     */
    public static function findByFilter($container, $filters = [])
    {
        $query = static::findByContainer($container);

        if(isset($filters[static::FILTER_STATUS_EXCLUDE])) {
            $includes =  Task::$statuses;
            $excludes = is_array($filters[static::FILTER_STATUS_EXCLUDE]) ? $filters[static::FILTER_STATUS_EXCLUDE] : [$filters[static::FILTER_STATUS_EXCLUDE]];
            foreach ($excludes as $exclude) {
                unset($includes[$exclude]);
            }

            $filters[static::FILTER_STATUS_INCLUDE] = $includes;
        }

        if(isset($filters[static::FILTER_STATUS_INCLUDE])) {
            $query->leftJoin('task', 'task.task_list_id=content_tag.id');
            $includes = is_array($filters[static::FILTER_STATUS_INCLUDE]) ? $filters[static::FILTER_STATUS_INCLUDE] : [$filters[static::FILTER_STATUS_INCLUDE]];

            $query->andWhere(
                ['OR',
                    ['IS', 'task.id', new Expression("NULL")],
                    ['IN', 'task.status', $includes]
                ]);
        }

        return $query;
    }

    /**
     * @param $container
     * @return ActiveQuery
     */
    public static function findOverviewLists(ContentContainerActiveRecord $container)
    {
        $query = static::findByContainer($container);
        $query->leftJoin('task', 'task.task_list_id=content_tag.id');
        $query->leftJoin('task_list_setting', 'task_list_setting.tag_id=content_tag.id');

        $includes =  [Task::STATUS_IN_PROGRESS, Task::STATUS_PENDING_REVIEW, Task::STATUS_PENDING];

        $query->andWhere(
            ['OR',
                ['IN', 'task.status', $includes],
                ['IS', 'task.id', new Expression("NULL")],
                ['task_list_setting.hide_if_completed' => '0']
            ]
        );

        $query->orderBy(['task_list_setting.sort_order' => SORT_ASC]);


        return $query;
    }

    public static function findHiddenLists(ContentContainerActiveRecord $container)
    {
        $query = static::findByContainer($container);
        $query->leftJoin('task t', 't.task_list_id=content_tag.id');
        $query->leftJoin('task_list_setting', 'task_list_setting.tag_id=content_tag.id');

        $includes =  [Task::STATUS_IN_PROGRESS, Task::STATUS_PENDING_REVIEW, Task::STATUS_PENDING];

        $subQuery = Task::find()->where(['task_list_id' => new Expression('content_tag.id')])->andWhere(['IN', 'task.status', $includes]);

        $query->andWhere(
            ['AND',
                ['NOT EXISTS', $subQuery],
                ['IS NOT', 't.id', new Expression("NULL")],
                ['task_list_setting.hide_if_completed' => '1']
            ]
        );

        $query->orderBy(['task_list_setting.updated_at' => SORT_ASC]);

        return $query;
    }

    /**
     * Deletes all tags by module id
     * @param ContentContainerActiveRecord|int $contentContainer
     */
    public static function deleteByModule($contentContainer = null)
    {
        $instance = new static();

        if($contentContainer) {
            $container_id = $contentContainer instanceof ContentContainerActiveRecord ? $contentContainer->contentcontainer_id : $contentContainer;
            static::deleteAll(['module_id' => $instance->module_id, 'contentcontainer_id' => $container_id]);
        } else {
            static::deleteAll(['module_id' => $instance->module_id]);
        }
    }

    /**
     * Deletes all tags by type
     * @param ContentContainerActiveRecord|int $contentContainer
     */
    public static function deleteByType($contentContainer = null)
    {
        $instance = new static();

        if($contentContainer) {
            $container_id = $contentContainer instanceof ContentContainerActiveRecord ? $contentContainer->contentcontainer_id : $contentContainer;
            static::deleteAll(['type' => $instance->type, 'contentcontainer_id' => $container_id]);
        } else {
            static::deleteAll(['type' => $instance->type]);
        }
    }

    /**
     * @inheritdoc
     */
    public function afterDelete()
    {
        // Workaround for non foreign key support
        Task::updateAll(['task_list_id' => new Expression('NULL')], ['task_list_id' => $this->id]);
        parent::afterDelete();
    }

    public function moveItemIndex($taskId, $newIndex)
    {
        /** @var $task Task */

        $transaction = Task::getDb()->beginTransaction();

        try {
            $task = Task::findOne(['id' => $taskId]);
            $tasks = $this->getNonCompletedTasks()->andWhere(['!=', 'task.id', $task->id])->all();
            $task->updateAttributes(['task_list_id' => $this->id]);

            // make sure no invalid index is given
            if ($newIndex < 0) {
                $newIndex = 0;
            } else if ($newIndex >= count($tasks) + 1) {
                $newIndex = count($tasks) - 1;
            }

            array_splice($tasks, $newIndex, 0, [$task]);

            foreach ($tasks as $index => $item) {
                $item->updateAttributes(['sort_order' => $index]);
            }

            $transaction->commit();
        } catch (\Exception $e) {
            $transaction->rollBack();
            throw $e;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        $this->refresh();
    }

    public function isHideIfCompleted()
    {
        return $this->addition->hide_if_completed;
    }

    public function getId()
    {
       return $this->id;
    }

    public function getTitle()
    {
        return $this->name;
    }

    public function getColor()
    {
        return $this->color;
    }
}