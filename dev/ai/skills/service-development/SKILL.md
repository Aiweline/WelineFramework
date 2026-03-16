---
name: service-development
description: Service 层开发。业务逻辑层、Controller 与 Model 之间、依赖注入、Api 接口定义。
globs: []
alwaysApply: false
---

# service-development（极简版）

## 何时使用

- 创建 Service 类
- Service 与 Controller、Model 协作
- 定义 Service 接口（Api 目录）
- 业务逻辑抽取

## 必做

- Service 位于 Controller 与 Model 之间，承载业务逻辑
- 通过构造函数依赖注入，用 ObjectManager 获取实例
- 接口放 Api/ 目录，实现放 Service/
- 使用 `declare(strict_types=1);` 和类型声明

## 最小示例

```php
class YourService
{
    public function __construct(
        private readonly YourModel $model,
        private readonly EventsManager $eventsManager
    ) {}
    public function doSomething(array $data): mixed { }
}
```

## 禁止

- Controller 直接操作 Model 写复杂业务
- Service 直接输出 HTTP 响应
- 在 Service 中写 SQL 方言
