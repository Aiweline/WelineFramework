<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Cache\Driver;

use Weline\Framework\App\Env;
use Weline\Framework\Cache\CacheDriverInterface;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Http\Request;

class File extends CacheDriverAbstract
{
    /**
     * @var string 存储缓存文件的目录。
     * 缓存文件的地址，例如/var/html/projects/var/cache/
     */
    private string $cachePath;

    /**
     * @DESC         |框架初始化函数保证缓存文件目录存在
     *
     * 参数区：
     */
    public function __init()
    {
        if (IS_WIN) {
            $this->config['path'] = str_replace('/', DS, $this->config['path']);
        } else {
            $this->config['path'] = str_replace('\\', DS, $this->config['path']);
        }
        $this->cachePath = $this->config['path'] ? BP . rtrim($this->config['path'], DS) . DS . $this->identity . DS : BP . 'var' . DS . 'cache' . DS . $this->identity . DS;
        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0775, true);
        }
    }

    /**
     * @DESC         |使用指定键从缓存中检索值
     *
     * 参数区：
     *
     * @param string $key
     *
     * @return bool|mixed
     */
    public function get(string $key): mixed
    {
        if (!$this->status) {
            return false;
        }
        $key       = $this->buildKey($key);
        $cacheFile = $this->cachePath . $key;
        if (!file_exists($cacheFile)) {
            return false;
        }
        // filemtime用来获取文件的修改时间
        if (filemtime($cacheFile) > time()) {
            // file_get_contents用来获取文件内容，unserialize用来反序列化文件内容
            return unserialize(file_get_contents($cacheFile));
        }

        return false;
    }

    /**
     * @DESC         |检查缓存中是否存在指定的键。
     *
     * 参数区：
     *
     * @param string $key
     *
     * @return bool
     */
    public function exists(string $key): bool
    {
        if (!$this->status) {
            return false;
        }
        $key       = $this->buildKey($key);
        $cacheFile = $this->cachePath . $key;
        if (!file_exists($cacheFile)) {
            return false;
        }
        // 用修改时间标记过期时间，存入时会做相应的处理
        return @filemtime($cacheFile) > time();
    }

    /**
     * @DESC         |将键标识的值存储到缓存中。
     *
     * 参数区：
     *
     * @param string $key
     * @param mixed  $value
     * @param int    $duration
     *
     * @return bool
     */
    public function set(string $key, mixed $value, int $duration = 1800): bool
    {
        if (!$this->status) {
            return false;
        }
        $key       = $this->buildKey($key);
        $cacheFile = $this->cachePath . $key;
        // serialize用来序列化缓存内容
        $value = serialize($value);

        # 错误阻止并发送报告

        // file_put_contents用来将序列化之后的内容写入文件，LOCK_EX表示写入时会对文件加锁
        $cacheFile = $this->processCacheFile($cacheFile);
        if (@file_put_contents($cacheFile, $value, LOCK_EX) !== false) {
            if ($duration <= 0) {
                // 不设置过期时间，设置为一年，这是因为用文件的修改时间来做过期时间造成的
                // redis/memcache 等都不会有这个问题
                $duration = 31536000; // 1 year
            }
            // touch用来设置修改时间，过期时间为当前时间加上$duration
            return touch($cacheFile, $duration + time());
        }

        return false;
    }

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
     * @return bool
     */
    public function add(string $key, mixed $value, int $duration = 1800): bool
    {
        if (!$this->status) {
            return false;
        }
        //  key不存在，就设置缓存
        if (!$this->exists($key)) {
            return $this->set($key, $value, $duration);
        }

        return false;
    }

    /**
     * @DESC         |从缓存中删除具有指定键的值
     *
     * 参数区：
     *
     * @param string $key
     *
     * @return bool
     */
    public function delete(string $key): bool
    {
        $key = $this->buildKey($key);

        $cacheFile = $this->cachePath . $key;
        if (is_file($cacheFile)) {
            // unlink用来删除文件
            return unlink($cacheFile);
        }
        return false;
    }

    /**
     * @DESC          # 从缓存中删除所有值。
     *                  如果在多个应用程序之间执行共享缓存操作，请小心操作。
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/14 22:10
     * 参数区：
     * @return mixed
     */
    public function flush(): bool
    {
        // 打开cache文件所在目录
        if ($dir = @dir($this->cachePath)) {
            // 列出目录中的所有文件
            while (($file = $dir->read()) !== false) {
                if ($file !== '.' && $file !== '..') {
                    unlink($this->cachePath . $file);
                }
            }
            // 关闭目录
            $dir->close();
        }
        return true;
    }

    /**
     * @DESC          # 从缓存中删除所有键的值。（清理缓存）
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/14 22:10
     * 参数区：
     * @return bool
     */
    public function clear(): bool
    {
        // 打开cache文件所在目录
        $this->processCacheFile($this->cachePath);
        if ($dir = @dir($this->cachePath)) {
            // 列出目录中的所有文件
            while (($file = $dir->read()) !== false) {
                if ($file !== '.' && $file !== '..') {
                    @file_put_contents($this->cachePath . $file, '', LOCK_EX);
                }
            }
            // 关闭目录
            $dir->close();
            return true;
        }
        return false;
    }

    /**
     * @DESC         |处理缓存文件路径
     *
     * 参数区：
     *
     * @param string $cacheFile
     *
     * @return string
     */
    public function processCacheFile(string $cacheFile): string
    {
        if (!file_exists($cacheFile)) {
            if (!is_dir($this->cachePath)) {
                mkdir($this->cachePath, 0775, true);
            }
            touch($cacheFile);
            chmod($cacheFile, 0755);
        }
        return $cacheFile;
    }
}
