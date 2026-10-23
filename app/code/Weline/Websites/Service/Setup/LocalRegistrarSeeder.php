<?php

declare(strict_types=1);

namespace Weline\Websites\Service\Setup;

use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\DomainRegistrar;

/** Idempotent seed for the built-in local registrar used by dev/E2E. */
final class LocalRegistrarSeeder
{
    public const LOCAL_CODE = 'weline_local';

    public function ensure(): void
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

            return;
        }

        $insert = clone $model;
        $insert->clearData()->clearQuery();
        $insert->setData(DomainRegistrar::schema_fields_CODE, self::LOCAL_CODE);
        $insert->setData(DomainRegistrar::schema_fields_NAME, (string)__('Weline（本地）'));
        $insert->setData(DomainRegistrar::schema_fields_DESCRIPTION, (string)__('本地开发与 E2E 用内置供应商；免对外支付，订单状态机与线上对齐。'));
        $insert->setData(DomainRegistrar::schema_fields_STATUS, DomainRegistrar::STATUS_ACTIVE);
        $insert->save(true);
    }
}
