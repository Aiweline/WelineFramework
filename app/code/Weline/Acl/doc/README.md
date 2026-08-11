# Weline Acl 权限控制模块

## 模块概述

Weline Acl (Access Control List) 是系统的权限控制模块，提供了基于角色的访问控制功能，确保系统安全性和数据保护。

## 主要功能

### 1. 角色管理
- 角色创建和编辑
- 角色层级关系
- 角色权限分配

### 2. 权限管理
- 资源权限定义
- 操作权限控制
- 权限继承机制

### 3. 用户权限
- 用户角色分配
- 权限验证
- 权限缓存

### 4. 菜单权限
- 菜单访问控制
- 动态菜单生成
- 权限过滤

后台菜单和 Controller 使用统一 source 语法：

`Vendor_Module::tag1:tag2:code`

最后一段是叶子资源 code，前面的段是有序 ACL 标签。统一通过
`Weline\Framework\Authorization\Resource\SourceIdParser` 解析或生成；模块菜单来自
`etc/backend/menu.xml`，Controller/action 权限来自
`#[Weline\Framework\Acl\Acl]`，不存在额外的 `acl.xml` 注册面。

角色可以获得精确叶子资源或标签路径授权。标签路径在 ACL 同步时展开为已存在、已启用的
具体资源；`AclTag` 的名称、说明、颜色和排序仅用于展示，不产生权限。父菜单只负责拓扑与
祖先可见性，不能代替叶子 Controller/action 授权。

验收必须同时证明：无权限角色看不到菜单，且手工输入 URL 仍被拒绝；有权限角色能看到
菜单并到达真实页面。QueryProvider、后台任务和运维操作同样使用精确 source，不从父菜单、
路由前缀或标签元数据推断权限。

跨模块读取后台菜单树或权限分配树时，只能依赖
`Weline\Acl\Api\ResourceTreeServiceInterface`；角色参数使用
`Weline\Acl\Api\RoleIdentityInterface`。旧 `Service/ResourceTreeServiceInterface` 仅保留
第三方兼容，新的调用方不得引用 ACL Service 或 Model。

API 应用授权只能通过 `Weline\Acl\Api\Scope\ScopeCatalogInterface`
读取可暴露 scope 和已启用资源；该契约只返回数组，不向调用模块暴露
ORM 或 Query Builder。

跨模块权限判定统一使用
`Weline\Acl\Api\Authorization\AuthorizationServiceInterface`，访问模式常量使用
`Weline\Acl\Api\Authorization\AccessMode`。角色查询统一使用
`Weline\Acl\Api\Role\RoleCatalogInterface`，结果是只读 `RoleRecord`，不会暴露
Role ORM、查询条件或可变请求状态。

## 对象 Scope ACL（P1B-004）

对象级授权与路由 RBAC / API `ScopeCatalogInterface` **分离**：

- 动作矩阵：`ObjectAction`（list/view/create/update/delete/physical-delete/refund/fulfill/export/import/unlock/replay/reconcile/all-sites）
- 判定服务：`ObjectAuthorizationServiceInterface`（default-deny；先动作后 Scope 交集）
- 授权表：`weline_acl_object_scope_grant`（`ObjectScopeGrant`）
- All Sites：独立 `is_all_sites=1` 授权，**永远只读**（list/view/export）；禁止写动作
- 禁止 `Store=*` / `Channel=*` 通配写授权；`website_id=0` 是合法默认站
- 提交重鉴权：`authorizeForSubmit(..., expectedGrantVersion)`；预览后撤权或版本变化必须失败
- 超管 `role_id=1` **不**隐式获得对象写权限
- Catalog 读：`websites.getStoreCatalogV1` / `getSalesChannelCatalogV1` 在后台会话下按对象 LIST 过滤，无权限返回空列表（不泄漏存在性）

