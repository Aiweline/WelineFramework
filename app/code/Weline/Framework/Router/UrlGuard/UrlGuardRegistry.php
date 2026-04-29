<?php

declare(strict_types=1);

/**
 * URL Guard 注册表
 *
 * 持有所有已注册的 UrlGuardInterface 实例。
 * 默认从 `app/code/Weline/Framework/Router/etc/url_guards.php` 与
 * `app/etc/env.php` 的 `router.url_guards` 配置项加载。
 *
 * 业务模块可通过 `register()` 方法在启动钩子里追加自定义 Guard。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Router\UrlGuard;

class UrlGuardRegistry
{
    /** @var array<string, UrlGuardInterface> */
    private array $guards = [];

    /**
     * @param array<int, UrlGuardInterface> $initialGuards 直接传入构造（测试常用）
     */
    public function __construct(array $initialGuards = [])
    {
        foreach ($initialGuards as $guard) {
            $this->register($guard);
        }
    }

    public function register(UrlGuardInterface $guard): void
    {
        $this->guards[$guard->getName()] = $guard;
    }

    public function unregister(string $guardName): void
    {
        unset($this->guards[$guardName]);
    }

    public function has(string $guardName): bool
    {
        return isset($this->guards[$guardName]);
    }

    /**
     * @return array<string, UrlGuardInterface>
     */
    public function all(): array
    {
        return $this->guards;
    }

    /**
     * 通过配置数组批量注册 BoundedUrlGuard。
     *
     * 配置形如：
     * ```
     * [
     *     ['name' => 'product_id_max', 'config' => ['pattern' => '#^/product/(?<id>\d+)#', 'param_name' => 'id', 'max' => 1000000]],
     * ]
     * ```
     *
     * @param array<int, array{name?:string, config?:array<string, mixed>}> $items
     */
    public function loadFromArray(array $items): void
    {
        foreach ($items as $item) {
            $name = (string)($item['name'] ?? '');
            $config = $item['config'] ?? [];
            if ($name === '' || !\is_array($config)) {
                continue;
            }
            $this->register(new BoundedUrlGuard($name, $config));
        }
    }
}
