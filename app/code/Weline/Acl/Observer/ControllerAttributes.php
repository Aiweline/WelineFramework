<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/1/7 22:14:08
 */

namespace Weline\Acl\Observer;

use Weline\Acl\Model\Acl;
use Weline\Acl\Service\CollectedAclSourceIdsRegistry;
use Weline\Framework\Event\Event;
use Weline\Framework\Log\LoggerFactory;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Php\FiberTaskBatch;

class ControllerAttributes implements \Weline\Framework\Event\ObserverInterface
{
    /** @var array 已加载的控制器类级别权限映射 [moduleName => [className => sourceId]] */
    private array $loaded_controller_acl_names = [];
    
    /** @var array 待处理的方法级别权限队列 [moduleName => [className => [aclData, ...]]] */
    private array $pending_method_acls = [];
    
    /** @var string 当前正在处理的模块名 */
    private string $current_module = '';
    
    /** @var array 待批量保存的类级别权限 [moduleName => [aclData, ...]] */
    private array $pending_class_level_acls = [];
    
    /** @var array 待批量保存的方法级别权限 [moduleName => [aclData, ...]] */
    private array $pending_method_level_acls = [];

    /** @var array<string, bool> checkParentExists 进程内缓存 */
    private array $parentExistsCache = [];

    /** @var array<string, bool>|null menu source_id 集合（懒加载一次） */
    private ?array $menuSourceIdSet = null;

    /** @var array<string, bool> 模块是否已有 menus 记录 */
    private array $moduleHasMenusCache = [];

    private const ACL_UPSERT_CHUNK = 400;

    public const ENV_FIBER_CONCURRENCY = 'WELINE_ACL_FIBER_CONCURRENCY';
    
    /**
     * @var \Weline\Acl\Model\Acl
     */
    private Acl $acl;

