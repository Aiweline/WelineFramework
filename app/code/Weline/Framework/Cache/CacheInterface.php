<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Cache;

interface CacheInterface
{
    /**
     * @DESC          # 缓存说明
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/3/14 22:29
     * 参数区：
     * @return string
     */
    public function tip(): string;

    /**
     * @DESC          # 获取识别名
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/3/14 23:52
     * 参数区：
     * @return string
     */
    public function getIdentify(): string;

    /**
     * @DESC         |获取状态
     * 0 : 关闭
     * 1 : 开启
     * 参数区：
     *
     * @return bool
     */
    public function getStatus(): bool;

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
    public function setStatus(bool $status): CacheInterface;

    /**
     * @DESC         |从给定键生成规范化缓存键。
     *
     * 参数区：
     *
     * @param string $key
     *
     * @return mixed
     */
    public function buildKey(string $key): mixed;

    /**
     * @DESC         |生成关于请求的缓存
     *
     * 参数区：
     *
     * @param string $key
     *
     * @return mixed
     */
    public function buildWithRequestKey(string $key): mixed;

    /**
     * @DESC         |使用指定键从缓存中检索值。
     *
     * 参数区：
     *
     * @param string $key
     *
     * @return mixed
     */
    public function get(string $key): mixed;

    /**
     * @DESC         |检查缓存中是否存在指定的键。
     *
     * 参数区：
     *
     * @param string $key
     *
     * @return mixed
     */
    public function exists(string $key): mixed;

    /**
     * @DESC         |使用指定的键从缓存中检索多个值。
     *
     * 参数区：
     *
     * @param array $keys
     *
     * @return mixed
     */
    public function getMulti(array $keys): mixed;

    /**
     * @DESC         |将键标识的值存储到缓存中。
     *
     * 参数区：
     *
     * @param string $key      键
     * @param mixed  $value    值
     * @param int    $duration 秒
     *
     * @return mixed
     */
    public function set(string $key, mixed $value, int $duration = 1800): mixed;

    /**
     * @DESC         |在缓存中存储多个项目。每个项包含一个由键标识的值。
     *
     * 参数区：
     *
     * @param array $items
     * @param int   $duration
     *
     * @return mixed
     */
    public function setMulti(array $items, int $duration = 1800): mixed;

    /**
     * @DESC         |如果缓存不包含该键，则将由键标识的值存储到缓存中。
     *                如果缓存已包含密钥，则不会执行任何操作。
     *
     * 参数区：
     *
     * @param string $key
     * @param mixed  $value
     * @param int    $duration
     *
     * @return mixed
     */
    public function add(string $key, mixed $value, int $duration = 1800): mixed;

    /**
     * @DESC         |在缓存中存储多个项目。每个项包含一个由键标识的值。
     *                如果缓存已经包含这样一个键，则现有值和过期时间将被保留。
     *
     * 参数区：
     *
     * @param     $items
     * @param int $duration
     *
     * @return mixed
     */
    public function addMulti($items, int $duration = 1800): mixed;

    /**
     * @DESC         |从缓存中删除具有指定键的值
     *
     * 参数区：
     *
     * @param string $key
     *
     * @return mixed
     */
    public function delete(string $key): mixed;

    /**
     * @DESC         |从缓存中删除所有值。（刷新缓存文件）
     *
     * 参数区：
     *
     * @return mixed
     */
    public function flush(): bool;

    /**
     * @DESC          # 从缓存中删除所有键的值。（清理缓存）
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/14 22:13
     * 参数区：
     * @return bool
     */
    public function clear(): bool;
}
