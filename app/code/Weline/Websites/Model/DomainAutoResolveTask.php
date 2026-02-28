<?php
declare(strict_types=1);

/**
 * Weline Websites - 域名自动解析任务模型
 *
 * 存储购买域名时需要自动解析到本服务器的任务队列
 */

namespace Weline\Websites\Model;

use Weline\Framework\Database\Connection\Api\Sql\TableInterface as Table;
use Weline\Framework\Setup\Data\Context as SetupContext;
use Weline\Framework\Setup\Db\ModelSetup;

class DomainAutoResolveTask extends \Weline\Framework\Database\Model
{
    public const fields_ID = 'task_id';
    public const fields_DOMAIN = 'domain';
    public const fields_ACCOUNT_ID = 'account_id';
    public const fields_STATUS = 'status';
    public const fields_RETRY_COUNT = 'retry_count';
    public const fields_LAST_ERROR = 'last_error';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    public const MAX_RETRY = 10;

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, SetupContext $context): void
    {
        $setup->dropTable();
        $this->install($setup, $context);
    }

    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, SetupContext $context): void
    {
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, SetupContext $context): void
    {
        if ($setup->tableExist()) {
            return;
        }

        $setup->createTable()
            ->addColumn(
                self::fields_ID,
                Table::column_type_INTEGER,
                11,
                'primary key auto_increment',
                __('任务ID')
            )
            ->addColumn(
                self::fields_DOMAIN,
                Table::column_type_VARCHAR,
                255,
                'not null',
                __('域名')
            )
            ->addColumn(
                self::fields_ACCOUNT_ID,
                Table::column_type_INTEGER,
                11,
                'not null default 0',
                __('域名商账户ID')
            )
            ->addColumn(
                self::fields_STATUS,
                Table::column_type_VARCHAR,
                20,
                "not null default 'pending'",
                __('状态: pending, processing, success, failed')
            )
            ->addColumn(
                self::fields_RETRY_COUNT,
                Table::column_type_INTEGER,
                11,
                'not null default 0',
                __('重试次数')
            )
            ->addColumn(
                self::fields_LAST_ERROR,
                Table::column_type_TEXT,
                0,
                '',
                __('最后一次错误信息')
            )
            ->addColumn(
                self::fields_CREATED_AT,
                Table::column_type_TIMESTAMP,
                0,
                'default CURRENT_TIMESTAMP',
                __('创建时间')
            )
            ->addColumn(
                self::fields_UPDATED_AT,
                Table::column_type_TIMESTAMP,
                0,
                'default CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                __('更新时间')
            )
            ->addIndex(Table::index_type_KEY, 'idx_status', self::fields_STATUS)
            ->addIndex(Table::index_type_KEY, 'idx_domain', self::fields_DOMAIN)
            ->create();
    }

    public function getTaskId(): int
    {
        return (int) $this->getData(self::fields_ID);
    }

    public function getDomain(): string
    {
        return (string) $this->getData(self::fields_DOMAIN);
    }

    public function setDomain(string $domain): self
    {
        return $this->setData(self::fields_DOMAIN, $domain);
    }

    public function getAccountId(): int
    {
        return (int) $this->getData(self::fields_ACCOUNT_ID);
    }

    public function setAccountId(int $accountId): self
    {
        return $this->setData(self::fields_ACCOUNT_ID, $accountId);
    }

    public function getStatus(): string
    {
        return (string) $this->getData(self::fields_STATUS);
    }

    public function setStatus(string $status): self
    {
        return $this->setData(self::fields_STATUS, $status);
    }

    public function getRetryCount(): int
    {
        return (int) $this->getData(self::fields_RETRY_COUNT);
    }

    public function incrementRetryCount(): self
    {
        return $this->setData(self::fields_RETRY_COUNT, $this->getRetryCount() + 1);
    }

    public function getLastError(): string
    {
        return (string) $this->getData(self::fields_LAST_ERROR);
    }

    public function setLastError(string $error): self
    {
        return $this->setData(self::fields_LAST_ERROR, $error);
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
        return $this->where(self::fields_DOMAIN, $domain)->find()->fetch();
    }

    /**
     * 创建自动解析任务
     */
    public static function createTask(string $domain, int $accountId): self
    {
        $task = new self();
        $task->loadByDomain($domain);

        if ($task->getTaskId() && $task->getStatus() !== self::STATUS_SUCCESS) {
            $task->setStatus(self::STATUS_PENDING);
            $task->setData(self::fields_RETRY_COUNT, 0);
            $task->setLastError('');
            $task->save();
            return $task;
        }

        if ($task->getTaskId()) {
            return $task;
        }

        $task->clearData();
        $task->setDomain($domain);
        $task->setAccountId($accountId);
        $task->setStatus(self::STATUS_PENDING);
        $task->save();

        return $task;
    }
}
