<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Backend\Config;

use Weline\Acl\Api\Resource\MenuRegistryInterface;
use Weline\Framework\App\Env;
use Weline\Framework\Config\Reader\XmlReader;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Php\FiberTaskBatch;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Setup\Service\SetupSourceFingerprint;
use Weline\Framework\System\File\Scanner;
use Weline\Framework\Xml\Parser;

class MenuXmlReader extends XmlReader
{
    private const RELATIVE_PATH = 'etc' . DIRECTORY_SEPARATOR . 'backend' . DIRECTORY_SEPARATOR . 'menu.xml';

    /** setup:upgrade -f / --force-menu、menu:collect -f：粘性强制，直到 hold 结束。 */
    private static bool $forceFullSticky = false;

    /** >0 时 MenuCollector 不得清除 sticky（防止升级中途二次 collect 提前熄灭强制）。 */
    private static int $forceFullHold = 0;

    /**
     * 本轮 read() 算出的待提交源指纹；须在 ACL/legacy 落库成功后再 commit。
     *
     * @var array<string, string>
     */
    private array $pendingFingerprints = [];

    public function __construct(
        Scanner $scanner,
        Parser  $parser,
                $path = 'etc/backend/menu.xml'
    )
    {
        parent::__construct($scanner, $parser, $path);
    }

    /**
     * 请求 menu.xml 全量重解析，并清除已落盘的 menu:* 指纹（粘性，直至 hold 结束）。
     */
    public static function requestForceFull(string $reason = ''): void
    {
        self::$forceFullSticky = true;
        try {
            $fp = new SetupSourceFingerprint();
            $removed = $fp->forgetByPrefix('menu:');
            SetupSourceFingerprint::resetMemoryStore();
            if (Runtime::isCli()) {
                try {
                    $msg = $reason !== ''
                        ? __('   - 强制全量重解析菜单（%{1}）；已清除 menu:* 指纹 %{2} 条', [$reason, $removed])
                        : __('   - 强制全量重解析菜单；已清除 menu:* 指纹 %{1} 条', [$removed]);
                    ObjectManager::getInstance(Printing::class)->note($msg);
                } catch (\Throwable) {
                }
            }
        } catch (\Throwable) {
        }
    }

    /**
     * 升级全程 hold：中途 MenuCollector 收口不得熄灭强制标志。
     */
    public static function beginForceFullHold(): void
    {
        self::$forceFullHold++;
        self::$forceFullSticky = true;
    }

    public static function endForceFullHold(): void
    {
        self::$forceFullHold = \max(0, self::$forceFullHold - 1);
        if (self::$forceFullHold === 0) {
            self::$forceFullSticky = false;
        }
    }

    public static function clearForceFullRequest(): void
    {
        if (self::$forceFullHold > 0) {
            self::$forceFullSticky = true;

            return;
        }
        self::$forceFullSticky = false;
    }

    /** 测试/升级收口：无条件熄灭 hold 与 sticky。 */
    public static function resetForceFullState(): void
    {
        self::$forceFullHold = 0;
        self::$forceFullSticky = false;
    }

    public static function isForceFullRequested(): bool
    {
        return self::$forceFullSticky;
    }

    public static function forceFullHoldDepth(): int
    {
        return self::$forceFullHold;
    }

    /**
     * 获取 menu.xml 文件列表：仅激活模块，用 base_path + etc/backend/menu.xml 直接定位，不扫描目录。
     *
     * @param \Closure|null $callback 保留签名兼容，此处未使用
     * @return array<string, string> 模块名 => 文件绝对路径
     */
    public function getFileList(null|\Closure $callback = null): array
    {
        $result = [];
        $modules = Env::getInstance()->getActiveModules();
        $order = ['app' => 0, 'framework' => 1, 'system' => 2, 'composer' => 3];
        uasort($modules, static fn($a, $b) => ($order[$a['position'] ?? 'composer'] ?? 4) <=> ($order[$b['position'] ?? 'composer'] ?? 4));
        foreach ($modules as $module) {
            $name = $module['name'] ?? '';
            $basePath = rtrim($module['base_path'] ?? '', '/\\');
            if ($name === '' || $basePath === '') {
                continue;
            }
            $filePath = $basePath . DIRECTORY_SEPARATOR . self::RELATIVE_PATH;
            if (is_file($filePath)) {
                $result[$name] = $filePath;
            }
        }
        return $callback ? $callback($result) : $result;
    }

