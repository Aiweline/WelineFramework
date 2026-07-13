<?php

declare(strict_types=1);

namespace Weline\Websites\Setup\Db\Migration;

use Weline\Framework\Database\Migration\AbstractMigration;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\DomainRegistrar;

/**
 * 内置本地域名供应商 Weline（开发与 E2E），setup:upgrade 可重复执行自愈
 */
class EnsureWelineLocalRegistrar20260403V100 extends AbstractMigration
{
    public const LOCAL_CODE = 'weline_local';

    public function getDescription(): string
    {
        return 'Ensure built-in local domain registrar row (weline_local)';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDate(): string
    {
        return '2026-04-03';
    }

    /**
     * @return array<int, string>
     */
    public function getAffectedTables(): array
    {
        return [DomainRegistrar::schema_table];
    }

    public function install(): bool
    {
        /** @var DomainRegistrar $model */
        $model = ObjectManager::getInstance(DomainRegistrar::class);
        $row = clone $model;
        $row->clearData()->clearQuery()
            ->where(DomainRegistrar::schema_fields_CODE, self::LOCAL_CODE)
            ->find()
            ->fetch();
        if ($row->getId()) {
            if ($row->getData(DomainRegistrar::schema_fields_STATUS) !== DomainRegistrar::STATUS_ACTIVE) {
                $row->setData(DomainRegistrar::schema_fields_STATUS, DomainRegistrar::STATUS_ACTIVE)->save(true);
            }

            return true;
        }

        $insert = clone $model;
        $insert->clearData()->clearQuery();
        $insert->setData(DomainRegistrar::schema_fields_CODE, self::LOCAL_CODE);
        $insert->setData(DomainRegistrar::schema_fields_NAME, (string)__('Weline（本地）'));
        $insert->setData(DomainRegistrar::schema_fields_DESCRIPTION, (string)__('本地开发与 E2E 用内置供应商；免对外支付，订单状态机与线上对齐。'));
        $insert->setData(DomainRegistrar::schema_fields_STATUS, DomainRegistrar::STATUS_ACTIVE);
        $insert->save(true);

        return true;
    }

    public function uninstall(): bool
    {
        return true;
    }
}
