<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Model;

use Weline\Framework\App;
use Weline\Framework\App\Env;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Db\Setup;
use Weline\Theme\Cache\ThemeCache;

class WelineTheme extends Model
{
    public const cache_TIME = 604800;

    public const fields_ID = 'id';

    public const fields_NAME = 'name';

    public const fields_MODULE_NAME = 'module_name';

    public const fields_PATH = 'path';

    public const fields_PARENT_ID = 'parent_id';

    public const fields_IS_ACTIVE = 'is_active';

//    protected $table = Install::table_THEME; # 如果需要设置特殊表名 需要加前缀

    private ?WelineTheme $theme = null;

    /**
     * @DESC          # 获取激活的主题 有缓存
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/31 21:15
     * 参数区：
     * @return $this
     * @throws \ReflectionException
     * @throws \Weline\Framework\Exception\Core
     */
    public function getActiveTheme(): static
    {
        if ($this->theme) {
            return $this->theme;
        }
        if ($theme = $this->_cache->get('theme')) {
            return $this->setData($theme);
        }
        $this->load(self::fields_IS_ACTIVE, 1);

        if ($this->getId()) {
            $this->_cache->set('theme', $this->getData(), static::cache_TIME);
            Env::getInstance()->setConfig('theme', $this->getData());
        }
        return $this;
    }

    public function getName()
    {
        return $this->getData(self::fields_NAME);
    }

    public function setName($value): static
    {
        $this->setData(self::fields_NAME, $value);

        return $this;
    }

    public function getModuleName()
    {
        return $this->getData(self::fields_MODULE_NAME);
    }

    public function setModuleName(string $module_name): static
    {
        $this->setData(self::fields_MODULE_NAME, $module_name);

        return $this;
    }

    public function getPath(): string
    {
        if ($this->getData(self::fields_PATH)) {
            return Env::path_THEME_DESIGN_DIR . str_replace('\\', DS, $this->getData(self::fields_PATH)) . DS;
        }
        return App::Env('theme')['path'] ?? '';
    }

    public function getOriginPath(): string
    {
        return $this->getData(self::fields_PATH);
    }

    public function getRelatePath(): string
    {
        return str_replace(BP, '', Env::path_THEME_DESIGN_DIR) . str_replace('\\', DS, $this->getData(self::fields_PATH)) . DS;
    }

    public function setPath($value): static
    {
        $this->setData(self::fields_PATH, $value);

        return $this;
    }

    public function getParentId()
    {
        return $this->getData(self::fields_PARENT_ID);
    }

    public function setParentId($value): static
    {
        $this->setData(self::fields_PARENT_ID, $value);

        return $this;
    }

    public function isActive()
    {
        return $this->getData(self::fields_IS_ACTIVE);
    }

    public function setIsActive(bool $value): static
    {
        $this->setData(self::fields_IS_ACTIVE, (int)$value);
        return $this;
    }

    public function getCreateTime()
    {
        return $this->getData(self::fields_CREATE_TIME);
    }

    public function setCreateTime($time): static
    {
        $this->setData(self::fields_CREATE_TIME, $time);

        return $this;
    }

    /**
     * @DESC         |保存之后如果当前主题处于激活状态则启用当前主题
     * 启用前清除所有缓存
     * 启用当前主题则将其他主题设置为不激活
     *
     * 参数区：
     */
    public function save_after()
    {
        if ($this->isActive() && $this->getId()) {
            #$this->query('UPDATE ' . $this->getTable() . ' SET `is_active`=0 WHERE id != ' . $this->getId())->fetch();
            $this->getQuery()
                 ->where(self::fields_IS_ACTIVE, 1)
                 ->where(self::fields_ID, $this->getId(), '!=')
                 ->update(self::fields_IS_ACTIVE, 0)
                 ->fetch();
            Env::getInstance()->setConfig('theme', $this->getData());
        }
    }

    public function setup(ModelSetup $setup, Context $context): void
    {
//        if ($setup->tableExist()) {
//            $setup->dropTable();
//        }
        $this->install($setup, $context);
    }

    public function upgrade(ModelSetup $setup, Context $context): void
    {
    }

    public function install(ModelSetup $setup, Context $context): void
    {
//        $setup->dropTable();
        if (!$setup->tableExist()) {
            $setup->getPrinting()->warning('安装数据库表：' . $this->getTable());
            $setup->createTable(
                '主题表'
            )->addColumn(
                'id',
                TableInterface::column_type_INTEGER,
                11,
                'primary key AUTO_INCREMENT',
                'ID'
            )->addColumn(
                'module_name',
                TableInterface::column_type_VARCHAR,
                '60',
                'UNIQUE NOT NULL ',
                '主题模块名'
            )->addColumn(
                'name',
                TableInterface::column_type_VARCHAR,
                '60',
                'UNIQUE NOT NULL ',
                '主题名'
            )->addColumn(
                'path',
                TableInterface::column_type_VARCHAR,
                '128',
                'UNIQUE NOT NULL ',
                '主题路径'
            )->addColumn(
                'parent_id',
                TableInterface::column_type_INTEGER,
                11,
                '',
                '父级主题'
            )->addColumn(
                'is_active',
                TableInterface::column_type_INTEGER,
                11,
                '',
                '是否激活'
            )->addColumn(
                'create_time',
                TableInterface::column_type_DATETIME,
                null,
                'NOT NULL DEFAULT CURRENT_TIMESTAMP',
                '安装时间'
            )->addColumn(
                'update_time',
                TableInterface::column_type_DATETIME,
                null,
                'NOT NULL DEFAULT CURRENT_TIMESTAMP',
                '更新时间'
            )->addIndex(
                TableInterface::index_type_DEFAULT,
                'parent_id',
                'parent_id'
            )->create();
        }
    }
}