后台 Controller 与 QueryProvider 统一依赖
`BackendObjectAuthorizationGuardInterface`。Guard 从当前后台身份解析角色：
身份缺失、禁用或非法时一律 fail-closed；查询拒绝固定返回
`403 object_scope_access_denied`，不得把角色、授权行或 Scope 判定原因暴露给调用方。
读列表使用无审计的 `isAllowed()` 做投影前过滤；提交使用
`requireForSubmit()`，并在真正持久化前校验当前授权版本。提交允许与拒绝都会写入脱敏审计，
审计只保留动作、阶段、Scope 类型、Scope 摘要和授权版本。
`SecurityLog` 会把底层保存结果收敛为布尔值并捕获底层 `Throwable`；审计存储异常不得把
已经完成的拒绝判定升级为 WLS `500`，也不得改变授权决定。
每次安全审计必须使用新的 Model 实例并显式写入 `created_at`，避免容器共享实例携带上一次
写入状态。Payment repair 使用 `reconcile` 写动作，并对操作者与独立审批者分别执行
`authorizeForSubmit(..., expectedGrantVersion)`；两次判定都会留下对象授权审计。

```php
use Weline\Acl\Api\Authorization\ObjectAction;
use Weline\Acl\Api\Authorization\ObjectAuthorizationServiceInterface;
use Weline\Framework\Runtime\ScopeIdentity;

/** @var ObjectAuthorizationServiceInterface $auth */
$ok = $auth->isObjectActionAllowed(
    $roleId,
    ObjectAction::UPDATE,
    ScopeIdentity::store(0, 'default', 'default', 'normal'),
);
$submit = $auth->authorizeForSubmit(
    $roleId,
    ObjectAction::UPDATE,
    ScopeIdentity::website(0, 'default'),
    $expectedGrantVersion,
);
```

非 HTTP 路由传输（例如站内 Worker QueryProvider）按 ACL `source_id` 判定时，使用
`Weline\Acl\Api\Authorization\ResourceAuthorizationServiceInterface::isSourceAllowed()`。
该契约只做精确 source 匹配：资源必须存在、启用且属于后台；普通角色还必须有精确
`RoleAccess.source_id`，不做父级、前缀、route 或菜单可见性推断。超级管理员也只在资源
真实存在并启用后放行，因此 descriptor 拼错 source 会 fail-closed。不要用
`AuthorizationServiceInterface::isRouteAllowed()` 代替它；旧 route API 对未保护路由具有白名单语义。

安装期角色写入使用 `Weline\Acl\Api\Role\RoleAdministrationInterface`；依赖模块只提交
role id、名称和说明，不得直接实例化 ACL Role Model。`RoleRecord` 同时实现
`RoleIdentityInterface`，可直接传入资源树读取契约。

后台 menu.xml 的解析和拓扑仍由 Backend 所有，ACL 通过
`Weline\Acl\Api\Resource\MenuRegistryInterface` 独占 ACL 菜单表的查询、禁用、删除和
upsert。接口只接收标量数组，不向 Backend 暴露 ORM 或查询构造器。
`setup:upgrade --route` 收集控制器 ACL 时，既有父级查询与 upsert 必须以固定小批次执行并
保持在同一事务内，避免 SQLite 的绑定变量上限使大型模块路由同步提前中止；写入异常只记
诊断日志并继续向上抛出，禁止调试输出提前结束命令并伪造成功退出码。
审计、遥测等模块需要把 `class + HTTP method + route` 解析为 ACL 资源时，使用
`AuthorizationServiceInterface::findRouteResource()`。结果是 final readonly `RouteResource`，
只含 `acl_id` 与 `source_name`；未匹配返回 `null`，ORM Model 和 Query Builder 留在 Acl 内部。

`Weline\Acl\Api\Role` 和 `RoleAccess` 是历史兼容 alias，仅用于尚未迁移的旧调用；
新代码禁止引用、继承或继续扩散这两个 alias。跨模块新代码只能使用上述 DTO、catalog
和 authorization 契约。

### 5. 操作权限
- 按钮权限控制
- API 接口权限
- 数据权限过滤

## 使用方法

### 角色创建
```php
use Weline\Acl\Model\Role;

$role = new Role();
$role->setName('管理员');
$role->setDescription('系统管理员角色');
$role->setParentId(0);
$role->save();
```

### 权限分配
```php
use Weline\Acl\Model\Permission;

$permission = new Permission();
$permission->setRoleId($roleId);
$permission->setResource('admin::system::config');
$permission->setAction('read');
$permission->save();
```

### 权限验证
```php
use Weline\Acl\Helper\Acl;

$acl = new Acl();

// 检查用户是否有某个权限
if ($acl->isAllowed($userId, 'admin::system::config', 'read')) {
    // 有权限执行操作
} else {
    // 无权限，拒绝访问
}
```

