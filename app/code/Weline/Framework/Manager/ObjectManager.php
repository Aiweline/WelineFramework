<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Manager;

use ReflectionClass;
use Weline\Framework\App\Exception;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Manager\Cache\ObjectCache;

class ObjectManager implements ManagerInterface
{
    public const unserializable_class = [
        \PDO::class,
        \WeakMap::class
    ];
    private static ?CacheInterface $cache = null;

    private static ?ObjectManager $instance = null;

    private static array $instances = [];
    private static array $reflections = [];
    private static array $origin_instances = [];

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

    private static function getCache(): CacheInterface
    {
        if (empty(self::$cache)) {
            self::$cache = (new ObjectCache())->create();
        }
        return self::$cache;
    }

    private static function initFactoryClass(string $class): string
    {
        if (!class_exists($class)) {
            if (str_ends_with($class, 'Factory')) {
                $class = substr($class, 0, strrpos($class, 'Factory'));
            }
            if (!class_exists($class)) {
                throw new Exception("工厂类：{$class} 不存在！");
            }
        }
        return $class;
    }

    public static function getReflectionInstance(string|object $class): ReflectionClass
    {
        if (is_object($class)) {
            $class = get_class($class);
        }
        if (!isset(self::$reflections[$class])) {
            self::$reflections[$class] = new \ReflectionClass($class);
        }
        return self::$reflections[$class];
    }

