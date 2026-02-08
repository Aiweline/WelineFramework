<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Cache;

use Weline\Framework\App;
use Weline\Framework\App\Env;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

class CacheFactory implements CacheFactoryInterface
{
    public const driver_NAMESPACE = Env::framework_name . '\\Framework\\Cache\\Driver\\';

    private static CacheFactory $instance;

    private array $config;

    private string $identity;
    private string $tip;
    private string $status;

    private static ?CacheInterface $driver = null;

    // 是否持久缓存
    private bool $keep;
    
    // 递归保护：防止事件触发时循环调用
    private static bool $isCreating = false;
    
    // 防止事件触发时的递归标志
    private static bool $creatingDriver = false;

    /**
     * @param string $identity [缓存识别]
     * @param bool $permanently [持久使用]
     * @param string $tip 【说明】
     */
    public function __construct(string $identity = 'cache_system', string $tip = '', bool $permanently = false)
    {
        $config = App::Env('cache');
        $this->config = is_array($config) ? $config : [];
        $this->identity = $identity;
        $this->tip = $tip;
        $this->keep = $permanently;
        $this->status = DEV ? ($this->config['status'][$identity] ?? $permanently) : ($permanently ?: $this->config['status'][$identity] ?? 1);
    }

    public function isKeep(): bool
    {
        return $this->keep;
    }

    public function __wakeup()
    {
        if (empty($this->driver)) {
            $this->config = (array)Env::getInstance()->getConfig('cache');
            $this->driver = $this->create();
        }
    }

    /**
     * @DESC         |创建缓存
     *
     * 参数区：
     *
     * @param string $driver [驱动名|驱动类]
     * @param string $tip [缓存说明]
     *
     * @return CacheInterface
     */
    public function create(string $driver = '', string $tip = ''): CacheInterface
    {
        if (empty($driver) && isset($this->config['default'])) {
            $driver = $this->config['default'];
        }
        if (class_exists(self::driver_NAMESPACE . ucfirst($driver))) {
            $driver_class = self::driver_NAMESPACE . ucfirst($driver);
        } else {
            $driver_class = $driver;
        }
        
        // 仅在常驻内存模式且非递归调用时触发事件，防止循环依赖
        if (\Weline\Framework\Runtime\Runtime::isPersistent() && !self::$isCreating) {
            self::$isCreating = true;
            try {
                // 触发 driver_create_before 事件，允许其他模块接管驱动（如常驻内存模式下接管 File 缓存）
                $eventData = new DataObject([
                    'driver' => $driver,
                    'driver_class' => $driver_class,
                    'identity' => $this->identity,
                    'config' => $this->config,
                    'tip' => $tip ?: $this->tip,
                ]);
                /** @var EventsManager $eventManager */
                $eventManager = ObjectManager::getInstance(EventsManager::class);
                $eventManager->dispatch('Weline_Framework_Cache::driver_create_before', $eventData);
                
                // 允许 Observer 替换驱动类
                $driver_class = $eventData->getData('driver_class');
            } finally {
                self::$isCreating = false;
            }
        }
        
        $driverConfig = $this->config['drivers'][$driver] ?? [];
        $status = (bool)Env::getInstance()->getData('cache/status/' . $this->identity);
        self::$driver = new $driver_class($this->identity, $driverConfig, $tip ?: $this->tip, $status ?: $this->status);
        return self::$driver;
    }
    /**
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key,mixed $default = null):mixed{
        if(self::$driver === null){
            self::$driver = self::create();
        }
        return self::$driver->get($key, $default);
    }
    /**
     * @param string $key
     * @return bool
     */
    public static function exists(string $key):bool{
        if(self::$driver === null){
            self::$driver = self::create();
        }
        return self::$driver->exists($key) ? true : false;
    }
    /**
     * @param string $key
     * @param mixed $value
     * @param int $duration
     * @return bool
     */
    public static function set(string $key, mixed $value, int $duration = 1800):bool{
        if(self::$driver === null){
            self::$driver = self::create();
        }
        return self::$driver->set($key, $value, $duration) ? true : false;
    }

    /**
     * 清除此缓存实例的所有数据（刷新）
     * 触发 Weline_Framework_Cache::integration::cache_flushed 事件，允许 Server 模块监听并重载 WLS
     */
    public function flush(): bool
    {
        if (self::$driver === null) {
            self::$driver = $this->create();
        }
        $result = self::$driver->flush();

        $this->dispatchCacheEvent('flush');
        return $result;
    }

    /**
     * 清除此缓存实例的所有键值（清理）
     * 触发 Weline_Framework_Cache::integration::cache_flushed 事件，允许 Server 模块监听并重载 WLS
     */
    public function clear(): bool
    {
        if (self::$driver === null) {
            self::$driver = $this->create();
        }
        $result = self::$driver->clear();

        $this->dispatchCacheEvent('clear');
        return $result;
    }

    /**
     * 删除指定缓存键
     */
    public function delete(string $key): bool
    {
        if (self::$driver === null) {
            self::$driver = $this->create();
        }
        return self::$driver->delete($key) ? true : false;
    }

    /**
     * 缓存清理后触发事件
     * 
     * 递归保护：如果事件处理中又触发了缓存操作，不会无限循环
     */
    private static bool $dispatchingCacheEvent = false;

    private function dispatchCacheEvent(string $operation): void
    {
        // 递归保护
        if (self::$dispatchingCacheEvent) {
            return;
        }
        self::$dispatchingCacheEvent = true;
        try {
            /** @var EventsManager $eventManager */
            $eventManager = ObjectManager::getInstance(EventsManager::class);
            $eventData = new DataObject([
                'identity' => $this->identity,
                'operation' => $operation,
                'tip' => $this->tip,
            ]);
            $eventManager->dispatch('Weline_Framework_Cache::integration::cache_flushed', $eventData);
        } catch (\Throwable $e) {
            // 事件触发失败不影响缓存操作
        } finally {
            self::$dispatchingCacheEvent = false;
        }
    }

    /**
     * @return bool|string
     */
    public function getStatus(): bool|string
    {
        return $this->status;
    }

    /**
     * @return string
     */
    public function tip(): string
    {
        return $this->tip;
    }

    /**
     * @return string
     */
    public function getIdentity(): string
    {
        return $this->identity;
    }
}
