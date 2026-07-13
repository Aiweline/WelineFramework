# Worker 视图预热贡献契约

持久 Worker 的模板、Hook 输出、静态文件和模块所有 FPC 公开路径预热由 `Weline_Theme` 统一执行；资源归属模块只提交数据描述，不允许 Theme 硬编码业务模块路径。

## 注册方式

资源拥有模块在 `Api/View` 实现 Framework 的
`ViewWarmupContributionProviderInterface`，返回只读
`ViewWarmupContribution`，并在 `etc/module.php` 注册唯一 capability：

```php
'provides' => [
    'view_warmup_contribution.Vendor_Module'
        => \Vendor\Module\Api\View\ViewWarmupContributionProvider::class,
],
```

描述符支持五类资源：

- `templates`：交给 `Template::getFetchFile()` 的模块模板标识。
- `tagTemplates`：按 `hooks`、`blocks` 等 Tag 类型分组的资源标识。
- `staticFiles`：使用 `/` 的仓库相对路径；禁止绝对路径、反斜线和 `..`。
- `hookNames`：预热 HookReader 编译索引的完整 Hook 名称。
- `fpcPaths`：模块拥有的公开绝对 URL path；必须以 `/` 开头，禁止 scheme/authority、空白和超长路径。

Provider 只能返回字符串数组，不得返回 Closure、服务对象、请求对象或运行期回调。

## 生命周期与性能边界

1. `framework:compile` 把模块 capability 写入 `generated/framework/modules.php`。
2. Worker 启动期由 `ViewWarmupContributionRegistry` 从编译索引解析 Provider；不经运行期 Server 事件发现或注册。
3. Registry 合并、去重后缓存一个只读聚合描述符。
4. `WorkerBootstrapWarmup` 按资源类型执行模板编译/opcache、静态文件页缓存和 FPC 路径预热。Framework/Theme 核心默认只加入首页 `/`，其他路径必须由所属模块的编译 Provider 贡献。

请求阶段不枚举 Provider、不扫描目录、不读取模块清单，也不通过 ObjectManager 查找贡献者。FPM 不执行这组持久 Worker 预热。

具体模块通过各自的编译 Provider 贡献模板、Hook、静态资源或 `fpcPaths`。Framework、Theme 和 Server 都不声明、引用、探测或硬编码 `WeShop_*` 及其他可选业务模块的预热资源。

## 约束

- 资源必须由拥有它的模块声明，不能把其他模块路径转移到自己的 Provider。
- 仅加入稳定且高频的资源；不要把整个模块目录全部预热。
- 删除或重命名资源时同步修改 Provider，并重新执行 `php bin/w framework:compile`。
- Provider 失败只影响对应预热阶段，不得把动态业务依赖带入请求热路径。
