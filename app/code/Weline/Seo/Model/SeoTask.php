<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * SEO 任务队列表
 * 
 * 用于存储需要异步处理的SEO任务，避免阻塞主流程
 * 
 * @package Weline_Seo
 */
class SeoTask extends Model
{
    public const table = 'weline_seo_task';
    public const fields_ID = 'task_id';
    public const fields_TASK_TYPE = 'task_type';
    public const fields_SUBJECT_TYPE = 'subject_type';
    public const fields_SUBJECT_ID = 'subject_id';
    public const fields_PAYLOAD = 'payload';
    public const fields_PRIORITY = 'priority';
    public const fields_STATUS = 'status';
    public const fields_ATTEMPTS = 'attempts';
    public const fields_MAX_ATTEMPTS = 'max_attempts';
    public const fields_ERROR_MESSAGE = 'error_message';
    public const fields_SCHEDULED_AT = 'scheduled_at';
    public const fields_PROCESSED_AT = 'processed_at';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    // 任务类型常量
    public const TASK_TYPE_FEED_GENERATE = 'feed_generate';
    public const TASK_TYPE_PUSH_URLS = 'push_urls';
    public const TASK_TYPE_KEYWORD_EXTRACT = 'keyword_extract';

    // 状态常量
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE = 'done';
    public const STATUS_ERROR = 'error';
    public const STATUS_CANCELLED = 'cancelled';

    // 优先级常量
    public const PRIORITY_LOW = 1;
    public const PRIORITY_NORMAL = 5;
    public const PRIORITY_HIGH = 10;

    /**
     * 安装数据表
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('SEO任务队列表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'primary key auto_increment',
                    '任务ID'
                )
                ->addColumn(
                    self::fields_TASK_TYPE,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'not null',
                    '任务类型：feed_generate, push_urls, keyword_extract'
                )
                ->addColumn(
                    self::fields_SUBJECT_TYPE,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'not null',
                    '主体类型：store, website等'
                )
                ->addColumn(
                    self::fields_SUBJECT_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'not null',
                    '主体ID'
                )
                ->addColumn(
                    self::fields_PAYLOAD,
                    TableInterface::column_type_TEXT,
                    0,
                    '',
                    '任务数据（JSON格式）'
                )
                ->addColumn(
                    self::fields_PRIORITY,
                    TableInterface::column_type_INTEGER,
                    1,
                    'default 5',
                    '优先级：1低，5普通，10高'
                )
                ->addColumn(
                    self::fields_STATUS,
                    TableInterface::column_type_VARCHAR,
                    20,
                    "default 'pending'",
                    '状态：pending, processing, done, error, cancelled'
                )
                ->addColumn(
                    self::fields_ATTEMPTS,
                    TableInterface::column_type_INTEGER,
                    1,
                    'default 0',
                    '尝试次数'
                )
                ->addColumn(
                    self::fields_MAX_ATTEMPTS,
                    TableInterface::column_type_INTEGER,
                    1,
                    'default 3',
                    '最大尝试次数'
                )
                ->addColumn(
                    self::fields_ERROR_MESSAGE,
                    TableInterface::column_type_TEXT,
                    0,
                    '',
                    '错误信息'
                )
                ->addColumn(
                    self::fields_SCHEDULED_AT,
                    TableInterface::column_type_DATETIME,
                    0,
                    '',
                    '计划执行时间'
                )
                ->addColumn(
                    self::fields_PROCESSED_AT,
                    TableInterface::column_type_DATETIME,
                    0,
                    '',
                    '实际处理时间'
                )
                ->addColumn(
                    self::fields_CREATED_AT,
                    TableInterface::column_type_DATETIME,
                    0,
                    '',
                    '创建时间'
                )
                ->addColumn(
                    self::fields_UPDATED_AT,
                    TableInterface::column_type_DATETIME,
                    0,
                    '',
                    '更新时间'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_task_type',
                    self::fields_TASK_TYPE,
                    '任务类型索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_status',
                    self::fields_STATUS,
                    '状态索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_subject',
                    [self::fields_SUBJECT_TYPE, self::fields_SUBJECT_ID],
                    '主体索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_scheduled_at',
                    self::fields_SCHEDULED_AT,
                    '计划时间索引'
                )
                ->addIndex(
                    TableInterface::index_type_KEY,
                    'idx_priority_status',
                    [self::fields_PRIORITY, self::fields_STATUS],
                    '优先级状态复合索引'
                )
                ->create();
        }
    }

    /**
     * 开发模式设置
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * 升级数据表
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 升级逻辑
    }

    /**
     * 保存前处理
     */
    public function save_before(): void
    {
        parent::save_before();
        
        if (!$this->getData(self::fields_CREATED_AT)) {
            $this->setData(self::fields_CREATED_AT, date('Y-m-d H:i:s'));
        }
        $this->setData(self::fields_UPDATED_AT, date('Y-m-d H:i:s'));
        
        // 如果没有设置计划时间，默认立即执行
        if (!$this->getData(self::fields_SCHEDULED_AT)) {
            $this->setData(self::fields_SCHEDULED_AT, date('Y-m-d H:i:s'));
        }
    }

