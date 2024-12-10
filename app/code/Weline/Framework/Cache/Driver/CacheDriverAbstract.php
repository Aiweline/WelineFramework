<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Cache\Driver;

use Weline\Framework\App\Env;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

abstract class CacheDriverAbstract implements \Weline\Framework\Cache\CacheDriverInterface
{
    protected bool $status;
    protected array $config;
    protected string $identity;
    protected string $tip;

    public function __construct(string $identity, array $config, $tip = '', bool $status = true)
    {
        $this->identity = $identity;
        $this->config   = $config;
        $this->tip      = $tip;
        $this->status   = $status;
        if (method_exists($this, '__init')) {
            $this->__init();
        }
    }

    private function __clone()
    {
    }

    public function __wakeup()
    {
        $this->__init();
    }

    public function setIdentity(string $identity): static
    {
        $this->identity = $identity;
        $this->__init();
        return $this;
    }

    public function getIdentify(): string
    {
        return $this->identity;
    }

    public function getStatus(): bool
    {
        return $this->status;
    }

    public function tip(): string
    {
        return $this->tip;
    }

    /**
     * @DESC         |设置状态
     * 0 : 关闭
     * 1 : 开启
     * 参数区：
     *
     * @param bool $status
     *
     * @return CacheInterface
     */
    public function setStatus(bool $status): CacheInterface
    {
        $this->status = $status;
        return $this;
    }

    /**
     * @DESC         |使用指定的键从缓存中检索多个值。
     *
     * 参数区：
     *
     * @param array $keys
     *
     * @return array
     */
    public function getMulti(array $keys): array
    {
        if (!$this->status) {
            return [];
        }
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->get($key);
        }

        return $results;
    }

    /**
     * @DESC         |缓存中存储多个项目。每个项包含一个由键标识的值。
     *
     * 参数区：
     *
     * @param array $items
     * @param int   $duration
     *
     * @return array
     */
    public function setMulti(array $items, int $duration = 1800): array
    {
        if (!$this->status) {
            return [];
        }
        $failedKeys = [];
        foreach ($items as $key => $value) {
            if ($this->set($key, $value, $duration) === false) {
                $failedKeys[] = $key;
            }
        }

        return $failedKeys;
    }

    /**
     * @DESC         |在缓存中存储多个项目。每个项包含一个由键标识的值。
     *                如果缓存已经包含这样一个键，则现有值和过期时间将被保留。
     *
     * 参数区：
     *
     * @param     $items
     * @param int $duration
     *
     * @return array
     */
    public function addMulti($items, int $duration = 1800): array
    {
        if (!$this->status) {
            return [];
        }
        $failedKeys = [];
        foreach ($items as $key => $value) {
            if ($this->add($key, $value, $duration) === false) {
                $failedKeys[] = $key;
            }
        }

        return $failedKeys;
    }

    /**
     * @DESC         |从给定键生成规范化缓存键
     *
     * 参数区：
     *
     * @param string $key
     *
     * @return string
     */
    public function buildKey(string $key): string
    {
        if (!is_string($key)) {
            // 不是字符串，json_encode转成字符串
            $key = json_encode($key);
        }
        return md5("{$this->identity}_$key");
    }

    /**
     * @DESC         | 生成请求级别的缓存key
     *
     * 参数区：
     *
     * @param string $key [基础键]
     *
     * @return string
     */
    public function buildWithRequestKey(string $key): string
    {
        if (!is_string($key)) {
            // 不是字符串，json_encode转成字符串
            $key = json_encode($key);
        }
        if (empty($attach_variables)) {
            $attach_variables['page']     = $this->getRequest()->getGet('page', 1);
            $attach_variables['pageSize'] = $this->getRequest()->getGet('pageSize', 10);
        }
        $key .= $this->getRequest()->getUri() . $this->getRequest()->getMethod() . json_encode($attach_variables);
        if ($attach_variables) {
            $key .= implode('', $attach_variables);
        }
        return md5("{$this->identity}_$key");
    }

    private function getRequest(): Request
    {
        return ObjectManager::getInstance(Request::class);
    }
}
