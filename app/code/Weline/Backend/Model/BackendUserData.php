<?php

namespace Weline\Backend\Model;

use Weline\Backend\Session\BackendSession;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class BackendUserData extends Model
{
    public const fields_ID = 'backend_user_data_id';
    public const fields_BACKEND_USER_ID = 'backend_user_id';
    public const fields_scope = 'scope';
    public const fields_JSON = 'json';

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
        // TODO: Implement upgrade() method.
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        # 检查表存在否
//        $setup->dropTable();
        if (!$setup->tableExist()) {
            $setup->createTable()
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'primary key auto_increment',
                    '后台用户数据ID'
                )
                ->addColumn(
                    self::fields_BACKEND_USER_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'not null',
                    '后台用户ID'
                )
                ->addColumn(
                    self::fields_scope,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'not null',
                    '作用域'
                )
                ->addColumn(
                    self::fields_JSON,
                    'json',
                    0,
                    "not null",
                    'json数据'
                )
                ->create();
        }
    }

    function getScope(string $scope): array
    {
        /**
         * @var BackendSession $session
         */
        $session = ObjectManager::getInstance(BackendSession::class);
        if (!$session->getLoginUserID()) {
            return [];
        }
        $data = $this->where(self::fields_BACKEND_USER_ID, $session->getLoginUserID())
            ->where(self::fields_scope, $scope)
            ->find()
            ->fetch();
        $json = $data['json'] ?? null;
        if (!$json) {
            return [];
        }
        return json_decode($json, true);
    }

    function deleteScope(string $scope): BackendUserData
    {
        /**
         * @var BackendSession $session
         */
        $session = ObjectManager::getInstance(BackendSession::class);
        if ($user_id = $session->getLoginUserID()) {
            $this->where(self::fields_BACKEND_USER_ID, $user_id)
                ->where(self::fields_scope, $scope)
                ->delete()
                ->fetch();
        }
        return $this;
    }
}
