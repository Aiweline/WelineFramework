<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Session;

use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Driver\SessionDriverHandlerInterface;

class SessionManager
{
    public const driver_NAMESPACE = Env::framework_name . '\\Framework\\Session\\Driver\\';

    private static SessionManager $instance;

    private array $config;
    private ?SessionDriverHandlerInterface $_session = null;
    private CacheInterface $cache;

    private function __clone()
    {
    }

    private function __construct()
    {
        $this->cache = ObjectManager::getInstance(Cache\SessionCache::class)->create();
        $this->config = (array)Env::getInstance()->getConfig('session');
    }

    /**
     * @DESC         |获取实例
     *
     * 参数区：
     *
     * @return SessionManager
     */
    public static function getInstance(): SessionManager
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * @DESC         |创建session
     *
     * 参数区：
     *
     * @param string $driver
     * @param string $area
     *
     * @return SessionDriverHandlerInterface
     */
    public function create(string $driver = ''): SessionDriverHandlerInterface
    {
        if (empty($this->_session)) {
            if (empty($driver) && isset($this->config['default'])) {
                $driver = $this->config['default'];
            }
            # 从缓存获取Session驱动类
            $cache_key = 'session_driver_class_' . $driver;
            $driver_class = $this->cache->get($cache_key) ?: self::driver_NAMESPACE . ucfirst($driver);
            if (!class_exists($driver_class)) {
                $modules = Env::getInstance()->getActiveModules();
                $drivers = [];
                foreach ($modules as $module) {
                    $driver_files = glob($module['base_path'] . 'Session/Driver/*.php');
                    foreach ($driver_files as $driver_file) {
                        $driver_name = pathinfo($driver_file, PATHINFO_FILENAME);
                        $driver_file_class = $module['namespace_path'] . DS . 'Session\Driver\\' . $driver_name;
                        if (!class_exists($driver_file_class)) {
                            new Exception(__('Session 驱动找不到！请检查env配置文件中 session[\'default\'] 是否正确。驱动类：%1', $driver_file_class));
                        }
                        $driver_ref_instance = ObjectManager::getReflectionInstance($driver_file_class);
                        if ($driver_ref_instance->isInstantiable()) {
                            $drivers[$driver_name] = $driver_file_class;
                        }
                    }
                    $driver_class = $drivers[ucfirst($driver)] ?? $driver_class;
                    $this->cache->set($cache_key, $driver_class);
                }
                if (!class_exists($driver_class)) {
                    new Exception(__('Session 驱动找不到！请检查env配置文件中 session[\'default\'] 是否正确。驱动类：%1', $driver_class));
                }
                # 设置驱动缓存
                if (PROD) {
                    $this->cache->set($cache_key, $driver_class);
                }
            }
            $driver_config = $this->config['drivers'][$driver] ?? [];
            $this->_session = new $driver_class($driver_config);
        }
        return $this->_session;
    }
}
