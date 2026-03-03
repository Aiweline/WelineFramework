<?php

declare(strict_types=1);

namespace Weline\Framework\Session\Business;

use Weline\Framework\Session\SessionFactory;
use Weline\Framework\Session\SessionInterface;

/**
 * 业务级 Session 抽象基类
 *
 * 提供命名空间隔离的数据存储，适用于购物车、愿望清单等业务场景。
 * 所有数据自动添加前缀，避免与其他业务数据冲突。
 *
 * 使用方式：
 * 1. 继承此类并定义 PREFIX 常量
 * 2. 添加业务相关的 getter/setter 方法
 *
 * @example
 * class CartSession extends AbstractBusinessSession
 * {
 *     protected const PREFIX = 'cart_';
 *
 *     public function getItems(): array
 *     {
 *         return $this->get('items') ?? [];
 *     }
 *
 *     public function setItems(array $items): void
 *     {
 *         $this->set('items', $items);
 *     }
 * }
 */
abstract class AbstractBusinessSession
{
    /** 数据前缀（子类必须定义） */
    protected const PREFIX = '';

    /** 底层 Session 实例 */
    protected SessionInterface $session;

    /**
     * 构造函数
     *
     * @param SessionInterface|null $session 底层 Session 实例，为空则自动获取
     */
    public function __construct(?SessionInterface $session = null)
    {
        $this->session = $session ?? SessionFactory::getInstance()->createSession();
    }

    /**
     * 获取数据
     *
     * @param string $key 键名（不含前缀）
     * @return mixed 数据值
     */
    protected function get(string $key): mixed
    {
        return $this->session->get(static::PREFIX . $key);
    }

    /**
     * 设置数据
     *
     * @param string $key 键名（不含前缀）
     * @param mixed $value 数据值
     */
    protected function set(string $key, mixed $value): void
    {
        $this->session->set(static::PREFIX . $key, $value);
    }

    /**
     * 检查数据是否存在
     *
     * @param string $key 键名（不含前缀）
     * @return bool 是否存在
     */
    protected function has(string $key): bool
    {
        return $this->session->get(static::PREFIX . $key) !== null;
    }

    /**
     * 删除数据
     *
     * @param string $key 键名（不含前缀）
     */
    protected function delete(string $key): void
    {
        $this->session->delete(static::PREFIX . $key);
    }

    /**
     * 获取该业务的所有数据
     *
     * @return array 所有带前缀的数据
     */
    public function all(): array
    {
        $allData = $this->session->all();
        $prefixLen = \strlen(static::PREFIX);
        $result = [];

        foreach ($allData as $key => $value) {
            if (\str_starts_with($key, static::PREFIX)) {
                $result[\substr($key, $prefixLen)] = $value;
            }
        }

        return $result;
    }

    /**
     * 清空该业务的所有数据
     */
    public function clear(): void
    {
        $allData = $this->session->all();

        foreach (\array_keys($allData) as $key) {
            if (\str_starts_with($key, static::PREFIX)) {
                $this->session->delete($key);
            }
        }
    }

    /**
     * 获取底层 Session
     */
    public function getSession(): SessionInterface
    {
        return $this->session;
    }
}
