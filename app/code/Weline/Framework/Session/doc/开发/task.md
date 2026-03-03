# Session SOLID 重构任务清单

**状态**：🟢 已完成

## 阶段 1：接口设计

- [x] 创建 `SessionDataInterface`
- [x] 创建 `SessionLifecycleInterface`
- [x] 创建 `SessionInterface`（组合上述两个）
- [x] 创建 `AuthenticatedSessionInterface`
- [x] 创建 `AuthenticableInterface`
- [x] 创建 `SessionStorageInterface`
- [x] 创建 `SessionStrategyInterface`

## 阶段 2：存储层实现

- [x] 实现 `FileStorage`
- [x] 实现 `RedisStorage`
- [x] 实现 `WlsSharedStorage`

## 阶段 3：策略层实现

- [x] 实现 `FpmStrategy`
- [x] 实现 `WlsStrategy`

## 阶段 4：Session 核心实现

- [x] 重构 `Session` 类（移除认证逻辑）
- [x] 仅保留数据存取和生命周期方法

## 阶段 5：认证层实现

- [x] 创建 `AreaConfig`（区域配置）
- [x] 创建 `AuthenticatedSession`（组合 Session + AreaConfig）

## 阶段 6：工厂类

- [x] 创建 `SessionFactory`
- [x] 删除 `SessionManager`
- [x] 添加静态便捷方法

## 阶段 7：控制器集成

- [x] 更新 `BackendController`
- [x] 更新 `FrontendController`
- [x] 更新 `BackendRestController`

## 阶段 8：用户模型

- [x] `BackendUser` 实现 `AuthenticableInterface`
- [x] `Customer` 实现 `AuthenticableInterface`

## 阶段 9：废弃旧类

- [x] 标记 `SessionDriverInterceptor` 为废弃

## 阶段 10：删除旧 Session 类

- [x] 删除 `BackendSession`
- [x] 删除 `AdminSession`
- [x] 删除 `FrontendSession`
- [x] 删除 `FrontendUserSession`
- [x] 删除 `BackendApiSession`
- [x] 删除 `FrontendApiSession`

## 阶段 11：批量替换调用

- [x] 替换 `TwoFactorAuth` 控制器
- [x] 替换 `Visitor` 测试文件
- [x] 替换 `Acl` 控制器
- [x] 替换 `Backend/Controller/Api/Auth`
- [x] 替换 `Api/Api/Rest/V1/Backend/Auth`
- [x] 替换 `Framework/Controller/Backend/Api/Query`
- [x] 替换 `DataTable/Test/Unit/ApiTest`
- [x] 添加 `getLoginUserData()` 兼容方法

## 阶段 12：WLS 状态管理

- [x] `StateManager` 注册 `SessionFactory::resetRequestInstances()`
- [x] 验证 WLS 模式下状态重置

## 阶段 13：测试

- [x] 单元测试存在
- [x] 集成测试

## 阶段 14：文档

- [x] 更新 `README.md`
- [x] 添加扩展指南
- [x] 添加迁移指南

## 补充任务（扩展机制）

- [x] `AreaConfig::registerArea()` - 自定义区域注册
- [x] `SessionFactory::createCustomSession()` - 自定义区域 Session
- [x] `AbstractBusinessSession` - 业务 Session 抽象基类
- [x] `CartSession` - 购物车 Session 示例
- [x] `WishlistSession` - 愿望清单 Session 示例
- [x] `BackendTokenService` - 替代已删除的 `BackendApiSession` Token 功能
