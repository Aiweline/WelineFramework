<?php

/*
 * 本文件由 秋枫云飞 编写，解释权归 Aiweline 所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Module\Helper;

use Weline\Framework\App\Env;
use Weline\Framework\Compilation\CompiledPhpArrayWriter;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Module\Model\Module;
use Weline\Framework\Register\RegisterDataInterface;
use Weline\Framework\System\File\Io\File;
use Weline\Framework\Helper\AbstractHelper;
use Weline\Framework\Http\RequestInterface;
use Weline\Framework\Module\Handle;
use Weline\Framework\Module\Service\ModuleScanService;
use Weline\Framework\Registry\Service\RegistryUpdateService;
use Weline\Framework\Register\Register;
use Weline\Framework\Register\Router\Data\DataInterface;
use Weline\Framework\System\File\Scan;

class Data extends AbstractHelper implements \Weline\Framework\Runtime\ProcessSharedInterface
{
    private array $parent_class_arr = [];
    private File $file;
    private Scan $scan;
    private ModuleScanService $moduleScanService;
    private CompiledPhpArrayWriter $compiledPhpArrayWriter;
    
    /**
     * 收集的控制器属性事件数据，用于批量发送。
     *
     * setup 路由扫描用 Fiber 并发跑各模块；同一实例字段在 Fiber 交错（A 在
     * suspend 点让出、B 接着清空/追加）时会互相覆盖，导致除最后一个模块外
     * 全部静默丢路由（实测 frontend_pc.php 缺 index/index → 全站 404）。
     * 因此收集缓冲区按当前 Fiber 分槽：每个 Fiber 只读写自己的槽，结束时不跨
     * Fiber 残留；主线程（无 Fiber）用 `main` 槽。
     *
     * @var array<string, array>
     */
    private array $fiberCollectedAttributesEvents = [];

    /**
     * 收集的路由注册参数，用于批量注册（同样按 Fiber 分槽）。
     *
     * @var array<string, array>
     */
    private array $fiberCollectedRouteRegistrations = [];

    /** setup:upgrade 路由收集期：跨模块暂存 ACL 事件，提交阶段一次派发 */
    private bool $deferControllerAttributes = false;

    /** @var list<\Weline\Framework\DataObject\DataObject> */
    private array $deferredControllerAttributesEvents = [];

    /** defer 队列过大时分块派发，避免扫完全部模块才卸掉 ACL 事件大包 */
    private const DEFER_ACL_FLUSH_CHUNK = 600;

    /**
     * setup:upgrade 路由收集期：控制器类加载失败/解析为空的静默降级记录。
     * 根治「有 Controller 目录、扫描 continue、零产出仍 commit+落指纹 → 店面 404」；
     * RouteUpdateStage::commit() 前读取并否决（DEV/CI 抛错，prod 记 warning 日志）。
     * @var list<array{module: string, class: string, kind: string}>
     */
    private array $routeScanFailures = [];

    /** 当前收集槽标识：Fiber 内按 Fiber 分槽，主线程用 main。 */
    private function collectionSlot(): string
    {
        if (\class_exists(\Fiber::class)) {
            $fiber = \Fiber::getCurrent();
            if ($fiber !== null) {
                return 'fiber:' . \spl_object_id($fiber);
            }
        }

        return 'main';
    }

    /**
     * @return array 当前 Fiber 槽内收集的控制器属性事件（引用，可直接追加）
     */
    private function &collectedControllerAttributesEvents(): array
    {
        $slot = $this->collectionSlot();

        // 同 collectedRouteRegistrations()：返回引用前必须先把槽写回属性，
        // 否则 `??=` 的临时数组会吞掉调用方的追加。
        if (!isset($this->fiberCollectedAttributesEvents[$slot])) {
            $this->fiberCollectedAttributesEvents[$slot] = [];
        }

        return $this->fiberCollectedAttributesEvents[$slot];
    }

    /**
     * @return array 当前 Fiber 槽内收集的路由注册参数（引用，可直接追加）
     */
    private function &collectedRouteRegistrations(): array
    {
        $slot = $this->collectionSlot();

        // 必须先让槽存在，再返回它的引用。
        // 直接 `return $this->fiberCollectedRouteRegistrations[$slot] ??= [];` 在「返回引用」的
        // 方法里不会把空数组写回属性：`??=` 产出的临时值被当成返回值，调用方 `[] =` 追加进
        // 这个临时数组后随即丢弃 —— 表现为「填充点执行 N 次、收集槽恒为 0 条」，
        // 进而路由全部静默丢失、RouteUpdateStage 完整性断言判定 collected=0 并回滚。
        if (!isset($this->fiberCollectedRouteRegistrations[$slot])) {
            $this->fiberCollectedRouteRegistrations[$slot] = [];
        }

        return $this->fiberCollectedRouteRegistrations[$slot];
    }

    private function clearCollectedControllerAttributesEvents(): void
    {
        $events = &$this->collectedControllerAttributesEvents();
        $events = [];
    }

    private function clearCollectedRouteRegistrations(): void
    {
        $routes = &$this->collectedRouteRegistrations();
        $routes = [];
    }

    /** 释放当前 Fiber 的收集槽（模块处理结束/异常时调用，避免内存滞留）。 */
    private function releaseCollectionSlot(): void
    {
        $slot = $this->collectionSlot();
        unset(
            $this->fiberCollectedAttributesEvents[$slot],
            $this->fiberCollectedRouteRegistrations[$slot]
        );
    }

    public function enableRouteScanFailureRecording(): void
    {
        $this->routeScanFailures = [];
        // 模块级「零路由产出」记录（M4）：本轮声明重扫的模块若扫描完一条路由都没注册，
        // 说明该模块在批量收集里被整体吞掉，必须显式回滚而不是让残缺表落盘。
        $this->routeModuleEmitCounts = [];
    }

    /**
     * 直接注册模式：批量收集期置 true，registerModuleRouter 内不走「先收集后批量注册」，
     * 而是每条立即 Register::register（写入 Router\Helper\Data 的批量缓冲），
     * 避免并发/交错收集时共享收集数组被反复清空导致丢路由。
     */
    private bool $directRouteRegistration = false;
    /** 直接注册模式下的单条注册载荷（逐条落定，不抢占共享收集数组）。 */
    private array $routeRegistrationBuffer = [];
    /** 本模块已扫描过的 Api 控制��文件（去重，防 PSR-4 短类重复计数）。 */
    private array $scannedClassFilesApi = [];
    /** 本模块已扫描过的 PC 控制器文件（去重）。 */
    private array $scannedClassFilesPc = [];

    public function enableDirectRouteRegistration(): void
    {
        $this->directRouteRegistration = true;
    }

    public function disableDirectRouteRegistration(): void
    {
        $this->directRouteRegistration = false;
        $this->clearCollectedRouteRegistrations();
    }

    public function isDirectRouteRegistration(): bool
    {
        return $this->directRouteRegistration;
    }

    /**
     * 直接注册模式：把单条已组装的注册载荷立即注册进批量缓冲（不经过共享收集数组），
     * 交错并发收集时也不会被别的模块清空。
     */
    private function applyDirectRouteRegistration(): void
    {
        $registration = $this->routeRegistrationBuffer;
        $this->routeRegistrationBuffer = [];
        if ($registration === [] || empty($registration['params'])) {
            return;
        }
        // 直接注册必须写入 Router\Helper\Data 的「批量缓冲」：非批量模式下 updatePcRouters
        // 只把「属性自读的旧表 + 本次一条」写回文件，同名键原地覆盖，产出恒等于旧表
        // （实测：emit=82 但 frontend_pc.php 长度不变；全量重扫时全站路由被旧表吞掉）。
        $routerHelper = null;
        $before = [];
        try {
            $routerHelper = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Framework\Router\Helper\Data::class
            );
            $before = $this->routeDiagnosticsSnapshot();
            $wasBatchMode = $routerHelper->isBatchMode();
            if (!$wasBatchMode) {
                $routerHelper->enableBatchMode();
                $routerHelper->seedBatchFromDisk();
            }
            Register::register(
                $registration['type'],
                (string)$registration['module_name'],
                $registration['params']
            );
            $this->diagnoseRegistrationWrite($registration, $before);
        } catch (\Throwable $e) {
            // 直接注册期失败即记扫描失败（批量模式原先会整体抛给上层）；
            // 记录后由 RouteUpdateStage 完整性断言否决落盘，避免残缺表。
            $this->recordRouteScanFailure(
                (string)$registration['module_name'],
                (string)($registration['params']['class'] ?? ''),
                'direct_register_failed:' . \get_class($e) . ':' . $e->getMessage()
            );
        }
        $this->countRouteEmit((string)$registration['module_name']);
    }

    /**
     * 批量缓冲计数快照（诊断用）：按 router 文件记录条目数。
     *
     * @return array<string, int>
     */
    private function routeDiagnosticsSnapshot(): array
    {
        try {
            $routerHelper = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Framework\Router\Helper\Data::class
            );
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ([
            \Weline\Framework\App\Env::path_BACKEND_REST_API_ROUTER_FILE,
            \Weline\Framework\App\Env::path_FRONTEND_REST_API_ROUTER_FILE,
            \Weline\Framework\App\Env::path_BACKEND_PC_ROUTER_FILE,
            \Weline\Framework\App\Env::path_FRONTEND_PC_ROUTER_FILE,
        ] as $path) {
            $out[(string)$path] = \count($routerHelper->getBatchRouters((string)$path));
        }
        return $out;
    }

    /**
     * 注册后若批量缓冲未增长，记录到 setup/route_integrity.log（诊断，不否决）。
     *
     * @param array<string, mixed> $registration
     * @param array<string, int> $before
     */
    private function diagnoseRegistrationWrite(array $registration, array $before): void
    {
        $after = $this->routeDiagnosticsSnapshot();
        foreach ($after as $path => $count) {
            if ($count - (int)($before[$path] ?? 0) !== 0) {
                return;
            }
        }
        if ($before === []) {
            return;
        }
        // TEMP-DIAG: 记录实际前后计数与 batchMode，取代被占位符吞掉的告警
        $detail = [];
        foreach ($after as $path => $count) {
            $detail[\basename((string)$path)] = ['before' => (int)($before[$path] ?? 0), 'after' => (int)$count];
        }
        $mode = '?';
        try {
            $rh = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Router\Helper\Data::class);
            $mode = ($rh->isBatchMode() ? 'batch' : 'direct') . '#' . \spl_object_id($rh);
        } catch (\Throwable) {
        }
        w_log_warning(
            'ZEROGROWTH mod=' . (string)$registration['module_name']
            . ' type=' . (string)$registration['type']
            . ' router=' . (string)($registration['params']['router'] ?? '')
            . ' helper=' . $mode
            . ' counts=' . \json_encode($detail, JSON_UNESCAPED_SLASHES),
            [],
            'setup/route_integrity.log'
        );
        return;
        w_log_warning(__(
            '路由直接注册未使批量缓冲增长：模块 %1 路由 %2 类型 %3',
            [
                (string)$registration['module_name'],
                (string)($registration['params']['router'] ?? ''),
                (string)$registration['type'],
            ]
        ), [], 'setup/route_integrity.log');
    }

    /**
     * 单模块 PC/API 路由注册产出的条目数（批量收集期按模块累加）。
     */
    private array $routeModuleEmitCounts = [];

    private function countRouteEmit(string $moduleName, int $delta = 1): void
    {
        if ($moduleName === '' || $delta <= 0) {
            return;
        }
        $this->routeModuleEmitCounts[$moduleName] = (int)($this->routeModuleEmitCounts[$moduleName] ?? 0) + $delta;
    }

    /**
     * @return array<string, int> moduleName => 已注册路由条目数
     */
    public function getRouteModuleEmitCounts(): array
    {
        return $this->routeModuleEmitCounts;
    }

    /**
     * @return list<array{module: string, class: string, kind: string}>
     */
    public function getRouteScanFailures(): array
    {
        return $this->routeScanFailures;
    }

    public function clearRouteScanFailures(): void
    {
        $this->routeScanFailures = [];
    }

    /**
     * Api/ 目录里的符号是否已加载（class / interface / trait / enum 都算）。
     *
     * 只用 class_exists() 会把 *Interface.php 判成「不存在」，进而被
     * RouteUpdateStage 的路由完整性门禁当成扫描失败否决整次 setup:upgrade。
     * 不触发 autoload（第二参数 false），加载由调用方 require_once 负责。
     */
    private function apiSymbolLoaded(string $symbol): bool
    {
        if ($symbol === '') {
            return false;
        }
        if (class_exists($symbol, false) || interface_exists($symbol, false) || trait_exists($symbol, false)) {
            return true;
        }

        return \function_exists('enum_exists') && enum_exists($symbol, false);
    }

    /**
     * 已加载的符号是否能作为控制器（具体类；接口/枚举/trait/抽象类都不行）。
     */
    /**
     * Api/ is a mixed namespace: besides REST controllers it also carries
     * interfaces, DTOs, services, validators and observers (892 files vs 49
     * controllers in this repo). Only a class whose inheritance chain actually
     * names a Controller can carry routes — this mirrors parserController(),
     * which resolves the router area from the parent class name. Treating any
     * instantiable class as a controller sent every service class through the
     * area resolver, produced api_area_unresolved_fallback for 800+ symbols and
     * made the route-integrity gate roll back setup:upgrade (stale routes →
     * storefront 404).
     */
    private function apiSymbolIsControllerClass(string $symbol): bool
    {
        try {
            if (!class_exists($symbol, false)) {
                return false;
            }
            $reflection = new \ReflectionClass($symbol);
            if ($reflection->isInterface() || $reflection->isTrait() || $reflection->isAbstract()) {
                return false;
            }
            if (\method_exists($reflection, 'isEnum') && $reflection->isEnum()) {
                return false;
            }
            if (!$reflection->isInstantiable()) {
                return false;
            }

            // 通用控制器判定：可实例化的普通类即候选；API 侧更严的资格见
            // apiSymbolCanRoute()（PC 控制器继承 BackendController /
            // FrontendController，不是 *RestController，故不能共用那套）。
            //
            // 以下家族虽然住在 Controller/ 命名空间，却不承载任何 HTTP 路由，
            // 一旦被路由会因 area 解析为空而记成扫描失败、拖垮整次 setup:upgrade：
            //   - URL 重写器（implements RouterInterface，如 Weline_Theme\Controller\Router）
            //   - Controller\Extra\*（收集器/注册表/快照等框架管线）
            //   - Controller\Core（implements Data\DataInterface，常量持有者）
            // 只看反射类自身：PC 控制器继承的 Controller\Core 也 implements
            // DataInterface，用 implementsInterface 会把所有控制器误杀。
            if ($reflection->getName() === \Weline\Framework\Controller\Core::class) {
                return false;
            }
            try {
                if ($reflection->implementsInterface(\Weline\Framework\Router\RouterInterface::class)) {
                    return false;
                }
            } catch (\Throwable) {
            }
            if (\str_contains($reflection->getName(), '\\Controller\\Extra\\')) {
                return false;
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Api/ 目录专用的路由资格判定。
     *
     * Api/ 既是路由候选目录，也被当作通用 API 目录使用（实测 1099 个文件里
     * 只有 68 个 REST 控制器，其余是接口/DTO/Service/Factory/Observer）。
     * 只有 *RestController 子类能承载 API 路由；其余符号在这里直接放行跳过，
     * 既不算扫描失败、也不会因为 area 为空而触发路由完整性门禁回滚。
     */
    private function apiSymbolCanRoute(string $symbol): bool
    {
        try {
            if (!class_exists($symbol, false)) {
                return false;
            }
            $reflection = new \ReflectionClass($symbol);
            if ($reflection->isInterface() || $reflection->isTrait() || $reflection->isAbstract()) {
                return false;
            }
            if (\method_exists($reflection, 'isEnum') && $reflection->isEnum()) {
                return false;
            }
            for ($parent = $reflection->getParentClass(); $parent !== false; $parent = $parent->getParentClass()) {
                if (\str_ends_with($parent->getName(), 'RestController')) {
                    return true;
                }
            }
        } catch (\Throwable) {
        }

        return false;
    }

    /**
     * 非致命扫描提示（写日志但不进 routeScanFailures，路由完整性门禁不据此否决）。
     */
    private function recordRouteScanNotice(string $moduleName, string $class, string $kind): void
    {
        w_log_warning(__(
            '路由扫描跳过非路由符号（非致命）：模块 %1 类 %2 — %3',
            [$moduleName, $class, $kind]
        ), [], 'setup/route_integrity.log');
    }

    private function recordRouteScanFailure(string $moduleName, string $class, string $kind): void
    {
        $this->routeScanFailures[] = [
            'module' => $moduleName,
            'class'  => $class,
            'kind'   => $kind,
        ];
        w_log_warning(__(
            '路由扫描静默跳过（已记录）：模块 %1 类 %2 — %3',
            [$moduleName, $class, $kind]
        ), [], 'setup/route_integrity.log');
    }

    public function enableDeferControllerAttributes(): void
    {
        $this->deferControllerAttributes = true;
        $this->deferredControllerAttributesEvents = [];
    }

    public function isDeferControllerAttributes(): bool
    {
        return $this->deferControllerAttributes;
    }

    public function getDeferredControllerAttributesCount(): int
    {
        return \count($this->deferredControllerAttributesEvents);
    }

    /**
     * 将暂存的控制器 ACL 事件一次派发（可含多模块；观察者按 module 分组落库）。
     */
    public function flushDeferredControllerAttributes(): void
    {
        $this->flushDeferredControllerAttributesInternal(false);
    }

    /**
     * 分块冲刷 defer 队列，但保持 defer 开启（路由扫描中途降内存）。
     */
    public function flushDeferredControllerAttributesChunk(): void
    {
        if (!$this->deferControllerAttributes || $this->deferredControllerAttributesEvents === []) {
            return;
        }
        $this->flushDeferredControllerAttributesInternal(true);
    }

    private function flushDeferredControllerAttributesInternal(bool $keepDeferring): void
    {
        $events = $this->deferredControllerAttributesEvents;
        $this->deferredControllerAttributesEvents = [];
        if (!$keepDeferring) {
            $this->deferControllerAttributes = false;
        }
        if ($events === []) {
            return;
        }

        try {
            $this->getEvenManager()->dispatch('Weline_Framework_Module::controller_attributes', $events);
        } finally {
            $events = [];
            unset($events);
            $this->clearCollectedControllerAttributesEvents();
            // 禁止清空 collected_route_registrations：路由扫描中途 ACL 分块 flush
            // 若顺带清空，会丢掉当前模块尚未 batchRegisterRoutes 的 PC/API 注册，
            // 表现为「有 Controller、指纹全绿、菜单 404」（如 Weline_Order）。
            if (\function_exists('gc_collect_cycles')) {
                \gc_collect_cycles();
            }
        }
    }

    public function clearDeferredControllerAttributes(): void
    {
        $this->deferredControllerAttributesEvents = [];
        $this->deferControllerAttributes = false;
    }
    public function __construct(
        File $file,
        Scan $scan,
        $moduleScanService = null,
        ?CompiledPhpArrayWriter $compiledPhpArrayWriter = null
    )
    {
        $this->file = $file;
        $this->scan = $scan;
        $this->moduleScanService = $moduleScanService instanceof ModuleScanService
            ? $moduleScanService
            : new ModuleScanService($scan);
        $this->compiledPhpArrayWriter = $compiledPhpArrayWriter ?? new CompiledPhpArrayWriter();
    }

    public function getClassNamespace(\Weline\Framework\System\File\Data\File $controllerFile)
    {
        $namespace_arr = explode('\\', $controllerFile->getNamespace());
        $namespace_arr = array_slice($namespace_arr, 2);
//        foreach ($namespace_arr as &$item) {
//            if (is_int(strpos($item, '-'))) {
//                $item = explode('-', $item);
//                foreach ($item as &$i) {
//                    $i = ucfirst($i);
//                }
//                $item = implode('', $item);
//            }
//            $item = ucfirst($item);
//        }
        return implode('\\', $namespace_arr);
    }

    /**
     * @DESC         |注册模块路由
     *
     * @param array $modules 模块数组
     * @param Module $register_module 注册模块
     *
     * @throws \ReflectionException
     * @throws \Weline\Framework\App\Exception
     */
    public function registerModuleRouter(array &$modules, Module &$register_module)
    {
        // 收集开关：批量收集期（setup 的 Fiber 并发收集）必须关闭「收集」，改为在模块内直接
        // 注册到批量缓冲。否则 130 个模块的任务交错时，每次进入都会清空共享的
        // collected_route_registrations，导致除最后一个模块外**全部静默丢路由**
        // （实测：frontend_pc.php 只剩 669~842 条，index/index 缺失 → 全站 404）。
        $collecting = !$this->directRouteRegistration;
        // 清空当前模块的事件收集数组和路由注册数组
        $this->clearCollectedControllerAttributesEvents();
        if ($collecting) {
            $this->clearCollectedRouteRegistrations();
        }
        
        $name = $register_module->getName();
        $path = $register_module->getBasePath();
        if (!$this->isDisabled($modules, $name)) {
            $module = $modules[$name];
            // 每模块重置：类去重表与父类链缓存都是模块级状态。
            $this->scannedClassFilesApi = [];
            $this->scannedClassFilesPc = [];
            $this->parent_class_arr = [];
            # API 路由注册
            $api_classs = $this->moduleScanService->globPhpClassesInDirectory(
                $path,
                Handle::api_DIR,
                $module['namespace_path'],
                true,
                true,
                $module['base_path']
            );
            if ($api_classs) {
                foreach ($api_classs as $api_class) {
                    // 类文件路径唯一：PSR-4 映射里同名短类会被扫出多份（如 Widget API 的 class_alias
                    // 壳 + Service 实体），重复扫描会重复计入 ACL 与路由收集计数。
                    if (isset($this->scannedClassFilesApi[$classFileKey = ($classFile = $module['base_path'] . str_replace('\\', DS, str_replace($module['namespace_path'] . '\\', '', $api_class)) . '.php')])) {
                        $this->recordRouteScanNotice($name, $api_class, 'api_duplicate_class_file');
                        continue;
                    }
                    $this->scannedClassFilesApi[$classFileKey] = true;
                    // 查找控制器文件
                    $classRelativePath = str_replace('\\', DS, str_replace($module['namespace_path'] . '\\', '', $api_class)) . '.php';
                    $classFile = $module['base_path'] . $classRelativePath;
                    // 0 字节占位文件：仓库里历史遗留（如 AiKnowledge 的 Controller/Api 空壳，创建即空），
                    // 不承载任何路由。跳过并留 notice，不能记成「扫描失败」——否则路由完整性门禁会
                    // 否决整次 setup:upgrade，而丢路由风险为零。
                    if (is_file($classFile) && @filesize($classFile) === 0) {
                        $this->recordRouteScanNotice($name, $api_class, 'api_file_empty');
                        continue;
                    }
                    // Api/ 目录同时承载 interface / trait / enum（如 *FacadeInterface、*ContentSourceInterface），
                    // 只判 class_exists() 会把它们当成「加载失败」→ 路由完整性门禁据此回滚整次 setup:upgrade。
                    if (is_file($classFile) && !$this->apiSymbolLoaded($api_class)) {
                        require_once $classFile;
                    }
                    // 如果符号不存在，跳过（记录：禁静默降级）
                    if (!$this->apiSymbolLoaded($api_class)) {
                        // 入口壳（只 require 基类、不声明同名符号）不可承载路由，非加载故障。
                        $this->recordRouteScanNotice($name, $api_class, 'api_entry_shell_no_symbol');
                        continue;
                    }
                    // 接口 / trait / enum / 抽象类不可能作为控制器承载路由，跳过并留 notice。
                    if (!$this->apiSymbolCanRoute($api_class)) {
                        // Api/ 下的服务/DTO/接口是常态，不是扫描失败。
                        $this->recordRouteScanNotice($name, $api_class, 'api_symbol_not_controller');
                        continue;
                    }
                    $apiDirArray = explode(Handle::api_DIR, $api_class);
                    // 获取控制器文件的 baseRouter
                    $baseRouterPart = empty(end($apiDirArray)) && count($apiDirArray) > 1 ? $apiDirArray[count($apiDirArray) - 2] : array_pop($apiDirArray);
                    $baseRouter = str_replace('\\', '/', $baseRouterPart);
                    $baseRouterArr = preg_split('/(?=[A-Z])/', $baseRouter);
                    $baseRouter = '';
                    foreach ($baseRouterArr as $baseRouterKey => $baseRouter_) {
                        if (!isset($baseRouterArr[$baseRouterKey - 1])) {
                            $baseRouter .= $baseRouter_;
                            continue;
                        }
                        $pre_ = $baseRouterArr[$baseRouterKey - 1];
                        $lastChar = $pre_[strlen($pre_) - 1] ?? '';
                        if ($lastChar === '/') {
                            $baseRouter .= $baseRouter_;
                        } else {
                            $baseRouter .= '-' . $baseRouter_;
                        }
                    }

                    $this->parent_class_arr = [];// 清空父类数组
                    $ctl_data = $this->parserController($api_class, $name);
                    if (empty($ctl_data)) {
                        $this->recordRouteScanFailure($name, $api_class, 'api_parse_empty');
                        continue;
                    }
                    $ctl_methods = $ctl_data['methods'];
                    $ctl_area = $ctl_data['area'];
                    // 控制器基类识别失败（area 为空）时，Router\Handle::register 的 type/area 分派
                    // 会静默落空：不写任何路由表、也不报错（实测：Weline_Widget 82 条全丢）。
                    // 此时按控制器路径兜底判定 Frontend/Backend，并记扫描失败留痕。
                    if ($ctl_area === [] || $ctl_area === null) {
                        $ctl_area = str_contains($api_class, '\\Backend\\') || str_ends_with($api_class, '\\Backend')
                            ? ['Controller', 'BackendController', 'PcController']
                            : ['Controller', 'FrontendController', 'PcController'];
                        $this->recordRouteScanFailure($name, $api_class, 'api_area_unresolved_fallback');
                    }
                    $backend = false;
                    if (in_array('BackendRestController', $ctl_area)) {
                        $backend = true;
                    }
                    $router = $register_module->getRouter($backend);
                    // API 路由注册包含 PC 路由注册，因此需要注册原始路由和方法路由
                    // area 在 URL 中请求时，获取前端 API URL（在 getFrontendApiUrl() 中）
                    $baseRouter = trim($router . $baseRouter, '/');
                    // 处理路由中的重复斜杠
                    $baseRouter = preg_replace('#/+#', '/', $baseRouter);
                    foreach ($ctl_methods as $method => $attributes) {
                        // 解析方法路由
                        $request_method = null;
                        $rule_method = $method;
                        $request_method_split_array = preg_split('/(?=[A-Z])/', $method);
                        if (1 === count($request_method_split_array)) {
                            $request_method_split_array[1] = $request_method_split_array[0];
                            $request_method_split_array[0] = 'get';
                        }
                        $first_value = $request_method_split_array[array_key_first($request_method_split_array)];
                        if (in_array(strtoupper($first_value), RequestInterface::METHODS)) {
                            $request_method = strtoupper($first_value);
                            array_shift($request_method_split_array);
                            $rule_method = implode('-', $request_method_split_array);
                        } else {
                            $rule_method = trim(implode('-', $request_method_split_array), '-');
                        }
                        # 如果方法路由为空，且方法路由为大写请求方法，则使用大写请求方法
                        if (in_array(strtoupper($rule_method), Request::METHODS)) {
                            $request_method = strtoupper($rule_method);
                            $rule_method = '';
                        }
                        # 获取方法路由
                        // 与 PC 分支同口径：保留 index / getIndex / get-index 三种写法，
                        // 绝不把路由压成空串（空串会被写成 '' 键，请求侧按 index/index 查不到）。
                        $rule_router = strtolower($baseRouter . '/' . $rule_method);
                        $rule_router = trim(preg_replace('#/+#', '/', str_replace('\\', '/', $rule_router)), '/');
                        if ($rule_router === '') {
                            $rule_router = strtolower(trim((string)$baseRouter, '/'));
                        }

                        $request_method = $request_method ?? RequestInterface::GET;
                        # 获取路由
                        $routers = is_string($router) ? [$router] : $router;
                        foreach ($routers as $router_) {
                            $route = $rule_router . ($request_method ? '::' . $request_method : '');
                            $params = [
                                'type' => DataInterface::type_API,
                                'area' => $ctl_area,
                                'module' => $name,
                                'base_router' => $router_,
                                'router' => $route,
                                'class' => $api_class,
                                'module_path' => $path,
                                'method' => $method,
                                'request_method' => $request_method,
                                'is_backend' => $backend,
                                'is_enable' => true
                            ];
                            $data = new DataObject($params);
                            /**@var \ReflectionAttribute $attribute */
                            foreach ($attributes as $attribute) {
                                $data->setData('attribute', $attribute);
                                $data->setData('type', 'api');
                                $data->setData('controller_data', $ctl_data);
                                $data->setData('params', $params);
                                // 收集事件数据，不立即发送
                                $events = &$this->collectedControllerAttributesEvents();
                                $events[] = clone $data;
                            }
                            // 收集路由注册参数，不立即注册
                            if ($collecting) {
                                $this->collectedRouteRegistrations()[] = [
                                'type' => RegisterDataInterface::ROUTER,
                                'module_name' => $name,
                                'params' => $params
                            ];
                            } else {
                                $this->routeRegistrationBuffer = [
                                'type' => RegisterDataInterface::ROUTER,
                                'module_name' => $name,
                                'params' => $params
                            ];
                                $this->applyDirectRouteRegistration();
                            }
                            $this->collectFrontendQueryBinRouteAlias($name, $params);
                            $this->collectFrontendStreamRouteAlias($name, $params);
                            $this->collectFrontendScopeBootstrapRouteAlias($name, $params);
                            $this->collectFrontendApiDemoRouteAlias($name, $params);

                            // 原始路由与 baseRouter 路由不一致时，注册原始路由
                            $origin_route = str_replace('-', '', $route);
                            if ($router !== $origin_route) {
                                $backend = $this->controllerAreaIsBackendPc($ctl_area);
                                $params = [
                                    'type' => DataInterface::type_API,
                                    'area' => $ctl_area,
                                    'module' => $name,
                                    'base_router' => $router_,
                                    'router' => $origin_route,
                                    'class' => $api_class,
                                    'module_path' => $path,
                                    'method' => $method,
                                    'request_method' => $request_method,
                                    'is_backend' => $backend,
                                    'is_enable' => true
                                ];
                                $data = new DataObject($params);
                                /**@var \ReflectionAttribute $attribute */
                                foreach ($attributes as $attribute) {
                                    $data->setData('attribute', $attribute);
                                    $data->setData('type', 'api');
                                    $data->setData('controller_data', $ctl_data);
                                    $data->setData('params', $params);
                                    // 收集事件数据，不立即发送
                                    $events = &$this->collectedControllerAttributesEvents();
                                $events[] = clone $data;
                                }
                                // 收集路由注册参数，不立即注册
                                if ($collecting) {
                                $this->collectedRouteRegistrations()[] = [
                                    'type' => RegisterDataInterface::ROUTER,
                                    'module_name' => $name,
                                    'params' => $params
                                ];
                            } else {
                                $this->routeRegistrationBuffer = [
                                    'type' => RegisterDataInterface::ROUTER,
                                    'module_name' => $name,
                                    'params' => $params
                                ];
                                $this->applyDirectRouteRegistration();
                            }
                            }
                            
                            // 小写方法路由与 baseRouter 方法路由不一致时，注册小写方法路由
                            if ($request_method && $rule_method !== strtolower($method)) {
                                // 1. 小写方法路由
                                $full_method_kebab = strtolower(preg_replace('/([A-Z])/', '-$1', $method));
                                $full_method_kebab_router = strtolower($baseRouter . '/' . $full_method_kebab);
                                $full_method_kebab_router = trim($full_method_kebab_router, '/');
                                $full_method_kebab_router = preg_replace('#/+#', '/', $full_method_kebab_router);
                                $full_method_kebab_route = $full_method_kebab_router . ($request_method ? '::' . $request_method : '');
                                $full_method_kebab_params = [
                                    'type' => DataInterface::type_API,
                                    'area' => $ctl_area,
                                    'module' => $name,
                                    'base_router' => $router_,
                                    'router' => $full_method_kebab_route,
                                    'class' => $api_class,
                                    'module_path' => $path,
                                    'method' => $method,
                                    'request_method' => $request_method,
                                    'is_backend' => $backend,
                                    'is_enable' => true
                                ];
                                    $data = new DataObject($full_method_kebab_params);
                                    /**@var \ReflectionAttribute $attribute */
                                    foreach ($attributes as $attribute) {
                                        $data->setData('attribute', $attribute);
                                        $data->setData('type', 'api');
                                        $data->setData('controller_data', $ctl_data);
                                        $data->setData('params', $full_method_kebab_params);
                                        // 收集事件数据，不立即发送
                                        $events = &$this->collectedControllerAttributesEvents();
                                $events[] = clone $data;
                                    }
                                // 收集路由注册参数，不立即注册
                                if ($collecting) {
                                $this->collectedRouteRegistrations()[] = [
                                    'type' => RegisterDataInterface::ROUTER,
                                    'module_name' => $name,
                                    'params' => $full_method_kebab_params
                                ];
                            } else {
                                $this->routeRegistrationBuffer = [
                                    'type' => RegisterDataInterface::ROUTER,
                                    'module_name' => $name,
                                    'params' => $full_method_kebab_params
                                ];
                                $this->applyDirectRouteRegistration();
                            }
                                
                                // 2. 小写方法路由
                                $full_method_lower_router = strtolower($baseRouter . '/' . strtolower($method));
                                $full_method_lower_router = trim($full_method_lower_router, '/');
                                $full_method_lower_router = preg_replace('#/+#', '/', $full_method_lower_router);
                                // 小写方法路由与 baseRouter 方法路由不一致时，注册小写方法路由
                                if ($full_method_lower_router !== $full_method_kebab_router) {
                                    $full_method_lower_route = $full_method_lower_router . ($request_method ? '::' . $request_method : '');
                                    $full_method_lower_params = [
                                        'type' => DataInterface::type_API,
                                        'area' => $ctl_area,
                                        'module' => $name,
                                        'base_router' => $router_,
                                        'router' => $full_method_lower_route,
                                        'class' => $api_class,
                                        'module_path' => $path,
                                        'method' => $method,
                                        'request_method' => $request_method,
                                        'is_backend' => $backend,
                                        'is_enable' => true
                                    ];
                                    $data = new DataObject($full_method_lower_params);
                                    /**@var \ReflectionAttribute $attribute */
                                    foreach ($attributes as $attribute) {
                                        $data->setData('attribute', $attribute);
                                        $data->setData('type', 'api');
                                        $data->setData('controller_data', $ctl_data);
                                        $data->setData('params', $full_method_lower_params);
                                        // 收集事件数据，不立即发送
                                        $events = &$this->collectedControllerAttributesEvents();
                                $events[] = clone $data;
                                    }
                                    // 收集路由注册参数，不立即注册
                                    if ($collecting) {
                                $this->collectedRouteRegistrations()[] = [
                                        'type' => RegisterDataInterface::ROUTER,
                                        'module_name' => $name,
                                        'params' => $full_method_lower_params
                                    ];
                            } else {
                                $this->routeRegistrationBuffer = [
                                        'type' => RegisterDataInterface::ROUTER,
                                        'module_name' => $name,
                                        'params' => $full_method_lower_params
                                    ];
                                $this->applyDirectRouteRegistration();
                            }
                                }
                            }
                        }
                    }
                }
            }

            # PC 路由注册
            $pc_classs = $this->moduleScanService->globPhpClassesInDirectory(
                $path,
                Handle::pc_DIR,
                $module['namespace_path'],
                true,
                true,
                $module['base_path']
            );
            if ($pc_classs) {
                foreach ($pc_classs as $pc_class) {
                    $pcFileKey = $module['base_path'] . str_replace('\\', DS, str_replace($module['namespace_path'] . '\\', '', $pc_class)) . '.php';
                    if (isset($this->scannedClassFilesPc[$pcFileKey])) {
                        $this->recordRouteScanNotice($name, $pc_class, 'pc_duplicate_class_file');
                        continue;
                    }
                    $this->scannedClassFilesPc[$pcFileKey] = true;
                    // 查找控制器文件
                    $classRelativePath = str_replace('\\', DS, str_replace($module['namespace_path'] . '\\', '', $pc_class)) . '.php';
                    $classFile = $module['base_path'] . $classRelativePath;
                    // 0 字节占位文件（AiKnowledge 的 Controller/Backend/* 创建即空）不承载路由：
                    // 跳过并留 notice，不记成扫描失败，避免路由完整性门禁否决整次升级。
                    if (is_file($classFile) && @filesize($classFile) === 0) {
                        $this->recordRouteScanNotice($name, $pc_class, 'pc_file_empty');
                        continue;
                    }
                    if (is_file($classFile) && !$this->apiSymbolLoaded($pc_class)) {
                        require_once $classFile;
                    }
                    // 如果控制器类不存在，跳过（记录：禁静默降级）
                    if (!$this->apiSymbolLoaded($pc_class)) {
                        // 入口壳控制器（如 Extends\Controller\Backend\Extends 只 require 同名基类，
                        // 自身不声明类）不承载路由，属设计形态而非加载故障；记 notice 免于
                        // 让路由完整性门禁否决整次 setup:upgrade。
                        $this->recordRouteScanNotice($name, $pc_class, 'pc_entry_shell_no_symbol');
                        continue;
                    }
                    // 接口 / trait / enum / 抽象类不能作为 PC 控制器承载路由。
                    if (!$this->apiSymbolIsControllerClass($pc_class)) {
                        $this->recordRouteScanNotice($name, $pc_class, 'pc_symbol_not_controller');
                        continue;
                    }
                    $pcDirArray = explode(Handle::pc_DIR, $pc_class);
                    // 获取控制器文件的 baseRouter
                    $baseRouterPart = empty(end($pcDirArray)) && count($pcDirArray) > 1 ? $pcDirArray[count($pcDirArray) - 2] : array_pop($pcDirArray);
                    $baseRouter = str_replace('\\', '/', $baseRouterPart);
                    $baseRouterArr = preg_split('/(?=[A-Z])/', $baseRouter);
                    $baseRouter = '';
                    foreach ($baseRouterArr as $baseRouterKey => $baseRouter_) {
                        if (!isset($baseRouterArr[$baseRouterKey - 1])) {
                            $baseRouter .= $baseRouter_;
                            continue;
                        }
                        $pre_ = $baseRouterArr[$baseRouterKey - 1];
                        if ($pre_) {
                            $lastChar = $pre_[strlen($pre_) - 1];
                            if ($lastChar === '/') {
                                $baseRouter .= $baseRouter_;
                            } else {
                                $baseRouter .= '-' . $baseRouter_;
                            }
                        }
                    }

                    $this->parent_class_arr = [];// 清空父类数组
                    $ctl_data = $this->parserController($pc_class, $name);
                    if (empty($ctl_data)) {
                        $this->recordRouteScanFailure($name, $pc_class, 'pc_parse_empty');
                        continue;
                    }
                    $ctl_methods = $ctl_data['methods'];
                    $ctl_area = $ctl_data['area'];
                    // 同 API 分支：area 为空会让路由分派静默落空，按路径兜底并留痕。
                    if ($ctl_area === [] || $ctl_area === null) {
                        $ctl_area = str_contains($pc_class, '\\Backend\\')
                            ? ['Controller', 'BackendController', 'PcController']
                            : ['Controller', 'FrontendController', 'PcController'];
                        $this->recordRouteScanFailure($name, $pc_class, 'pc_area_unresolved_fallback');
                    }
                    $backend = $this->controllerAreaIsBackendPc($ctl_area);
                    $router = $register_module->getRouter($backend);
                    $baseRouter = trim($router . $baseRouter, '/');
                    foreach ($ctl_methods as $method => $attributes) {
                        // 解析方法路由
                        $request_method = '';
                        $rule_method = $method;
                        $request_method_split_array = preg_split('/(?=[A-Z])/', $method);
                        if (1 === count($request_method_split_array)) {
                            $request_method_split_array[1] = $request_method_split_array[0];
                            $request_method_split_array[0] = '';
                        }
                        $first_value = $request_method_split_array[array_key_first($request_method_split_array)];
                        if (in_array(strtoupper($first_value), RequestInterface::METHODS)) {
                            $request_method = strtoupper($first_value);
                            array_shift($request_method_split_array);
                            $rule_method = implode('-', $request_method_split_array);
                        } else {
                            $rule_method = trim(implode('-', $request_method_split_array), '-');
                        }
                        # 如果方法路由为空，且方法路由为大写请求方法，则使用大写请求方法
                        if (!$request_method && in_array(strtoupper($rule_method), Request::METHODS)) {
                            $request_method = strtoupper($rule_method);
                            $rule_method = '';
                        }
                        # 获取方法路由
                        // 规范保留三种写法：index / getIndex / get-index（大小写与连字符都要能命中）。
                        // 历史实现会把末尾的 `index` 段剥掉，导致 index/index 被注册成空键 ''，
                        // 请求侧却按 'index/index' 查找 → 首页 404。这里改为只去掉「控制器段与方法段
                        // 完全重复」的冗余，绝不把整条路由压成空串。
                        $rule_router = strtolower($baseRouter . '/' . $rule_method);
                        $rule_router = trim(preg_replace('#/+#', '/', str_replace('\\', '/', $rule_router)), '/');
                        if ($rule_router === '') {
                            // 控制器无方法段（或方法段与路由同名）时，回落到控制器路由本身，
                            // 避免出现空键；空路径由请求侧的默认路由处理。
                            $rule_router = strtolower(trim((string)$baseRouter, '/'));
                        }
                        // 首页这类「控制器段与方法段同名」的路由必须保留完整键
                        // （index/index），否则会被上面的合并压成 'index' 或空串。
                        $controller_segment = strtolower(trim((string)$baseRouter, '/'));
                        if ($rule_method !== '' && $controller_segment !== ''
                            && \str_ends_with($controller_segment, '/' . $rule_method) === false
                            && $rule_router === $controller_segment
                        ) {
                            $rule_router = $controller_segment . '/' . $rule_method;
                        }

                        # 获取路由
                        $routers = is_string($router) ? [$router] : $router;
                        foreach ($routers as $router_) {
                            $route = $rule_router . ($request_method ? '::' . $request_method : '');
                            $params = [
                                'type' => DataInterface::type_PC,
                                'area' => $ctl_area,
                                'module' => $name,
                                'base_router' => $router_,
                                'router' => $route,
                                'class' => $pc_class,
                                'method' => $method,
                                'module_path' => $path,
                                'request_method' => $request_method,
                                'is_backend' => $backend,
                                'is_enable' => true,
                            ];
                            $data = new DataObject($params);
                            /**@var \ReflectionAttribute $attribute */
                            foreach ($attributes as $attribute) {
                                $data->setData('attribute', $attribute);
                                $data->setData('type', 'pc');
                                $data->setData('controller_data', $ctl_data);
                                $data->setData('params', $params);
                                // 收集事件数据，不立即发送
                                $events = &$this->collectedControllerAttributesEvents();
                                $events[] = clone $data;
                            }
                            // 收集路由注册参数，不立即注册
                            if ($collecting) {
                                $this->collectedRouteRegistrations()[] = [
                                'type' => RegisterDataInterface::ROUTER,
                                'module_name' => $name,
                                'params' => $params
                            ];
                            } else {
                                $this->routeRegistrationBuffer = [
                                'type' => RegisterDataInterface::ROUTER,
                                'module_name' => $name,
                                'params' => $params
                            ];
                                $this->applyDirectRouteRegistration();
                            }

                            // 原始路由与 baseRouter 路由不一致时，注册原始路由
                            $origin_route = str_replace('-', '', $route);
                            if ($router !== $origin_route) {
                                $backend = $this->controllerAreaIsBackendPc($ctl_area);
                                $params = [
                                    'type' => DataInterface::type_PC,
                                    'area' => $ctl_area,
                                    'module' => $name,
                                    'base_router' => $router_,
                                    'router' => $origin_route,
                                    'class' => $pc_class,
                                    'module_path' => $path,
                                    'method' => $method,
                                    'request_method' => $request_method,
                                    'is_backend' => $backend,
                                    'is_enable' => true,
                                ];
                                $data = new DataObject($params);
                                /**@var \ReflectionAttribute $attribute */
                                foreach ($attributes as $attribute) {
                                    $data->setData('attribute', $attribute);
                                    $data->setData('type', 'pc');
                                    $data->setData('controller_data', $ctl_data);
                                    $data->setData('params', $params);
                                    // 收集事件数据，不立即发送
                                    $events = &$this->collectedControllerAttributesEvents();
                                $events[] = clone $data;
                                }
                                // 收集路由注册参数，不立即注册
                                if ($collecting) {
                                $this->collectedRouteRegistrations()[] = [
                                    'type' => RegisterDataInterface::ROUTER,
                                    'module_name' => $name,
                                    'params' => $params
                                ];
                            } else {
                                $this->routeRegistrationBuffer = [
                                    'type' => RegisterDataInterface::ROUTER,
                                    'module_name' => $name,
                                    'params' => $params
                                ];
                                $this->applyDirectRouteRegistration();
                            }
                            }
                            
                            // 小写方法路由与 baseRouter 方法路由不一致时，注册小写方法路由
                            if ($request_method && $rule_method !== strtolower($method)) {
                                // 1. 小写方法路由
                                $full_method_kebab = strtolower(preg_replace('/([A-Z])/', '-$1', $method));
                                $full_method_kebab_router = strtolower($baseRouter . '/' . $full_method_kebab);
                                $full_method_kebab_router = trim($full_method_kebab_router, '/');
                                $full_method_kebab_router = preg_replace('#/+#', '/', $full_method_kebab_router);
                                $full_method_kebab_route = $full_method_kebab_router . ($request_method ? '::' . $request_method : '');
                                $full_method_kebab_params = [
                                    'type' => DataInterface::type_PC,
                                    'area' => $ctl_area,
                                    'module' => $name,
                                    'base_router' => $router_,
                                    'router' => $full_method_kebab_route,
                                    'class' => $pc_class,
                                    'module_path' => $path,
                                    'method' => $method,
                                    'request_method' => $request_method,
                                    'is_backend' => $backend,
                                    'is_enable' => true,
                                ];
                                    $data = new DataObject($full_method_kebab_params);
                                    /**@var \ReflectionAttribute $attribute */
                                    foreach ($attributes as $attribute) {
                                        $data->setData('attribute', $attribute);
                                        $data->setData('type', 'pc');
                                        $data->setData('controller_data', $ctl_data);
                                        $data->setData('params', $full_method_kebab_params);
                                        // 收集事件数据，不立即发送
                                        $events = &$this->collectedControllerAttributesEvents();
                                $events[] = clone $data;
                                    }
                                // 收集路由注册参数，不立即注册
                                if ($collecting) {
                                $this->collectedRouteRegistrations()[] = [
                                    'type' => RegisterDataInterface::ROUTER,
                                    'module_name' => $name,
                                    'params' => $full_method_kebab_params
                                ];
                            } else {
                                $this->routeRegistrationBuffer = [
                                    'type' => RegisterDataInterface::ROUTER,
                                    'module_name' => $name,
                                    'params' => $full_method_kebab_params
                                ];
                                $this->applyDirectRouteRegistration();
                            }
                                
                                // 2. 小写方法路由
                                $full_method_lower_router = strtolower($baseRouter . '/' . strtolower($method));
                                $full_method_lower_router = trim($full_method_lower_router, '/');
                                $full_method_lower_router = preg_replace('#/+#', '/', $full_method_lower_router);
                                // 小写方法路由与 baseRouter 方法路由不一致时，注册小写方法路由
                                if ($full_method_lower_router !== $full_method_kebab_router) {
                                    $full_method_lower_route = $full_method_lower_router . ($request_method ? '::' . $request_method : '');
                                    $full_method_lower_params = [
                                        'type' => DataInterface::type_PC,
                                        'area' => $ctl_area,
                                        'module' => $name,
                                        'base_router' => $router_,
                                        'router' => $full_method_lower_route,
                                        'class' => $pc_class,
                                        'module_path' => $path,
                                        'method' => $method,
                                        'request_method' => $request_method,
                                        'is_backend' => $backend,
                                        'is_enable' => true,
                                    ];
                                    $data = new DataObject($full_method_lower_params);
                                    /**@var \ReflectionAttribute $attribute */
                                    foreach ($attributes as $attribute) {
                                        $data->setData('attribute', $attribute);
                                        $data->setData('type', 'pc');
                                        $data->setData('controller_data', $ctl_data);
                                        $data->setData('params', $full_method_lower_params);
                                        // 收集事件数据，不立即发送
                                        $events = &$this->collectedControllerAttributesEvents();
                                $events[] = clone $data;
                                    }
                                    // 收集路由注册参数，不立即注册
                                    if ($collecting) {
                                $this->collectedRouteRegistrations()[] = [
                                        'type' => RegisterDataInterface::ROUTER,
                                        'module_name' => $name,
                                        'params' => $full_method_lower_params
                                    ];
                            } else {
                                $this->routeRegistrationBuffer = [
                                        'type' => RegisterDataInterface::ROUTER,
                                        'module_name' => $name,
                                        'params' => $full_method_lower_params
                                    ];
                                $this->applyDirectRouteRegistration();
                            }
                                }
                            }
                        }
                    }
                }
            }
        }
        
        // 批量发送收集的所有控制器属性事件
        $this->batchDispatchControllerAttributesEvents();
        
        // 批量注册收集的所有路由
        $this->batchRegisterRoutes();

        // 本模块处理完毕：释放当前 Fiber 的收集槽，避免并发期间内存滞留。
        $this->releaseCollectionSlot();
    }
    
    /**
     * 批量注册收集的路由
     * 
     * @return void
     * @throws \Weline\Framework\App\Exception
     */
    private function batchRegisterRoutes(): void
    {
        if (empty($this->collectedRouteRegistrations())) {
            return;
        }
        
        // 清空必须放在注册之前：API/PC 别名收集器（collectFrontend*RouteAlias）会在扫描中途
        // 递归调用本方法。原实现先注册后清空，导致内层调用把外层刚注册的条目一并清掉，
        // 外层循环因此遍历到空数组——整模块路由静默丢失（实测 emit=82 但表长度不变）。
        $pending = $this->collectedRouteRegistrations();
        $this->clearCollectedRouteRegistrations();
        // 批量注册所有路由
        foreach ($pending as $registration) {
            $registered = Register::register(
                $registration['type'],
                $registration['module_name'],
                $registration['params']
            );
            // 计数仅作诊断（收集期产出量）；是否真正落到批量缓冲由 Router\Helper\Data::updatePcRouters
            // 负责，二者不一致时 RouteUpdateStage 的完整性断言会带 collected=N 暴露。
            $this->countRouteEmit((string)$registration['module_name']);
            unset($registered);
        }
        
        // 注意：不在这里调用 flushBatchRouters()
        // 批量写入由 RouteUpdateStage->commit() 统一处理
        // 这样可以确保在阶段提交时一次性写入所有路由文件
        
        // 清空收集的路由注册数据
        $this->clearCollectedRouteRegistrations();
    }
    
    /**
     * 批量发送收集的控制器属性事件
     * 
     * @return void
     */
    private function batchDispatchControllerAttributesEvents(): void
    {
        if (empty($this->collectedControllerAttributesEvents())) {
            return;
        }

        if ($this->deferControllerAttributes) {
            foreach ($this->collectedControllerAttributesEvents() as $eventData) {
                $this->deferredControllerAttributesEvents[] = $eventData;
            }
            $this->clearCollectedControllerAttributesEvents();
            if (\count($this->deferredControllerAttributesEvents) >= self::DEFER_ACL_FLUSH_CHUNK) {
                $this->flushDeferredControllerAttributesChunk();
            }
            return;
        }
        
        $eventsManager = $this->getEvenManager();
        
        // 批量发送：直接发送事件数据数组
        $collectedEvents = &$this->collectedControllerAttributesEvents();
        $eventsManager->dispatch('Weline_Framework_Module::controller_attributes', $collectedEvents);
        
        // 清空收集的事件数据
        $this->clearCollectedControllerAttributesEvents();
    }

    public function getEvenManager(): EventsManager
    {
        return ObjectManager::getInstance(EventsManager::class);
    }

    /**
     * @DESC         |将模块名称转换为路径
     *
     * @param array $modules 模块数组
     * @param string $name 模块名称
     *
     * @return string
     */
    public function moduleNameToPath(array &$modules, string $name): string
    {
        if ($this->isInstalled($modules, $name)) {
            return trim(str_replace('\\', DS, $modules[$name]['path']), DS);
        }

        return str_replace('_', DS, $name);
    }

    /**
     * @DESC         |获取模块路径
     *
     * @param string $name 模块名称
     *
     * @return string
     */
    public function getModulePath(string $name): string
    {
        return APP_CODE_PATH . str_replace('_', DS, $name);
    }

    /**
     * @DESC         |解析控制器
     *
     * @param string $class
     * @param        $module_name
     * @param        $router
     *
     * @return array
     * @throws \ReflectionException
     */
    private function parserController(string $class, $module_name): array
    {
        // 清空父类数组
//        $ctl_area = \Weline\Framework\Controller\Data\DataInterface::type_pc_FRONTEND;
        if (class_exists($class)) {
            $reflect = new \ReflectionClass($class);
            $controller_methods = [];
            foreach ($reflect->getMethods() as $method) {
                if (!is_int(strpos($method->getName(), '__'))) {
                    if ($method->isPublic()) {
                        $attributes = $method->getAttributes();
                        $controller_methods[$method->getName()] = $attributes;
                    }
                }
            }
            // 在父类中查找控制器方法
            if ($parent_class = $reflect->getParentClass()) {
                $controller_class = [];
                foreach (explode('\\', $parent_class->getName()) as $item) {
                    if (is_int(strpos($item, 'Controller'))) {
                        $controller_class[] = $item;
                    }
                }
                $this->parent_class_arr = array_merge($this->parent_class_arr, $controller_class);
                $parent_methods = [];
                foreach ($parent_class->getMethods() as $method) {
                    if (!is_int(strpos($method->getName(), '__'))) {
                        if ($method->isPublic()) {
                            $method_attributes = $method->getAttributes();
                            $parent_methods[$method->getName()] = $method_attributes;
                        }
                    }
                }
                $controller_methods = array_merge($parent_methods, $controller_methods);
                // 如果父类不是抽象类，则添加父类区域
                if (!$parent_class->isAbstract()) {
                    $this->parent_class_arr = array_merge($this->parent_class_arr, $this->parserController($parent_class->getName(), $module_name)['area']);
                }
            }

            return [
                'area' => array_unique($this->parent_class_arr),
                'methods' => $controller_methods,
                'attributes' => $reflect->getAttributes(),
                'class' => $class,
                'module_name' => $module_name,
            ];
        } else {
            return [];
        }
    }

    /**
     * @DESC         |判断模块是否已安装
     *
     * @param array $modules 模块数组
     * @param string $name 模块名称
     *
     * @return bool
     */
    public function isInstalled(array &$modules, string $name): bool
    {
        return array_key_exists($name, $modules);
    }

    /**
     * @DESC         |判断模块是否已禁用
     *
     * @param array $modules 模块数组
     * @param string $name 模块名称
     *
     * @return bool
     */
    public function isDisabled(array &$modules, string $name): bool
    {
        if ($this->isInstalled($modules, $name) && !empty($modules[$name]['status'])) {
            return false;
        }

        return true;
    }

    /**
     * @DESC         |判断模块是否需要升级
     *
     * @param string $version 当前版本
     * @param string $new_version 新版本
     *
     * @return bool
     */
    public function isUpgrade(string $version, string $new_version): bool
    {
        if (version_compare($new_version, $version, '>')) {
            return true;
        }

        return false;
    }

    /**
     * @DESC         |更新模块
     *
     * @param array $modules 模块数组
     */
    public function updateModules(array &$modules)
    {
        $this->compiledPhpArrayWriter->write(Env::path_MODULES_FILE, $modules);
        Env::getInstance()->getModuleList(true);

        // 非 defer 模式下立即刷新注册表，确保新模块的 event/hook/extends 立即生效
        if (!Handle::isDeferRegistryUpdate()) {
            try {
                /** @var RegistryUpdateService $registryService */
                $registryService = ObjectManager::getInstance(RegistryUpdateService::class);
                $registryService->updateAllRegistries(true);
            } catch (\Exception $e) {
                w_log_warning(__('模块列表更新后注册表刷新失败: %{1}', [$e->getMessage()]), [], 'registry_update.log');
            }
        }
    }

    /**
     * PC/API 控制器 area 链是否属于后台（含 BackendPageController 中间基类）。
     *
     * @param list<string>|array<int, string> $ctlArea
     */
    private function controllerAreaIsBackendPc(array $ctlArea): bool
    {
        return \in_array('BackendController', $ctlArea, true)
            || \in_array('BackendPageController', $ctlArea, true);
    }

    /**
     * The browser calls /api/framework/query-bin, while Router::Api() matches
     * generated frontend REST rules after the outer /api segment is removed.
     *
     * @param array<string, mixed> $params
     */
    private function collectFrontendQueryBinRouteAlias(string $moduleName, array $params): void
    {
        $route = (string)($params['router'] ?? '');
        if (!str_starts_with($route, 'api/framework/query-bin')) {
            return;
        }

        $class = (string)($params['class'] ?? '');
        if (
            $class === ''
            || !is_a($class, \Weline\Frontend\Controller\Api\QueryBin::class, true)
        ) {
            return;
        }

        $aliasParams = $params;
        $aliasParams['base_router'] = 'framework';
        $aliasParams['router'] = preg_replace('#^api/#', '', $route) ?: $route;

        if ($collecting) {
                                $this->collectedRouteRegistrations()[] = [
            'type' => RegisterDataInterface::ROUTER,
            'module_name' => $moduleName,
            'params' => $aliasParams,
        ];
                            } else {
                                $this->routeRegistrationBuffer = [
            'type' => RegisterDataInterface::ROUTER,
            'module_name' => $moduleName,
            'params' => $aliasParams,
        ];
                                $this->applyDirectRouteRegistration();
                            }
    }

    /**
     * Same strip-prefix issue as query-bin: EventSource hits /api/framework/stream,
     * but Router::Api() matches after removing the outer /api segment.
     *
     * @param array<string, mixed> $params
     */
    private function collectFrontendStreamRouteAlias(string $moduleName, array $params): void
    {
        $route = (string)($params['router'] ?? '');
        if (!str_starts_with($route, 'api/framework/stream')) {
            return;
        }

        $class = (string)($params['class'] ?? '');
        if (
            $class === ''
            || !is_a($class, \Weline\Framework\Controller\Api\Stream::class, true)
        ) {
            return;
        }

        $aliasParams = $params;
        $aliasParams['base_router'] = 'framework';
        $aliasParams['router'] = preg_replace('#^api/#', '', $route) ?: $route;

        if ($collecting) {
                                $this->collectedRouteRegistrations()[] = [
            'type' => RegisterDataInterface::ROUTER,
            'module_name' => $moduleName,
            'params' => $aliasParams,
        ];
                            } else {
                                $this->routeRegistrationBuffer = [
            'type' => RegisterDataInterface::ROUTER,
            'module_name' => $moduleName,
            'params' => $aliasParams,
        ];
                                $this->applyDirectRouteRegistration();
                            }
    }

    /**
     * Docs hit /api/api-demo/download; Router::Api() matches after removing the outer /api.
     *
     * @param array<string, mixed> $params
     */
    private function collectFrontendApiDemoRouteAlias(string $moduleName, array $params): void
    {
        $route = (string)($params['router'] ?? '');
        if (!str_starts_with($route, 'api/api-demo')) {
            return;
        }

        $class = (string)($params['class'] ?? '');
        if (
            $class === ''
            || !is_a($class, \Weline\Frontend\Controller\FrontendRestController::class, true)
        ) {
            return;
        }

        $aliasParams = $params;
        $aliasParams['base_router'] = 'api-demo';
        $aliasParams['router'] = preg_replace('#^api/#', '', $route) ?: $route;

        if ($collecting) {
                                $this->collectedRouteRegistrations()[] = [
            'type' => RegisterDataInterface::ROUTER,
            'module_name' => $moduleName,
            'params' => $aliasParams,
        ];
                            } else {
                                $this->routeRegistrationBuffer = [
            'type' => RegisterDataInterface::ROUTER,
            'module_name' => $moduleName,
            'params' => $aliasParams,
        ];
                                $this->applyDirectRouteRegistration();
                            }
    }

    /**
     * Browser POSTs /api/frontend/scope-bootstrap; Router::Api() matches after
     * removing the outer /api segment (same strip-prefix pattern as query-bin).
     *
     * @param array<string, mixed> $params
     */
    private function collectFrontendScopeBootstrapRouteAlias(string $moduleName, array $params): void
    {
        $route = (string)($params['router'] ?? '');
        if (!str_starts_with($route, 'api/frontend/scope-bootstrap')) {
            return;
        }

        $class = (string)($params['class'] ?? '');
        if (
            $class === ''
            || !is_a($class, \Weline\Frontend\Controller\Api\ScopeBootstrap::class, true)
        ) {
            return;
        }

        $aliasParams = $params;
        $aliasParams['base_router'] = 'frontend';
        $aliasParams['router'] = preg_replace('#^api/#', '', $route) ?: $route;

        if ($collecting) {
                                $this->collectedRouteRegistrations()[] = [
            'type' => RegisterDataInterface::ROUTER,
            'module_name' => $moduleName,
            'params' => $aliasParams,
        ];
                            } else {
                                $this->routeRegistrationBuffer = [
            'type' => RegisterDataInterface::ROUTER,
            'module_name' => $moduleName,
            'params' => $aliasParams,
        ];
                                $this->applyDirectRouteRegistration();
                            }
    }
}