    /**
     * 获取待处理任务
     * 
     * @param string $taskType 任务类型
     * @param int $limit 限制数量
     * @return array
     */
    public function getPendingTasks(string $taskType, int $limit = 100): array
    {
        return $this->reset()
            ->where(self::fields_TASK_TYPE, $taskType)
            ->where(self::fields_STATUS, self::STATUS_PENDING)
            ->where(self::fields_SCHEDULED_AT, date('Y-m-d H:i:s'), '<=')
            ->where(self::fields_ATTEMPTS, $this->getData(self::fields_MAX_ATTEMPTS) ?: 3, '<')
            ->order(self::fields_PRIORITY, 'DESC')
            ->order(self::fields_CREATED_AT, 'ASC')
            ->limit($limit)
            ->select()
            ->fetchArray();
    }

    /**
     * 标记任务为处理中
     * 
     * @return self
     */
    public function markProcessing(): self
    {
        $this->setStatus(self::STATUS_PROCESSING)
            ->setData(self::fields_ATTEMPTS, ($this->getAttempts() + 1))
            ->setData(self::fields_PROCESSED_AT, date('Y-m-d H:i:s'))
            ->save();
        
        return $this;
    }

    /**
     * 标记任务为完成
     * 
     * @param string|null $result 结果信息
     * @return self
     */
    public function markDone(?string $result = null): self
    {
        $this->setStatus(self::STATUS_DONE)
            ->setData(self::fields_PROCESSED_AT, date('Y-m-d H:i:s'));
        
        if ($result) {
            $payload = $this->getPayloadArray();
            $payload['result'] = $result;
            $this->setPayloadArray($payload);
        }
        
        $this->save();
        
        return $this;
    }

    /**
     * 标记任务为失败
     * 
     * @param string $errorMessage 错误信息
     * @return self
     */
    public function markError(string $errorMessage): self
    {
        $attempts = $this->getAttempts();
        $maxAttempts = $this->getMaxAttempts();
        
        if ($attempts >= $maxAttempts) {
            $this->setStatus(self::STATUS_ERROR);
        } else {
            // 未达到最大尝试次数，重置为pending，等待重试
            $this->setStatus(self::STATUS_PENDING);
            // 延迟重试：下次执行时间延后（尝试次数 * 5分钟）
            $retryDelay = $attempts * 5 * 60;
            $this->setData(
                self::fields_SCHEDULED_AT,
                date('Y-m-d H:i:s', time() + $retryDelay)
            );
        }
        
        $this->setData(self::fields_ERROR_MESSAGE, $errorMessage)
            ->setData(self::fields_PROCESSED_AT, date('Y-m-d H:i:s'))
            ->save();
        
        return $this;
    }

    /**
     * 获取Payload数组
     * 
     * @return array
     */
    public function getPayloadArray(): array
    {
        $payload = $this->getData(self::fields_PAYLOAD);
        if (empty($payload)) {
            return [];
        }
        
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            return is_array($decoded) ? $decoded : [];
        }
        
        return is_array($payload) ? $payload : [];
    }

    /**
     * 设置Payload数组
     * 
     * @param array $payload
     * @return self
     */
    public function setPayloadArray(array $payload): self
    {
        return $this->setData(self::fields_PAYLOAD, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    // ===== Getters and Setters =====

    public function getTaskType(): string
    {
        return (string)$this->getData(self::fields_TASK_TYPE);
    }

    public function setTaskType(string $taskType): self
    {
        return $this->setData(self::fields_TASK_TYPE, $taskType);
    }

    public function getSubjectType(): string
    {
        return (string)$this->getData(self::fields_SUBJECT_TYPE);
    }

    public function setSubjectType(string $subjectType): self
    {
        return $this->setData(self::fields_SUBJECT_TYPE, $subjectType);
    }

    public function getSubjectId(): int
    {
        return (int)$this->getData(self::fields_SUBJECT_ID);
    }

    public function setSubjectId(int $subjectId): self
    {
        return $this->setData(self::fields_SUBJECT_ID, $subjectId);
    }

    public function getPayload(): string
    {
        return (string)$this->getData(self::fields_PAYLOAD);
    }

    public function setPayload(string $payload): self
    {
        return $this->setData(self::fields_PAYLOAD, $payload);
    }

    public function getPriority(): int
    {
        return (int)$this->getData(self::fields_PRIORITY);
    }

    public function setPriority(int $priority): self
    {
        return $this->setData(self::fields_PRIORITY, $priority);
    }

    public function getStatus(): string
    {
        return (string)$this->getData(self::fields_STATUS);
    }

    public function setStatus(string $status): self
    {
        return $this->setData(self::fields_STATUS, $status);
    }

    public function getAttempts(): int
    {
        return (int)$this->getData(self::fields_ATTEMPTS);
    }

    public function getMaxAttempts(): int
    {
        return (int)$this->getData(self::fields_MAX_ATTEMPTS) ?: 3;
    }

    public function setMaxAttempts(int $maxAttempts): self
    {
        return $this->setData(self::fields_MAX_ATTEMPTS, $maxAttempts);
    }

    public function getErrorMessage(): string
    {
        return (string)$this->getData(self::fields_ERROR_MESSAGE);
    }

    public function isPending(): bool
    {
        return $this->getStatus() === self::STATUS_PENDING;
    }

    public function isProcessing(): bool
    {
        return $this->getStatus() === self::STATUS_PROCESSING;
    }

    public function isDone(): bool
    {
        return $this->getStatus() === self::STATUS_DONE;
    }

    public function isError(): bool
    {
        return $this->getStatus() === self::STATUS_ERROR;
    }
}

