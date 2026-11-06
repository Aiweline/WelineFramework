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
    use \Weline\Theme\Model\Concern\LoadRequestMemoTrait;

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
     * 主题目录代次命名空间：任何主题行写入即 bump，跨 Worker 立即失效目录快照。
     */
    public const CATALOG_GENERATION_NAMESPACE = 'global/storefront/theme/catalogue';

    /** @var list<array<string, mixed>>|null 进程内驻留的主题目录快照（13 行量级） */
    private static ?array $catalogRows = null;

    /** @var array<int, array<string, mixed>>|null 主键索引，避免每次请求重建 */
    private static ?array $catalogById = null;

    /** 快照建立时的代次指纹；代次变化即整袋作废 */
    private static string $catalogGeneration = '';

    /**
     * 主题目录快照：进程内驻留，代次变化才回库。
     *
     * 布局解析每请求都要拿主题行及其 parent 链（设计层覆盖判定、父主题
     * published 继承、主题 code 派生）。主题目录是全局事实、行数固定且极小，
     * 属于「Worker 起动期一次装载、请求期零库读」的数据，不需要每请求重查
     * w_weline_theme。
     *
     * 失效：{@see self::CATALOG_GENERATION_NAMESPACE} 由 {@see self::save_after()} /
     * {@see self::delete_after()} bump；代次指纹按请求冻结（同命名空间请求内只读一次），
     * 本方法每请求只做一次比较，代次未变即直接返回进程袋。
     *
     * @return list<array<string, mixed>>
     */
    public static function fetchCatalogRows(): array
    {
        $generation = self::catalogGeneration();
        if (self::$catalogRows !== null && self::$catalogGeneration === $generation) {
            return self::$catalogRows;
        }

        $rows = [];
        try {
            $rows = w_obj(self::class)->clearData()->clearQuery()->select()->fetchArray();
        } catch (\Throwable) {
            // 读取失败不覆盖既有快照，也不把空目录钉进进程。
            return self::$catalogRows ?? [];
        }
        $rows = \is_array($rows) ? \array_values($rows) : [];

        if ($generation === '') {
            // 代次不可得（活跃事务中 / 命名空间读取失败）：不把快照钉进进程。
            self::$catalogRows = null;
            self::$catalogById = null;
            self::$catalogGeneration = '';

            return $rows;
        }

        self::$catalogRows = $rows;
        self::$catalogById = null;
        self::$catalogGeneration = $generation;

        return $rows;
    }

    /**
     * 目录行（按主键）：命中进程袋时零库读。
     *
     * @return array<string, mixed>
     */
    public static function catalogRow(int $themeId): array
    {
        if ($themeId < 1) {
            return [];
        }

        return self::catalogIndex()[$themeId] ?? [];
    }

    /**
     * 目录里 name 精确匹配的第一行（模块默认主题目录行查询用）。
     *
     * @return array<string, mixed>
     */
    public static function catalogRowByName(string $name, ?string $moduleName = null): array
    {
        foreach (self::fetchCatalogRows() as $row) {
            if (!\is_array($row)) {
                continue;
            }
            if ((string)($row[self::schema_fields_NAME] ?? '') !== $name) {
                continue;
            }
            if ($moduleName !== null
                && (string)($row[self::schema_fields_MODULE_NAME] ?? '') !== $moduleName
            ) {
                continue;
            }

            return $row;
        }

        return [];
    }

    /**
     * 目录里 path 精确匹配的第一行（静态资源发布按 theme 路径反查主题用）。
     *
     * @return array<string, mixed>
     */
    public static function catalogRowByPath(string $path): array
    {
        $normalized = \trim(\str_replace('\\', '/', $path), '/');
        if ($normalized === '') {
            return [];
        }
        foreach (self::fetchCatalogRows() as $row) {
            if (!\is_array($row)) {
                continue;
            }
            // 与 SQL 等值语义保持一致：不做归一化宽松匹配。
            if ((string)($row[self::schema_fields_PATH] ?? '') === $normalized) {
                return $row;
            }
        }

        return [];
    }

    /** 丢弃进程内主题目录快照（写入路径 / 进程缓存重置）。 */
    public static function clearCatalogRowsCache(): void
    {
        self::$catalogRows = null;
        self::$catalogById = null;
        self::$catalogGeneration = '';
    }

    /** 发布主题目录失效：代次 +1，其他 Worker 下一次比较即可看到。 */
    public static function bumpCatalogGeneration(): void
    {
        try {
            ObjectManager::getInstance(\Weline\Framework\Cache\Contract\NamespaceGenerationInterface::class)
                ->bump(self::CATALOG_GENERATION_NAMESPACE);
        } catch (\Throwable) {
            // 代次发布是优化：写入本身已完成，不因失效通道失败而回滚。
        }
        self::clearCatalogRowsCache();
    }

    /** @return array<int, array<string, mixed>> */
    private static function catalogIndex(): array
    {
        if (self::$catalogById !== null) {
            return self::$catalogById;
        }

        $byId = [];
        foreach (self::fetchCatalogRows() as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $id = (int)($row[self::schema_fields_ID] ?? 0);
            if ($id > 0) {
                $byId[$id] = $row;
            }
        }

        return self::$catalogById = $byId;
    }

    private static function catalogGeneration(): string
    {
        try {
            $fingerprint = ObjectManager::getInstance(\Weline\Framework\Cache\Contract\NamespaceGenerationInterface::class)
                ->fingerprint([self::CATALOG_GENERATION_NAMESPACE]);
        } catch (\Throwable) {
            return '';
        }

        return \is_string($fingerprint) ? $fingerprint : '';
    }
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
        // 与 getPath() 同语义的 null 安全：链上缓存重建的空模型（getThemeChain 缓存分支
        // `setData($themeData)` 不校验内容）会让 getData 返回 null，严格返回类型把
        // 「空串走 fallback」放大成烘焙进程 TypeError（layout_solidify_queue 死信 4 次）。
        return (string)$this->getData(self::schema_fields_PATH);
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
        // 目录快照优先：父主题行与自身同属 w_weline_theme，而该表已由 Worker
        // preload 全量装进进程袋（fetchCatalogRows）。此前这里直接 load() 回落查库，
        // 使每个新 Worker 的首个店面请求都要为「拿父主题」付一次库读，
        // 尽管同一份行就在进程内存里。代次失效由目录写入路径负责，快照不会过旧。
        $catalogRow = self::catalogRow((int)$parentId);
        if ($catalogRow !== []) {
            /** @var WelineTheme $parentTheme */
            $parentTheme = ObjectManager::make(WelineTheme::class);
            $parentTheme->setData($catalogRow);
            if ($parentTheme->getId()) {
                $this->_cache->set($cacheKey, $parentTheme->getData(), static::cache_TIME);

                return $parentTheme;
            }
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
        
        // 尝试从缓存获取（重建的行必须有 id 与 path，否则下游 getOriginPath/getPath
        // 消费到空模型；空行一律丢弃，不能混进链里）
        if ($cached = $this->_cache->get($cacheKey)) {
            $chain = [];
            foreach ($cached as $themeData) {
                if (!\is_array($themeData) || empty($themeData[self::schema_fields_ID])) {
                    continue;
                }
                /** @var WelineTheme $theme */
                $theme = ObjectManager::make(WelineTheme::class);
                $chain[] = $theme->setData($themeData);
            }
            if ($chain !== []) {
                return $chain;
            }
            // 缓存内容整体无效：丼掉缓存，走下方重建（不再把空链写回缓存）
            $this->_cache->delete($cacheKey);
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
        // Any in-request row memo captured before this write must not be reused.
        self::bumpLoadMemoGeneration();
        // 主题目录快照 + 代次：本 Worker 立即失效，其他 Worker 下一次代次比较即可看到。
        self::bumpCatalogGeneration();
        // 请求间驻留的运行时主题行（ThemeContextService L1/L2）也必须跟着失效。
        \Weline\Theme\Service\ThemeContextService::invalidateSharedCache();
        if (!$this->getId()) {
            return;
        }
        $this->_cache->delete('theme');
        $this->_cache->delete('theme_frontend');
        $this->_cache->delete('theme_backend');
    }

    public function delete_after()
    {
        self::bumpLoadMemoGeneration();
        self::bumpCatalogGeneration();
        \Weline\Theme\Service\ThemeContextService::invalidateSharedCache();
        parent::delete_after();
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
