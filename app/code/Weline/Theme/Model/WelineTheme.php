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
    #[Col('varchar', 255, nullable: true, comment: '鍓嶅彴棰勮鍥剧墖璺緞')]
    public const schema_fields_FRONTEND_PREVIEW_IMAGE = 'frontend_preview_image';
    #[Col('varchar', 255, nullable: true, comment: '鍚庡彴棰勮鍥剧墖璺緞')]
    public const schema_fields_BACKEND_PREVIEW_IMAGE = 'backend_preview_image';
    #[Col('int', 11, comment: '父级主题')]
    public const schema_fields_PARENT_ID = 'parent_id';
    /** @deprecated 已退役；店面/后台权威为应用引用。常量仅兼容旧调用。 */
    public const schema_fields_IS_ACTIVE = 'is_active';
    /** @deprecated 已退役 */
    public const schema_fields_IS_ACTIVE_FRONTEND = 'is_active_frontend';
    /** @deprecated 已退役 */
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
     * 解析当前区域主题（兼容旧名 getActiveTheme）。
     * 权威：ThemeContextService::resolveTheme → 注册 Default；不再读 is_active_*。
     *
     * @param string|null $area 'frontend' 前台 | 'backend' 后台 | null 视为 frontend
     * @return static
     */
    public function getActiveTheme(?string $area = null): static
    {
        $normalized = $area === 'backend' ? 'backend' : 'frontend';
        $cacheKey = $normalized === 'backend' ? 'theme_backend' : 'theme_frontend';
        if ($area === null && $this->theme) {
            return $this->theme;
        }
        if ($cached = $this->_cache->get($cacheKey)) {
            return $this->setData($cached);
        }
        try {
            /** @var \Weline\Theme\Service\ThemeContextService $ctx */
            $ctx = ObjectManager::getInstance(\Weline\Theme\Service\ThemeContextService::class);
            $resolved = $ctx->resolveTheme($normalized, null, false)
                ?? $ctx->resolveRegisteredDefaultTheme($normalized);
            if ($resolved !== null) {
                $data = $resolved->getData();
                if (\is_array($data) && $data !== []) {
                    $this->setData($data);
                    $this->_cache->set($cacheKey, $this->getData(), static::cache_TIME);
                    Env::getInstance()->setConfig('theme', $this->getData());
                }
            }
        } catch (\Throwable) {
            // leave empty
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
        $path = (string)$this->getData(self::schema_fields_PATH);
        $moduleDefaultPath = $this->resolveModuleDefaultThemePath();
        if ($moduleDefaultPath !== null) {
            return $moduleDefaultPath;
        }

        if ($path !== '') {
            $path = str_replace(['/', '\\'], DS, $path);
            if ($this->isAbsoluteThemePath($path)) {
                return rtrim($path, DS) . DS;
            }

            return rtrim(Env::path_THEME_DESIGN_DIR, '/\\') . DS . trim($path, DS) . DS;
        }
        return App::Env('theme')['path'] ?? '';
    }

    private function resolveModuleDefaultThemePath(): ?string
    {
        if ((string)$this->getData(self::schema_fields_MODULE_NAME) !== 'Weline_Theme') {
            return null;
        }

        $module = Env::getInstance()->getModuleInfo('Weline_Theme');
        $basePath = (string)($module['base_path'] ?? '');
        if ($basePath === '') {
            return null;
        }

        $themePath = rtrim($basePath, '/\\') . DS . 'view' . DS . 'theme';
        if (!is_dir($themePath)) {
            return null;
        }

        return rtrim($themePath, DS) . DS;
    }

    private function isAbsoluteThemePath(string $path): bool
    {
        return preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
            || str_starts_with($path, DS)
            || str_starts_with($path, '\\\\');
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
    private function normalizePreviewImagePath(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $value = \str_replace('\\', '/', \trim($value));
        $value = \ltrim($value, '/');
        if (\str_starts_with($value, 'pub/')) {
            $value = \substr($value, 4);
        }

        return \ltrim($value, '/');
    }
    public function getPreviewImage(): ?string
    {
        return $this->normalizePreviewImagePath($this->getData(self::schema_fields_PREVIEW_IMAGE));
    }
    public function setPreviewImage(?string $value): static
    {
        $this->setData(self::schema_fields_PREVIEW_IMAGE, $this->normalizePreviewImagePath($value));
        return $this;
    }
    public function getFrontendPreviewImage(): ?string
    {
        return $this->normalizePreviewImagePath($this->getData(self::schema_fields_FRONTEND_PREVIEW_IMAGE));
    }
    public function setFrontendPreviewImage(?string $value): static
    {
        $this->setData(self::schema_fields_FRONTEND_PREVIEW_IMAGE, $this->normalizePreviewImagePath($value));
        return $this;
    }
    public function getBackendPreviewImage(): ?string
    {
        return $this->normalizePreviewImagePath($this->getData(self::schema_fields_BACKEND_PREVIEW_IMAGE));
    }
    public function setBackendPreviewImage(?string $value): static
    {
        $this->setData(self::schema_fields_BACKEND_PREVIEW_IMAGE, $this->normalizePreviewImagePath($value));
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
            $parentTheme = ObjectManager::make(WelineTheme::class);
            return $parentTheme->setData($cached);
        }
        // 从数据库加载
        try {
            /** @var WelineTheme $parentTheme */
            $parentTheme = ObjectManager::make(WelineTheme::class);
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
        $cacheKey = 'theme_chain_v2_' . $this->getId();
        
        // 尝试从缓存获取
        if ($cached = $this->_cache->get($cacheKey)) {
            $chain = [];
            foreach ($cached as $themeData) {
                /** @var WelineTheme $theme */
                $theme = ObjectManager::make(WelineTheme::class);
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
    /** @deprecated 激活标记已退役 */
    public function isActive()
    {
        return 0;
    }
    /** @deprecated 激活标记已退役 */
    public function setIsActive(bool $value): static
    {
        unset($value);

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
     * 保存后清理主题缓存；不再维护 is_active_* 互斥标记。
     */
    public function save_after()
    {
        if (!$this->getId()) {
            return;
        }
        $this->_cache->delete('theme');
        $this->_cache->delete('theme_frontend');
        $this->_cache->delete('theme_backend');
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
