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

class CacheFactory implements CacheFactoryInterface
{
    public const driver_NAMESPACE = Env::framework_name . '\\Framework\\Cache\\Driver\\';

    private static CacheFactory $instance;

    private array $config;

    private string $identity;
    private string $tip;
    private string $status;

    private ?CacheInterface $driver = null;

    // 是否持久缓存
    private bool $keep;

    /**
     * @param string $identity [缓存识别]
     * @param bool $permanently [持久使用]
     * @param string $tip 【说明】
     */
    public function __construct(string $identity = 'cache_system', string $tip = '', bool $permanently = false)
    {
        $this->config = App::Env('cache');
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
        $status = (bool)Env::getInstance()->getData('cache/status/' . $this->identity);
        $this->driver = new $driver_class($this->identity, $this->config['drivers'][$driver], $tip ?: $this->tip, $status ?: $this->status);
        return $this->driver;
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
