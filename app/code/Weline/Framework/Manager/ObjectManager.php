<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Manager;

use ReflectionClass;
use Weline\Framework\App\Debug;
use Weline\Framework\App\Exception;
use Weline\Framework\Cache\Contract\MemoryStoreInterface;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\Adapter\FileAdapter;
use Weline\Framework\Cache\Pool\CachePool;
use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Runtime\RequestScope;

class ObjectManager implements ManagerInterface
{
    private const PRECOMPILED_METADATA_FORMAT = 'weline-reflection-metadata/v2';

    private const COMPILED_FACTORY_FORMAT = 'weline-compiled-factories/v2';

    public const unserializable_class = [
        \PDO::class,
        \WeakMap::class
    ];
    private static ?CachePoolInterface $cache = null;

    private static ?ObjectManager $instance = null;

    private static array $instances = [];
    private static array $reflections = [];
    private static array $origin_instances = [];
    private static ?\WeakMap $fiberInstances = null;
    private static ?\WeakMap $fiberOriginInstances = null;
    /**
     * 方法参数元数据缓存
     * 格式：['ClassName::methodName' => ['params' => [...], 'dependencies' => [...]]]
     */
    private static array $methodParamsMetadata = [];

    /**
     * Object-valued PHP defaults recovered from legacy compiled metadata.
     * Old reflection_metadata.php files represented `new Service()` as null.
     *
     * @var array<string, mixed>
     */
    private static array $metadataDefaultValueCache = [];
    
    /**
     * 预编译反射元数据（从 generated/reflection_metadata.php 加载）
     * 性能优化：避免 FPM 模式下每次请求都执行反射操作
     */
    private static ?array $precompiledMetadata = null;

    /** @var array<string, string> */
    private static array $precompiledMetadataSourceSignatures = [];
    
    /**
     * 预编译元数据是否已尝试加载
     */
    private static bool $precompiledLoaded = false;
    
    /**
     * PHP 8 性能优化：缓存静态类检测结果
     * 格式：['ClassName' => bool]
     */
    private static array $staticClassCache = [];
    
    /**
     * PHP 8 性能优化：缓存 class_exists 结果
     * 格式：['ClassName' => bool]
     */
    private static array $classExistsCache = [];
    
    /**
     * PHP 8 性能优化：缓存构造函数信息
     * 格式：['ClassName' => ['isPublic' => bool, 'hasGetInstance' => bool, 'getInstanceMethod' => ReflectionMethod|null]]
     */
    private static array $constructorCache = [];
    
    /**
     * PHP 8 性能优化：缓存魔术方法名称（避免重复创建数组）
     * 使用 const 替代 readonly（静态属性不能是 readonly）
     */
    private const MAGIC_METHODS = ['__construct', '__destruct', '__clone', '__wakeup', '__sleep'];
    
    /**
     * 性能优化：缓存接口检测结果
     * 格式：['ClassName' => bool]
     */
    private static array $interfaceCache = [];

    /**
     * 编译型工厂容器（从 generated/compiled_factories.php 加载）
     * 无参 getInstance 时优先使用，避免反射
     */
    private static ?array $compiledFactories = null;

    /** @var array<class-string, string> */
    private static array $compiledFactorySourceSignatures = [];

    /** @var array<string, string|null> */
    private static array $currentSourceSignatures = [];

    /**
     * 编译型工厂是否已尝试加载
     */
    private static bool $compiledFactoriesLoaded = false;

    private static ?array $generatedPluginRegistry = null;
    private static ?int $generatedPluginRegistryMtime = null;
    private static array $interceptorGenerationInProgress = [];

    /**
     * Authoritative contract bindings compiled from etc/module.php.
     *
     * FrameworkCompiler temporarily installs its staging registry so a first
     * compile never reads a stale final generation. Runtime uses the immutable
     * final registry and keeps it process-local.
     */
    private static ?ServiceProviderRegistry $serviceProviderRegistry = null;

    /** @var array<string, class-string|null> */
    private static array $serviceProviderImplementationCache = [];

    public static function replaceServiceProviderRegistry(
        ?ServiceProviderRegistry $registry,
    ): ?ServiceProviderRegistry {
        $previous = self::$serviceProviderRegistry;
        self::$serviceProviderRegistry = $registry;
        self::$serviceProviderImplementationCache = [];

        return $previous;
    }

    private static function resolveProvidedImplementation(string $contract): ?string
    {
        if (\array_key_exists($contract, self::$serviceProviderImplementationCache)) {
            return self::$serviceProviderImplementationCache[$contract];
        }

        $explicitRegistry = self::$serviceProviderRegistry !== null;
        $registry = self::$serviceProviderRegistry ??= new ServiceProviderRegistry();
        try {
            $implementation = $registry->implementationFor($contract);
        } catch (\RuntimeException $error) {
            if ($explicitRegistry) {
                throw $error;
            }

            // The bootstrap command may run before the first compiled registry
            // exists. Unbound third-party interfaces can still use their
            // explicit Factory migration bridge during that control-plane gap.
            $implementation = null;
        }

        if ($implementation !== null) {
            if ($implementation === $contract
                || !\class_exists($implementation, true)
                || !\is_a($implementation, $contract, true)
            ) {
                throw new Exception(
                    "模块 Provider 绑定无效：{$contract} => {$implementation}",
                );
            }
        }

        return self::$serviceProviderImplementationCache[$contract] = $implementation;
    }

    private static function instantiateInterface(
        string $contract,
        array $arguments,
        bool $shared,
        bool $cache,
    ): object {
        $implementation = self::resolveProvidedImplementation($contract);
        if ($implementation !== null) {
            $instance = self::getInstance($implementation, $arguments, $shared, $cache);
            if (!$instance instanceof $contract) {
                throw new Exception(
                    "模块 Provider 实现类型不匹配：{$contract} => {$implementation}",
                );
            }
            if ($shared) {
                self::setScopedInstance($contract, $instance);
            }

            return $instance;
        }

        $factoryClass = $contract . 'Factory';
        if (!self::cachedClassExists($factoryClass)) {
            throw new Exception(
                "接口 {$contract} 未在 etc/module.php provides 声明实现，且迁移工厂 {$factoryClass} 不存在，无法实例化",
            );
        }

        $factoryClass = self::parserClass($factoryClass);
        $factory = self::instantiateObject(
            self::getReflectionInstance($factoryClass),
            $factoryClass,
            [],
        );
        self::callInitMethod($factory);
        if (!$factory instanceof FactoryObjectInterface) {
            throw new Exception("工厂类 {$factoryClass} 没有实现 FactoryObjectInterface 接口");
        }

        $instance = $factory->create();
        if (!\is_object($instance) || !$instance instanceof $contract) {
            throw new Exception("工厂类 {$factoryClass} 返回了不匹配 {$contract} 的实例");
        }
        if ($shared) {
            self::setScopedInstance($contract, $instance);
        }

        return $instance;
    }

    private function __clone()
    {
    }

    public function __init()
    {
        self::getCache();
    }

    public function __construct()
    {
        self::getCache();
    }

    /**
     * PHP 8 性能优化：使用 isset 替代 empty（isset 更快）
     */
    private static function getCache(): CachePoolInterface
    {
        if (!isset(self::$cache)) {
            $adapter = new FileAdapter('object');
            self::$cache = new CachePool('object', $adapter, '对象缓存', true, 86400);
        }
        return self::$cache;
    }
    
    /**
     * PHP 8 性能优化：缓存 class_exists 结果
     */
    private static function cachedClassExists(string $class): bool
    {
        if (!isset(self::$classExistsCache[$class])) {
            self::$classExistsCache[$class] = class_exists($class, true);
        }
        return self::$classExistsCache[$class];
    }

    private static function getReflectionTypeNames(?\ReflectionType $type): array
    {
        if ($type === null) {
            return [];
        }

        if ($type instanceof \ReflectionNamedType) {
            return [$type->getName()];
        }

        if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
            $names = [];
            foreach ($type->getTypes() as $childType) {
                foreach (self::getReflectionTypeNames($childType) as $name) {
                    $names[] = $name;
                }
            }
            return $names;
        }