    function __construct(
        Acl $acl
    )
    {
        $this->acl = $acl;
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        // 获取事件数据
        // 注意：EventsManager::dispatch 中，如果 $data 是数组，会创建 Event($data)
        // 这意味着整个数组会被设置为 Event 的 _data，而不是 _data['data']
        // 所以需要直接获取整个数据，而不是通过 'data' 键
        $data = $event->getData();
        
        // 过滤掉 'observers' 键，只保留事件数据（DataObject 数组）
        $eventDataArray = [];
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                if ($key !== 'observers' && $value instanceof \Weline\Framework\DataObject\DataObject) {
                    $eventDataArray[] = $value;
                }
            }
        }
        
        if (empty($eventDataArray)) {
            return;
        }

        // 支持跨模块一次派发：按 module 分组后 Fiber 协作收集（setup:upgrade defer 场景）
        $byModule = [];
        foreach ($eventDataArray as $eventData) {
            $module = (string)$eventData->getData('module');
            if ($module === '') {
                continue;
            }
            $byModule[$module][] = $eventData;
        }
        $eventDataArray = [];

        $printing = null;
        $moduleTotal = \count($byModule);
        if (\PHP_SAPI === 'cli' && $moduleTotal > 1) {
            try {
                $printing = ObjectManager::getInstance(\Weline\Framework\Output\Cli\Printing::class);
            } catch (\Throwable) {
                $printing = null;
            }
        }

        $tasks = [];
        foreach ($byModule as $module => $moduleEvents) {
            $tasks[$module] = $moduleEvents;
        }
        unset($byModule);

        $batch = new FiberTaskBatch(null, true, self::ENV_FIBER_CONCURRENCY);
        try {
            $batch->mapModules(
                $tasks,
                function (string $module, mixed $moduleEvents): string {
                    $this->processModuleControllerAttributes(
                        $module,
                        \is_array($moduleEvents) ? $moduleEvents : []
                    );

                    return $module;
                },
                static function (string $phase, array $ctx) use ($printing): void {
                    if ($printing === null || $phase !== 'task') {
                        return;
                    }
                    $printing->note(__(
                        '   - ACL 收集 [%{i}/%{total}]：%{module}…',
                        [
                            'i' => (int)($ctx['done'] ?? 0),
                            'total' => (int)($ctx['total'] ?? 0),
                            'module' => (string)($ctx['key'] ?? ''),
                        ]
                    ));
                    if (\defined('STDOUT') && \is_resource(\STDOUT)) {
                        \fflush(\STDOUT);
                    }
                },
                [
                    'env' => self::ENV_FIBER_CONCURRENCY,
                    'fail_fast' => true,
                    'label' => 'acl-collect',
                ]
            );
            unset($tasks);

            // 全量收集完成后再一次（或分块）批量 upsert，避免每模块多次往返。
            $this->flushAllPendingAclsBatched($printing);
        } finally {
            unset($tasks);
            $this->releaseWorkingMemory();
        }
    }

    /**
     * 卸掉本观察者持有的大块工作集（pending / 菜单索引 / 父级缓存等）。
     */
    private function releaseWorkingMemory(): void
    {
        $this->loaded_controller_acl_names = [];
        $this->pending_method_acls = [];
        $this->pending_class_level_acls = [];
        $this->pending_method_level_acls = [];
        $this->parentExistsCache = [];
        $this->menuSourceIdSet = null;
        $this->moduleHasMenusCache = [];
        $this->current_module = '';
        if (\function_exists('gc_collect_cycles')) {
            \gc_collect_cycles();
        }
    }

    /**
     * @param list<\Weline\Framework\DataObject\DataObject> $eventDataArray
     */
    private function processModuleControllerAttributes(string $module, array $eventDataArray): void
    {
        // 初始化模块状态
        if (!isset($this->loaded_controller_acl_names[$module])) {
            $this->loaded_controller_acl_names[$module] = [];
        }
        if (!isset($this->pending_method_acls[$module])) {
            $this->pending_method_acls[$module] = [];
        }
        if (!isset($this->pending_class_level_acls[$module])) {
            $this->pending_class_level_acls[$module] = [];
        }
        if (!isset($this->pending_method_level_acls[$module])) {
            $this->pending_method_level_acls[$module] = [];
        }
        
        $this->current_module = $module;
        
        // 第一阶段：先收集所有类级别权限（确保父级权限先存在）
        $processedClasses = [];
        foreach ($eventDataArray as $eventData) {
            $className = $eventData->getData('class');
            if (empty($className) || isset($processedClasses[$className])) {
                continue;
            }
            
            $controller_attributes = $eventData->getData('controller_data/attributes');
            if (!empty($controller_attributes)) {
                $type = $eventData->getData('type');
                $this->collectClassLevelAcl($className, $controller_attributes, $eventData, $type);
                $processedClasses[$className] = true;
            }
        }
        
        // 第二阶段：收集所有方法级别权限（此时类级别权限已在内存中）
        foreach ($eventDataArray as $eventData) {
            $attribute = $eventData->getData('attribute');
            if (!$attribute || $attribute->getName() !== \Weline\Framework\Acl\Acl::class) {
                continue;
            }
            
            $className = $eventData->getData('class');
            $type = $eventData->getData('type');
            $this->collectMethodLevelAcl($className, $attribute, $eventData, $type);
        }
        // 收集完成，释放事件大数组引用以降低内存峰值（后续仅用 pending_* 与 loaded_*）
        $eventDataArray = [];

        // 仍挂在 pending_method_acls 的方法级权限：父级已齐则落 pending_method_level_acls，
        // 避免 processModule 结束 unset 时静默丢弃（Theme scope_* 曾因此升级后消失）。
        if (!empty($this->pending_method_acls[$module])) {
            foreach (\array_keys($this->pending_method_acls[$module]) as $pendingClass) {
                $this->processPendingMethodAcls($module, (string)$pendingClass);
            }
        }
        // 不在此处按模块落库：execute() 末尾 flushAllPendingAclsBatched 一次批量 upsert。
        // 释放仅用于收集期的内存索引。
        unset($this->loaded_controller_acl_names[$module], $this->pending_method_acls[$module]);
    } 

    /**
     * 收集类级别权限
     * SOLID原则：单一职责 - 专门负责类级别权限的收集和保存
     * 
     * @param string $className 类名
     * @param array $controller_attributes 控制器属性数组
     * @param \Weline\Framework\DataObject\DataObject $data 事件数据
     * @param string $type 权限类型
     * @return void
     */
    private function collectClassLevelAcl(string $className, array $controller_attributes, $data, string $type): void
    {
        $module = $data->getData('module');
        
        foreach ($controller_attributes as $controller_attribute) {
            // Acl属性
            if ($controller_attribute->getName() === \Weline\Framework\Acl\Acl::class) {
                /**@var \Weline\Framework\Acl\Acl $acl */
                $acl = ObjectManager::make($controller_attribute->getName(), $controller_attribute->getArguments());
                $route = explode('::', $data->getData('router'));
                if (count($route) > 1) {
                    array_pop($route);
                }
                $route = implode('', $route);
                $acl->setModule($data->getData('module'))
                    ->setRoute($route)
                    ->setRouter($data->getData('base_router'))
                    ->setClass($data->getData('class'))
                    ->setMethod('')  // 类级别权限的 method 字段应该为空
                    ->setIsEnable($data->getData('is_enable') ?: true)
                    ->setIsBackend($data->getData('is_backend') ?: false)
                    ->setType($type);
                $this->applyAccessMetadataDefaults($acl);
                
                // 控制器 #[Acl] 仅负责 pc 接口权限，type 固定为 pc
                // type='menus' 仅由 MenuCollector（menu.xml）写入；侧栏菜单必须以 menu.xml 为准
                // 不再保留既有 type='menus'，避免已从 menu.xml 移除的 controller ACL 仍显示在侧栏
                // parent_source 空值保留交由批量 upsert 前一次性 SELECT 回填，禁止此处逐条查库。
                $this->assertClassAclAttachedToMenu($acl);
                
                // 收集到批量保存数组，不立即保存
                if (!isset($this->pending_class_level_acls[$module])) {
                    $this->pending_class_level_acls[$module] = [];
                }
                $aclData = $this->normalizeAclDataForPersistence($acl->getData());
                // 补全表中有默认值的字段，避免插入时缺列
                if (!isset($aclData[\Weline\Acl\Model\Acl::schema_fields_ORDER])) {
                    $aclData[\Weline\Acl\Model\Acl::schema_fields_ORDER] = 0;
                }
                $this->pending_class_level_acls[$module][] = $aclData;
                
                // 记录类级别权限ID，供方法级别权限使用（按模块索引）
                $this->loaded_controller_acl_names[$module][$className] = $acl->getSourceId();
                
                // 处理该类待处理的方法级别权限（类级别权限收集完成后立即处理）
                $this->processPendingMethodAcls($module, $className);
            }
        }
    }

    /**
     * 收集方法级别权限
     * SOLID原则：单一职责 - 专门负责方法级别权限的收集和保存
     * 
     * @param string $className 类名
     * @param \ReflectionAttribute $attribute 方法属性
     * @param \Weline\Framework\DataObject\DataObject $data 事件数据
     * @param string $type 权限类型
     * @return void
     */
    private function collectMethodLevelAcl(string $className, $attribute, $data, string $type): void
    {
        /**@var \Weline\Framework\Acl\Acl $acl */
        $acl = ObjectManager::make($attribute->getName(), $attribute->getArguments());
        $module = $data->getData('module');
        $sourceId = $acl->getSourceId();
        
        // 如果类级别权限已存在，先处理待处理的方法权限队列
        // 这确保在收集新方法权限前，之前待处理的权限已被处理
        if (isset($this->loaded_controller_acl_names[$module][$className])) {
            $this->processPendingMethodAcls($module, $className);
        }
        
        // 确保类级别权限已经收集（如果还没有，先收集）
        if (!isset($this->loaded_controller_acl_names[$module][$className])) {
            // 从数据库查找类级别权限（传入模块名以提高查找精度）
            $moduleName = $data->getData('module') ?: '';
            $classLevelParent = $this->findClassLevelParent($className, $acl->getSourceId(), $moduleName);
            
            if (empty($classLevelParent)) {
                // 如果找不到类级别权限，将方法权限加入待处理队列
                // 等待类级别权限收集后再处理
                if (!isset($this->pending_method_acls[$module][$className])) {
                    $this->pending_method_acls[$module][$className] = [];
                }
                $this->pending_method_acls[$module][$className][] = [
                    'acl' => $acl,
                    'data' => $data,
                    'type' => $type
                ];
                
                return;
            } else {
                // 找到了类级别权限，记录到内存中（按模块索引）
                $this->loaded_controller_acl_names[$module][$className] = $classLevelParent;
                // 处理该类待处理的方法级别权限（刚找到类级别权限时处理一次）
                $this->processPendingMethodAcls($module, $className);
            }
        }
        
        // 设置父级权限（传入 data 以便获取模块名）
        $this->setParentSource($acl, $className, $data);
        // 验证父级权限
        $this->validateParentSource($acl);
        
        // 设置路由信息
        $this->setRouteInfo($acl, $data, $type);
        // 方法级 #[Acl] 默认未带 is_backend/is_enable；必须从路由事件回填，
        // 否则 ResourceAuthorizationService 会因 is_backend=0 拒绝超管旁路。
        $acl->setIsEnable((bool)($data->getData('is_enable') ?? true))
            ->setIsBackend((bool)($data->getData('is_backend') ?? false));
        
        // 收集到批量保存数组，不立即保存
        if (!isset($this->pending_method_level_acls[$module])) {
            $this->pending_method_level_acls[$module] = [];
        }
        $this->pending_method_level_acls[$module][] = $this->normalizeAclDataForPersistence($acl->getData());
    }

    /**
     * 设置父级权限
     * SOLID原则：单一职责 - 专门负责父级权限的设置逻辑
     * 
     * @param \Weline\Framework\Acl\Acl $acl 权限对象
     * @param string $className 类名
     * @param \Weline\Framework\DataObject\DataObject|null $data 事件数据（可选，用于获取模块名）
     * @return void
     */
    private function setParentSource($acl, string $className, $data = null): void
    {
        $module = $data ? ($data->getData('module') ?: '') : '';
        $specifiedParent = $acl->getParentSource();
        
        // 如果属性中指定了父级权限，验证它是否存在（可能在数据库或批量保存数组中）
        if (!empty($specifiedParent)) {
            // 检查是否在内存中（已加载的类级别权限）
            $parent_acl_source = $this->loaded_controller_acl_names[$module][$className] ?? '';
            if ($parent_acl_source === $specifiedParent) {
                // 父级权限已经在内存中，确认使用
                return;
            }
            
            // 检查是否在批量保存数组中（待保存的类级别权限）
            if (isset($this->pending_class_level_acls[$module])) {
                foreach ($this->pending_class_level_acls[$module] as $pendingClassAcl) {
                    if (isset($pendingClassAcl['source_id']) && $pendingClassAcl['source_id'] === $specifiedParent) {
                        // 父级权限在批量保存数组中，确认使用
                        // 同时记录到内存中，供后续使用
                        if (!isset($this->loaded_controller_acl_names[$module][$className])) {
                            $this->loaded_controller_acl_names[$module][$className] = $specifiedParent;
                        }
                        return;
                    }
                }
            }

            // 同批收集中已出现的 source_id 视为可用，避免逐条 checkParentExists 打库。
            if ($this->isSourceKnownInBatch($specifiedParent)) {
                if (!isset($this->loaded_controller_acl_names[$module][$className])) {
                    $this->loaded_controller_acl_names[$module][$className] = $specifiedParent;
                }
                return;
            }
            
            // 检查是否在数据库中（带进程内缓存）
            if ($this->checkParentExists($specifiedParent)) {
                // 父级权限在数据库中，确认使用
                // 同时记录到内存中，供后续使用
                if (!isset($this->loaded_controller_acl_names[$module][$className])) {
                    $this->loaded_controller_acl_names[$module][$className] = $specifiedParent;
                }
                return;
            }
            
            // 如果指定的父级权限不存在，继续使用其他逻辑查找父级权限
            // 但保留属性中指定的父级权限，可能在后续批量保存时父级权限会被保存
        }
        
        // 优先使用控制器级别的acl资源作为子方法的父级资源。
        // 防御：DB 中 method='' 的方法级脏数据会被 findClassLevelParent 误当成类级，
        // 导致 loaded 映射等于当前 source_id，进而 parent===source。
        $parent_acl_source = $this->loaded_controller_acl_names[$module][$className] ?? '';
        if (!empty($parent_acl_source) && $parent_acl_source !== $acl->getSourceId()) {
            $acl->setParentSource($parent_acl_source);
            return;
        }
        if ($parent_acl_source === $acl->getSourceId()) {
            unset($this->loaded_controller_acl_names[$module][$className]);
        }
        
        // 如果控制器没有类级别的权限，尝试通过权限ID模式推断父级权限
        $inferred_parent = $this->inferParentSource($acl->getSourceId());
        if (!empty($inferred_parent)) {
            if ($this->isSourceKnownInBatch($inferred_parent) || $this->checkParentExists($inferred_parent)) {
                $acl->setParentSource($inferred_parent);
                return;
            }
        }
        
        // 从数据库查找类级别的权限（传入模块名以提高查找精度）
        $moduleName = $data ? ($data->getData('module') ?: '') : '';
        $class_level_parent = $this->findClassLevelParent($className, $acl->getSourceId(), $moduleName);
        if (!empty($class_level_parent)) {
            $acl->setParentSource($class_level_parent);
            // 如果找到了，也记录到内存中，避免重复查询（按模块索引）
            $module = $data ? ($data->getData('module') ?: '') : '';
            if (!empty($module) && !isset($this->loaded_controller_acl_names[$module][$className])) {
                $this->loaded_controller_acl_names[$module][$className] = $class_level_parent;
            }
        }
    }

    /**
     * 验证父级权限
     * SOLID原则：单一职责 - 专门负责父级权限的验证
     * 
     * @param \Weline\Framework\Acl\Acl $acl 权限对象
     * @return void
     * @throws \Exception
     */
    private function validateParentSource($acl): void
    {
        if (!empty($acl->getParentSource()) && $acl->getSourceId() === $acl->getParentSource()) {
            throw new \Exception(__('资源ID和父级资源ID不能相同，请检查! 资源ID: %{1}, 父级资源ID: %{2}', [$acl->getSourceId(), $acl->getParentSource()]));
        }
    }

    /**
     * 框架约定：类级 ACL 必须依附在菜单 ACL 上（source 命中菜单或 parent_source 指向菜单）。
     * 断层直接抛异常，防止无菜单承接的权限节点进入系统。
     *
     * @throws \Exception
     */
    private function assertClassAclAttachedToMenu($acl): void
    {
        $sourceId = (string)$acl->getSourceId();
        $parentSource = (string)$acl->getParentSource();
        $module = (string)$acl->getModule();

        // 首次安装 / 菜单尚未同步：模块尚无 menus 记录时跳过严格校验。
        if ($module !== '' && !$this->moduleHasMenuRecords($module)) {
            return;
        }

        $menuIds = $this->menuSourceIdSet();
        if (isset($menuIds[$sourceId])) {
            return;
        }
        if ($parentSource !== '' && isset($menuIds[$parentSource])) {
            return;
        }

        throw new \Exception(__('框架约定错误：类级 ACL %{1} 未依附菜单 ACL。请确保 source_id 对应 menu.xml 节点，或 parent_source 指向 type=menus 的菜单节点。当前 parent_source=%{2}', [
            $sourceId,
            $parentSource ?: __('(空)'),
        ]));
    }

    /**
     * @return array<string, bool>
     */
    private function menuSourceIdSet(): array
    {
        if ($this->menuSourceIdSet !== null) {
            return $this->menuSourceIdSet;
        }

        $this->menuSourceIdSet = [];
        try {
            $rows = $this->acl->reset()
                ->where(Acl::schema_fields_TYPE, Acl::type_MENUS)
                ->fields(Acl::schema_fields_SOURCE_ID)
                ->select()
                ->fetchArray();
            foreach ($rows ?: [] as $row) {
                $id = (string)($row[Acl::schema_fields_SOURCE_ID] ?? '');
                if ($id !== '') {
                    $this->menuSourceIdSet[$id] = true;
                }
            }
        } catch (\Throwable) {
            $this->menuSourceIdSet = [];
        }

        return $this->menuSourceIdSet;
    }

    private function moduleHasMenuRecords(string $module): bool
    {
        if (\array_key_exists($module, $this->moduleHasMenusCache)) {
            return $this->moduleHasMenusCache[$module];
        }

        try {
            $existingMenus = $this->acl->reset()
                ->where(Acl::schema_fields_MODULE, $module)
                ->where(Acl::schema_fields_TYPE, Acl::type_MENUS)
                ->fields(Acl::schema_fields_SOURCE_ID)
                ->limit(1)
                ->select()
                ->fetchArray();
            $this->moduleHasMenusCache[$module] = !empty($existingMenus);
        } catch (\Throwable) {
            $this->moduleHasMenusCache[$module] = false;
        }

        return $this->moduleHasMenusCache[$module];
    }

    /**
     * 设置路由信息
     * SOLID原则：单一职责 - 专门负责路由信息的设置
     * 
     * @param \Weline\Framework\Acl\Acl $acl 权限对象
     * @param \Weline\Framework\DataObject\DataObject $data 事件数据
     * @param string $type 权限类型
     * @return void
     */
    private function setRouteInfo($acl, $data, string $type): void
    {
        $route = explode('::', $data->getData('router'));
        if (count($route) > 1) {
            array_pop($route);
        }
        $route = implode('', $route);
        $requestMethod = $data->getData('request_method');
        $methodName = $data->getData('method');
        
        // 如果 request_method 为空，保持为空，允许所有 HTTP 方法访问
        // 这样对于没有 HTTP 方法前缀的方法（如 save()），可以接受任何 HTTP 请求
        // 注意：空值不会导致被误判为类级别权限，因为类级别权限的 method 字段也为空，但会通过其他字段（如 route）区分
        
        $acl->setModule($data->getData('module'))
            ->setRoute($route)
            ->setRouter($data->getData('base_router'))
            ->setClass($data->getData('class'))
            ->setMethod($requestMethod)
            ->setType($type);
        $this->applyAccessMetadataDefaults($acl);
    }

    private function applyAccessMetadataDefaults($acl): void
    {
        $acl->setAccessMode(Acl::normalizeAccessMode($acl->getAccessMode(), $acl->getMethod()));
        $acl->setScopeGroup(trim((string)$acl->getScopeGroup()));
        $acl->setApiExposable($acl->getApiExposable());
    }

    private function normalizeAclDataForPersistence(array $aclData): array
    {
        if (array_key_exists(Acl::schema_fields_ACL_ID, $aclData)
            && ($aclData[Acl::schema_fields_ACL_ID] === '' || $aclData[Acl::schema_fields_ACL_ID] === null)
        ) {
            unset($aclData[Acl::schema_fields_ACL_ID]);
        }

        $aclData[Acl::schema_fields_ORDER] = $this->normalizeIntegerValue(
            $aclData[Acl::schema_fields_ORDER] ?? 0,
            0
        );

        $flagDefaults = [
            Acl::schema_fields_IS_ENABLE => 1,
            Acl::schema_fields_IS_BACKEND => 0,
            Acl::schema_fields_API_EXPOSABLE => 0,
        ];
        foreach ($flagDefaults as $field => $default) {
            if (array_key_exists($field, $aclData)) {
                $aclData[$field] = $this->normalizeIntegerValue($aclData[$field], $default);
            }
        }

        // pgsql 等迁移后的表常丢失列 DEFAULT；批量 insert 若显式写入 null 会触发 NOT NULL
        // Controller #[Acl] collection: default origin is controller_attribute (not menu_xml).
        if (!isset($aclData[Acl::schema_fields_ACL_ORIGIN]) || $aclData[Acl::schema_fields_ACL_ORIGIN] === null || $aclData[Acl::schema_fields_ACL_ORIGIN] === '') {
            $aclData[Acl::schema_fields_ACL_ORIGIN] = Acl::acl_origin_controller_attribute;
        }
        if (!isset($aclData[Acl::schema_fields_ACCESS_MODE]) || $aclData[Acl::schema_fields_ACCESS_MODE] === null || $aclData[Acl::schema_fields_ACCESS_MODE] === '') {
            $aclData[Acl::schema_fields_ACCESS_MODE] = Acl::ACCESS_MODE_EDIT;
        }
        if (!isset($aclData[Acl::schema_fields_SCOPE_GROUP]) || $aclData[Acl::schema_fields_SCOPE_GROUP] === null) {
            $aclData[Acl::schema_fields_SCOPE_GROUP] = '';
        }
        if (!array_key_exists(Acl::schema_fields_API_EXPOSABLE, $aclData) || $aclData[Acl::schema_fields_API_EXPOSABLE] === null || $aclData[Acl::schema_fields_API_EXPOSABLE] === '') {
            $aclData[Acl::schema_fields_API_EXPOSABLE] = 0;
        }
        $now = date('Y-m-d H:i:s');
        if (empty($aclData['create_time'])) {
            $aclData['create_time'] = $now;
        }
        if (empty($aclData['update_time'])) {
            $aclData['update_time'] = $now;
        }

        return $aclData;
    }

    private function normalizeIntegerValue(mixed $value, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_numeric($value)) {
            return (int)$value;
        }

        $booleanValue = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($booleanValue !== null) {
            return $booleanValue ? 1 : 0;
        }

        return $default;
    }

    /**
     * 处理待处理的方法级别权限
     * 当类级别权限收集完成后，处理之前待处理的方法级别权限
     * 
     * @param string $module 模块名
     * @param string $className 类名
     * @return void
     */
    private function processPendingMethodAcls(string $module, string $className): void
    {
        if (!isset($this->pending_method_acls[$module][$className]) || empty($this->pending_method_acls[$module][$className])) {
            return;
        }
        
        foreach ($this->pending_method_acls[$module][$className] as $pending) {
            $acl = $pending['acl'];
            $data = $pending['data'];
            $type = $pending['type'];
            
            // 设置父级权限（传入 data 以便获取模块名）
            $this->setParentSource($acl, $className, $data);
            
            // 验证父级权限
            $this->validateParentSource($acl);
            
            // 设置路由信息
            $this->setRouteInfo($acl, $data, $type);
            $acl->setIsEnable((bool)($data->getData('is_enable') ?? true))
                ->setIsBackend((bool)($data->getData('is_backend') ?? false));
            
            // 收集到批量保存数组，不立即保存
            if (!isset($this->pending_method_level_acls[$module])) {
                $this->pending_method_level_acls[$module] = [];
            }
            $methodAclData = $this->normalizeAclDataForPersistence($acl->getData());
            if (!isset($methodAclData[\Weline\Acl\Model\Acl::schema_fields_ORDER])) {
                $methodAclData[\Weline\Acl\Model\Acl::schema_fields_ORDER] = 0;
            }
            $this->pending_method_level_acls[$module][] = $methodAclData;
        }
        
        // 清空待处理队列
        unset($this->pending_method_acls[$module][$className]);
    }

    /**
     * 全量收集后一次性批量 upsert（类级先于方法级），减少按模块 DB 往返。
     */
    private function flushAllPendingAclsBatched(?\Weline\Framework\Output\Cli\Printing $printing = null): void
    {
        $classRows = $this->flattenPendingAcls($this->pending_class_level_acls);
        $methodRows = $this->flattenPendingAcls($this->pending_method_level_acls);
        $this->pending_class_level_acls = [];
        $this->pending_method_level_acls = [];

        $classRows = $this->deduplicateAclsBySourceId($classRows);
        $methodRows = $this->deduplicateAclsBySourceId($methodRows);
        if ($classRows === [] && $methodRows === []) {
            return;
        }

        $allIds = [];
        foreach ([$classRows, $methodRows] as $group) {
            foreach ($group as $row) {
                $id = (string)($row['source_id'] ?? '');
                if ($id !== '') {
                    $allIds[$id] = true;
                }
            }
        }
        $meta = $this->loadExistingAclMeta(\array_keys($allIds));
        unset($allIds);

        $classPrepared = $this->prepareAclRowsForUpsert($classRows, $meta);
        unset($classRows);
        $methodPrepared = $this->prepareAclRowsForUpsert($methodRows, $meta);
        unset($methodRows, $meta);

        $total = \count($classPrepared) + \count($methodPrepared);
        if ($printing !== null) {
            $printing->note(__(
                '   - ACL 批量 upsert：类级 %{c} + 方法级 %{m} = %{t} 条（每批 %{chunk}）…',
                [
                    'c' => \count($classPrepared),
                    'm' => \count($methodPrepared),
                    't' => $total,
                    'chunk' => self::ACL_UPSERT_CHUNK,
                ]
            ));
            if (\defined('STDOUT') && \is_resource(\STDOUT)) {
                \fflush(\STDOUT);
            }
        }

        $this->acl->reset()->clearData();
        $this->acl->beginTransaction();
        try {
            $this->upsertAclRowsChunked($classPrepared, $printing, 'class');
            $this->upsertAclRowsChunked($methodPrepared, $printing, 'method');
            $this->acl->commit();
            $ids = \array_merge(
                \array_column($classPrepared, 'source_id'),
                \array_column($methodPrepared, 'source_id'),
            );
            unset($classPrepared, $methodPrepared);
            if ($ids !== []) {
                CollectedAclSourceIdsRegistry::addMany($ids);
            }
            unset($ids);
        } catch (\Exception $exception) {
            unset($classPrepared, $methodPrepared);
            $this->acl->rollBack();
            if (DEV) {
                p($exception->getMessage());
            }
            throw $exception;
        }

        if ($printing !== null) {
            $printing->success(__('   - ACL 批量 upsert 完成：%{t} 条', ['t' => $total]));
            if (\defined('STDOUT') && \is_resource(\STDOUT)) {
                \fflush(\STDOUT);
            }
        }
    }

    /**
     * @param array<string, list<array<string, mixed>>> $pendingByModule
     * @return list<array<string, mixed>>
     */
    private function flattenPendingAcls(array $pendingByModule): array
    {
        $rows = [];
        foreach ($pendingByModule as $moduleRows) {
            foreach ($moduleRows as $row) {
                if (\is_array($row)) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * O(n) 按 source_id 去重，后者覆盖前者。
     *
     * @param list<array<string, mixed>> $acls
     * @return list<array<string, mixed>>
     */
    private function deduplicateAclsBySourceId(array $acls): array
    {
        $byId = [];
        $noId = [];
        foreach ($acls as $acl) {
            $sourceId = (string)($acl['source_id'] ?? '');
            if ($sourceId === '') {
                $noId[] = $acl;
                continue;
            }
            $byId[$sourceId] = $acl;
        }

        return \array_values(\array_merge(\array_values($byId), $noId));
    }

    /**
     * @param list<string> $sourceIds
     * @return array<string, array{parent_source: string, is_menu_xml: bool}>
     */
    private function loadExistingAclMeta(array $sourceIds): array
    {
        $meta = [];
        $sourceIds = \array_values(\array_unique(\array_filter(\array_map('strval', $sourceIds))));
        if ($sourceIds === []) {
            return $meta;
        }

        foreach (\array_chunk($sourceIds, self::ACL_UPSERT_CHUNK) as $chunk) {
            $rows = $this->acl->reset()
                ->where(Acl::schema_fields_SOURCE_ID, $chunk, 'in')
                ->select(
                    Acl::schema_fields_SOURCE_ID . ','
                    . Acl::schema_fields_PARENT_SOURCE . ','
                    . Acl::schema_fields_TYPE . ','
                    . Acl::schema_fields_ACL_ORIGIN
                )
                ->fetchArray();
            foreach ($rows ?: [] as $row) {
                $id = (string)($row[Acl::schema_fields_SOURCE_ID] ?? '');
                if ($id === '') {
                    continue;
                }
                $meta[$id] = [
                    'parent_source' => (string)($row[Acl::schema_fields_PARENT_SOURCE] ?? ''),
                    'is_menu_xml' => ((string)($row[Acl::schema_fields_TYPE] ?? '') === Acl::type_MENUS)
                        && ((string)($row[Acl::schema_fields_ACL_ORIGIN] ?? '') === Acl::acl_origin_menu_xml),
                ];
            }
        }

        return $meta;
    }

    /**
     * @param list<array<string, mixed>> $acls
     * @param array<string, array{parent_source: string, is_menu_xml: bool}> $meta
     * @return list<array<string, mixed>>
     */
    private function prepareAclRowsForUpsert(array $acls, array $meta): array
    {
        $prepared = [];
        foreach ($acls as $acl) {
            $sourceId = (string)($acl['source_id'] ?? '');
            if ($sourceId === '') {
                continue;
            }
            if (!empty($meta[$sourceId]['is_menu_xml'])) {
                continue;
            }
            $newParent = (string)($acl['parent_source'] ?? '');
            if ($newParent === '' && !empty($meta[$sourceId]['parent_source'])) {
                $acl['parent_source'] = $meta[$sourceId]['parent_source'];
            }
            $prepared[] = $acl;
        }

        return $prepared;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function upsertAclRowsChunked(array $rows, ?\Weline\Framework\Output\Cli\Printing $printing, string $label): void
    {
        if ($rows === []) {
            return;
        }
        $chunks = \array_chunk($rows, self::ACL_UPSERT_CHUNK);
        $totalChunks = \count($chunks);
        foreach ($chunks as $i => $chunk) {
            if ($printing !== null && $totalChunks > 1) {
                $printing->note(__(
                    '   - ACL upsert %{label} [%{i}/%{total}]：%{n} 条…',
                    ['label' => $label, 'i' => $i + 1, 'total' => $totalChunks, 'n' => \count($chunk)]
                ));
                if (\defined('STDOUT') && \is_resource(\STDOUT)) {
                    \fflush(\STDOUT);
                }
            }
            $this->acl->reset()->clearData();
            $this->acl->getQuery()->insert($chunk, 'source_id', '')->fetch();
        }
    }

    private function batchSaveClassLevelAcls(string $module): void
    {
        $rows = $this->pending_class_level_acls[$module] ?? [];
        unset($this->pending_class_level_acls[$module]);
        if ($rows === []) {
            return;
        }
        $rows = $this->deduplicateAclsBySourceId($rows);
        $meta = $this->loadExistingAclMeta(\array_column($rows, 'source_id'));
        $prepared = $this->prepareAclRowsForUpsert($rows, $meta);
        unset($rows, $meta);
        if ($prepared === []) {
            return;
        }
        $this->acl->reset()->clearData();
        $this->acl->beginTransaction();
        try {
            $this->upsertAclRowsChunked($prepared, null, 'class');
            $this->acl->commit();
            CollectedAclSourceIdsRegistry::addMany(\array_column($prepared, 'source_id'));
            unset($prepared);
        } catch (\Exception $exception) {
            unset($prepared);
            $this->acl->rollBack();
            throw $exception;
        }
    }

    private function batchSaveMethodLevelAcls(string $module): void
    {
        $rows = $this->pending_method_level_acls[$module] ?? [];
        unset($this->pending_method_level_acls[$module]);
        if ($rows === []) {
            return;
        }
        $rows = $this->deduplicateAclsBySourceId($rows);
        $meta = $this->loadExistingAclMeta(\array_column($rows, 'source_id'));
        $prepared = $this->prepareAclRowsForUpsert($rows, $meta);
        unset($rows, $meta);
        if ($prepared === []) {
            return;
        }
        $this->acl->reset()->clearData();
        $this->acl->beginTransaction();
        try {
            $this->upsertAclRowsChunked($prepared, null, 'method');
            $this->acl->commit();
            CollectedAclSourceIdsRegistry::addMany(\array_column($prepared, 'source_id'));
            unset($prepared);
        } catch (\Exception $exception) {
            unset($prepared);
            $this->acl->rollBack();
            throw $exception;
        }
    }

    private function isSourceKnownInBatch(string $sourceId): bool
    {
        if ($sourceId === '') {
            return false;
        }
        foreach ($this->pending_class_level_acls as $rows) {
            foreach ($rows as $row) {
                if ((string)($row['source_id'] ?? '') === $sourceId) {
                    return true;
                }
            }
        }
        foreach ($this->loaded_controller_acl_names as $map) {
            if (\in_array($sourceId, $map, true)) {
                return true;
            }
        }

        return isset($this->parentExistsCache[$sourceId]) && $this->parentExistsCache[$sourceId];
    }

    /**
     * 批量保存当前模块的所有权限
     * 用于在权限收集完成后，保存最后一个模块的权限
     * 
     * @return void
     * @throws \Exception
     */
    public function flushPendingAcls(): void
    {
        if (!empty($this->current_module)) {
            $this->batchSaveClassLevelAcls($this->current_module);
            $this->batchSaveMethodLevelAcls($this->current_module);
        }
    }

    /**
     * 通过权限ID模式推断父级权限
     * 
     * @param string $sourceId 权限ID
     * @return string 推断的父级权限ID，如果无法推断则返回空字符串
     */
    private function inferParentSource(string $sourceId): string
    {
        // 权限ID格式：Module::permission_name 或 Module::parent_permission_child
        if (strpos($sourceId, '::') === false) {
            return '';
        }
        
        list($module, $permission) = explode('::', $sourceId, 2);
        
        // 如果权限名包含下划线，尝试推断父级
        if (strpos($permission, '_') !== false) {
            $parts = explode('_', $permission);
            // 至少 3 段才推断，避免 ai_market → Module::ai。
            // 剥最后一段：ai_site_agent_index → ai_site_agent（旧启发式只取前两段会错挂到 ::ai_site）
            if (count($parts) >= 3) {
                array_pop($parts);
                $inferredParent = $module . '::' . implode('_', $parts);
                if ($inferredParent !== $sourceId) {
                    return $inferredParent;
                }
            }
        }
        
        return '';
    }

    /**
     * 检查父级权限是否在数据库中存在
     * 
     * @param string $parentSourceId 父级权限ID
     * @return bool 如果存在返回true，否则返回false
     */
    private function checkParentExists(string $parentSourceId): bool
    {
        if ($parentSourceId === '') {
            return false;
        }
        if (\array_key_exists($parentSourceId, $this->parentExistsCache)) {
            return $this->parentExistsCache[$parentSourceId];
        }
        if ($this->isSourceKnownInBatch($parentSourceId)) {
            $this->parentExistsCache[$parentSourceId] = true;

            return true;
        }

        try {
            $parentAcl = clone $this->acl;
            $parentAcl->reset();
            $result = $parentAcl->where(Acl::schema_fields_SOURCE_ID, $parentSourceId)
                ->find()
                ->fetch();
            $exists = (bool)($result && $result->getId());
            $this->parentExistsCache[$parentSourceId] = $exists;

            return $exists;
        } catch (\Exception $e) {
            $this->parentExistsCache[$parentSourceId] = false;

            return false;
        }
    }

    /**
     * 查找类级别的父级权限
     * 通过类名和模块名查找对应的类级别权限（通常是类上定义的第一个Acl属性）
     * 
     * @param string $className 类名
     * @param string $currentSourceId 当前权限ID（用于排除自身）
     * @param string $moduleName 模块名（可选，用于精确查找）
     * @return string 类级别的父级权限ID，如果找不到则返回空字符串
     */
    private function findClassLevelParent(string $className, string $currentSourceId, string $moduleName = ''): string
    {
        try {
            // 首先从内存中查找（最快，按模块索引）；同批 upsert 前不必再验库。
            if (!empty($moduleName) && isset($this->loaded_controller_acl_names[$moduleName][$className])) {
                $parentSourceId = $this->loaded_controller_acl_names[$moduleName][$className];
                if ($parentSourceId && $parentSourceId !== $currentSourceId) {
                    return $parentSourceId;
                }
            }
            
            // 通过类名查找对应的类级别权限
            // 类级别权限的特征：class字段等于类名，method字段为空，且不是当前权限
            $parentAcl = clone $this->acl;
            $parentAcl->reset();
            
            // 构建查询条件
            $query = $parentAcl->where(Acl::schema_fields_CLASS, $className)
                ->where(Acl::schema_fields_SOURCE_ID, $currentSourceId, '!=');
            
            // 如果提供了模块名，同时按模块名查找（更精确）
            if (!empty($moduleName)) {
                $query->where(Acl::schema_fields_MODULE, $moduleName);
            }
            
            $results = $query->select()->fetch();
            
            if ($results && $results->getItems()) {
                // 优先查找 method 字段为空的权限（类级别权限通常 method 为空）
                foreach ($results->getItems() as $item) {
                    $method = $item->getData(Acl::schema_fields_METHOD);
                    if (empty($method)) {
                        $parentSourceId = $item->getData(Acl::schema_fields_SOURCE_ID);
                        if ($parentSourceId && $parentSourceId !== $currentSourceId) {
                            $this->parentExistsCache[(string)$parentSourceId] = true;

                            return $parentSourceId;
                        }
                    }
                }
                
                // 如果没找到 method 为空的，尝试通过权限ID模式匹配
                foreach ($results->getItems() as $item) {
                    $parentSourceId = $item->getData(Acl::schema_fields_SOURCE_ID);
                    if ($parentSourceId && $parentSourceId !== $currentSourceId) {
                        // 检查权限ID是否可能是父级（通过模式匹配）
                        $currentParts = explode('::', $currentSourceId);
                        $parentParts = explode('::', $parentSourceId);
                        if (count($currentParts) === 2 && count($parentParts) === 2) {
                            $currentPerm = $currentParts[1];
                            $parentPerm = $parentParts[1];
                            // 如果父级权限名是当前权限名的前缀，则认为是父级
                            if (strpos($currentPerm, $parentPerm . '_') === 0) {
                                $this->parentExistsCache[(string)$parentSourceId] = true;

                                return $parentSourceId;
                            }
                        }
                    }
                }
            }
            
            return '';
        } catch (\Throwable $e) {
            // 如果查询出错（含 mock/无查询链），返回空字符串
            return '';
        }
    }
}
