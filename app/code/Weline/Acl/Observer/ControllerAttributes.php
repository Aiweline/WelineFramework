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
use Weline\Framework\Manager\ObjectManager;
use function PHPUnit\Framework\throwException;

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
        
        // 事件收到的就是某个模块下的全部路由信息，一次性处理落库
        if (empty($eventDataArray)) {
            return;
        }
        
        // 获取模块名（所有事件数据应该属于同一个模块）
        $module = $eventDataArray[0]->getData('module');
        if (empty($module)) {
            return;
        }
        
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
        
        // 第三阶段：一次性批量保存（注意顺序：先类级别，后方法级别，确保父子关系正确）
        $this->batchSaveClassLevelAcls($module);
        $this->batchSaveMethodLevelAcls($module);
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
                
                // 控制器 #[Acl] 仅负责 pc 接口权限，type 固定为 pc
                // type='menus' 仅由 MenuCollector（menu.xml）写入；侧栏菜单必须以 menu.xml 为准
                // 不再保留既有 type='menus'，避免已从 menu.xml 移除的 controller ACL 仍显示在侧栏
                $sourceId = $acl->getSourceId();
                $aclParentSource = $acl->getParentSource();
                if (empty($aclParentSource)) {
                    // 仅当 parent_source 为空时，查询数据库保留 menu.xml 可能设置的 parent_source
                    $existingRecords = $this->acl->reset()
                        ->where(\Weline\Acl\Model\Acl::schema_fields_SOURCE_ID, $sourceId)
                        ->fields(\Weline\Acl\Model\Acl::schema_fields_PARENT_SOURCE)
                        ->select()
                        ->fetchArray();
                    $existingRecord = $existingRecords[0] ?? null;
                    if ($existingRecord && !empty($existingRecord['parent_source'])) {
                        $acl->setParentSource($existingRecord['parent_source']);
                    }
                }
                $this->assertClassAclAttachedToMenu($acl);
                
                // 收集到批量保存数组，不立即保存
                if (!isset($this->pending_class_level_acls[$module])) {
                    $this->pending_class_level_acls[$module] = [];
                }
                $aclData = $acl->getData();
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
        
        // 收集到批量保存数组，不立即保存
        if (!isset($this->pending_method_level_acls[$module])) {
            $this->pending_method_level_acls[$module] = [];
        }
        $this->pending_method_level_acls[$module][] = $acl->getData();
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
            
            // 检查是否在数据库中
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
        
        // 优先使用控制器级别的acl资源作为子方法的父级资源
        $parent_acl_source = $this->loaded_controller_acl_names[$module][$className] ?? '';
        if (!empty($parent_acl_source)) {
            $acl->setParentSource($parent_acl_source);
            return;
        }
        
        // 如果控制器没有类级别的权限，尝试通过权限ID模式推断父级权限
        $inferred_parent = $this->inferParentSource($acl->getSourceId());
        if (!empty($inferred_parent)) {
            // 检查推断的父级权限是否在数据库中存在
            if ($this->checkParentExists($inferred_parent)) {
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

        $sourceMenu = $this->acl->reset()
            ->where(\Weline\Acl\Model\Acl::schema_fields_SOURCE_ID, $sourceId)
            ->where(\Weline\Acl\Model\Acl::schema_fields_TYPE, \Weline\Acl\Model\Acl::type_MENUS)
            ->fields(\Weline\Acl\Model\Acl::schema_fields_SOURCE_ID)
            ->select()
            ->fetchArray();
        if (!empty($sourceMenu)) {
            return;
        }

        if ($parentSource !== '') {
            $parentMenu = $this->acl->reset()
                ->where(\Weline\Acl\Model\Acl::schema_fields_SOURCE_ID, $parentSource)
                ->where(\Weline\Acl\Model\Acl::schema_fields_TYPE, \Weline\Acl\Model\Acl::type_MENUS)
                ->fields(\Weline\Acl\Model\Acl::schema_fields_SOURCE_ID)
                ->select()
                ->fetchArray();
            if (!empty($parentMenu)) {
                return;
            }
        }

        throw new \Exception(__('框架约定错误：类级 ACL %{1} 未依附菜单 ACL。请确保 source_id 对应 menu.xml 节点，或 parent_source 指向 type=menus 的菜单节点。当前 parent_source=%{2}', [
            $sourceId,
            $parentSource ?: __('(空)'),
        ]));
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
            
            // 收集到批量保存数组，不立即保存
            if (!isset($this->pending_method_level_acls[$module])) {
                $this->pending_method_level_acls[$module] = [];
            }
            $methodAclData = $acl->getData();
            if (!isset($methodAclData[\Weline\Acl\Model\Acl::schema_fields_ORDER])) {
                $methodAclData[\Weline\Acl\Model\Acl::schema_fields_ORDER] = 0;
            }
            $this->pending_method_level_acls[$module][] = $methodAclData;
        }
        
        // 清空待处理队列
        unset($this->pending_method_acls[$module][$className]);
    }

    /**
     * 批量保存类级别权限
     * 
     * @param string $module 模块名
     * @return void
     * @throws \Exception
     */
    private function batchSaveClassLevelAcls(string $module): void
    {
        if (!isset($this->pending_class_level_acls[$module]) || empty($this->pending_class_level_acls[$module])) {
            return;
        }
        
        // 🔧 修复：去重 pending_class_level_acls，只保留每个 source_id 的最新记录
        // 因为同一个 source_id 可能因为不同的路由变体或多次扫描被收集多次
        // 但 ACL 权限应该只保存一次，所以需要去重
        $deduplicatedAcls = [];
        $seenSourceIds = [];
        $duplicateInfo = []; // 记录重复的详细信息（开发环境用）
        
        foreach ($this->pending_class_level_acls[$module] as $index => $acl) {
            $sourceId = $acl['source_id'] ?? '';
            if (!empty($sourceId)) {
                // 如果已经见过这个 source_id
                if (isset($seenSourceIds[$sourceId])) {
                    // 开发环境：记录重复信息
                    if (DEV) {
                        // 只有在真正有重复时才记录
                        if (!isset($duplicateInfo[$sourceId])) {
                            // 第一次发现重复，记录首次出现的信息
                            $firstAcl = $seenSourceIds[$sourceId]; // 保存的是第一个 ACL 的引用
                            $duplicateInfo[$sourceId] = [
                                'first' => [
                                    'index' => $firstAcl['index'] ?? 'unknown',
                                    'class' => $firstAcl['class'] ?? '',
                                    'method' => $firstAcl['method'] ?? '',
                                    'route' => $firstAcl['route'] ?? '',
                                    'router' => $firstAcl['router'] ?? '',
                                ],
                                'duplicates' => []
                            ];
                        }
                        // 记录重复出现的信息
                        $duplicateInfo[$sourceId]['duplicates'][] = [
                            'index' => $index,
                            'class' => $acl['class'] ?? '',
                            'method' => $acl['method'] ?? '',
                            'route' => $acl['route'] ?? '',
                            'router' => $acl['router'] ?? '',
                        ];
                    }
                    // 找到已存在的记录并替换
                    foreach ($deduplicatedAcls as $existingIndex => $existingAcl) {
                        if (($existingAcl['source_id'] ?? '') === $sourceId) {
                            $deduplicatedAcls[$existingIndex] = $acl;
                            break;
                        }
                    }
                } else {
                    // 第一次遇到这个 source_id
                    // 开发环境：保存第一个 ACL 的引用，以便后续发现重复时使用
                    if (DEV) {
                        $seenSourceIds[$sourceId] = [
                            'index' => $index,
                            'class' => $acl['class'] ?? '',
                            'method' => $acl['method'] ?? '',
                            'route' => $acl['route'] ?? '',
                            'router' => $acl['router'] ?? '',
                        ];
                    } else {
                        $seenSourceIds[$sourceId] = true;
                    }
                    $deduplicatedAcls[] = $acl;
                }
            } else {
                // 如果没有 source_id，保留（虽然这种情况不应该发生）
                $deduplicatedAcls[] = $acl;
            }
        }
        
        // 🔧 开发环境：如果发现重复，只做详细提示，不中断执行（落库仍然使用去重后的数据）
        if (DEV && !empty($duplicateInfo)) {
            $errorMessages = [];
            $errorMessages[] = __('【ACL 重复检测】模块: %{1}', [$module]);
            $errorMessages[] = __('发现以下重复的 source_id：');
            foreach ($duplicateInfo as $sourceId => $info) {
                $errorMessages[] = "\n" . __('重复的 source_id: %{1}', [$sourceId]);
                $errorMessages[] = __('  首次出现:');
                $first = $info['first'] ?? [];
                $errorMessages[] = __('    - 类: %{1}', [$first['class'] ?? '']);
                $errorMessages[] = __('    - 方法: %{1}', [$first['method'] ?? '']);
                $errorMessages[] = __('    - 路由: %{1}', [$first['route'] ?? '']);
                $errorMessages[] = __('    - 路由器: %{1}', [$first['router'] ?? '']);
                $duplicates = $info['duplicates'] ?? [];
                $errorMessages[] = __('  重复出现 (%{1} 次):', [count($duplicates)]);
                foreach ($duplicates as $dup) {
                    $errorMessages[] = __('    - 索引 #%{1}:', [$dup['index']]);
                    $errorMessages[] = __('      类: %{1}', [$dup['class']]);
                    $errorMessages[] = __('      方法: %{1}', [$dup['method']]);
                    $errorMessages[] = __('      路由: %{1}', [$dup['route']]);
                    $errorMessages[] = __('      路由器: %{1}', [$dup['router']]);
                }
            }
            $errorMessages[] = "\n" . __('请检查代码，确保每个 source_id 只定义一次！');
            // 仅在开发环境输出提示，不抛异常，允许重复存在但实际入库已做去重
            pp(implode("\n", $errorMessages));
        }

        // 防止控制器 ACL 覆盖 menu.xml 同源的菜单记录
        $deduplicatedAcls = $this->excludeMenuXmlSources($deduplicatedAcls);
        if (empty($deduplicatedAcls)) {
            unset($this->pending_class_level_acls[$module]);
            return;
        }
        
        $this->acl->reset()->clearData();
        $this->acl->beginTransaction();
        try {
            // 控制器 ACL 统一为 type='pc'；type='menus' 仅由 MenuCollector（menu.xml）负责
            // 批量保存时不再保留既有 type='menus'，确保侧栏菜单严格以 menu.xml 为准
            // 仅保留 parent_source（当新数据为空时）
            $sourceIds = array_column($deduplicatedAcls, 'source_id');
            $existingParentMap = [];
            if (!empty($sourceIds)) {
                $existingRecords = $this->acl->reset()
                    ->where(\Weline\Acl\Model\Acl::schema_fields_SOURCE_ID, $sourceIds, 'in')
                    ->select(\Weline\Acl\Model\Acl::schema_fields_SOURCE_ID . ',' . \Weline\Acl\Model\Acl::schema_fields_PARENT_SOURCE)
                    ->fetchArray();
                foreach ($existingRecords as $record) {
                    $existingParentMap[$record['source_id']] = $record['parent_source'] ?? '';
                }
            }
            foreach ($deduplicatedAcls as &$acl) {
                $sourceId = $acl['source_id'] ?? '';
                if (!empty($sourceId) && isset($existingParentMap[$sourceId])) {
                    $newParent = $acl['parent_source'] ?? '';
                    if (empty($newParent) && $existingParentMap[$sourceId] !== '') {
                        $acl['parent_source'] = $existingParentMap[$sourceId];
                    }
                }
            }
            unset($acl);

            // acl 表对 source_id 有 UNIQUE，存在则按冲突键更新全部字段（方言由适配器生成）
            $this->acl->reset()->clearData();
            $this->acl->getQuery()->insert($deduplicatedAcls, 'source_id', '')->fetch();
            $this->acl->commit();
            CollectedAclSourceIdsRegistry::add(...array_column($deduplicatedAcls, 'source_id'));
        } catch (\Exception $exception) {
            $this->acl->rollBack();
            if (DEV) {
                p($exception->getMessage());
            }
            throw $exception;
        }
        // 清空已保存的类级别权限
        unset($this->pending_class_level_acls[$module]);
    }

    /**
     * 批量保存方法级别权限
     * 
     * @param string $module 模块名
     * @return void
     * @throws \Exception
     */
    private function batchSaveMethodLevelAcls(string $module): void
    {
        if (!isset($this->pending_method_level_acls[$module]) || empty($this->pending_method_level_acls[$module])) {
            return;
        }
        
        // 🔧 修复：去重 pending_method_level_acls，只保留每个 source_id 的最新记录
        // 因为同一个 source_id 可能因为不同的路由变体（例如不同的 HTTP 方法）被收集多次
        // 但 ACL 权限应该只保存一次，所以需要去重
        $deduplicatedAcls = [];
        $seenSourceIds = [];
        $duplicateInfo = []; // 记录重复的详细信息（开发环境用）
        
        foreach ($this->pending_method_level_acls[$module] as $index => $acl) {
            $sourceId = $acl['source_id'] ?? '';
            if (!empty($sourceId)) {
                // 如果已经见过这个 source_id
                if (isset($seenSourceIds[$sourceId])) {
                    // 开发环境：记录重复信息
                    if (DEV) {
                        // 只有在真正有重复时才记录
                        if (!isset($duplicateInfo[$sourceId])) {
                            // 第一次发现重复，记录首次出现的信息
                            $firstAcl = $seenSourceIds[$sourceId]; // 保存的是第一个 ACL 的引用
                            $duplicateInfo[$sourceId] = [
                                'first' => [
                                    'index' => $firstAcl['index'] ?? 'unknown',
                                    'class' => $firstAcl['class'] ?? '',
                                    'method' => $firstAcl['method'] ?? '',
                                    'route' => $firstAcl['route'] ?? '',
                                    'router' => $firstAcl['router'] ?? '',
                                ],
                                'duplicates' => []
                            ];
                        }
                        // 记录重复出现的信息
                        $duplicateInfo[$sourceId]['duplicates'][] = [
                            'index' => $index,
                            'class' => $acl['class'] ?? '',
                            'method' => $acl['method'] ?? '',
                            'route' => $acl['route'] ?? '',
                            'router' => $acl['router'] ?? '',
                        ];
                    }
                    // 找到已存在的记录并替换
                    foreach ($deduplicatedAcls as $existingIndex => $existingAcl) {
                        if (($existingAcl['source_id'] ?? '') === $sourceId) {
                            $deduplicatedAcls[$existingIndex] = $acl;
                            break;
                        }
                    }
                } else {
                    // 第一次遇到这个 source_id
                    // 开发环境：保存第一个 ACL 的引用，以便后续发现重复时使用
                    if (DEV) {
                        $seenSourceIds[$sourceId] = [
                            'index' => $index,
                            'class' => $acl['class'] ?? '',
                            'method' => $acl['method'] ?? '',
                            'route' => $acl['route'] ?? '',
                            'router' => $acl['router'] ?? '',
                        ];
                    } else {
                        $seenSourceIds[$sourceId] = true;
                    }
                    $deduplicatedAcls[] = $acl;
                }
            } else {
                // 如果没有 source_id，保留（虽然这种情况不应该发生）
                $deduplicatedAcls[] = $acl;
            }
        }
        
        // 🔧 开发环境：如果发现重复，只做详细提示，不中断执行（落库仍然使用去重后的数据）
        if (DEV && !empty($duplicateInfo)) {
            $errorMessages = [];
            $errorMessages[] = __('【ACL 重复检测】模块: %{1} (方法级别权限)', [$module]);
            $errorMessages[] = __('发现以下重复的 source_id：');
            foreach ($duplicateInfo as $sourceId => $info) {
                $errorMessages[] = "\n" . __('重复的 source_id: %{1}', [$sourceId]);
                $errorMessages[] = __('  首次出现:');
                $first = $info['first'] ?? [];
                $errorMessages[] = __('    - 类: %{1}', [$first['class'] ?? '']);
                $errorMessages[] = __('    - 方法: %{1}', [$first['method'] ?? '']);
                $errorMessages[] = __('    - 路由: %{1}', [$first['route'] ?? '']);
                $errorMessages[] = __('    - 路由器: %{1}', [$first['router'] ?? '']);
                $duplicates = $info['duplicates'] ?? [];
                $errorMessages[] = __('  重复出现 (%{1} 次):', [count($duplicates)]);
                foreach ($duplicates as $dup) {
                    $errorMessages[] = __('    - 索引 #%{1}:', [$dup['index']]);
                    $errorMessages[] = __('      类: %{1}', [$dup['class']]);
                    $errorMessages[] = __('      方法: %{1}', [$dup['method']]);
                    $errorMessages[] = __('      路由: %{1}', [$dup['route']]);
                    $errorMessages[] = __('      路由器: %{1}', [$dup['router']]);
                }
            }
            $errorMessages[] = "\n" . __('请检查代码，确保每个 source_id 只定义一次！');
            // 仅在开发环境输出提示，不抛异常，允许重复存在但实际入库已做去重
            pp(implode("\n", $errorMessages));
        }

        // 防止控制器 ACL 覆盖 menu.xml 同源的菜单记录
        $deduplicatedAcls = $this->excludeMenuXmlSources($deduplicatedAcls);
        if (empty($deduplicatedAcls)) {
            unset($this->pending_method_level_acls[$module]);
            return;
        }
        
        $this->acl->reset()->clearData();
        $this->acl->beginTransaction();
        try {
            // 方法级别 ACL 统一为 type='pc'；仅保留 parent_source（当新数据为空时）
            $sourceIds = array_column($deduplicatedAcls, 'source_id');
            $existingParentMap = [];
            if (!empty($sourceIds)) {
                $existingRecords = $this->acl->reset()
                    ->where(\Weline\Acl\Model\Acl::schema_fields_SOURCE_ID, $sourceIds, 'in')
                    ->select(\Weline\Acl\Model\Acl::schema_fields_SOURCE_ID . ',' . \Weline\Acl\Model\Acl::schema_fields_PARENT_SOURCE)
                    ->fetchArray();
                foreach ($existingRecords as $record) {
                    $existingParentMap[$record['source_id']] = $record['parent_source'] ?? '';
                }
            }
            foreach ($deduplicatedAcls as &$acl) {
                $sourceId = $acl['source_id'] ?? '';
                if (!empty($sourceId) && isset($existingParentMap[$sourceId])) {
                    $newParent = $acl['parent_source'] ?? '';
                    if (empty($newParent) && $existingParentMap[$sourceId] !== '') {
                        $acl['parent_source'] = $existingParentMap[$sourceId];
                    }
                }
            }
            unset($acl);

            // acl 表对 source_id 有 UNIQUE，存在则按冲突键更新全部字段（方言由适配器生成）
            $this->acl->reset()->clearData();
            $this->acl->getQuery()->insert($deduplicatedAcls, ['source_id'], '')->fetch();
            $this->acl->commit();
            CollectedAclSourceIdsRegistry::add(...array_column($deduplicatedAcls, 'source_id'));
        } catch (\Exception $exception) {
            $this->acl->rollBack();
            if (DEV) {
                p($exception->getMessage());
            }
            throw $exception;
        }
        
        // 清空已保存的方法级别权限
        unset($this->pending_method_level_acls[$module]);
    }

    /**
     * 排除来源于 menu.xml 的菜单资源，避免被控制器 ACL 覆盖。
     *
     * @param array<int, array<string, mixed>> $acls
     * @return array<int, array<string, mixed>>
     */
    private function excludeMenuXmlSources(array $acls): array
    {
        if (empty($acls)) {
            return $acls;
        }

        $sourceIds = array_values(array_filter(array_map(static fn(array $acl): string => (string)($acl['source_id'] ?? ''), $acls)));
        if (empty($sourceIds)) {
            return $acls;
        }

        $existingMenuXml = $this->acl->reset()
            ->where(Acl::schema_fields_SOURCE_ID, $sourceIds, 'in')
            ->where(Acl::schema_fields_TYPE, Acl::type_MENUS)
            ->where(Acl::schema_fields_ACL_ORIGIN, Acl::acl_origin_menu_xml)
            ->fields(Acl::schema_fields_SOURCE_ID)
            ->select()
            ->fetchArray();
        $excludeSourceIds = array_column($existingMenuXml, Acl::schema_fields_SOURCE_ID);
        if (empty($excludeSourceIds)) {
            return $acls;
        }

        return array_values(array_filter(
            $acls,
            static fn(array $acl): bool => !in_array((string)($acl['source_id'] ?? ''), $excludeSourceIds, true)
        ));
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
     * 例如：GuoLaiRen_PageBuilder::page_builder_edit_post -> GuoLaiRen_PageBuilder::page_builder
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
        // 例如：page_builder_edit_post -> page_builder
        // 例如：page_builder_index -> page_builder
        if (strpos($permission, '_') !== false) {
            $parts = explode('_', $permission);
            // 只有当权限名至少有3部分时，才推断父级（避免 ai_market -> ai_market 的情况）
            // 例如：page_builder_edit_post (4部分) -> page_builder (前2部分)
            // 例如：page_builder_index (3部分) -> page_builder (前2部分)
            // 例如：ai_market (2部分) -> 不推断，返回空字符串
            if (count($parts) >= 3) {
                $parentPermission = $parts[0] . '_' . $parts[1];
                $inferredParent = $module . '::' . $parentPermission;
                // 确保推断的父级与当前权限ID不同
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
        try {
            $parentAcl = clone $this->acl;
            $parentAcl->reset();
            $result = $parentAcl->where(Acl::schema_fields_SOURCE_ID, $parentSourceId)
                ->find()
                ->fetch();
            return $result && $result->getId();
        } catch (\Exception $e) {
            // 如果查询出错，返回false
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
            // 首先从内存中查找（最快，按模块索引）
            if (!empty($moduleName) && isset($this->loaded_controller_acl_names[$moduleName][$className])) {
                $parentSourceId = $this->loaded_controller_acl_names[$moduleName][$className];
                if ($parentSourceId && $parentSourceId !== $currentSourceId) {
                    // 验证父级权限是否在数据库中存在
                    $exists = $this->checkParentExists($parentSourceId);                    if ($exists) {
                        return $parentSourceId;
                    }
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
                            return $parentSourceId;
                        }
                    }
                }
                
                // 如果没找到 method 为空的，尝试通过权限ID模式匹配
                foreach ($results->getItems() as $item) {
                    $parentSourceId = $item->getData(Acl::schema_fields_SOURCE_ID);
                    if ($parentSourceId && $parentSourceId !== $currentSourceId) {
                        // 检查权限ID是否可能是父级（通过模式匹配）
                        // 例如：如果当前是 page_builder_index，父级可能是 page_builder
                        $currentParts = explode('::', $currentSourceId);
                        $parentParts = explode('::', $parentSourceId);
                        if (count($currentParts) === 2 && count($parentParts) === 2) {
                            $currentPerm = $currentParts[1];
                            $parentPerm = $parentParts[1];
                            // 如果父级权限名是当前权限名的前缀，则认为是父级
                            if (strpos($currentPerm, $parentPerm . '_') === 0) {
                                return $parentSourceId;
                            }
                        }
                    }
                }
            }
            
            return '';
        } catch (\Exception $e) {
            // 如果查询出错，返回空字符串
            return '';
        }
    }
}