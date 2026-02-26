<?php

declare(strict_types=1);

namespace Weline\Storage\Service;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Storage\Adapter\LocalStorage;
use Weline\Storage\Adapter\OssStorage;
use Weline\Storage\Adapter\S3Storage;
use Weline\Storage\Api\StorageInterface;
use Weline\Storage\Model\StorageConfig;

/**
 * @DESC | 存储管理器，统一管理所有存储后端
 */
class StorageManager
{
    private array $disks = [];
    private array $drivers = [];
    private ?string $defaultDisk = null;
    private bool $initialized = false;
    
    private StorageConfig $storageConfig;
    private EventsManager $eventsManager;
    
    public function __construct()
    {
        $this->storageConfig = ObjectManager::getInstance(StorageConfig::class);
        $this->eventsManager = ObjectManager::getInstance(EventsManager::class);
        
        $this->registerDefaultDrivers();
    }
    
    /**
     * 注册默认驱动
     */
    private function registerDefaultDrivers(): void
    {
        $this->drivers = [
            'local' => LocalStorage::class,
            's3' => S3Storage::class,
            'oss' => OssStorage::class,
        ];
        
        $eventData = ['data' => ['drivers' => &$this->drivers]];
        $this->eventsManager->dispatch('Weline_Storage::integration::register_drivers', $eventData);
    }
    
    /**
     * 从数据库加载存储配置
     */
    private function loadFromDatabase(): void
    {
        if ($this->initialized) {
            return;
        }
        
        $this->initialized = true;
        
        $configs = $this->storageConfig->getEnabledConfigs();
        
        foreach ($configs as $config) {
            $name = $config['name'] ?? '';
            if (!$name) {
                continue;
            }
            
            $driver = $config['driver'] ?? 'local';
            $configArray = [];
            
            if (!empty($config['config'])) {
                $configArray = \json_decode($config['config'], true) ?: [];
            }
            
            $this->registerDisk($name, $driver, $configArray);
            
            if (($config['is_default'] ?? 0) == 1) {
                $this->defaultDisk = $name;
            }
        }
        
        if ($this->defaultDisk === null && !empty($this->disks)) {
            $this->defaultDisk = \array_key_first($this->disks);
        }
    }
    
    /**
     * 注册存储磁盘
     */
    public function registerDisk(string $name, string $driver, array $config = []): self
    {
        if (!isset($this->drivers[$driver])) {
            throw new \InvalidArgumentException(__('未知的存储驱动：%{driver}', ['driver' => $driver]));
        }
        
        $adapterClass = $this->drivers[$driver];
        $this->disks[$name] = new $adapterClass($config);
        
        return $this;
    }
    
    /**
     * 注册自定义驱动
     */
    public function registerDriver(string $driver, string $adapterClass): self
    {
        if (!\is_subclass_of($adapterClass, StorageInterface::class)) {
            throw new \InvalidArgumentException(__('驱动类必须实现 StorageInterface 接口'));
        }
        
        $this->drivers[$driver] = $adapterClass;
        return $this;
    }
    
    /**
     * 获取指定存储磁盘
     */
    public function disk(?string $name = null): StorageInterface
    {
        $this->loadFromDatabase();
        
        $name = $name ?? $this->defaultDisk;
        
        if ($name === null) {
            return $this->getLocalDisk();
        }
        
        if (!isset($this->disks[$name])) {
            throw new \InvalidArgumentException(__('存储磁盘不存在：%{name}', ['name' => $name]));
        }
        
        return $this->disks[$name];
    }
    
    /**
     * 获取默认存储磁盘
     */
    public function getDefault(): StorageInterface
    {
        return $this->disk();
    }
    
    /**
     * 获取本地存储磁盘（备用）
     */
    public function getLocalDisk(): StorageInterface
    {
        if (!isset($this->disks['__local__'])) {
            $this->disks['__local__'] = new LocalStorage([
                'root_path' => PUB . 'media',
                'base_url' => '/pub/media',
            ]);
        }
        
        return $this->disks['__local__'];
    }
    
    /**
     * 获取所有已注册的磁盘
     */
    public function getDisks(): array
    {
        $this->loadFromDatabase();
        return $this->disks;
    }
    
    /**
     * 获取所有可用的驱动
     */
    public function getDrivers(): array
    {
        return $this->drivers;
    }
    
    /**
     * 获取默认磁盘名称
     */
    public function getDefaultDiskName(): ?string
    {
        $this->loadFromDatabase();
        return $this->defaultDisk;
    }
    
    /**
     * 检查磁盘是否存在
     */
    public function hasDisk(string $name): bool
    {
        $this->loadFromDatabase();
        return isset($this->disks[$name]);
    }
    
    /**
     * 测试存储配置
     */
    public function testConfig(string $driver, array $config): bool
    {
        if (!isset($this->drivers[$driver])) {
            return false;
        }
        
        try {
            $adapterClass = $this->drivers[$driver];
            $adapter = new $adapterClass($config);
            return $adapter->testConnection();
        } catch (\Throwable $e) {
            return false;
        }
    }
    
    /**
     * 获取存储配置列表（用于前端展示）
     */
    public function getStorageList(): array
    {
        $this->loadFromDatabase();
        
        $list = [];
        foreach ($this->disks as $name => $disk) {
            if ($name === '__local__') {
                continue;
            }
            
            $info = $disk->getInfo();
            $list[] = [
                'name' => $name,
                'driver' => $info['driver'] ?? 'unknown',
                'is_default' => $name === $this->defaultDisk,
                'info' => $info,
            ];
        }
        
        return $list;
    }
    
    /**
     * 重新加载配置
     */
    public function reload(): void
    {
        $this->disks = [];
        $this->defaultDisk = null;
        $this->initialized = false;
        $this->loadFromDatabase();
    }
}