### 控制器权限控制
```php
namespace Your\Module\Controller;

use Weline\Framework\Controller\AbstractController;
use Weline\Acl\Helper\Acl;

class YourController extends AbstractController
{
    protected function _init()
    {
        parent::_init();

        // 检查访问权限
        $acl = new Acl();
        if (!$acl->isAllowed($this->getUserId(), 'your::module::controller', 'access')) {
            $this->redirect('admin/auth/denied');
        }
    }

    public function index()
    {
        // 控制器逻辑
    }
}
```

## 配置说明

### ACL 配置
在 `app/etc/acl.php` 中配置权限相关设置：

```php
'acl' => [
    'cache' => true,
    'cache_time' => 3600,
    'default_role' => 'guest',
    'super_admin_role' => 'super_admin'
]
```

### 资源定义
```php
'resources' => [
    'admin::system::config' => [
        'label' => '系统配置',
        'actions' => ['read', 'write', 'delete']
    ],
    'admin::user::manage' => [
        'label' => '用户管理',
        'actions' => ['read', 'write', 'delete']
    ]
]
```

## 依赖关系

- Weline_Framework

## 版本信息

- 当前版本：1.0.0
- 作者：秋枫雁飞
- 邮箱：aiweline@qq.com
- 网址：aiweline.com

## 权限模型

### RBAC (基于角色的访问控制)
```
用户 -> 角色 -> 权限 -> 资源
```

### 权限继承
- 子角色继承父角色权限
- 支持权限覆盖
- 支持权限拒绝

### 权限缓存
- 权限数据缓存
- 缓存自动更新
- 性能优化

## 安全最佳实践

### 1. 最小权限原则
- 只分配必要的权限
- 定期审查权限分配
- 及时撤销不需要的权限

### 2. 权限验证
- 前端和后端双重验证
- 敏感操作二次确认
- 操作日志记录

### 3. 权限管理
- 角色权限分离
- 定期权限审计
- 权限变更通知

## 权限检查示例

### 模板中的权限检查
```html
{if $acl->isAllowed($user_id, 'admin::system::config', 'write')}
    <button class="btn btn-primary">编辑配置</button>
{/if}

{if $acl->isAllowed($user_id, 'admin::user::manage', 'delete')}
    <button class="btn btn-danger">删除用户</button>
{/if}
```

### API 接口权限检查
```php
public function apiAction()
{
    $acl = new Acl();
    $resource = $this->getRequest()->getParam('resource');
    $action = $this->getRequest()->getParam('action');

    if (!$acl->isAllowed($this->getUserId(), $resource, $action)) {
        return $this->error('权限不足');
    }

    // 执行API逻辑
}
```

## 权限管理界面

### 角色管理
- 角色列表
- 角色创建/编辑
- 角色删除
- 角色权限分配

### 权限分配
- 权限树形展示
- 批量权限分配
- 权限继承设置
- 权限冲突检测

### 用户权限
- 用户角色查看
- 用户权限检查
- 权限测试工具

## 调试和测试

### 权限调试
```php
// 开启权限调试
$acl->setDebug(true);

// 查看权限检查过程
$result = $acl->isAllowed($userId, $resource, $action);
$debug = $acl->getDebugInfo();
```

### 权限测试
```php
// 测试用户权限
$testCases = [
    ['user_id' => 1, 'resource' => 'admin::system::config', 'action' => 'read'],
    ['user_id' => 1, 'resource' => 'admin::system::config', 'action' => 'write']
];

foreach ($testCases as $case) {
    $result = $acl->isAllowed($case['user_id'], $case['resource'], $case['action']);
    echo "用户 {$case['user_id']} 对 {$case['resource']} 的 {$case['action']} 权限: " . ($result ? '允许' : '拒绝') . "\n";
}
```

## 性能优化

### 1. 权限缓存
- 启用权限缓存
- 合理设置缓存时间
- 及时清理过期缓存

### 2. 数据库优化
- 权限表索引优化
- 查询语句优化
- 批量操作优化

### 3. 内存优化
- 权限数据内存缓存
- 减少重复查询
- 优化权限检查算法

## 多资源编目

详见 [multi-resource-catalog.md](./multi-resource-catalog.md)：Query/task 投影、`resource_type`、标签整包赋权、`kind=self` 例外与 compile→upgrade 命令链。