    /**
     * 读取菜单配置：仅激活模块，base_path 直接定位，逐文件解析合并，降低内存占用。
     * menu.xml 源指纹命中时跳过该模块解析（由 MenuCollector 以 modulesFilter 限定 diff）。
     * 指纹只进入 pending，须由 MenuCollector 在 DB 落库成功后 {@see commitPendingFingerprints()}。
     *
     * @param bool $applySourceFingerprint false 时强制全量解析且不登记 pending（供 MenuSourceProvider 等只读列举）
     * @return array<string, array{file: string, data: array}>
     */
    public function read(bool $applySourceFingerprint = true): array
    {
        $this->pendingFingerprints = [];
        $fileList = $this->getFileList();
        if ($fileList === []) {
            return [];
        }

        $fpService = new SetupSourceFingerprint();
        $activeKeys = \array_keys($fileList);
        \sort($activeKeys, \SORT_STRING);
        $allModules = \array_keys(Env::getInstance()->getModuleList());
        $activeAll = \array_keys(Env::getInstance()->getActiveModules());
        $disabled = \array_values(\array_diff($allModules, $activeAll));
        \sort($disabled, \SORT_STRING);
        $activeFp = \hash('sha256', \implode(',', $activeKeys) . '|disabled:' . \implode(',', $disabled));
        $forceFull = !$applySourceFingerprint
            || self::$forceFullSticky
            || !$fpService->matches('menu:active_set', $activeFp);
        // 源指纹命中但 ACL 菜单产物为空：禁止跳过（否则侧栏永远空着）
        if ($applySourceFingerprint && !$forceFull && !$this->destinationMenusPresent()) {
            $forceFull = true;
            if (Runtime::isCli()) {
                try {
                    ObjectManager::getInstance(Printing::class)->note(__(
                        '   - 菜单 ACL 产物为空，忽略 menu.xml 源指纹，强制全量重解析'
                    ));
                } catch (\Throwable) {
                }
            }
        }

        $module_menus = [];
        $fpPending = $applySourceFingerprint ? ['menu:active_set' => $activeFp] : [];
        $batch = new FiberTaskBatch(null, true, 'WELINE_MENU_FIBER_CONCURRENCY');
        $batch->mapModules(
            $fileList,
            function (string $module, mixed $filePath) use ($fpService, $forceFull, $applySourceFingerprint): ?array {
                $path = (string)$filePath;
                $fp = $fpService->fingerprintFile($path);
                $key = 'menu:' . $module;
                if ($applySourceFingerprint && !$forceFull && $fpService->matches($key, $fp)) {
                    // 双条件：源指纹命中且产物指纹一致才跳过（库被改坏/半成功时强制重解析）
                    $destKey = 'menu:dest:' . $module;
                    $destFp = $this->destinationFingerprintForModule($module);
                    if ($fpService->matches($destKey, $destFp)) {
                        return null;
                    }
                }
                $this->assertMenuXmlWellFormed($path, $module);
                $config = $this->parser->parseFile($path);
                $module_and_file = $module . '::' . $path;
                $one = $this->processOneMenuConfig($config, $path, $module_and_file);
                unset($config);
                if ($one === null) {
                    return null;
                }
                if ($applySourceFingerprint) {
                    $one['_fp'] = $fp;
                    $one['_fp_key'] = $key;
                }

                return $one;
            },
            static function (string $phase, array $ctx) use (&$module_menus, &$fpPending, $applySourceFingerprint): void {
                if ($phase !== 'task' || !($ctx['ok'] ?? false)) {
                    return;
                }
                $one = $ctx['result'] ?? null;
                if ($one !== null && \is_array($one)) {
                    if ($applySourceFingerprint) {
                        $key = (string)($one['_fp_key'] ?? '');
                        $fp = (string)($one['_fp'] ?? '');
                        unset($one['_fp'], $one['_fp_key']);
                        if ($key !== '' && $fp !== '') {
                            $fpPending[$key] = $fp;
                        }
                    }
                    $module_menus[(string)($ctx['key'] ?? '')] = $one;
                }
            },
            [
                'env' => 'WELINE_MENU_FIBER_CONCURRENCY',
                'fail_fast' => true,
                'label' => 'menu-xml-collect',
                'keep_results' => false,
            ]
        );
        unset($batch, $fileList);

        // 延后落盘：成功写入 ACL/legacy 后再 commit，避免「指纹已写、库未同步」永久跳过。
        $this->pendingFingerprints = $fpPending;

        if (Runtime::isCli() && $module_menus === [] && !$forceFull && $applySourceFingerprint) {
            try {
                ObjectManager::getInstance(Printing::class)->note(__(
                    '   - 菜单 menu.xml 源指纹全命中，跳过解析（DB 已有菜单产物）。强制重收集：php bin/w menu:collect -f 或 php bin/w setup:upgrade --force-menu / -f'
                ));
            } catch (\Throwable) {
            }
        }

        foreach ($module_menus as &$module_menu) {
            $data = $module_menu['data'] ?? null;
            if ($data) {
                $orders = array_column($data, 'order');
                array_multisort($orders, SORT_ASC, $data);
                $module_menu['data'] = $data;
            }
        }
        unset($module_menu);

        return $module_menus;
    }

