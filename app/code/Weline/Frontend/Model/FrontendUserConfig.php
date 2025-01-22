<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Frontend\Model;

use Weline\Backend\Model\Config;
use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Session;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class FrontendUserConfig extends \Weline\Framework\Database\Model
{
    public const fields_ID = 'user_id';
    public const fields_SESSION = 'session';
    public const fields_config = 'config';

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
//        $setup->dropTable();
        $this->install($setup, $context);
    }

    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable()
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, null, 'primary key', '用户ID')
                ->addColumn(self::fields_config, TableInterface::column_type_TEXT, null, '', '配置')
                ->create();
            /**@var Config $config */
            $config = ObjectManager::getInstance(Config::class);
            $config->setConfig('frontend_default_avatar', 'Weline_Frontend::/img/logo.png', 'Weline_Frontend');
            $setup->getPrinting()->printing('frontend_default_avatar', 'Weline_Frontend::/img/logo.png');
        }
    }

    public function setUserId(int $frontend_user_id)
    {
        return $this->setData(self::fields_ID, $frontend_user_id);
    }

    public function setSession(Session $session): static
    {
        $this->setUserId($session->getLoginUserID());
        $this->setData(self::fields_SESSION, $session::class);
        $this->addConfig($session->getData());
        return $this;
    }

    public function addConfig(string|array $key, mixed $data = null): static
    {
        try {
            $config = $this->getOriginConfig() ? json_decode($this->getOriginConfig(), true) : [];
        } catch (\Exception $exception) {
            $config = [];
        }
        if (is_array($key)) {
            $config = array_merge($config, $key);
        } else {
            $config[$key] = $data;
        }
        $this->setData(self::fields_config, json_encode($config));
        return $this;
    }

    public function getOriginConfig()
    {
        return $this->getData(self::fields_config);
    }

    public function getConfig(string $key)
    {
        try {
            $config = $this->getOriginConfig() ? json_decode($this->getOriginConfig(), true) : [];
            return $config[$key] ?? '';
        } catch (\Exception $exception) {
            return '';
        }
    }

    public function save(array|bool|AbstractModel $data = [], string|array $sequence = ''): bool|int
    {
        $this->forceCheck();
        return parent::save($data, $sequence);
    }
}
