<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/** SEO 任务队列表 - 存储需异步处理的SEO任务 */
#[Table(comment: 'SEO任务队列表')]
#[Index(name: 'idx_task_type', columns: ['task_type'])]
#[Index(name: 'idx_status', columns: ['status'])]
#[Index(name: 'idx_subject', columns: ['subject_type', 'subject_id'])]
#[Index(name: 'idx_scope_module', columns: ['scope', 'module'])]
#[Index(name: 'idx_scheduled_at', columns: ['scheduled_at'])]
#[Index(name: 'idx_priority_status', columns: ['priority', 'status'])]
class SeoTask extends Model
{

    public const schema_table = 'weline_seo_task';
    public const schema_primary_key = 'task_id';
    #[Col('int', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: '任务ID')]
    public const schema_fields_ID = 'task_id';
    #[Col('varchar', 50, nullable: false, comment: '任务类型')]
    public const schema_fields_TASK_TYPE = 'task_type';
    #[Col('varchar', 50, nullable: false, comment: '主体类型')]
    public const schema_fields_SUBJECT_TYPE = 'subject_type';
    #[Col('int', 0, nullable: false, comment: '主体ID')]
    public const schema_fields_SUBJECT_ID = 'subject_id';
    #[Col('varchar', 100, comment: '业务scope')]
    public const schema_fields_SCOPE = 'scope';
    #[Col('varchar', 150, comment: '来源模块')]
    public const schema_fields_MODULE = 'module';
    #[Col('text', comment: '任务数据JSON')]
    public const schema_fields_PAYLOAD = 'payload';
    #[Col('int', 1, nullable: false, default: 5, comment: '优先级')]
    public const schema_fields_PRIORITY = 'priority';
    #[Col('varchar', 20, nullable: false, default: 'pending', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('int', 1, nullable: false, default: 0, comment: '尝试次数')]
    public const schema_fields_ATTEMPTS = 'attempts';
    #[Col('int', 1, nullable: false, default: 3, comment: '最大尝试次数')]
    public const schema_fields_MAX_ATTEMPTS = 'max_attempts';
    #[Col('text', comment: '错误信息')]
    public const schema_fields_ERROR_MESSAGE = 'error_message';
    #[Col('datetime', comment: '计划执行时间')]
    public const schema_fields_SCHEDULED_AT = 'scheduled_at';
    #[Col('datetime', comment: '实际处理时间')]
    public const schema_fields_PROCESSED_AT = 'processed_at';
    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

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
     * 保存前处理
     */
    public function save_before(): void
    {
        parent::save_before();
        
        if (!$this->getData(self::schema_fields_CREATED_AT)) {
            $this->setData(self::schema_fields_CREATED_AT, date('Y-m-d H:i:s'));
        }
        $this->setData(self::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'));
        
        // 如果没有设置计划时间，默认立即执行
        if (!$this->getData(self::schema_fields_SCHEDULED_AT)) {
            $this->setData(self::schema_fields_SCHEDULED_AT, date('Y-m-d H:i:s'));
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
            ->where(self::schema_fields_TASK_TYPE, $taskType)
            ->where(self::schema_fields_STATUS, self::STATUS_PENDING)
            ->where(self::schema_fields_SCHEDULED_AT, date('Y-m-d H:i:s'), '<=')
            ->where(self::schema_fields_ATTEMPTS, $this->getData(self::schema_fields_MAX_ATTEMPTS) ?: 3, '<')
            ->order(self::schema_fields_PRIORITY, 'DESC')
            ->order(self::schema_fields_CREATED_AT, 'ASC')
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
            ->setData(self::schema_fields_ATTEMPTS, ($this->getAttempts() + 1))
            ->setData(self::schema_fields_PROCESSED_AT, date('Y-m-d H:i:s'))
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
            ->setData(self::schema_fields_PROCESSED_AT, date('Y-m-d H:i:s'));
        
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
                self::schema_fields_SCHEDULED_AT,
                date('Y-m-d H:i:s', time() + $retryDelay)
            );
        }
        
        $this->setData(self::schema_fields_ERROR_MESSAGE, $errorMessage)
            ->setData(self::schema_fields_PROCESSED_AT, date('Y-m-d H:i:s'))
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
        $payload = $this->getData(self::schema_fields_PAYLOAD);
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
        return $this->setData(self::schema_fields_PAYLOAD, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    // ===== Getters and Setters =====

    public function getTaskType(): string
    {
        return (string)$this->getData(self::schema_fields_TASK_TYPE);
    }

    public function setTaskType(string $taskType): self
    {
        return $this->setData(self::schema_fields_TASK_TYPE, $taskType);
    }

    public function getSubjectType(): string
    {
        return (string)$this->getData(self::schema_fields_SUBJECT_TYPE);
    }

    public function setSubjectType(string $subjectType): self
    {
        return $this->setData(self::schema_fields_SUBJECT_TYPE, $subjectType);
    }

    public function getSubjectId(): int
    {
        return (int)$this->getData(self::schema_fields_SUBJECT_ID);
    }

    public function setSubjectId(int $subjectId): self
    {
        return $this->setData(self::schema_fields_SUBJECT_ID, $subjectId);
    }

    public function getPayload(): string
    {
        return (string)$this->getData(self::schema_fields_PAYLOAD);
    }

    public function setPayload(string $payload): self
    {
        return $this->setData(self::schema_fields_PAYLOAD, $payload);
    }

    public function getPriority(): int
    {
        return (int)$this->getData(self::schema_fields_PRIORITY);
    }

    public function setPriority(int $priority): self
    {
        return $this->setData(self::schema_fields_PRIORITY, $priority);
    }

    public function getStatus(): string
    {
        return (string)$this->getData(self::schema_fields_STATUS);
    }

    public function setStatus(string $status): self
    {
        return $this->setData(self::schema_fields_STATUS, $status);
    }

    public function getAttempts(): int
    {
        return (int)$this->getData(self::schema_fields_ATTEMPTS);
    }

    public function getMaxAttempts(): int
    {
        return (int)$this->getData(self::schema_fields_MAX_ATTEMPTS) ?: 3;
    }

    public function setMaxAttempts(int $maxAttempts): self
    {
        return $this->setData(self::schema_fields_MAX_ATTEMPTS, $maxAttempts);
    }

    public function getErrorMessage(): string
    {
        return (string)$this->getData(self::schema_fields_ERROR_MESSAGE);
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


