<?php
declare(strict_types=1);

/**
 * Weline Websites - 域名自动解析任务模型
 *
 * 存储购买域名时需要自动解析到本服务器的任务队列
 */

namespace Weline\Websites\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '域名自动解析任务表')]
#[Index(name: 'idx_status', columns: ['status'])]
#[Index(name: 'idx_domain', columns: ['domain'])]
class DomainAutoResolveTask extends Model
{
    public const schema_table = 'weline_websites_domain_auto_resolve_task';
    public const schema_primary_key = 'task_id';


    #[Col('int', 11, nullable: false, primaryKey: true, autoIncrement: true, comment: '任务ID')]
    public const schema_fields_ID = 'task_id';
    #[Col('varchar', 255, nullable: false, comment: '域名')]
    public const schema_fields_DOMAIN = 'domain';
    #[Col('int', 11, nullable: false, default: 0, comment: '域名商账户ID')]
    public const schema_fields_ACCOUNT_ID = 'account_id';
    #[Col('varchar', 20, nullable: false, default: 'pending', comment: '状态: pending, processing, success, failed')]
    public const schema_fields_STATUS = 'status';
    #[Col('int', 11, nullable: false, default: 0, comment: '重试次数')]
    public const schema_fields_RETRY_COUNT = 'retry_count';
    #[Col('text', nullable: true, comment: '最后一次错误信息')]
    public const schema_fields_LAST_ERROR = 'last_error';
    #[Col('timestamp', nullable: true, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('timestamp', nullable: true, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    public const MAX_RETRY = 10;

    public function getTaskId(): int
    {
        return (int) $this->getData(self::schema_fields_ID);
    }

    public function getDomain(): string
    {
        return (string) $this->getData(self::schema_fields_DOMAIN);
    }

    public function setDomain(string $domain): self
    {
        return $this->setData(self::schema_fields_DOMAIN, $domain);
    }

    public function getAccountId(): int
    {
        return (int) $this->getData(self::schema_fields_ACCOUNT_ID);
    }

    public function setAccountId(int $accountId): self
    {
        return $this->setData(self::schema_fields_ACCOUNT_ID, $accountId);
    }

    public function getStatus(): string
    {
        return (string) $this->getData(self::schema_fields_STATUS);
    }

    public function setStatus(string $status): self
    {
        return $this->setData(self::schema_fields_STATUS, $status);
    }

    public function getRetryCount(): int
    {
        return (int) $this->getData(self::schema_fields_RETRY_COUNT);
    }

    public function incrementRetryCount(): self
    {
        return $this->setData(self::schema_fields_RETRY_COUNT, $this->getRetryCount() + 1);
    }

    public function getLastError(): string
    {
        return (string) $this->getData(self::schema_fields_LAST_ERROR);
    }

    public function setLastError(string $error): self
    {
        return $this->setData(self::schema_fields_LAST_ERROR, $error);
    }

    public function canRetry(): bool
    {
        return $this->getRetryCount() < self::MAX_RETRY;
    }

    /**
     * 通过域名查找任务
     */
    public function loadByDomain(string $domain): self
    {
        $this->clearQuery();
        return $this->where(self::schema_fields_DOMAIN, $domain)->find()->fetch();
    }

    /**
     * 创建自动解析任务
     * 新任务使用新实例插入，避免主键 task_id 残留导致唯一约束冲突
     */
    public static function createTask(string $domain, int $accountId): self
    {
        $existing = new self();
        $existing->loadByDomain($domain);

        if ($existing->getTaskId() && $existing->getStatus() !== self::STATUS_SUCCESS) {
            $existing->setStatus(self::STATUS_PENDING);
            $existing->setData(self::schema_fields_RETRY_COUNT, 0);
            $existing->setLastError('');
            $existing->save();
            return $existing;
        }

        if ($existing->getTaskId()) {
            return $existing;
        }

        // 使用新实例插入，确保不带主键，由数据库序列生成 task_id，避免 duplicate key
        $task = new self();
        $task->setDomain($domain);
        $task->setAccountId($accountId);
        $task->setStatus(self::STATUS_PENDING);
        $task->setData(self::schema_fields_ID, null);
        $task->save();

        return $task;
    }
}

