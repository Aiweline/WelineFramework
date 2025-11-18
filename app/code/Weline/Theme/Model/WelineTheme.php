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

    public const fields_CONFIG = 'config';

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

    /**
     * 获取父主题对象
     * 
     * @return WelineTheme|null 父主题对象，如果没有父主题则返回null
     */
    public function getParentTheme(): ?WelineTheme
    {
        $parentId = $this->getParentId();
        if (!$parentId) {
            return null;
        }

        // 尝试从缓存获取
        $cacheKey = 'theme_parent_' . $parentId;
        if ($cached = $this->_cache->get($cacheKey)) {
            /** @var WelineTheme $parentTheme */
            $parentTheme = ObjectManager::getInstance(WelineTheme::class);
            return $parentTheme->setData($cached);
        }

        // 从数据库加载
        try {
            /** @var WelineTheme $parentTheme */
            $parentTheme = ObjectManager::getInstance(WelineTheme::class);
            $parentTheme->load($parentId);
            
            if ($parentTheme->getId()) {
                // 缓存父主题数据
                $this->_cache->set($cacheKey, $parentTheme->getData(), static::cache_TIME);
                return $parentTheme;
            }
        } catch (\Exception $e) {
            // 加载失败，返回null
        }

        return null;
    }

    /**
     * 获取完整的主题继承链（从基础到当前）
     * 
     * @return WelineTheme[] 主题继承链数组，第一个是基础主题，最后一个是当前主题
     */
    public function getThemeChain(): array
    {
        $cacheKey = 'theme_chain_' . $this->getId();
        
        // 尝试从缓存获取
        if ($cached = $this->_cache->get($cacheKey)) {
            $chain = [];
            foreach ($cached as $themeData) {
                /** @var WelineTheme $theme */
                $theme = ObjectManager::getInstance(WelineTheme::class);
                $chain[] = $theme->setData($themeData);
            }
            return $chain;
        }

        $chain = [];
        $visited = [];
        $currentTheme = $this;

        // 递归收集父主题
        while ($currentTheme && $currentTheme->getId()) {
            $themeId = $currentTheme->getId();
            
            // 防止循环引用
            if (in_array($themeId, $visited)) {
                break;
            }
            $visited[] = $themeId;

            // 将父主题添加到链的前面（保证顺序：基础 → 父 → 子）
            array_unshift($chain, $currentTheme);

            // 获取父主题
            $parentTheme = $currentTheme->getParentTheme();
            if ($parentTheme) {
                $currentTheme = $parentTheme;
            } else {
                break;
            }
        }

        // 缓存继承链数据
        $chainData = [];
        foreach ($chain as $theme) {
            $chainData[] = $theme->getData();
        }
        $this->_cache->set($cacheKey, $chainData, static::cache_TIME);

        return $chain;
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
            )->addColumn(
                'config',
                TableInterface::column_type_TEXT,
                null,
                '',
                '主题配置（JSON格式，存储partials选择等）'
            )->addIndex(
                TableInterface::index_type_DEFAULT,
                'parent_id',
                'parent_id'
            )->create();
        } else {
            // 如果表已存在，检查是否需要添加 config 字段
            if (!$setup->hasField('config')) {
                $setup->getPrinting()->warning('添加字段：config');
                $setup->alterTable()
                    ->addColumn('config', 'update_time', TableInterface::column_type_TEXT, '', '', '主题配置（JSON格式，存储partials选择等）')
                    ->alter();
            }
        }
    }
    
    /**
     * 获取主题配置
     * @return array
     */
    public function getConfig(): array
    {
        $config = $this->getData(self::fields_CONFIG);
        if (empty($config)) {
            return [];
        }
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($config) ? $config : [];
    }
    
    /**
     * 设置主题配置
     * @param array $config
     * @return $this
     */
    public function setConfig(array $config): static
    {
        $this->setData(self::fields_CONFIG, json_encode($config, JSON_UNESCAPED_UNICODE));
        return $this;
    }
    
    /**
     * 获取配置项
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getConfigValue(string $key, $default = null)
    {
        $config = $this->getConfig();
        return $config[$key] ?? $default;
    }
    
    /**
     * 设置配置项
     * @param string $key
     * @param mixed $value
     * @return $this
     */
    public function setConfigValue(string $key, $value): static
    {
        $config = $this->getConfig();
        $config[$key] = $value;
        return $this->setConfig($config);
    }
}