        return [];
    }

    private static function getFirstNamedReflectionType(?\ReflectionType $type): ?string
    {
        foreach (self::getReflectionTypeNames($type) as $name) {
            if ($name !== 'null') {
                return $name;
            }
        }

        return null;
    }

    private static function getClassReflectionTypeName(?\ReflectionType $type): ?string
    {
        foreach (self::getReflectionTypeNames($type) as $name) {
            if ($name === 'null') {
                continue;
            }
            if (self::cachedClassExists($name) || interface_exists($name, true)) {
                return $name;
            }
        }

        return null;
    }

    private static function reflectionTypeIncludesName(?\ReflectionType $type, string $expectedName): bool
    {
        foreach (self::getReflectionTypeNames($type) as $name) {
            if ($name === $expectedName) {
                return true;
            }
        }

        return false;
    }

    private static function currentRequestFiber(): ?\Fiber
    {
        if (!class_exists(\Weline\Framework\Runtime\Runtime::class)) {
            return null;
        }

        if (!\Weline\Framework\Runtime\Runtime::isPersistent()) {
            return null;
        }

        return \Fiber::getCurrent();
    }

    private static function getScopedInstance(string $class, bool $origin = false): ?object
    {
        $fiber = self::currentRequestFiber();
        if ($fiber === null) {
            return $origin
                ? (self::$origin_instances[$class] ?? null)
                : (self::$instances[$class] ?? null);
        }

        return self::getFiberScope($origin, $fiber)?->get($class);
    }

    private static function setScopedInstance(string $class, object $object, bool $origin = false): void
    {
        $fiber = self::currentRequestFiber();
        if ($fiber === null) {
            if ($origin) {
                self::$origin_instances[$class] = $object;
            } else {
                self::$instances[$class] = $object;
            }
            return;
        }

        self::getFiberScope($origin, $fiber, true)->set($class, $object);
    }

    private static function getScopedInstances(bool $origin = false): array
    {
        $fiber = self::currentRequestFiber();
        if ($fiber === null) {
            return $origin ? self::$origin_instances : self::$instances;
        }

        return self::getFiberScope($origin, $fiber)?->all() ?? [];
    }

    private static function setScopedInstances(array $instances, bool $origin = false): void
    {
        $fiber = self::currentRequestFiber();
        if ($fiber === null) {
            if ($origin) {
                self::$origin_instances = $instances;
            } else {
                self::$instances = $instances;
            }
            return;
        }

        if ($instances === []) {
            self::unsetFiberScope($origin, $fiber);
            return;
        }

        self::getFiberScope($origin, $fiber, true)->replace($instances);
    }

    private static function removeScopedInstance(string $class, bool $origin = false): void
    {
        $fiber = self::currentRequestFiber();
        if ($fiber === null) {
            if ($origin) {
                unset(self::$origin_instances[$class]);
            } else {
                unset(self::$instances[$class]);
            }
            return;
        }

        $scope = self::getFiberScope($origin, $fiber);
        if ($scope === null) {
            return;
        }

        $scope->remove($class);
        if ($scope->isEmpty()) {
            self::unsetFiberScope($origin, $fiber);
        }
    }

    private static function getFiberScope(bool $origin, \Fiber $fiber, bool $create = false): ?RequestScope
    {
        if ($origin) {
            if (self::$fiberOriginInstances === null) {
                if (!$create) {
                    return null;
                }
                self::$fiberOriginInstances = new \WeakMap();
            }
            $storage = self::$fiberOriginInstances;
        } else {
            if (self::$fiberInstances === null) {
                if (!$create) {
                    return null;
                }
                self::$fiberInstances = new \WeakMap();
            }
            $storage = self::$fiberInstances;
        }

        if (!isset($storage[$fiber])) {
            if (!$create) {
                return null;
            }
            $storage[$fiber] = new RequestScope();
        }

        $scope = $storage[$fiber];
        if ($scope instanceof RequestScope) {
            return $scope;
        }

        $scope = new RequestScope(is_array($scope) ? $scope : []);
        $storage[$fiber] = $scope;

        return $scope;
    }

    private static function unsetFiberScope(bool $origin, \Fiber $fiber): void
    {
        if ($origin) {
            if (self::$fiberOriginInstances !== null && isset(self::$fiberOriginInstances[$fiber])) {
                unset(self::$fiberOriginInstances[$fiber]);
            }
            return;
        }

        if (self::$fiberInstances !== null && isset(self::$fiberInstances[$fiber])) {
            unset(self::$fiberInstances[$fiber]);
        }
    }

    /**
     * 检测类是否为静态类（所有方法都是静态的，且没有可实例化的构造函数）
     * 
     * PHP 8 性能优化：
     * - 缓存检测结果，避免重复反射
     * - 使用缓存的反射类
     * - 提前返回，减少循环
     *
     * @param string $class 类名
     * @return bool
     */
    public static function isStaticClass(string $class): bool
    {
        // PHP 8 性能优化：使用缓存避免重复检测
        if (isset(self::$staticClassCache[$class])) {
            return self::$staticClassCache[$class];
        }
        
        // 先检查是否是接口（接口不能是静态类，且不能反射）
        if (interface_exists($class, true)) {
            return self::$staticClassCache[$class] = false;
        }
        
        try {
            // PHP 8 性能优化：使用缓存的反射类
            $refClass = self::getReflectionInstance($class);
            
            // 检查是否有公共构造函数（提前返回）
            $constructor = $refClass->getConstructor();
            if ($constructor?->isPublic()) {
                return self::$staticClassCache[$class] = false;
            }
            
            // PHP 8 性能优化：缓存 getInstance 方法检查
            if ($refClass->hasMethod('getInstance')) {
                $getInstanceMethod = $refClass->getMethod('getInstance');
                if ($getInstanceMethod->isStatic()) {
                    return self::$staticClassCache[$class] = false;
                }
            }
            
            // 获取所有公共方法
            $methods = $refClass->getMethods(\ReflectionMethod::IS_PUBLIC);
            
            // 如果没有公共方法，可能是静态类
            if (empty($methods)) {
                return self::$staticClassCache[$class] = true;
            }
            
            // PHP 8 性能优化：使用常量，避免重复创建数组
            foreach ($methods as $method) {
                // 跳过构造函数和魔术方法
                $methodName = $method->getName();
                if (in_array($methodName, self::MAGIC_METHODS, true)) {
                    continue;
                }
                
                // 如果有非静态的公共方法，不是静态类（提前返回）
                if (!$method->isStatic()) {
                    return self::$staticClassCache[$class] = false;
                }
            }
            
            // 所有公共方法都是静态的，且没有公共构造函数，判定为静态类
            return self::$staticClassCache[$class] = true;
        } catch (\ReflectionException $e) {
            // 反射失败，假设不是静态类
            return self::$staticClassCache[$class] = false;
        }
    }

    /**
     * 初始化工厂类：处理 Factory 后缀
     * 
     * PHP 8 性能优化：使用缓存的 class_exists
     */
    private static function initFactoryClass(string $class): string
    {
        // 如果类存在，直接返回
        if (self::cachedClassExists($class)) {
            return $class;
        }
        
        // 如果类不存在且以Factory结尾，尝试查找对应的实现类
        // 但要注意：接口的工厂类（如ThemeChainResolverInterfaceFactory）应该保持原样
        if (str_ends_with($class, 'Factory')) {
            $baseClass = substr($class, 0, strrpos($class, 'Factory'));
            
            // 如果去掉Factory后是接口，说明这是接口的工厂类，应该保持原样
            // 接口的工厂类必须存在，否则抛出异常
            if (interface_exists($baseClass, true)) {
                // 这是接口的工厂类，如果工厂类本身不存在，应该抛出异常
                // 但首先尝试使用 autoload 加载工厂类
                if (!class_exists($class, true)) {
                    throw new Exception(__("接口工厂类: %{1} 不存在！", $class));
                }
                // 如果工厂类存在，直接返回工厂类名
                return $class;
            }
            
            // 如果不是接口，尝试查找对应的实现类（传统工厂类模式）
            if (self::cachedClassExists($baseClass)) {
                return $baseClass;
            }
            
            throw new Exception(__("Factory工厂类: %{1} 不存在！", $class));
        }
        
        return $class;
    }

    /**
     * 获取反射类实例（带缓存）
     * 
     * PHP 8 性能优化：
     * - 缓存所有反射类，避免重复创建
     * - 使用 isset 替代 empty
     */
    public static function getReflectionInstance(string|object $class): ReflectionClass
    {
        $className = is_object($class) ? $class::class : $class;
        
        // 防止尝试反射接口（接口必须通过工厂类实例化）
        if (interface_exists($className, true)) {
            // 获取调用堆栈信息
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
            $callerInfo = '';
            if (isset($backtrace[2])) {
                $caller = $backtrace[2];
                $callerFile = $caller['file'] ?? 'unknown';
                $callerLine = $caller['line'] ?? 'unknown';
                $callerFunction = $caller['function'] ?? 'unknown';
                $callerClass = $caller['class'] ?? '';
                $callerInfo = "\n调用位置: " . ($callerClass ? $callerClass . '::' : '') . $callerFunction . "()\n文件: " . str_replace(BP, '', $callerFile) . ":" . $callerLine;
            }
            throw new Exception("禁止反射接口 {$className}，接口必须通过工厂类实例化。{$callerInfo}");
        }
        
        if (!isset(self::$reflections[$className])) {
            self::$reflections[$className] = new ReflectionClass($className);
        }
        return self::$reflections[$className];
    }

    /**
     * 获取实例（依赖注入容器核心方法）
     * 
     * 职责：单一职责原则 - 负责对象实例的获取和生命周期管理
     * 
     * 流程：
     * 1. 检查共享实例缓存
     * 2. 检查文件缓存
     * 3. 解析类名（拦截器、工厂类）
     * 4. 检测静态类
     * 5. 解析构造函数参数（依赖注入）
     * 6. 实例化对象（处理私有构造函数）
     * 7. 初始化对象（__init、工厂类处理）
     * 8. 缓存对象
     * 
     * @param string $class 类名（空字符串时返回 ObjectManager 自身）
     * @param array $arguments 构造函数参数（会与依赖注入参数合并）
     * @param bool $shared 是否共享实例（true：单例模式，false：每次创建新实例）
     * @param bool $cache 是否使用文件缓存
     * @return mixed 对象实例
     * @throws Exception
     * @throws \ReflectionException
     */
    public static function getInstance(string $class = '', array $arguments = [], bool $shared = true, bool $cache = false): mixed
    {
        // PHP 8 性能优化：使用 isset 替代 empty（isset 更快）
        if ($class === '') {
            return self::$instance ??= new self();
        }
        
        // PHP 8 性能优化：延迟初始化 ObjectManager 实例
        self::$instance ??= new self();
        
        // PHP 8 性能优化：提前返回，避免后续处理
        if ($shared) {
            $sharedInstance = self::getScopedInstance($class);
            if ($sharedInstance !== null) {
                return $sharedInstance;
            }
        }
        
        // PHP 8 性能优化：合并条件判断，减少函数调用
        if ($cache && !CLI && $shared) {
            $cachedObject = self::getCache()->get($class);
            if ($cachedObject) {
                $cachedObject = self::initClassInstance($class, $cachedObject);
                self::setScopedInstance($class, $cachedObject);
                return $cachedObject;
            }
        }
        
        // 性能优化：使用缓存检测接口，减少重复的 interface_exists 调用
        $factoryClass = $class . 'Factory';
        
        // 检查接口缓存
        if (!isset(self::$interfaceCache[$class])) {
            $isInterface = false;
            
            // 快速路径：以 Interface 结尾的类名
            if (str_ends_with($class, 'Interface')) {
                // 先检查工厂类
                $factoryExists = class_exists($factoryClass, true);
                $isInterface = $factoryExists || interface_exists($class, true);
            } else {
                // 检查是否是接口
                $isInterface = interface_exists($class, true);
                
                // 如果不是接口且不是类，检查工厂类
                if (!$isInterface && !class_exists($class, true) && class_exists($factoryClass, true)) {
                    $isInterface = interface_exists($class, true);
                }
            }
            
            self::$interfaceCache[$class] = $isInterface;
        }
        
        $isInterface = self::$interfaceCache[$class];
        
        // etc/module.php provides is authoritative. Factory remains only as
        // the explicit third-party migration bridge for unbound interfaces.
        if ($isInterface) {
            return self::instantiateInterface($class, $arguments, $shared, $cache);
        }
        
        // 解析类名（处理拦截器和工厂类）
        $new_class = self::parserClass($class);
        
        // 编译工厂快速路径：无参且存在编译闭包时直接实例化，跳过所有反射
        if ($arguments === []) {
            self::loadCompiledFactories();
            if (self::$compiledFactories !== null
                && isset(self::$compiledFactories[$new_class])
                && self::compiledFactoryIsCurrent($new_class)
            ) {
                $new_object = (self::$compiledFactories[$new_class])();
                $new_object = self::initClassInstance($class, $new_object);
                if ($shared) {
                    self::setScopedInstance($class, $new_object);
                }
                if ($cache && !CLI && !in_array($class, self::unserializable_class, true)) {
                    self::getCache()->set($class, $new_object);
                }
                return $new_object;
            }
        }
        
        // 防止 parserClass 返回接口；仍走同一份 Provider 权威解析。
        if (interface_exists($new_class, true)) {
            $instance = self::instantiateInterface($new_class, $arguments, $shared, $cache);
            if ($shared && $class !== $new_class) {
                self::setScopedInstance($class, $instance);
            }
            return $instance;
        }
        
        // 检测静态类（静态类禁止实例化）
        // 注意：isStaticClass会调用getReflectionInstance，所以必须在接口检查之后
        if (self::isStaticClass($new_class)) {
            throw new Exception(__('不支持静态类实例化：%{1}。静态类应直接使用，无需通过 ObjectManager 实例化。', $new_class));
        }
        
        // 解析构造函数参数（依赖注入）
        $arguments = self::resolveConstructorArguments($new_class, $arguments);
        
        // 获取反射类（使用缓存）- 这里不应该再是接口
        $refClass = self::getReflectionInstance($new_class);
        
        // 再次验证不是接口（防止getReflectionInstance返回接口的反射）
        if ($refClass->isInterface()) {
            throw new Exception("尝试实例化接口 {$new_class}，接口必须通过工厂类实例化");
        }
        
        // 实例化对象（处理私有构造函数）
        $new_object = self::instantiateObject($refClass, $new_class, $arguments);
        
        // 初始化对象（__init、工厂类处理）
        $new_object = self::initClassInstance($class, $new_object);
        
        // 存储到共享实例缓存
        if ($shared) {
            self::setScopedInstance($class, $new_object);
        }
        
        // 缓存到文件（如果需要）
        if ($cache && !CLI && !in_array($class, self::unserializable_class, true)) {
            self::getCache()->set($class, $new_object);
        }
        
        return $new_object;
    }

    /**
     * @DESC          # 向后兼容 create() 调用（对齐 legacy 代码）
     *
     * 仅保持兼容：内部转发到 getInstance。
     * @param string $class
     * @param array $arguments
     * @param bool $shared
     * @param bool $cache
     * @return mixed
     */
    public static function create(string $class = '', array $arguments = [], bool $shared = true, bool $cache = false): mixed
    {
        return self::getInstance($class, $arguments, $shared, $cache);
    }

    /**
     * Backward-compatible alias for callers that use ObjectManager::get().
     */
    public static function get(string $class = '', array $arguments = [], bool $shared = true, bool $cache = false): mixed
    {
        return self::getInstance($class, $arguments, $shared, $cache);
    }
    
    /**
     * 解析构造函数参数：合并用户参数和依赖注入参数
     * 
     * PHP 8 性能优化：
     * - 使用 isset 和 !== [] 替代 empty
     * - 提前返回
     * 
     * @param string $class 类名
     * @param array $userArguments 用户提供的参数
     * @return array 合并后的参数数组
     */
    private static function resolveConstructorArguments(string $class, array $userArguments): array
    {
        // 先检查是否是接口（接口不能解析构造函数参数）
        if (interface_exists($class, true)) {
            throw new Exception("尝试解析接口 {$class} 的构造函数参数，接口必须通过工厂类实例化");
        }
        
        // PHP 8 性能优化：提前返回，避免不必要的函数调用
        if ($userArguments === []) {
            return self::getMethodParams($class);
        }
        
        $diArguments = self::getMethodParams($class);
        
        // 如果依赖注入返回了参数，合并它们
        if (isset($diArguments[0]) && $diArguments[0]) {
            return array_merge($diArguments, [$userArguments]);
        }
        
        return $userArguments;
    }
    
    /**
     * 实例化对象：处理公共构造函数和私有构造函数（使用静态工厂方法）
     * 
     * PHP 8 性能优化：
     * - 缓存构造函数信息，避免重复反射
     * - 使用 nullsafe 操作符
     * 
     * @param ReflectionClass $refClass 反射类
     * @param string $class 类名
     * @param array $arguments 构造函数参数
     * @return mixed 对象实例
     * @throws Exception
     */
    private static function instantiateObject(ReflectionClass $refClass, string $class, array $arguments): mixed
    {
        // PHP 8 性能优化：缓存构造函数信息
        if (!isset(self::$constructorCache[$class])) {
            $constructor = $refClass->getConstructor();
            self::$constructorCache[$class] = [
                'isPublic' => $constructor?->isPublic() ?? false,
                'hasGetInstance' => $refClass->hasMethod('getInstance'),
                'getInstanceMethod' => $refClass->hasMethod('getInstance') ? $refClass->getMethod('getInstance') : null
            ];
        }
        
        $cache = self::$constructorCache[$class];
        
        // 处理私有构造函数：使用静态工厂方法
        // 注意：必须传入 getInstance 方法的参数，而非构造函数的参数（二者签名可能不同，如 ConnectionFactory）
        if (!$cache['isPublic'] && $cache['hasGetInstance']) {
            $getInstanceMethod = $cache['getInstanceMethod'];
            if ($getInstanceMethod?->isStatic()) {
                $getInstanceArgs = count($getInstanceMethod->getParameters()) > 0
                    ? self::getMethodParams($class, 'getInstance')
                    : $arguments;
                return self::invokeGetInstance($getInstanceMethod, $class, $getInstanceArgs);
            }
        }
        
        // 公共构造函数：直接实例化
        return $refClass->newInstanceArgs($arguments);
    }
    
    /**
     * PHP 8 性能优化：调用 getInstance 方法（提取逻辑减少重复）
     */
    private static function invokeGetInstance(\ReflectionMethod $method, string $class, array $arguments): mixed
    {
        $params = $method->getParameters();
        
        // 如果 getInstance 方法需要参数，通过依赖注入获取
        if (count($params) > 0) {
            // 优先使用传入的 arguments
            if ($arguments !== []) {
                return $method->invokeArgs(null, $arguments);
            }
            // 通过依赖注入系统获取参数
            $getInstanceArgs = self::getMethodParams($class, 'getInstance');
            return $method->invokeArgs(null, $getInstanceArgs);
        }
        
        // 无参数的 getInstance 方法
        return $method->invoke(null);
    }
    

    /**
     * @DESC          # 读取原始对象数据，不处理Factory工厂类
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/3/20 13:34
     * 参数区：
     *
     * @param string $class 类
     * @param array $arguments __construct()初始化参数
     * @param bool $shared 是否共享：共享后会多次调用存储在对象管理器中的同一个对象（上一次存储的对象）
     * @param bool $cache 是否缓存
     *
     * @return mixed
     * @throws Exception
     * @throws \ReflectionException
     */
    /**
     * 读取原始对象数据，不处理Factory工厂类
     * 
     * PHP 8 性能优化：
     * - 使用 isset 替代 empty
     * - 使用 ??= 操作符
     * - 减少字符串拼接
     * 
     * @param string $class 类
     * @param array $arguments __construct()初始化参数
     * @param bool $shared 是否共享
     * @param bool $cache 是否缓存
     * @return mixed
     * @throws Exception
     * @throws \ReflectionException
     */
    public static function getOriginInstance(string $class, array $arguments = [], bool $shared = true, bool $cache = false): mixed
    {
        // PHP 8 性能优化：使用 ??= 操作符
        if ($class === '') {
            return self::$instance ??= new self();
        }
        self::$instance ??= new self();
        
        // PHP 8 性能优化：提前返回
        $originInstance = self::getScopedInstance($class, true);
        if ($originInstance !== null) {
            return $originInstance;
        }
        
        // PHP 8 性能优化：合并条件判断
        if ($cache && !CLI && $shared) {
            $cachedObject = self::getCache()->get($class);
            if ($cachedObject) {
                $cachedObject = self::initClassInstance($class, $cachedObject, false);
                self::setScopedInstance($class, $cachedObject, true);

                return $cachedObject;
            }
        }
        
        // PHP 8 性能优化：使用缓存的类名解析
        $class_cache_key = $class . '_cache_key';
        $new_class = self::getCache()->get($class_cache_key);
        if (!$new_class) {
            $new_class = self::parserClass($class);
            self::getCache()->set($class_cache_key, $new_class);
        }
        
        $arguments = $arguments ?: self::getMethodParams($new_class);
        $refClass = self::getReflectionInstance($new_class);
        $new_object = $refClass->newInstanceArgs($arguments);
        $new_object = self::initClassInstance($class, $new_object, false);

        self::setScopedInstance($class, $new_object, true);
        
        // PHP 8 性能优化：使用 in_array 的严格模式
        if ($cache && !CLI && !in_array($class, self::unserializable_class, true)) {
            self::getCache()->set($class, $new_object);
        }

        return $new_object;
    }

    /**
     * @DESC          # 设置实例
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/1/5 22:49
     * 参数区：
     *
     * @param string $class
     * @param object $object
     *
     * @return mixed
     */
    public static function setInstance(string $class, object &$object): mixed
    {
        self::setScopedInstance($class, $object);
        return true;
    }

    public static function addInstance($class, &$object)
    {
        self::setScopedInstance($class, $object);
    }

    public static function _getInstance($class)
    {
        return self::getScopedInstance($class);
    }

    public static function getInstances(): array
    {
        return self::getScopedInstances();
    }

    /**
     * 清理所有实例缓存（用于热重载）
     */
    public static function clearInstances(): void
    {
        self::setScopedInstances([]);
        self::$reflections = [];
        self::setScopedInstances([], true);
        if (self::currentRequestFiber() === null) {
            self::$fiberInstances = null;
            self::$fiberOriginInstances = null;
        }
    }

    public static function clearCurrentRequestScope(): void
    {
        $fiber = self::currentRequestFiber();
        if ($fiber === null) {
            self::$instances = [];
            self::$origin_instances = [];
            return;
        }

        self::unsetFiberScope(false, $fiber);
        self::unsetFiberScope(true, $fiber);
    }

    public static function clearRequestScopeForFiber(\Fiber $fiber): void
    {
        self::unsetFiberScope(false, $fiber);
        self::unsetFiberScope(true, $fiber);
    }

    /**
     * Clear only the current WLS request Fiber's ObjectManager buckets.
     *
     * This is intentionally narrower than clearInstances(): it releases
     * request-local shared instances after a Fiber finishes without touching
     * process-level instances or rebuildable metadata caches.
     */
    public static function clearCurrentFiberInstances(bool $includeOrigin = true): void
    {
        $fiber = self::currentRequestFiber();
        if ($fiber === null) {
            return;
        }

        if (self::$fiberInstances !== null && isset(self::$fiberInstances[$fiber])) {
            unset(self::$fiberInstances[$fiber]);
        }

        if ($includeOrigin && self::$fiberOriginInstances !== null && isset(self::$fiberOriginInstances[$fiber])) {
            unset(self::$fiberOriginInstances[$fiber]);
        }
    }

    /**
     * 在长生命周期 Worker 内存压力升高时，释放可重建的进程内缓存。
     *
     * 仅清理：
     * - 实现了 MemoryStoreInterface 的 L1 内存缓存
     * - ObjectManager 自身的元数据缓存
     *
     * 不移除业务对象实例，避免影响正在运行的请求。
     *
     * @return array{memory_store_clears:int, metadata_entries_cleared:int}
     */
    public static function relieveMemoryPressure(bool $aggressive = false): array
    {
        $seenObjects = [];
        $memoryStoreClears = 0;

        foreach (self::getAllScopedInstanceBuckets() as $bucket) {
            foreach ($bucket as $instance) {
                if (!$instance instanceof MemoryStoreInterface) {
                    continue;
                }

                $objectId = \spl_object_id($instance);
                if (isset($seenObjects[$objectId])) {
                    continue;
                }
                $seenObjects[$objectId] = true;

                $instance->clearMemory();
                $memoryStoreClears++;
            }
        }

        $metadataEntries = \count(self::$reflections)
            + \count(self::$methodParamsMetadata)
            + \count(self::$metadataDefaultValueCache)
            + \count(self::$staticClassCache)
            + \count(self::$classExistsCache)
            + \count(self::$constructorCache)
            + \count(self::$interfaceCache)
            + \count(self::$parsedClasses)
            + \count(self::$initMethodCache)
            + \count(self::$createMethodCache)
            + (self::$generatedPluginRegistry === null ? 0 : 1)
            + \count(self::$interceptorGenerationInProgress);

        if ($metadataEntries > 0 || $aggressive) {
            self::$reflections = [];
            self::$methodParamsMetadata = [];
            self::$metadataDefaultValueCache = [];
            self::$staticClassCache = [];
            self::$classExistsCache = [];
            self::$constructorCache = [];
            self::$interfaceCache = [];
            self::$parsedClasses = [];
            self::$initMethodCache = [];
            self::$createMethodCache = [];
            self::$generatedPluginRegistry = null;
            self::$generatedPluginRegistryMtime = null;
            self::$interceptorGenerationInProgress = [];
            self::$currentSourceSignatures = [];
        }

        return [
            'memory_store_clears' => $memoryStoreClears,
            'metadata_entries_cleared' => $metadataEntries,
        ];
    }

    /**
     * Lightweight WLS memory diagnostics for local health probes.
     *
     * This intentionally reports counts and class-name samples only. It must not
     * expose object data, request payloads, headers, or credentials.
     *
     * @return array<string, mixed>
     */
    public static function getRuntimeMemoryDiagnostics(int $sampleLimit = 12, bool $includeObjectProperties = false): array
    {
        $sampleLimit = \max(0, \min(50, $sampleLimit));
        $fiberBuckets = self::summarizeFiberBuckets(self::$fiberInstances, $sampleLimit);
        $fiberOriginBuckets = self::summarizeFiberBuckets(self::$fiberOriginInstances, $sampleLimit);

        $diagnostics = [
            'main_instances' => self::summarizeInstanceBucket(self::$instances, $sampleLimit),
            'origin_instances' => self::summarizeInstanceBucket(self::$origin_instances, $sampleLimit),
            'fiber_instances' => $fiberBuckets,
            'fiber_origin_instances' => $fiberOriginBuckets,
            'metadata_entries' => [
                'reflections' => \count(self::$reflections),
                'method_params' => \count(self::$methodParamsMetadata),
                'static_class' => \count(self::$staticClassCache),
                'class_exists' => \count(self::$classExistsCache),
                'constructors' => \count(self::$constructorCache),
                'interfaces' => \count(self::$interfaceCache),
                'parsed_classes' => \count(self::$parsedClasses),
                'init_methods' => \count(self::$initMethodCache),
                'create_methods' => \count(self::$createMethodCache),
                'generated_plugin_registry' => self::$generatedPluginRegistry === null ? 0 : 1,
                'interceptor_generation' => \count(self::$interceptorGenerationInProgress),
            ],
            'memory_store_instances' => self::countMemoryStoreInstances(),
        ];

        if ($includeObjectProperties) {
            $diagnostics['object_property_top'] = self::summarizeObjectProperties($sampleLimit);
        }

        return $diagnostics;
    }

    /**
     * @param array<string, object> $instances
     * @return array{count:int, sample:list<string>}
     */
    private static function summarizeInstanceBucket(array $instances, int $sampleLimit): array
    {
        $sample = [];
        if ($sampleLimit > 0) {
            foreach (\array_keys($instances) as $className) {
                $sample[] = (string)$className;
                if (\count($sample) >= $sampleLimit) {
                    break;
                }
            }
        }

        return [
            'count' => \count($instances),
            'sample' => $sample,
        ];
    }

    /**
     * @param \WeakMap<\Fiber, RequestScope|array<string, object>>|null $storage
     * @return array{bucket_count:int, instance_count:int, buckets:list<array{fiber_id:int, count:int, sample:list<string>}>}
     */
    private static function summarizeFiberBuckets(?\WeakMap $storage, int $sampleLimit): array
    {
        $bucketCount = 0;
        $instanceCount = 0;
        $buckets = [];
        if ($storage === null) {
            return [
                'bucket_count' => 0,
                'instance_count' => 0,
                'buckets' => [],
            ];
        }

        foreach ($storage as $fiber => $instances) {
            $bucketCount++;
            $instances = $instances instanceof RequestScope
                ? $instances->all()
                : (\is_array($instances) ? $instances : []);
            $instanceCount += \count($instances);
            if (\count($buckets) < $sampleLimit) {
                $summary = self::summarizeInstanceBucket($instances, $sampleLimit);
                $buckets[] = [
                    'fiber_id' => \spl_object_id($fiber),
                    'count' => $summary['count'],
                    'sample' => $summary['sample'],
                ];
            }
        }

        return [
            'bucket_count' => $bucketCount,
            'instance_count' => $instanceCount,
            'buckets' => $buckets,
        ];
    }

    private static function countMemoryStoreInstances(): int
    {
        $seenObjects = [];
        $count = 0;
        foreach (self::getAllScopedInstanceBuckets() as $bucket) {
            foreach ($bucket as $instance) {
                if (!$instance instanceof MemoryStoreInterface) {
                    continue;
                }
                $objectId = \spl_object_id($instance);
                if (isset($seenObjects[$objectId])) {
                    continue;
                }
                $seenObjects[$objectId] = true;
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array{objects_scanned:int, threshold_bytes:int, top:list<array<string, mixed>>}
     */
    private static function summarizeObjectProperties(int $limit = 25, int $thresholdBytes = 8192): array
    {
        $limit = \max(1, \min(100, $limit));
        $thresholdBytes = \max(0, $thresholdBytes);
        $seenObjects = [];
        $items = [];
        $objectsScanned = 0;

        foreach (self::getAllScopedInstanceBuckets() as $bucket) {
            foreach ($bucket as $ownerClass => $instance) {
                $objectId = \spl_object_id($instance);
                if (isset($seenObjects[$objectId])) {
                    continue;
                }
                $seenObjects[$objectId] = true;
                $objectsScanned++;

                try {
                    $reflection = new \ReflectionObject($instance);
                    $properties = $reflection->getProperties();
                } catch (\Throwable) {
                    continue;
                }

                foreach ($properties as $property) {
                    if (!self::isDiagnosticPropertyReadable($property)) {
                        continue;
                    }
                    try {
                        $property->setAccessible(true);
                        $value = $property->getValue($instance);
                    } catch (\Throwable) {
                        continue;
                    }

                    $visitedValues = 0;
                    $nestedSeenObjects = [$objectId => true];
                    $approxBytes = self::estimateDiagnosticValueSize($value, 0, $nestedSeenObjects, $visitedValues);
                    if ($approxBytes < $thresholdBytes) {
                        continue;
                    }

                    $items[] = [
                        'owner' => \is_string($ownerClass) ? $ownerClass : $instance::class,
                        'property' => $property->getName(),
                        'type' => \get_debug_type($value),
                        'count' => \is_countable($value) ? \count($value) : null,
                        'approx_bytes' => $approxBytes,
                    ];
                }
            }
        }

        \usort(
            $items,
            static fn(array $a, array $b): int => ((int)$b['approx_bytes']) <=> ((int)$a['approx_bytes'])
        );

        return [
            'objects_scanned' => $objectsScanned,
            'threshold_bytes' => $thresholdBytes,
            'top' => \array_slice($items, 0, $limit),
        ];
    }

    private static function isDiagnosticPropertyReadable(\ReflectionProperty $property): bool
    {
        if ($property->isStatic()) {
            return false;
        }

        if (\method_exists($property, 'isDeprecated') && $property->isDeprecated()) {
            return false;
        }

        return !$property->getDeclaringClass()->isInternal();
    }

    /**
     * @param array<int, bool> $seenObjects
     */
    private static function estimateDiagnosticValueSize(
        mixed $value,
        int $depth,
        array &$seenObjects,
        int &$visitedValues
    ): int {
        if ($visitedValues > 50000) {
            return 0;
        }
        $visitedValues++;

        if (\is_string($value)) {
            return \strlen($value);
        }
        if (\is_int($value) || \is_float($value) || \is_bool($value) || $value === null) {
            return 16;
        }
        if (\is_resource($value)) {
            return 32;
        }
        if (\is_array($value)) {
            if ($depth >= 5) {
                return \count($value) * 32;
            }
            $size = 16;
            foreach ($value as $key => $item) {
                $size += \is_string($key) ? \strlen($key) : 16;
                $size += self::estimateDiagnosticValueSize($item, $depth + 1, $seenObjects, $visitedValues);
                if ($visitedValues > 50000) {
                    break;
                }
            }
            return $size;
        }
        if (!\is_object($value)) {
            return 0;
        }

        $objectId = \spl_object_id($value);
        if (isset($seenObjects[$objectId])) {
            return 64;
        }
        $seenObjects[$objectId] = true;
        if ($depth >= 3) {
            return 128;
        }

        $size = 128;
        try {
            $reflection = new \ReflectionObject($value);
            if ($reflection->isInternal()) {
                return $size;
            }

            foreach ($reflection->getProperties() as $property) {
                if (!self::isDiagnosticPropertyReadable($property)) {
                    continue;
                }
                $property->setAccessible(true);
                $size += self::estimateDiagnosticValueSize(
                    $property->getValue($value),
                    $depth + 1,
                    $seenObjects,
                    $visitedValues
                );
                if ($visitedValues > 50000) {
                    break;
                }
            }
        } catch (\Throwable) {
        }

        return $size;
    }

    /**
     * @return list<array<string, object>>
     */
    private static function getAllScopedInstanceBuckets(): array
    {
        $buckets = [];

        if (self::$instances !== []) {
            $buckets[] = self::$instances;
        }
        if (self::$origin_instances !== []) {
            $buckets[] = self::$origin_instances;
        }

        if (self::$fiberInstances !== null) {
            foreach (self::$fiberInstances as $scope) {
                $instances = $scope instanceof RequestScope
                    ? $scope->all()
                    : (is_array($scope) ? $scope : []);
                if ($instances !== []) {
                    $buckets[] = $instances;
                }
            }
        }

        if (self::$fiberOriginInstances !== null) {
            foreach (self::$fiberOriginInstances as $scope) {
                $instances = $scope instanceof RequestScope
                    ? $scope->all()
                    : (is_array($scope) ? $scope : []);
                if ($instances !== []) {
                    $buckets[] = $instances;
                }
            }
        }

        return $buckets;
    }
    
    /**
     * 移除指定类的实例缓存
     * 
     * 用于 WLS 模式下需要在请求间重新创建的单例。
     * 
     * @param string $className 类名
     * @return void
     */
    public static function removeInstance(string $className): void
    {
        // 移除实例缓存
        self::removeScopedInstance($className);
        
        // 也尝试移除解析后的类名对应的实例
        if (isset(self::$parsedClasses[$className])) {
            $resolvedClass = self::$parsedClasses[$className];
            self::removeScopedInstance($resolvedClass);
        }
        
        // 移除原始实例
        self::removeScopedInstance($className, true);
    }

    /**
     * 解析类名
     *
     * @param string $class
     *
     * @return string
     * @throws \Weline\Framework\App\Exception
     */
    /**
     * 解析类名缓存
     * 格式：['ClassName' => 'ResolvedClassName']
     */
    private static array $parsedClasses = [];
    
    /**
     * 解析类名：处理拦截器和工厂类
     * 
     * PHP 8 性能优化：
     * - 缓存解析结果
     * - 使用缓存的 class_exists
     * - 减少字符串拼接
     * 
     * @param string $class 原始类名
     * @return string 解析后的类名
     * @throws Exception
     */
    public static function parserClass(string $class): string
    {
        self::loadGeneratedPluginRegistry();
        // PHP 8 性能优化：缓存解析结果，避免重复的 class_exists 调用
        if (isset(self::$parsedClasses[$class])) {
            return self::$parsedClasses[$class];
        }
        
        // 1. 拦截器处理（优先级最高）
        $interceptor = $class . '\\Interceptor';
        if (self::ensureRegisteredInterceptorAvailable($class, $interceptor) || self::cachedClassExists($interceptor)) {
            return self::$parsedClasses[$class] = $interceptor;
        }
        
        // 2. 工厂类处理（工厂类不存在时还原类）
        return self::$parsedClasses[$class] = self::initFactoryClass($class);
    }

    private static function loadGeneratedPluginRegistry(): void
    {
        if (self::canTrustGeneratedPluginRegistryForRuntime()) {
            return;
        }

        $file = BP . 'generated' . DIRECTORY_SEPARATOR . 'plugins.php';
        $mtime = \is_file($file) ? (int)\filemtime($file) : 0;

        if (self::$generatedPluginRegistryMtime === $mtime) {
            return;
        }

        if (self::$generatedPluginRegistryMtime !== null) {
            self::$parsedClasses = [];
            self::$classExistsCache = [];
        }

        self::$generatedPluginRegistryMtime = $mtime;
        self::$generatedPluginRegistry = null;

        if ($mtime === 0) {
            return;
        }

        $registry = @include $file;
        if (\is_array($registry)) {
            self::$generatedPluginRegistry = $registry;
        }
    }

    private static function canTrustGeneratedPluginRegistryForRuntime(): bool
    {
        return self::$generatedPluginRegistryMtime !== null
            && \class_exists(\Weline\Framework\Runtime\Runtime::class, false)
            && \Weline\Framework\Runtime\Runtime::isPersistent();
    }

    private static function classHasRegisteredPlugins(string $class): bool
    {
        self::loadGeneratedPluginRegistry();

        $classToPlugins = self::$generatedPluginRegistry['class_to_plugins'] ?? [];

        return \is_array($classToPlugins) && !empty($classToPlugins[$class]);
    }

    private static function getGeneratedInterceptorPath(string $interceptor): string
    {
        return BP . 'generated' . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR
            . \str_replace('\\', DIRECTORY_SEPARATOR, $interceptor) . '.php';
    }

    private static function ensureRegisteredInterceptorAvailable(string $class, string $interceptor): bool
    {
        if (\class_exists($interceptor, false)) {
            return true;
        }

        if (!self::classHasRegisteredPlugins($class)) {
            return false;
        }

        $interceptorPath = self::getGeneratedInterceptorPath($interceptor);
        if (\is_file($interceptorPath)) {
            unset(self::$classExistsCache[$interceptor]);

            return self::cachedClassExists($interceptor);
        }

        if (isset(self::$interceptorGenerationInProgress[$class])) {
            return false;
        }

        self::$interceptorGenerationInProgress[$class] = true;

        try {
            \Weline\Framework\Plugin\Proxy\Generator::createInterceptor($class);
        } catch (\Throwable) {
            return false;
        } finally {
            unset(self::$interceptorGenerationInProgress[$class]);
            unset(self::$classExistsCache[$interceptor]);
        }

        return \is_file($interceptorPath) && self::cachedClassExists($interceptor);
    }

    /**
     * 初始化类实例：执行 __init 方法和工厂类处理
     * 
     * 职责：单一职责原则 - 只负责实例初始化
     * 
     * @param string $class 类名
     * @param mixed $new_object 新创建的对象实例
     * @param bool $init_factory 是否初始化工厂类（调用 create 方法）
     * @return mixed 初始化后的对象实例
     */
    private static function initClassInstance(string $class, $new_object, bool $init_factory = true): mixed
    {
        // 1. 执行 __init 方法（如果存在）
        self::callInitMethod($new_object);
        
        // 2. 处理工厂类（如果需要）
        if ($init_factory && self::isFactoryClass($class)) {
            $new_object = self::processFactoryClass($class, $new_object);
            // 工厂类创建后，再次执行 __init（如果存在）
            self::callInitMethod($new_object);
        }
        
        return $new_object;
    }
    
    /**
     * 缓存 __init 方法存在性检测结果
     */
    private static array $initMethodCache = [];
    
    /**
     * 调用对象的 __init 方法（如果存在）
     * 
     * 性能优化：缓存方法存在性检测结果，避免每次都用 try-catch
     * 
     * @param mixed $object 对象实例
     * @return void
     */
    private static function callInitMethod($object): void
    {
        $className = $object::class;
        
        // 检查缓存
        if (!isset(self::$initMethodCache[$className])) {
            self::$initMethodCache[$className] = method_exists($object, '__init');
        }
        
        // 如果方法存在，调用它
        if (self::$initMethodCache[$className]) {
            $object->__init();
        }
    }
    
    /**
     * 检查是否为工厂类
     * 
     * @param string $class 类名
     * @return bool
     */
    private static function isFactoryClass(string $class): bool
    {
        return str_ends_with($class, 'Factory');
    }
    
    /**
     * 缓存 create 方法存在性检测结果
     */
    private static array $createMethodCache = [];
    
    /**
     * 处理工厂类：调用 create 方法创建实际对象
     * 
     * 性能优化：缓存方法存在性检测结果
     * 
     * @param string $class 工厂类名
     * @param mixed $factoryObject 工厂类实例
     * @return mixed 创建的对象实例
     */
    private static function processFactoryClass(string $class, $factoryObject): mixed
    {
        // 提前返回特殊处理：这些类名以 Factory 结尾但 create() 需要参数，实例已由 getInstance 提供
        if (self::isDbManagerFactory($class) || self::isConnectionFactory($class) || self::isParameterizedFactory($class)) {
            return $factoryObject;
        }
        
        $className = $factoryObject::class;
        
        // 检查缓存
        if (!isset(self::$createMethodCache[$className])) {
            self::$createMethodCache[$className] = method_exists($factoryObject, 'create');
        }
        
        // 如果 create 方法存在，调用它
        if (self::$createMethodCache[$className]) {
            return $factoryObject->create();
        }
        
        return $factoryObject;
    }
    
    /**
     * 检查是否为 ConnectionFactory（create(ConfigProvider) 需参数，不无参调用）
     */
    private static function isConnectionFactory(string $class): bool
    {
        return $class === \Weline\Framework\Database\ConnectionFactory::class;
    }
    
    /**
     * 需要参数的工厂类列表（create 方法需要参数，不能无参调用）
     */
    private static array $parameterizedFactories = [
        \Weline\Framework\Cache\AdapterFactory::class,
        \Weline\Framework\Session\SessionFactory::class,
    ];
    
    /**
     * 检查是否为需要参数的工厂类
     * 
     * @param string $class 类名
     * @return bool
     */
    private static function isParameterizedFactory(string $class): bool
    {
        return in_array($class, self::$parameterizedFactories, true);
    }
    
    /**
     * 检查是否为 DbManagerFactory
     * 
     * PHP 8 性能优化：使用 str_ends_with（PHP 8 原生函数，更快）
     * 
     * @param string $class 类名
     * @return bool
     */
    private static function isDbManagerFactory(string $class): bool
    {
        // PHP 8 性能优化：str_contains 和 str_ends_with 是原生函数，比手动查找更快
        return str_contains($class, 'DbManager') && str_ends_with($class, 'Factory');
    }

    /**
     * @Desc         | 创建实例并运行
     * @param        $class
     * @param string $method
     * @param array $params
     *
     * @return mixed
     * @throws \ReflectionException
     * @throws Exception
     */
    public static function make($class, array $params = [], string $method = '__construct'): mixed
    {
        // 拦截器处理
        $new_class = self::parserClass($class);
        
        // 检测是否为静态类，静态类禁止通过 ObjectManager 实例化
        if (self::isStaticClass($new_class)) {
            throw new Exception(__('不支持静态类实例化：%{1}。静态类应直接使用，无需通过 ObjectManager 实例化。', $new_class));
        }
        
        if ('__construct' === $method) {
            $instance = (new ReflectionClass($new_class));
            $method_params = self::getMethodParams($instance, $method);
            
            // 处理参数合并：正确处理依赖注入参数和data参数
            $final_params = [];
            $data_param = null;
            
            // 获取方法参数信息
            $method_ref = $instance->getMethod($method);
            $parameters = $method_ref->getParameters();
            
            // 合并依赖注入参数和传入的参数
            foreach ($parameters as $param_index => $param) {
                $param_value = self::resolveParameterValue(
                    $param,
                    $param_index,
                    $params,
                    $method_params,
                    $param_type_name ?? null
                );
                $final_params[] = $param_value;
            }
            
            // PHP 8 性能优化：如果data参数存在且最后一个参数是数组类型，使用data参数
            if ($data_param !== null && $parameters !== []) {
                $last_param = end($parameters);
                $lastType = $last_param?->getType();
                if (self::reflectionTypeIncludesName($lastType, 'array')) {
                    $final_params[count($parameters) - 1] = $data_param;
                }
            }
            
            // 移除空值检查，因为依赖注入的参数可能是有意义的空值
            // 但保留对null的检查，确保必需参数不为null
            $instance = $instance->newInstanceArgs($final_params);
            $instance = self::initClassInstance($class, $instance);
        } else {
            $instance = new ReflectionClass($new_class);
            $instance = self::initClassInstance($class, $instance);
            $paramArr = self::getMethodParams($instance, $method);
            $instance = $instance->{$method}(...array_merge($paramArr, $params));
        }
        return $instance;
    }

    /**
     * @Desc         | 创建实例并运行
     * @param        $class
     * @param string $method
     * @param array $params
     *
     * @return mixed
     * @throws \ReflectionException
     * @throws Exception
     */
    public static function makeWithoutFactory($class, array $params = [], string $method = '__construct'): mixed
    {
        // 拦截器处理
        if ('__construct' === $method) {
            $instance = (new ReflectionClass($class));
            $method_params = self::getMethodParams($instance, $method);
            foreach ($method_params as $key => $method_param) {
                if (empty($method_param)) {
                    unset($method_params[$key]);
                }
            }
            $method_params = array_merge($method_params, $params);
            $instance = $instance->newInstanceArgs($method_params);
            if (method_exists($instance, '__init')) {
                $instance->__init();
            }
        } else {
            $instance = new ReflectionClass($class);
            $paramArr = self::getMethodParams($instance, $method);
            $instance = $instance->{$method}(...array_merge($paramArr, $params));
            // 注意：这里 $instance 是 ReflectionClass 对象，不是实例，不需要调用 __init
        }
        return $instance;
    }

    /**
     * @Desc         | 获取方法参数,插件实现
     * @param        $className
     * @param string $methodsName
     *
     * @return array
     * @throws Exception
     */
    /**
     * 加载预编译反射元数据
     * 
     * 从 generated/reflection_metadata.php 加载预编译的类构造函数参数元数据，
     * 避免运行时反射开销。文件由 reflection:compile 命令生成。
     * 
     * 兼容性：如果文件不存在，回退到运行时反射（FPM/WLS 均兼容）
     */
    /**
     * Explicitly loads process-global generated runtime metadata for WLS preload.
     *
     * @return array<string, int>
     */
    public static function preloadRuntimeMetadata(): array
    {
        self::loadPrecompiledMetadata();
        self::loadCompiledFactories();
        self::loadGeneratedPluginRegistry();

        $safeClasses = [];
        $safeClassFile = BP . 'generated' . DIRECTORY_SEPARATOR . 'reflection_safe_classes.php';
        if (\is_file($safeClassFile)) {
            $loaded = include $safeClassFile;
            if (\is_array($loaded)) {
                $safeClasses = $loaded;
            }
        }

        return [
            'reflection_metadata' => \is_array(self::$precompiledMetadata) ? \count(self::$precompiledMetadata) : 0,
            'compiled_factories' => \is_array(self::$compiledFactories) ? \count(self::$compiledFactories) : 0,
            'plugin_classes' => \is_array(self::$generatedPluginRegistry['class_to_plugins'] ?? null)
                ? \count(self::$generatedPluginRegistry['class_to_plugins'])
                : 0,
            'reflection_safe_classes' => self::countNestedScalarValues($safeClasses),
        ];
    }

    private static function countNestedScalarValues(array $rows): int
    {
        $count = 0;
        foreach ($rows as $row) {
            if (\is_array($row)) {
                $count += self::countNestedScalarValues($row);
                continue;
            }
            if (\is_scalar($row)) {
                $count++;
            }
        }

        return $count;
    }

    private static function loadPrecompiledMetadata(): void
    {
        if (self::$precompiledLoaded) {
            return;
        }
        self::$precompiledLoaded = true;
        $file = BP . 'generated' . DIRECTORY_SEPARATOR . 'reflection_metadata.php';
        if (\is_file($file)) {
            $payload = include $file;
            if (\is_array($payload)
                && ($payload['format'] ?? null) === self::PRECOMPILED_METADATA_FORMAT
                && (int)($payload['php_version_id'] ?? 0) === \PHP_VERSION_ID
                && \is_array($payload['metadata'] ?? null)
                && \is_array($payload['source_signatures'] ?? null)
            ) {
                self::$precompiledMetadata = $payload['metadata'];
                self::$precompiledMetadataSourceSignatures = $payload['source_signatures'];
            }
        }
    }

    /**
     * 加载编译型工厂容器（从 generated/compiled_factories.php）
     * 文件不存在时保持 null，getInstance 回退到反射路径
     */
    private static function loadCompiledFactories(): void
    {
        if (self::$compiledFactoriesLoaded) {
            return;
        }
        self::$compiledFactoriesLoaded = true;
        $file = BP . 'generated' . DIRECTORY_SEPARATOR . 'compiled_factories.php';
        if (\is_file($file)) {
            $payload = include $file;
            if (\is_array($payload)
                && ($payload['format'] ?? null) === self::COMPILED_FACTORY_FORMAT
                && (int)($payload['php_version_id'] ?? 0) === \PHP_VERSION_ID
                && \is_array($payload['factories'] ?? null)
                && \is_array($payload['source_signatures'] ?? null)
            ) {
                self::$compiledFactories = $payload['factories'];
                self::$compiledFactorySourceSignatures = $payload['source_signatures'];
            }
        }
    }

    private static function compiledFactoryIsCurrent(string $className): bool
    {
        $expected = self::$compiledFactorySourceSignatures[$className] ?? null;
        if (!\is_string($expected)
            || \preg_match('/^[a-f0-9]{64}$/D', $expected) !== 1
            || !(self::$compiledFactories[$className] ?? null) instanceof \Closure
        ) {
            unset(self::$compiledFactories[$className], self::$compiledFactorySourceSignatures[$className]);
            return false;
        }

        $current = self::currentSourceSignature($className, null, true);
        if (!\is_string($current) || !\hash_equals($expected, $current)) {
            unset(self::$compiledFactories[$className], self::$compiledFactorySourceSignatures[$className]);
            return false;
        }

        return true;
    }

    private static function precompiledMetadataIsCurrent(string $className, string $methodName): bool
    {
        $cacheKey = $className . '::' . $methodName;
        $expected = self::$precompiledMetadataSourceSignatures[$cacheKey] ?? null;
        if (!\is_string($expected) || \preg_match('/^[a-f0-9]{64}$/D', $expected) !== 1) {
            return false;
        }

        $current = self::currentSourceSignature($className, $methodName, false);
        return \is_string($current) && \hash_equals($expected, $current);
    }

    private static function currentSourceSignature(
        string $className,
        ?string $methodName,
        bool $factoryMode,
    ): ?string {
        $cacheKey = $className . '::' . ($factoryMode ? '@factory' : (string)$methodName);
        if (\array_key_exists($cacheKey, self::$currentSourceSignatures)) {
            return self::$currentSourceSignatures[$cacheKey];
        }

        try {
            $class = self::getReflectionInstance($className);
            $method = null;
            if ($factoryMode) {
                $constructor = $class->getConstructor();
                if ($constructor !== null && $constructor->isPrivate() && $class->hasMethod('getInstance')) {
                    $candidate = $class->getMethod('getInstance');
                    if ($candidate->isStatic() && $candidate->getNumberOfRequiredParameters() === 0) {
                        $method = $candidate;
                    }
                }
                $method ??= $constructor;
            } elseif ($methodName !== null && $class->hasMethod($methodName)) {
                $method = $class->getMethod($methodName);
            }

            $files = [];
            $classFile = $class->getFileName();
            if (\is_string($classFile) && $classFile !== '') {
                $files[$classFile] = true;
            }
            if ($method instanceof \ReflectionMethod) {
                $declarationFile = $method->getDeclaringClass()->getFileName();
                if (\is_string($declarationFile) && $declarationFile !== '') {
                    $files[$declarationFile] = true;
                }
            }
            if ($files === []) {
                return self::$currentSourceSignatures[$cacheKey] = null;
            }

            $paths = \array_keys($files);
            \sort($paths, \SORT_STRING);
            $context = \hash_init('sha256');
            foreach ($paths as $path) {
                $digest = (string)@\hash_file('sha256', $path);
                if (\preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
                    return self::$currentSourceSignatures[$cacheKey] = null;
                }
                \hash_update($context, $digest . "\0");
            }

            return self::$currentSourceSignatures[$cacheKey] = \hash_final($context);
        } catch (\Throwable) {
            return self::$currentSourceSignatures[$cacheKey] = null;
        }
    }
    
    protected static function getMethodParams($instance_or_class, string $methodsName = '__construct'): array
    {
        // 获取类名
        $className = $instance_or_class instanceof ReflectionClass
            ? $instance_or_class->getName()
            : (is_object($instance_or_class) ? $instance_or_class::class : $instance_or_class);
        $cacheKey = $className . '::' . $methodsName;
        
        // 检查元数据缓存
        if (!isset(self::$methodParamsMetadata[$cacheKey])) {
            // 优先使用预编译元数据（避免运行时反射）
            self::loadPrecompiledMetadata();
            if (self::$precompiledMetadata !== null
                && isset(self::$precompiledMetadata[$cacheKey])
                && self::precompiledMetadataIsCurrent($className, $methodsName)
            ) {
                $metadata = self::$precompiledMetadata[$cacheKey];
            } else {
                // 回退到运行时反射解析
                $metadata = self::parseMethodParamsMetadata($instance_or_class, $methodsName);
            }
            self::$methodParamsMetadata[$cacheKey] = $metadata;
            
            // 优化：如果没有参数，直接返回空数组，不进行后续处理
            if (empty($metadata['params'])) {
                return [];
            }
        } else {
            // 如果已缓存且无参数，直接返回
            if (empty(self::$methodParamsMetadata[$cacheKey]['params'])) {
                return [];
            }
        }
        
        // 根据元数据和当前 self::$instances 状态生成实际参数
        return self::buildParamsFromMetadata(self::$methodParamsMetadata[$cacheKey], $className);
    }
    
    /**
     * 解析方法参数元数据（静态信息，可缓存）
     * 
     * @param mixed $instance_or_class 类名或实例
     * @param string $methodsName 方法名
     * @return array 元数据数组
     * @throws Exception
     */
    private static function parseMethodParamsMetadata($instance_or_class, string $methodsName): array
    {
        // 获取类名
        $className = $instance_or_class instanceof ReflectionClass
            ? $instance_or_class->getName()
            : (is_object($instance_or_class) ? $instance_or_class::class : $instance_or_class);
        
        // 先检查是否是接口（接口不能解析方法参数）
        if (interface_exists($className, true)) {
            // 获取调用堆栈信息
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
            $callerInfo = '';
            if (isset($backtrace[2])) {
                $caller = $backtrace[2];
                $callerFile = $caller['file'] ?? 'unknown';
                $callerLine = $caller['line'] ?? 'unknown';
                $callerFunction = $caller['function'] ?? 'unknown';
                $callerClass = $caller['class'] ?? '';
                $callerInfo = "\n调用位置: " . ($callerClass ? $callerClass . '::' : '') . $callerFunction . "()\n文件: " . str_replace(BP, '', $callerFile) . ":" . $callerLine;
            }
            throw new Exception("禁止实例化接口 {$className}，接口必须通过工厂类实例化。{$callerInfo}");
        }
        
        // 优化：使用缓存的 ReflectionClass，避免重复创建
        if (is_object($instance_or_class)) {
            $class = $instance_or_class;
        } else {
            // 使用缓存的 ReflectionClass
            $class = self::getReflectionInstance($className);
        }
        
        $metadata = [
            'className' => $className,
            'methodName' => $methodsName,
            'params' => []
        ];
        
        // 判断该类是否有函数
        if ($class->hasMethod($methodsName)) {
            try {
                $method = $class->getMethod($methodsName);
            } catch (\ReflectionException $e) {
                if (CLI or DEV) {
                    echo('无法实例化该类：' . $className . '，错误：' . $e->getMessage());
                }
                throw new Exception('无法获得对象方法：' . $methodsName . '，错误：' . $e->getMessage());
            }
            
            // PHP 8 性能优化：判断方法是否有参数（提前返回）
            $params = $method->getParameters();
            $paramCount = count($params);
            if ($paramCount > 0) {
                foreach ($params as $key => $param) {
                    $paramMeta = [
                        'index' => $key,
                        'name' => $param->getName(),
                        'type' => null,
                        'typeName' => null,
                        'hasDefault' => false,
                        'defaultValue' => null,
                        'isClass' => false
                    ];
                    
                    // 获取参数类型
                    if ($param->getType()) {
                        $type = $param->getType();
                        $paramMeta['type'] = $type;
                        $typeName = self::getClassReflectionTypeName($type) ?? self::getFirstNamedReflectionType($type);
                        
                        // PHP 8 性能优化：处理 Union Types 和可空类型
                        if ($type instanceof \ReflectionUnionType) {
                            // Union Types: 查找类类型（排除null）
                            $types = $type->getTypes();
                            foreach ($types as $unionType) {
                                $unionTypeName = self::getClassReflectionTypeName($unionType) ?? self::getFirstNamedReflectionType($unionType);
                                if (is_string($unionTypeName) && $unionTypeName !== 'null' && self::cachedClassExists($unionTypeName)) {
                                    $typeName = $unionTypeName;
                                    $paramMeta['isClass'] = true;
                                    break;
                                }
                            }
                        } elseif ($type instanceof \ReflectionNamedType && $type->allowsNull()) {
                            // PHP 8 可空类型：使用 ReflectionNamedType
                            if (self::cachedClassExists($typeName)) {
                                $paramMeta['isClass'] = true;
                            }
                        } elseif (self::cachedClassExists($typeName)) {
                            // 普通类类型
                            $paramMeta['isClass'] = true;
                        } else {
                            // 检查是否是接口
                            try {
                                $refType = new \ReflectionClass($typeName);
                                if ($refType->isInterface()) {
                                    $paramMeta['isClass'] = true;
                                }
                            } catch (\ReflectionException $e) {
                                // 非 FQN 时尝试按声明类命名空间解析短类名（避免 getInstance(ConfigProvider) 等被解析为 null）
                                $builtin = ['array', 'string', 'int', 'float', 'bool', 'object', 'mixed', 'null', 'false', 'true'];
                                if (strpos($typeName, '\\') === false && !in_array($typeName, $builtin, true)) {
                                    $declaringClass = $method->getDeclaringClass();
                                    $ns = $declaringClass->getNamespaceName();
                                    $candidates = [$ns . '\\' . $typeName];
                                    if (str_contains($ns, 'Database')) {
                                        $candidates[] = $ns . '\\DbManager\\' . $typeName;
                                    }
                                    foreach ($candidates as $fqn) {
                                        if (self::cachedClassExists($fqn)) {
                                            $typeName = $fqn;
                                            $paramMeta['isClass'] = true;
                                            break;
                                        }
                                    }
                                }
                            }
                        }
                        
                        $paramMeta['typeName'] = $typeName;
                    }
                    
                    // 检查是否有默认值
                    try {
                        if ($param->isDefaultValueAvailable()) {
                            $paramMeta['hasDefault'] = true;
                            $paramMeta['defaultValue'] = $param->getDefaultValue();
                        }
                    } catch (\Exception $e) {
                        // 忽略无法获取默认值的异常
                    }
                    
                    $metadata['params'][] = $paramMeta;
                }
            }
        }
        
        return $metadata;
    }
    
    /**
     * 根据元数据和当前 self::$instances 状态生成实际参数
     * 
     * @param array $metadata 元数据
     * @param string $className 类名（用于错误信息）
     * @return array 实际参数数组
     * @throws Exception
     */
    private static function buildParamsFromMetadata(array $metadata, string $className): array
    {
        $paramArr = [];
        
        // PHP 8 性能优化：验证元数据格式（提前返回）
        if (!isset($metadata['params']) || !is_array($metadata['params'])) {
            return [];
        }
        
        $params = $metadata['params'];
        $paramCount = count($params);
        
        // PHP 8 性能优化：预分配数组大小（如果可能）
        $paramArr = [];
        
        foreach ($params as $paramMeta) {
            // PHP 8 性能优化：类型检查提前返回
            if (!is_array($paramMeta)) {
                continue;
            }
            
            // PHP 8 性能优化：使用 ?? 操作符，减少 isset 检查
            $isClass = ($paramMeta['isClass'] ?? false) === true;
            $paramTypeName = $paramMeta['typeName'] ?? null;
            // 预编译 reflection_metadata 在生成时若 class_exists 未命中，会将合法类误标为 isClass=false，导致注入 null
            if (!$isClass && is_string($paramTypeName) && str_contains($paramTypeName, '\\')
                && (self::cachedClassExists($paramTypeName) || interface_exists($paramTypeName, true))) {
                $isClass = true;
            }

            if ($isClass) {
                // 类类型参数：检查实例是否已存在
                if (empty($paramTypeName)) {
                    // 如果类型名称为空，跳过该参数
                    continue;
                }
                
                // PHP 8 性能优化：使用 ?? 操作符，减少 isset 检查
                $existingDependency = self::getScopedInstance($paramTypeName);
                if ($existingDependency !== null) {
                    $paramArr[] = $existingDependency;
                } elseif (($paramMeta['hasDefault'] ?? false) === true) {
                    // Legacy reflection_metadata.php could not serialize a
                    // `new Service()` default and stored null instead. Recover
                    // that rare value from real reflection once, while a
                    // genuine nullable class default remains null.
                    $paramArr[] = self::resolveMetadataDefaultValue(
                        $metadata,
                        $paramMeta,
                        $className,
                        $isClass,
                    );
                } else {
                    // 递归获取依赖参数
                    try {
                        // 先检查是否为静态类
                        if (self::isStaticClass($paramTypeName)) {
                            throw new Exception(__('不支持静态类实例化：%{1}。静态类应直接使用，无需通过 ObjectManager 实例化。请修改构造函数，移除对静态类的依赖注入。', $paramTypeName));
                        }
                        
                        $actualClass = $paramTypeName;
                        $args = interface_exists($actualClass, true)
                            ? []
                            : self::getMethodParams($actualClass);
                        // 接口由 module.php provides 权威解析；具体类沿用原 DI。
                        try {
                            $newObj = ObjectManager::getInstance($actualClass, $args);
                        } catch (\ReflectionException $e) {
                            if (CLI or DEV) {
                                echo('无法实例化该类：' . $actualClass . '，错误：' . $e->getMessage());
                            }
                            throw new Exception('无法实例化该类：' . $paramTypeName . '，错误：' . $e->getMessage());
                        }
                        // PHP 8 性能优化：直接调用 __init，使用 try-catch 处理不存在的情况
                        try {
                            $newObj->__init();
                        } catch (\Error $e) {
                            // __init 方法不存在，忽略
                        }
                        // 处理 Factory 类
                        if ($newObj instanceof FactoryObjectInterface) {
                            $instance = $newObj->create();
                            self::setScopedInstance($paramTypeName, $instance);
                            self::setScopedInstance($actualClass, $newObj);
                            $paramArr[] = $instance;
                        } else {
                            self::setScopedInstance($paramTypeName, $newObj);
                            $paramArr[] = $newObj;
                        }
                    } catch (\Exception $e) {
                        // 如果实例化失败，记录错误但继续处理其他参数
                        if (CLI or DEV) {
                            echo('警告：无法实例化依赖类 ' . $paramTypeName . '：' . $e->getMessage() . "\n");
                        }
                        // 如果参数没有默认值，抛出异常
                        if (!isset($paramMeta['hasDefault']) || !$paramMeta['hasDefault']) {
                            throw new Exception('无法实例化该类：' . $paramTypeName . '，错误：' . $e->getMessage());
                        }
                    }
                }
            } else {
                // PHP 8 性能优化：非类类型参数处理
                $hasDefault = ($paramMeta['hasDefault'] ?? false) === true;
                $typeName = $paramMeta['typeName'] ?? null;
                
                if ($hasDefault) {
                    $defaultValue = self::resolveMetadataDefaultValue(
                        $metadata,
                        $paramMeta,
                        $className,
                        $isClass,
                    );
                    // PHP 8 性能优化：确保数组类型参数的默认值是数组
                    if ($typeName === 'array' && !is_array($defaultValue)) {
                        $defaultValue = [];
                    }
                    $paramArr[] = $defaultValue;
                } else {
                    // PHP 8 性能优化：根据类型名称直接返回
                    $paramArr[] = ($typeName === 'array') ? [] : null;
                }
            }
        }
        
        return $paramArr;
    }

    /**
     * Restore object defaults lost by older precompiled reflection metadata.
     *
     * Metadata generated before defaultValueCaptured existed cannot
     * distinguish `Type $value = new Type()` from `?Type $value = null`.
     * Reflection is therefore used only for the ambiguous class/null shape and
     * cached for the lifetime of the process. All ordinary metadata stays on
     * the zero-reflection path.
     */
    private static function resolveMetadataDefaultValue(
        array $metadata,
        array $paramMeta,
        string $className,
        bool $isClass,
    ): mixed {
        $defaultValue = $paramMeta['defaultValue'] ?? null;
        $captured = $paramMeta['defaultValueCaptured'] ?? !(
            $isClass
            && ($paramMeta['hasDefault'] ?? false) === true
            && $defaultValue === null
        );
        if ($captured) {
            return $defaultValue;
        }

        $methodName = (string)($metadata['methodName'] ?? '__construct');
        $parameterIndex = (int)($paramMeta['index'] ?? -1);
        $parameterName = (string)($paramMeta['name'] ?? '');
        $cacheKey = $className . '::' . $methodName . ':' . $parameterIndex . ':' . $parameterName;
        if (\array_key_exists($cacheKey, self::$metadataDefaultValueCache)) {
            return self::$metadataDefaultValueCache[$cacheKey];
        }

        try {
            $class = self::getReflectionInstance($className);
            if (!$class->hasMethod($methodName)) {
                return self::$metadataDefaultValueCache[$cacheKey] = $defaultValue;
            }
            $parameters = $class->getMethod($methodName)->getParameters();
            $parameter = $parameters[$parameterIndex] ?? null;
            if (!$parameter instanceof \ReflectionParameter
                || ($parameterName !== '' && $parameter->getName() !== $parameterName)
            ) {
                $parameter = null;
                foreach ($parameters as $candidate) {
                    if ($candidate->getName() === $parameterName) {
                        $parameter = $candidate;
                        break;
                    }
                }
            }
            if (!$parameter instanceof \ReflectionParameter || !$parameter->isDefaultValueAvailable()) {
                return self::$metadataDefaultValueCache[$cacheKey] = $defaultValue;
            }
            return self::$metadataDefaultValueCache[$cacheKey] = $parameter->getDefaultValue();
        } catch (\Throwable $throwable) {
            throw new \RuntimeException(
                "Unable to restore compiled default value for {$className}::{$methodName}({$parameterName}).",
                0,
                $throwable,
            );
        }
    }

    /**
     * 解析单个参数的值：按优先级合并用户参数、依赖注入参数、默认值
     * 
     * 职责：单一职责原则 - 只负责单个参数的解析
     * 
     * @param \ReflectionParameter $param 反射参数对象
     * @param int $param_index 参数索引
     * @param array $userParams 用户提供的参数
     * @param array $diParams 依赖注入参数
     * @param string|null $param_type_name 参数类型名称
     * @return mixed 参数值
     * @throws Exception
     */
    private static function resolveParameterValue(
        \ReflectionParameter $param,
        int $param_index,
        array &$userParams,
        array $diParams,
        ?string $param_type_name
    ): mixed {
        $param_name = $param->getName();
        $param_type = $param->getType();
        $param_type_name = self::getClassReflectionTypeName($param_type) ?? self::getFirstNamedReflectionType($param_type);
        
        // 优先级1：用户提供的参数（按名称）
        if (isset($userParams[$param_name])) {
            $value = $userParams[$param_name];
            unset($userParams[$param_name]);
            return self::ensureParameterType($value, $param_type_name);
        }
        
        // 优先级2：用户提供的参数（按索引）
        if (isset($userParams[$param_index])) {
            $value = $userParams[$param_index];
            unset($userParams[$param_index]);
            return self::ensureParameterType($value, $param_type_name);
        }
        
        // 优先级3：依赖注入的参数
        if (isset($diParams[$param_index]) && $diParams[$param_index] !== null) {
            $value = $diParams[$param_index];
            return self::validateAndFixParameterType($value, $param_type, $param_type_name, $param_name);
        }
        
        // 优先级4：参数默认值
        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }
        
        // 优先级5：通过依赖注入创建对象（如果是类类型）
        if ($param_type && $param_type_name !== 'array') {
            try {
                // 如果是接口，尝试查找对应的工厂类
                try {
                    $refParamType = new \ReflectionClass($param_type_name);
                    if ($refParamType->isInterface()) {
                        $factoryClass = $param_type_name . 'Factory';
                        if (self::cachedClassExists($factoryClass)) {
                            $factory = self::getInstance($factoryClass, [], true, false);
                            if ($factory instanceof FactoryObjectInterface) {
                                return $factory->create();
                            }
                        }
                    }
                } catch (\ReflectionException $e) {
                    // 不是接口或类不存在，继续正常流程
                }
                return self::getInstance($param_type_name);
            } catch (\Exception $e) {
                throw new Exception("无法解析构造函数参数 '{$param_name}' (类型: {$param_type_name})，错误: " . $e->getMessage());
            }
        }
        
        // 优先级6：数组类型参数，使用空数组
        return [];
    }
    
    /**
     * 确保参数类型正确（数组类型检查）
     * 
     * PHP 8 性能优化：使用严格比较，提前返回
     * 
     * @param mixed $value 参数值
     * @param string|null $type_name 类型名称
     * @return mixed 修正后的值
     */
    private static function ensureParameterType(mixed $value, ?string $type_name): mixed
    {
        // PHP 8 性能优化：严格比较，提前返回
        if ($type_name === 'array' && !is_array($value)) {
            return [];
        }
        return $value;
    }
    
    /**
     * 验证并修正参数类型（类类型和数组类型）
     * 
     * PHP 8 性能优化：
     * - 使用缓存的 class_exists
     * - 提前返回
     * - 减少类型检查
     * 
     * @param mixed $value 参数值
     * @param \ReflectionType|null $param_type 反射类型
     * @param string|null $type_name 类型名称
     * @param string $param_name 参数名称
     * @return mixed 修正后的值
     * @throws Exception
     */
    private static function validateAndFixParameterType(
        mixed $value,
        ?\ReflectionType $param_type,
        ?string $type_name,
        string $param_name
    ): mixed {
        // PHP 8 性能优化：数组类型检查提前返回
        if ($type_name === 'array' && !is_array($value)) {
            return [];
        }
        
        // 类类型参数验证
        if ($param_type && $type_name && self::cachedClassExists($type_name)) {
            if (!is_object($value)) {
                // 依赖注入返回的不是对象，尝试重新实例化
                try {
                    return self::getInstance($type_name);
                } catch (\Exception $e) {
                    throw new Exception("无法解析构造函数参数 '{$param_name}' (类型: {$type_name})，依赖注入返回了 " . gettype($value) . "，错误: " . $e->getMessage());
                }
            }
            if (!($value instanceof $type_name)) {
                // 对象类型不匹配，尝试重新实例化
                try {
                    return self::getInstance($type_name);
                } catch (\Exception $e) {
                    throw new Exception("无法解析构造函数参数 '{$param_name}' (类型: {$type_name})，对象类型不匹配，错误: " . $e->getMessage());
                }
            }
        }
        
        return $value;
    }

    /**
     * 读取类反射（实例方法，用于子类扩展）
     *
     * @param string $class 类名
     * @return ReflectionClass 反射类对象
     * @throws \ReflectionException
     */
    protected function getReflectionClass($class): ReflectionClass
    {
        return new \ReflectionClass($class);
    }
}
