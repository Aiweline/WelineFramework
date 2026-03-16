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
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Db\Setup;
#[Table(comment: '主题表')]
#[Index(name: 'parent_id', columns: ['parent_id'])]
class WelineTheme extends Model
{
    public string $module_name = '';
    public const cache_TIME = 604800;
    public const schema_table = 'weline_theme';
    public const schema_primary_key = 'id';
    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col('varchar', 60, nullable: false, unique: true, comment: '主题名')]
    public const schema_fields_NAME = 'name';
    #[Col('varchar', 255, nullable: false, comment: '模块名')]
    public const schema_fields_MODULE_NAME = 'module_name';
    #[Col('varchar', 128, nullable: false, unique: true, comment: '主题路径')]
    public const schema_fields_PATH = 'path';
    #[Col('varchar', 255, nullable: true, comment: '预览图片路径')]
    public const schema_fields_PREVIEW_IMAGE = 'preview_image';
    #[Col('int', 11, comment: '父级主题')]
    public const schema_fields_PARENT_ID = 'parent_id';
    #[Col('int', 11, comment: '是否激活')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col('tinyint', 1, default: 0, comment: '前台是否激活')]
    public const schema_fields_IS_ACTIVE_FRONTEND = 'is_active_frontend';
    #[Col('tinyint', 1, default: 0, comment: '后台是否激活')]
    public const schema_fields_IS_ACTIVE_BACKEND = 'is_active_backend';
    #[Col('text', comment: '主题配置JSON')]
    public const schema_fields_CONFIG = 'config';
    #[Col('datetime', default: 'CURRENT_TIMESTAMP', comment: '安装时间')]
    public const schema_fields_CREATE_TIME = 'create_time';
    #[Col('datetime', default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATE_TIME = 'update_time';
//    protected $table = Install::table_THEME; # 如果需要设置特殊表名 需要加前缀
    private ?WelineTheme $theme = null;
    /**
     * 获取激活的主题（支持按区域：前台/后台）
     *
     * @param string|null $area 'frontend' 前台 | 'backend' 后台 | null 兼容旧逻辑（is_active）
     * @return static
     */
    public function getActiveTheme(?string $area = null): static
    {
        $cacheKey = $area === 'frontend' ? 'theme_frontend' : ($area === 'backend' ? 'theme_backend' : 'theme');
        if ($area === null && $this->theme) {
            return $this->theme;
        }
        if ($cached = $this->_cache->get($cacheKey)) {
            return $this->setData($cached);
        }
        $field = $area === 'frontend' ? self::schema_fields_IS_ACTIVE_FRONTEND
            : ($area === 'backend' ? self::schema_fields_IS_ACTIVE_BACKEND : self::schema_fields_IS_ACTIVE);
        $this->load($field, 1);
        if (!$this->getId() && $area !== null) {
            $this->clearQuery();
            $this->load(self::schema_fields_IS_ACTIVE, 1);
        }
        if ($this->getId()) {
            $this->_cache->set($cacheKey, $this->getData(), static::cache_TIME);
            Env::getInstance()->setConfig('theme', $this->getData());
        }
        if ($area === null) {
            $this->theme = $this;
        }
        return $this;
    }
    public function getName()
    {
        return $this->getData(self::schema_fields_NAME);
    }
    public function setName($value): static
    {
        $this->setData(self::schema_fields_NAME, $value);
        return $this;
    }
    public function getModuleName()
    {
        return $this->getData(self::schema_fields_MODULE_NAME);
    }
    public function setModuleName(string $module_name): static
    {
        $this->setData(self::schema_fields_MODULE_NAME, $module_name);
        return $this;
    }
    public function getPath(): string
    {
        if ($this->getData(self::schema_fields_PATH)) {
            return Env::path_THEME_DESIGN_DIR . str_replace('\\', DS, $this->getData(self::schema_fields_PATH)) . DS;
        }
        return App::Env('theme')['path'] ?? '';
    }
    public function getOriginPath(): string
    {
        return $this->getData(self::schema_fields_PATH);
    }
    public function getRelatePath(): string
    {
        return str_replace(BP, '', Env::path_THEME_DESIGN_DIR) . str_replace('\\', DS, $this->getData(self::schema_fields_PATH)) . DS;
    }
    public function setPath($value): static
    {
        $this->setData(self::schema_fields_PATH, $value);
        return $this;
    }
    public function getPreviewImage(): ?string
    {
        return $this->getData(self::schema_fields_PREVIEW_IMAGE);
    }
    public function setPreviewImage(?string $value): static
    {
        $this->setData(self::schema_fields_PREVIEW_IMAGE, $value);
        return $this;
    }
    public function getParentId()
    {
        return $this->getData(self::schema_fields_PARENT_ID);
    }
    public function setParentId($value): static
    {
        $this->setData(self::schema_fields_PARENT_ID, $value);
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
        return $this->getData(self::schema_fields_IS_ACTIVE);
    }
    public function setIsActive(bool $value): static
    {
        $this->setData(self::schema_fields_IS_ACTIVE, (int)$value);
        return $this;
    }
    public function getCreateTime()
    {
        return $this->getData(self::schema_fields_CREATE_TIME);
    }
    public function setCreateTime($time): static
    {
        $this->setData(self::schema_fields_CREATE_TIME, $time);
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
        if (!$this->getId()) {
            return;
        }
        if ($this->isActive()) {
            $this->getQuery()
                 ->clearQuery()
                 ->where(self::schema_fields_IS_ACTIVE, 1)
                 ->where(self::schema_fields_ID, $this->getId(), '!=')
                 ->update([self::schema_fields_IS_ACTIVE => 0])
                 ->fetch();
            Env::getInstance()->setConfig('theme', $this->getData());
        }
        if ((int)$this->getData(self::schema_fields_IS_ACTIVE_FRONTEND) === 1) {
            $this->getQuery()
                 ->clearQuery()
                 ->where(self::schema_fields_IS_ACTIVE_FRONTEND, 1)
                 ->where(self::schema_fields_ID, $this->getId(), '!=')
                 ->update([self::schema_fields_IS_ACTIVE_FRONTEND => 0])
                 ->fetch();
            $this->_cache->delete('theme_frontend');
        }
        if ((int)$this->getData(self::schema_fields_IS_ACTIVE_BACKEND) === 1) {
            $this->getQuery()
                 ->clearQuery()
                 ->where(self::schema_fields_IS_ACTIVE_BACKEND, 1)
                 ->where(self::schema_fields_ID, $this->getId(), '!=')
                 ->update([self::schema_fields_IS_ACTIVE_BACKEND => 0])
                 ->fetch();
            $this->_cache->delete('theme_backend');
        }
        $this->_cache->delete('theme');
    }
/**
     * 获取主题配置
     * @return array
     */
    public function getConfig(): array
    {
        $config = $this->getData(self::schema_fields_CONFIG);
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
        $this->setData(self::schema_fields_CONFIG, json_encode($config, JSON_UNESCAPED_UNICODE));
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