    /**
     * @DESC          # 获取实例(如果不填写class则获取对象管理器本身，如果填写则获取class实例)
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
     * @throws null
     */
    public static function getInstance(string $class = '', array $arguments = [], bool $shared = true, bool $cache = false): mixed
    {
        if (empty($class)) {
            return self::$instance = new self();
        }
        if (empty(self::$instance)) {
            self::$instance = new self();
        }
        if (isset(self::$instances[$class]) && $obj = self::$instances[$class]) {
            /*if (method_exists($obj, '__init')) {
                $obj->__init();
            }*/
            return $obj;
        }
        // 缓存对象读取
        if ($cache && !CLI && $shared && $cache_class_object = self::getCache()->get($class)) {
            self::$instances[$class] = self::initClassInstance($class, $cache_class_object);
            return self::$instances[$class];
        }
        // 类名规则处理
        $new_class = self::parserClass($class);

        //        $class_cache_key = $class . '_cache_key';
        //        $new_class       = self::$cache->get($class_cache_key);
        //        if (empty($new_class)) {
        //            $new_class = self::parserClass($class);
        //            self::$cache->set($class_cache_key, $new_class);
        //        }

        # 兼容原类参数，做到只修改特定参数
        if ($arguments) {
            if (isset(self::getMethodParams($new_class)[0]) && self::getMethodParams($new_class)[0]) {
                $arguments = array_merge(self::getMethodParams($new_class), [$arguments]);
            }
        } else {
            $arguments = self::getMethodParams($new_class);
        }
//        if (str_contains($new_class, 'Weline\Console\Console\Console\Listing')) {
//            p($arguments);
//        }
        $refClass = self::$reflections[$class] ?? self::$reflections[$class] = new ReflectionClass($new_class);
        //        if ($refClass->isAbstract()) {
        //            throw new Exception('抽象类无法被实例化：'.$class);
        //        }
        // self::$reflections[$class] = $refClass;
        //        p($refClass->getAttributes());
        $new_object = $refClass->newInstanceArgs($arguments);
        //        $new_object->filename = $refClass->getFileName();
        /*$classAttrs = $refClass->getAttributes();
        foreach ($classAttrs as $key => $classAttr) {
            $value = $classAttr->getName();
            if ($value === $scanAnno) {
                $refProperties =  $refClass->getProperties();
                $obj = $refClass->newInstance();
                foreach ($refProperties as $key => $refProperty) {
                    $refProperty = $refClass->getProperty($refProperty->getName());
                    $propertyAttrs = $refProperty->getAttributes();
                    $value = $propertyAttrs[0]->getArguments();
                    $refProperty->setValue($obj, $value[0]);
                    $container[$class] = $obj;
                }
            }
        }*/
        $new_object = self::initClassInstance($class, $new_object);

        self::addInstance($class, $new_object);
        // 缓存可缓存对象
        if ($cache && !CLI && !in_array($class, self::unserializable_class)) {
            self::getCache()->set($class, self::$instances[$class]);
        };
        return self::_getInstance($class);
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
    public static function getOriginInstance(string $class, array $arguments = [], bool $shared = true, bool $cache = false): mixed
    {
        if (empty($class)) {
            return self::$instance = new self();
        }
        if (empty(self::$instance)) {
            self::$instance = new self();
        }
        if (isset(self::$origin_instances['' . $class]) && $obj = self::$origin_instances[$class]) {
            return $obj;
        }
        // 缓存对象读取
        if ($cache && !CLI && $shared && $cache_class_object = self::getCache()->get($class)) {
            self::$origin_instances[$class] = self::initClassInstance($class, $cache_class_object, false);
            return self::$origin_instances[$class];
        }
        // 类名规则处理
        $class_cache_key = $class . '_cache_key';
        $new_class = self::$cache->get($class_cache_key);
        if (empty($new_class)) {
            $new_class = self::parserClass($class);
            self::$cache->set($class_cache_key, $new_class);
        }
        $arguments = $arguments ?: self::getMethodParams($new_class);
        if ($new_class == 'Kte\GeoDataToOpencart\Console\Cache\GeoCacheFactory') {
            p($arguments);
        }
        $refClass = (new \ReflectionClass($new_class));
        //        p($refClass->getAttributes());
        $new_object = $refClass->newInstanceArgs($arguments);
        /*$classAttrs = $refClass->getAttributes();
        foreach ($classAttrs as $key => $classAttr) {
            $value = $classAttr->getName();
            if ($value === $scanAnno) {
                $refProperties =  $refClass->getProperties();
                $obj = $refClass->newInstance();
                foreach ($refProperties as $key => $refProperty) {
                    $refProperty = $refClass->getProperty($refProperty->getName());
                    $propertyAttrs = $refProperty->getAttributes();
                    $value = $propertyAttrs[0]->getArguments();
                    $refProperty->setValue($obj, $value[0]);
                    $container[$class] = $obj;
                }
            }
        }*/
        $new_object = self::initClassInstance($class, $new_object, false);

        self::$origin_instances[$class] = $new_object;
        // 缓存可缓存对象
        if ($cache && !CLI && !in_array($class, self::unserializable_class)) {
            self::getCache()->set($class, self::$origin_instances[$class]);
        };

        return self::$origin_instances[$class];
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
        return self::$instances[$class];
    }

    public static function getInstances(): array
    {
        return self::$instances;
    }

    /**
     * 解析类名
     *
     * @param string $class
     *
     * @return string
     * @throws \Weline\Framework\App\Exception
     */
    public static function parserClass(string $class): string
    {
        // 拦截器处理
        $interceptor = $class . '\\Interceptor';
        if (class_exists($interceptor)) {
            return $interceptor;
        }
        // 工厂类处理 工厂类不存在时还原类
        return self::initFactoryClass($class);
    }

    /**
     * 初始化类实例
     *
     * @param string $class
     * @param        $new_object
     * @param bool $init_factory
     *
     * @return mixed
     */
    private static function initClassInstance(string $class, $new_object, bool $init_factory = true): mixed
    {
        $init_method_name = '__init';
        if (method_exists($new_object, $init_method_name)) {
            $new_object->__init();
        }
        //        if ($class == 'Weline\DeveloperWorkspace\Block\Catalogs') {
        //            p($new_object->__init());
        //        }
        // 工厂类
        if (str_ends_with($class, 'Factory') && $init_factory) {
            $create_method = 'create';
            if (method_exists($new_object, $create_method)) {
                $new_object = $new_object->$create_method();
            }
            if (method_exists($new_object, $init_method_name)) {
                $new_object->__init();
                return $new_object;
            }
        }
        return $new_object;
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
        if ('__construct' === $method) {
            $instance = (new ReflectionClass($new_class));
            $method_params = self::getMethodParams($instance, $method);
            foreach ($method_params as $key => $method_param) {
                if (empty($method_param)) {
                    unset($method_params[$key]);
                }
            }
            $method_params = array_merge($method_params, $params);
            $instance = $instance->newInstanceArgs($method_params);
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
            if (method_exists($instance, '__init')) {
                $instance->__init();
            }
            $paramArr = self::getMethodParams($instance, $method);
            $instance = $instance->{$method}(...array_merge($paramArr, $params));
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
    protected static function getMethodParams($instance_or_class, string $methodsName = '__construct'): array
    {
        // 通过反射获得该类
        if (is_object($instance_or_class)) {
            $className = $instance_or_class::class;
            $class = $instance_or_class;
        } else {
            $className = $instance_or_class;
            try {
                $class = new ReflectionClass($className);
            } catch (\ReflectionException $e) {
                throw new Exception('无法实例化该类：' . $className . '，错误：' . $e->getMessage());
            }
        }
        $paramArr = []; // 记录参数，和参数类型（例如：class,string等）
        // 判断该类是否有函数
        if ($class->hasMethod($methodsName)) {
            // 获得构造函数
            try {
                $construct = $class->getMethod($methodsName);
            } catch (\ReflectionException $e) {
                if (CLI or DEV) {
                    echo('无法实例化该类：' . $className . '，错误：' . $e->getMessage());
                }
                throw new Exception('无法获得对象方法：' . $methodsName . '，错误：' . $e->getMessage());
            }
            // 判断构造函数是否有参数
            $params = $construct->getParameters();
            if (count($params) > 0) {
                // 判断参数类型
                foreach ($params as $key => $param) {
                    if ($param->getType() && class_exists($param->getType()->getName())) {
                        // 获得参数类型名称
                        $paramTypeName = $param->getType()->getName();
                        if (isset(self::$instances[$paramTypeName])) {
                            $paramArr[] = self::$instances[$paramTypeName];
                        } else {
                            // 获得参数类型
                            $args = self::getMethodParams($paramTypeName);
//                            if($instance_or_class == 'Weline\I18n\Observer\GetWordsFile'){
//                                dd($params);
//                                dd(self::$instances);
//                            }
//                            if($instance_or_class == 'Weline\I18n\Model\I18n'){
//                                dd($params[1]->getType()->getName());
//                            }
//                            if($instance_or_class == 'Weline\I18n\Model\I18n'){
//                                dd($params[1]->getType()->getName());
//                            }
                            // 实例化时执行自定义__init方法
                            try {
                                $newObj = ObjectManager::getInstance($paramTypeName, $args);
//                                $newObj = (new ReflectionClass(self::parserClass($paramTypeName)))->newInstanceArgs($args);
                            } catch (\ReflectionException $e) {
                                if (CLI or DEV) {
                                    echo('无法实例化该类：' . $className . '，错误：' . $e->getMessage());
                                }
                                throw new Exception('无法实例化该类：' . $paramTypeName . '，错误：' . $e->getMessage());
                            }
                            if (method_exists($newObj, '__init')) {
                                $newObj->__init();
                            }
                            if (str_ends_with($newObj::class, 'Factory')) {
                                self::$instances[$paramTypeName] = $newObj;
                                self::$instances[rtrim($paramTypeName, 'Factory')] = $newObj->create();;
                            } else {
                                self::$instances[$paramTypeName] = $newObj;
                            }
                            $paramArr[] = $newObj;
                        }
                    } else {
                        try {
                            if ($param->isDefaultValueAvailable()) {
                                $paramArr[] = $param->getDefaultValue();
                            }
                        } catch (\Exception $e) {
                            throw new Exception('无法实例化该类：' . $className . '，错误：' . $e->getMessage());
                        }
                    }
                }
            }
        }

        return $paramArr;
    }

    /**
     * @DESC         |读取类反射
     *
     * 参数区：
     *
     * @param $class
     *
     * @return ReflectionClass
     * @throws \ReflectionException
     */
    protected function getReflectionClass($class): ReflectionClass
    {
        return new \ReflectionClass($class);
    }
}
