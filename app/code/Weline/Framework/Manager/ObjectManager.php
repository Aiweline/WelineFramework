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
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\Adapter\FileAdapter;
use Weline\Framework\Cache\Pool\CachePool;
use Weline\Framework\Manager\FactoryObjectInterface;

class ObjectManager implements ManagerInterface
{
    public const unserializable_class = [
        \PDO::class,
        \WeakMap::class
    ];
    private static ?CachePoolInterface $cache = null;

    private static ?ObjectManager $instance = null;

    private static array $instances = [];
    private static array $reflections = [];
    private static array $origin_instances = [];
    /**
     * 方法参数元数据缓存
     * 格式：['ClassName::methodName' => ['params' => [...], 'dependencies' => [...]]]
     */
    private static array $methodParamsMetadata = [];
    
    /**
     * 预编译反射元数据（从 generated/reflection_metadata.php 加载）
     * 性能优化：避免 FPM 模式下每次请求都执行反射操作
     */
    private static ?array $precompiledMetadata = null;
    
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

    /**
     * 编译型工厂是否已尝试加载
     */
    private static bool $compiledFactoriesLoaded = false;

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
        if ($shared && isset(self::$instances[$class])) {
            return self::$instances[$class];
        }
        
        // PHP 8 性能优化：合并条件判断，减少函数调用
        if ($cache && !CLI && $shared) {
            $cachedObject = self::getCache()->get($class);
            if ($cachedObject) {
                return self::$instances[$class] = self::initClassInstance($class, $cachedObject);
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
        
        // 如果确认是接口，使用工厂类创建实现类实例
        if ($isInterface) {
            // 确保工厂类存在（使用class_exists确保autoload生效）
            if (class_exists($factoryClass, true)) {
                // 直接实例化工厂类，不经过initClassInstance（避免自动调用create）
                // 我们需要工厂类实例，而不是实现类实例
                $factory_new_class = self::parserClass($factoryClass);
                $factory_refClass = self::getReflectionInstance($factory_new_class);
                $factory = self::instantiateObject($factory_refClass, $factory_new_class, []);
                // 只调用__init，不调用processFactoryClass
                self::callInitMethod($factory);
                
                if ($factory instanceof FactoryObjectInterface) {
                    $instance = $factory->create();
                    // 缓存接口实例（实际缓存的是实现类实例）
                    if ($shared) {
                        self::$instances[$class] = $instance;
                    }
                    return $instance;
                } else {
                    throw new Exception("工厂类 {$factoryClass} 没有实现 FactoryObjectInterface 接口");
                }
            } else {
                // 接口存在但没有工厂类，抛出异常
                throw new Exception("接口 {$class} 没有对应的工厂类 {$factoryClass}，无法实例化");
            }
        }
        
        // 解析类名（处理拦截器和工厂类）
        $new_class = self::parserClass($class);
        
        // 编译工厂快速路径：无参且存在编译闭包时直接实例化，跳过所有反射
        if ($arguments === []) {
            self::loadCompiledFactories();
            if (self::$compiledFactories !== null && isset(self::$compiledFactories[$new_class])) {
                $new_object = (self::$compiledFactories[$new_class])();
                $new_object = self::initClassInstance($class, $new_object);
                self::$instances[$class] = $new_object;
                if ($cache && !CLI && !in_array($class, self::unserializable_class, true)) {
                    self::getCache()->set($class, $new_object);
                }
                return $new_object;
            }
        }
        
        // 再次检查解析后的类名是否是接口（防止parserClass返回接口）
        // 必须在isStaticClass之前检查，因为isStaticClass会调用getReflectionInstance
        if (interface_exists($new_class, true)) {
            $factoryClass = $new_class . 'Factory';
            if (self::cachedClassExists($factoryClass)) {
                // 直接实例化工厂类，不经过initClassInstance（避免自动调用create）
                $factory_new_class = self::parserClass($factoryClass);
                $factory_refClass = self::getReflectionInstance($factory_new_class);
                $factory = self::instantiateObject($factory_refClass, $factory_new_class, []);
                // 只调用__init，不调用processFactoryClass
                self::callInitMethod($factory);
                
                if ($factory instanceof FactoryObjectInterface) {
                    $instance = $factory->create();
                    if ($shared) {
                        self::$instances[$class] = $instance;
                        self::$instances[$new_class] = $instance;
                    }
                    return $instance;
                } else {
                    throw new Exception("工厂类 {$factoryClass} 没有实现 FactoryObjectInterface 接口");
                }
            } else {
                throw new Exception("接口 {$new_class} 没有对应的工厂类 {$factoryClass}，无法实例化");
            }
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
        self::$instances[$class] = $new_object;
        
        // 缓存到文件（如果需要）
        if ($cache && !CLI && !in_array($class, self::unserializable_class, true)) {
            self::getCache()->set($class, $new_object);
        }
        
        return $new_object;
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
        if (isset(self::$origin_instances[$class])) {
            return self::$origin_instances[$class];
        }
        
        // PHP 8 性能优化：合并条件判断
        if ($cache && !CLI && $shared) {
            $cachedObject = self::getCache()->get($class);
            if ($cachedObject) {
                return self::$origin_instances[$class] = self::initClassInstance($class, $cachedObject, false);
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

        self::$origin_instances[$class] = $new_object;
        
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
        self::$instances[$class] = $object;
        return true;
    }

    public static function addInstance($class, &$object)
    {
        self::$instances[$class] = $object;
    }

    public static function _getInstance($class)
    {
        return self::$instances[$class] ?? null;
    }

    public static function getInstances(): array
    {
        return self::$instances;
    }

    /**
     * 清理所有实例缓存（用于热重载）
     */
    public static function clearInstances(): void
    {
        self::$instances = [];
        self::$reflections = [];
        self::$origin_instances = [];
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
        unset(self::$instances[$className]);
        
        // 也尝试移除解析后的类名对应的实例
        if (isset(self::$parsedClasses[$className])) {
            $resolvedClass = self::$parsedClasses[$className];
            unset(self::$instances[$resolvedClass]);
        }
        
        // 移除原始实例
        unset(self::$origin_instances[$className]);
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
        // PHP 8 性能优化：缓存解析结果，避免重复的 class_exists 调用
        if (isset(self::$parsedClasses[$class])) {
            return self::$parsedClasses[$class];
        }
        
        // 1. 拦截器处理（优先级最高）
        $interceptor = $class . '\\Interceptor';
        if (self::cachedClassExists($interceptor)) {
            return self::$parsedClasses[$class] = $interceptor;
        }
        
        // 2. 工厂类处理（工厂类不存在时还原类）
        return self::$parsedClasses[$class] = self::initFactoryClass($class);
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
                if ($lastType && $lastType->getName() === 'array') {
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
    private static function loadPrecompiledMetadata(): void
    {
        if (self::$precompiledLoaded) {
            return;
        }
        self::$precompiledLoaded = true;
        $file = BP . 'generated' . DIRECTORY_SEPARATOR . 'reflection_metadata.php';
        if (\is_file($file)) {
            self::$precompiledMetadata = include $file;
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
            self::$compiledFactories = include $file;
        }
    }
    
    protected static function getMethodParams($instance_or_class, string $methodsName = '__construct'): array
    {
        // 获取类名
        $className = is_object($instance_or_class) ? $instance_or_class::class : $instance_or_class;
        $cacheKey = $className . '::' . $methodsName;
        
        // 检查元数据缓存
        if (!isset(self::$methodParamsMetadata[$cacheKey])) {
            // 优先使用预编译元数据（避免运行时反射）
            self::loadPrecompiledMetadata();
            if (self::$precompiledMetadata !== null && isset(self::$precompiledMetadata[$cacheKey])) {
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
        $className = is_object($instance_or_class) ? $instance_or_class::class : $instance_or_class;
        
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
                        $typeName = $type->getName();
                        
                        // PHP 8 性能优化：处理 Union Types 和可空类型
                        if ($type instanceof \ReflectionUnionType) {
                            // Union Types: 查找类类型（排除null）
                            $types = $type->getTypes();
                            foreach ($types as $unionType) {
                                $unionTypeName = $unionType->getName();
                                if ($unionTypeName !== 'null' && self::cachedClassExists($unionTypeName)) {
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
            
            if ($isClass) {
                // 类类型参数：检查实例是否已存在
                $paramTypeName = $paramMeta['typeName'] ?? null;
                if (empty($paramTypeName)) {
                    // 如果类型名称为空，跳过该参数
                    continue;
                }
                
                // PHP 8 性能优化：使用 ?? 操作符，减少 isset 检查
                if (isset(self::$instances[$paramTypeName])) {
                    $paramArr[] = self::$instances[$paramTypeName];
                } elseif (($paramMeta['hasDefault'] ?? false) === true) {
                    // 如果参数有默认值且实例不存在，使用默认值（避免不必要的实例化）
                    $paramArr[] = $paramMeta['defaultValue'] ?? null;
                } else {
                    // 递归获取依赖参数
                    try {
                        // 先检查是否为静态类
                        if (self::isStaticClass($paramTypeName)) {
                            throw new Exception(__('不支持静态类实例化：%{1}。静态类应直接使用，无需通过 ObjectManager 实例化。请修改构造函数，移除对静态类的依赖注入。', $paramTypeName));
                        }
                        
                        // 如果是接口，尝试查找对应的工厂类
                        $actualClass = $paramTypeName;
                        try {
                            $refParamType = new \ReflectionClass($paramTypeName);
                            if ($refParamType->isInterface()) {
                                $factoryClass = $paramTypeName . 'Factory';
                                if (self::cachedClassExists($factoryClass)) {
                                    $actualClass = $factoryClass;
                                } else {
                                    // 如果找不到工厂类，抛出明确的异常
                                    throw new Exception("接口 {$paramTypeName} 没有对应的工厂类 {$factoryClass}，无法实例化。请创建工厂类或使用其他方式获取实例。");
                                }
                            }
                        } catch (\ReflectionException $e) {
                            // 不是接口或类不存在，继续正常流程
                        } catch (\Exception $e) {
                            // 如果是接口但没有工厂类，直接抛出异常
                            throw $e;
                        }
                        
                        $args = self::getMethodParams($actualClass);
                        // 实例化依赖对象
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
                            self::$instances[$paramTypeName] = $instance;
                            self::$instances[$actualClass] = $newObj;
                            $paramArr[] = $instance;
                        } else {
                            self::$instances[$paramTypeName] = $newObj;
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
                    $defaultValue = $paramMeta['defaultValue'] ?? null;
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
        $param_type_name = $param_type ? $param_type->getName() : null;
        
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
