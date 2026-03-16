---
name: cache-usage
description: 缓存使用。CacheFactory 继承、CacheInterface、模块缓存类、缓存读写、cache:clear。
globs: []
alwaysApply: false
---

# cache-usage（极简版）

## 何时使用

- 创建模块缓存类
- 缓存读写、缓存清理
- 配置缓存驱动（File/Redis）

## 必做

- 继承 CacheFactory 创建模块缓存类
- 构造函数传 identity、tip、permanently
- 持久化缓存需 `cache:clean -f` 才能清理
- 使用 `$cache->get()`、`$cache->set()`、`$cache->delete()`

## 最小示例

```php
class YourModuleCache extends CacheFactory
{
    public function __construct(
        string $identity = 'your_module_cache',
        string $tip = '您的模块缓存',
        bool $permanently = false
    ) {
        parent::__construct($identity, $tip, $permanently);
    }
}
```

## 禁止

- 直接 new 缓存驱动，应通过 CacheFactory 子类
- 持久化缓存用普通 cache:clear 清理（需 -f）