    /**
     * @return array<string, string>
     */
    public function peekPendingFingerprints(): array
    {
        return $this->pendingFingerprints;
    }

    /**
     * 将本轮 pending 源指纹写入磁盘仓（仅应在菜单 DB 落库成功后调用）。
     */
    public function commitPendingFingerprints(): void
    {
        if ($this->pendingFingerprints === []) {
            return;
        }
        (new SetupSourceFingerprint())->mergeUpdates($this->pendingFingerprints);
        $this->pendingFingerprints = [];
    }

    /**
     * 合并追加 pending（如 collect 成功后写入 menu:dest:* 产物指纹）。
     *
     * @param array<string, string> $updates
     */
    public function mergePendingFingerprints(array $updates): void
    {
        foreach ($updates as $key => $value) {
            $k = \trim((string)$key);
            $v = (string)$value;
            if ($k === '' || $v === '') {
                continue;
            }
            $this->pendingFingerprints[$k] = $v;
        }
    }

    /**
     * 丢弃本轮 pending（收集失败或只读列举路径）。
     */
    public function discardPendingFingerprints(): void
    {
        $this->pendingFingerprints = [];
    }

    /**
     * ACL 受管菜单产物是否已落库。源指纹命中但产物为空时不得跳过解析。
     */
    private function destinationMenusPresent(): bool
    {
        try {
            return ObjectManager::getInstance(MenuRegistryInterface::class)->countManagedMenus() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function destinationFingerprintForModule(string $module): string
    {
        try {
            return ObjectManager::getInstance(MenuRegistryInterface::class)->destinationFingerprint($module);
        } catch (\Throwable) {
            return \hash('sha256', 'dest-unavailable');
        }
    }

    /**
     * Fail hard on malformed menu.xml (e.g. corrupted icon attributes).
     * Silent skip previously dropped whole modules from the sidebar/search index.
     */
    private function assertMenuXmlWellFormed(string $filePath, string $module): void
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $xml = simplexml_load_file($filePath);
            if ($xml !== false) {
                return;
            }
            $parts = [];
            foreach (libxml_get_errors() as $error) {
                $parts[] = trim($error->message) . ' (line ' . $error->line . ')';
            }
            throw new \Exception(__(
                '菜单 XML 无法解析：模块 %{1}，文件 %{2}。%{3}',
                [
                    $module,
                    $filePath,
                    $parts !== [] ? implode('; ', $parts) : __('未知解析错误'),
                ]
            ));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * 处理单个 menu.xml 解析结果，返回 ['file' => ..., 'data' => [...]] 或 null 表示跳过。
     */
    private function processOneMenuConfig(array $config, string $filePath, string $module_and_file): ?array
    {
        if (!isset($config['menus']) || !is_array($config['menus'])) {
            w_log_warning(__('跳过格式不正确的菜单配置文件：%{1}', [$module_and_file]));
            return null;
        }
        if (
            !isset($config['menus']['_attribute']['noNamespaceSchemaLocation'])
            && 'urn:weline:module:Weline_Backend::etc/xsd/menu.xsd' !== ($config['menus']['_attribute']['noNamespaceSchemaLocation'] ?? '')
        ) {
            $this->checkElementAttribute(
                $config['menus'],
                'noNamespaceSchemaLocation',
                __('菜单元素menus必须设置：noNamespaceSchemaLocation="urn:weline:module:Weline_Backend::etc/xsd/menu.xsd"，文件：%{1}', $module_and_file)
            );
        }
        $menusContent = $config['menus']['_value'] ?? $config['menus'];
        $menusContent = is_array($menusContent) ? $menusContent : [];
        $data = [];
        foreach ($menusContent as $key => $menuGroup) {
            if ($key === '_attribute' || $key === '_value') {
                continue;
            }
            if ($key === 'menu') {
                $data = array_merge($data, $this->parseMenuElement($menuGroup, '', $module_and_file));
            }
        }
        return ['file' => $filePath, 'data' => $data];
    }
    
    /**
     * 递归解析 <menu> 元素
     *
     * @param array|mixed $menuData 菜单数据（可能是单个菜单或菜单数组）
     * @param string $parentSource 父菜单的 source（用于自动继承）
     * @param string $moduleAndFile 模块和文件标识（用于错误提示）
     * @return array 扁平化的菜单数组
     */
    private function parseMenuElement(mixed $menuData, string $parentSource, string $moduleAndFile): array
    {
        $items = [];
        
        if (!is_array($menuData)) {
            return $items;
        }
        
        $menuList = is_int(array_key_first($menuData)) ? $menuData : [$menuData];
        
        foreach ($menuList as $menu) {
            if (!isset($menu['_attribute'])) {
                continue;
            }
            
            $attrs = $menu['_attribute'];
            
            $this->checkElementAttribute($menu, 'source', __('菜单配置错误：menu元素缺少source属性,文件：%{1}', $moduleAndFile));
            $this->checkElementAttribute($menu, 'name', __('菜单配置错误：menu元素缺少name属性,文件：%{1}', $moduleAndFile));
            $this->checkElementAttribute($menu, 'title', __('菜单配置错误：menu元素缺少title属性,文件：%{1}', $moduleAndFile));
            $this->checkElementAttribute($menu, 'order', __('菜单配置错误：menu元素缺少order属性,文件：%{1}', $moduleAndFile));
            
            if (empty($attrs['parent']) && !empty($parentSource)) {
                $attrs['parent'] = $parentSource;
            }
            
            if (!isset($attrs['action'])) {
                $attrs['action'] = '';
            }
            if (!isset($attrs['icon'])) {
                $attrs['icon'] = '';
            }
            if (!isset($attrs['is_system']) || '0' !== $attrs['is_system']) {
                $attrs['is_system'] = 1;
            }
            if (!isset($attrs['is_backend']) || '0' !== $attrs['is_backend']) {
                $attrs['is_backend'] = 1;
            }
            
            $items[] = $attrs;
            
            $currentSource = $attrs['source'] ?? '';
            
            if (isset($menu['_value']) && is_array($menu['_value']) && isset($menu['_value']['menu'])) {
                $childItems = $this->parseMenuElement($menu['_value']['menu'], $currentSource, $moduleAndFile);
                $items = array_merge($items, $childItems);
            }
        }
        
        return $items;
    }
}
