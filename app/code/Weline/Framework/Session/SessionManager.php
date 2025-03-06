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

    /**
     * @param mixed $driver
     * @return mixed|string
     */
    public function getSessionDriverClass(string $driver): string
    {
        if (empty($driver) && isset($this->config['default'])) {
            $driver = $this->config['default'];
        }
        if (empty($driver)) {
            $driver = 'file';
        }
        # 从缓存获取Session驱动类
        $cache_key = 'session_driver_class_' . $driver;
        if ($driver_class = $this->cache->get($cache_key)) {
            return $driver_class;
        }
        $default_driver_class = self::driver_NAMESPACE . ucfirst($driver);
        $modules = Env::getInstance()->getActiveModules();
        $drivers = $this->config['drivers'] ?? [];
        if ($driver_class = $drivers[$driver]['class'] ?? '') {
            return $driver_class;
        }
        foreach ($modules as $module) {
            $driver_files = glob($module['base_path'] . 'Session/Driver/*.php');
            foreach ($driver_files as $driver_file) {
                $driver_name = pathinfo($driver_file, PATHINFO_FILENAME);
                $driver_file_class = $module['namespace_path'] . DS . 'Session\Driver\\' . ucfirst($driver_name);
                if (!class_exists($driver_file_class)) {
                    new Exception(__('Session 驱动找不到！请检查env配置文件中 session[\'default\'] 是否正确。驱动类：%1', $driver_file_class));
                }
                $driver_ref_instance = ObjectManager::getReflectionInstance($driver_file_class);
                if ($driver_ref_instance->isInstantiable()) {
                    $drivers[strtolower($driver_name)]['class'] = $driver_file_class;
                }
            }
            $driver_class = $drivers[ucfirst($driver)] ?? $driver_class;
        }
        if (empty($driver)) {
            $driver_class = $default_driver_class;
        } else {
            $driver_class = $drivers[$driver]['class'] ?? '';
        }
        if ($driver and !class_exists($driver_class)) {
            Env::log('session', __('指定Session驱动为: %1 但是驱动类找不到!', $driver));
            trigger_error(__('指定Session驱动为: %1 但是驱动类找不到!', $driver), E_USER_ERROR);
        }
        # 设置驱动缓存
        if (PROD) {
            $this->cache->set($cache_key, $driver_class);
        }
        Env::set('session.drivers', $drivers);
        return $driver_class;
    }

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
            $driver_class = $this->getSessionDriverClass($driver);
            $driver_config = $this->config['drivers'][$driver] ?? [];
            $this->_session = new $driver_class($driver_config);
        }
        return $this->_session;
    }
}
