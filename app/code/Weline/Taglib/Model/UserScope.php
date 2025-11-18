<?php

namespace Weline\Taglib\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class UserScope extends Model
{
    public const table = 'user_scope';
    public const primary_key = 'id';

    public const fields_ID = 'id';
    public const fields_USER_ID = 'user_id';
    public const fields_SCOPE = 'scope';
    public const fields_DATA = 'data'; // json

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 可根据需要实现升级逻辑
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }
        $setup->createTable('用户作用域数据表')
            ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 11, 'primary key auto_increment', 'ID')
            ->addColumn(self::fields_USER_ID, TableInterface::column_type_INTEGER, 11, 'not null', '用户ID')
            ->addColumn(self::fields_SCOPE, TableInterface::column_type_VARCHAR, 60, 'not null', '数据作用域')
            ->addColumn(self::fields_DATA, TableInterface::column_type_TEXT, null, '', '作用域数据(JSON)')
            ->create();
    }


    public function getScopeData( int $userId, string $scope,string $key = ''): array
    {
        $data = $this->where('scope', $scope)->where('user_id', $userId)->find()->fetchArray();
        if ($key) {
            $data = json_decode($data['data'], true);
            return $data[$key] ?? [];
        }
        return $data;
    }

    public function setScopeData( int $userId, string $scope, array $data): static
    {
        $this->setData('user_id', $userId, true)
            ->setData('data', json_encode($data))
            ->setData('scope', $scope, true)
            ->save();
        return $this;
    }
}
