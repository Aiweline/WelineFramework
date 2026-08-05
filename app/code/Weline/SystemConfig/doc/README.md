# Weline SystemConfig 系统配置模块

## 模块概述

Weline SystemConfig 是系统的配置管理模块，提供了统一的配置存储、读取、管理功能，支持多种配置类型和配置继承机制。

## 当前规划入口

- AI 技能入口：开发或读取模块配置前先使用 [system-config-scope](../../../../.codex/skills/system-config-scope/SKILL.md)。
- [SystemConfig Scope 配置树计划](./scope-config-tree-plan.md)：配置模块子计划，定义 `system_config` 如何升级为统一 scope 配置系统。
- [SystemConfig 与 Theme 虚拟布局总计划](./scope-config-theme-layout-master-plan.md)：跨模块总计划，关联 SystemConfig、Framework Scope、Theme 虚拟布局、产品/分类布局接入。
- [Theme 虚拟布局与产品/分类布局计划](../../Theme/doc/virtual-layout-scope-plan.md)：Theme 模块子计划，说明虚拟布局、源码编辑、可视化编辑、AI 创建和定时恢复策略。

## 主要功能

### 0. Typed Scope 解析（TASK-P1C-001）

- 四层 fallback：`Channel → Store → Website → Global`（`ScopeIdentity`）。
- 来源 DTO：`ConfigScopeSource`（`exact|fallback|default|unresolved`）。
- API：`ConfigReader::resolveTypedConfig` / `ConfigStore::resolveTypedConfig` / `ScopedConfigRepositoryInterface::resolveTypedConfig`。
- 零号站 Website（`code=default`）存储为 `default.__website__.default`，与 Global `default.default.default` 可区分。
- **禁止短 scope 新写**（如 `default` / `shop.main`）；lock/unlock 见下节。

### 0.1 Lock suppression（TASK-P1C-002）

- 上级 `lock`：下级弱覆盖行写入 `metadata.suppressed_by_lock_version`（**不用** `is_active=0`）。
- 解析跳过 suppressed 行，回落到上级/Global。
- `unlock` **不自动复活**下级；需显式 `restore_suppressed` / `discard_suppressed`。
- CAS：`base_versions` / `expected_version` 冲突返回 `status=conflict`。
- API：`SystemConfigLockService` / `SystemConfigCenterService::{previewLock,lockScope,unlockScope,previewRestoreSuppressed,restoreSuppressedRows,discardSuppressedRows}`。

### 0.2 配置中心 TargetScope（TASK-P1C-004）

- 后台工作 Scope 用 Website / Store / Channel 三段选择；`Global` = 空 website。
- **写目标只信表单显式** `target_scope` / `website_code+store_code+channel_code`；Session 仅 UI 恢复。
- POST 强制 `form_key` CSRF + Same-Origin（Origin/Referer）；敏感字段需 `reauth_password`。
- Query 写操作缺少显式 TargetScope 时拒绝：`system_config_write_requires_explicit_target_scope`。
- 字段展示：`source_kind` / 覆盖 / 锁定 / 压制徽章。
- 统一配置中心筛选栏使用官方 Taglib：
  - 模块：`<w:module-manager:module:select>`
  - Website：`<w:websites:website:select allow-empty>`（空值 = Global）
  - Store / Channel：`<w:websites:store:select>` / `<w:websites:channel:select>`
  - Locale：`<w:i18n:language:select>`（空值归一为 `default`）
  - 切换 Website/Store/Channel/Module/Locale 会自动提交筛选表单；Website/Store 变更会清空下级段。

### 0.3 配置对象授权（TASK-P1B-004）

- 后台读取和写入除路由 ACL 外，还必须通过对象 Scope ACL；缺少后台身份或对象授权时固定拒绝。
- 新写入只接受显式 `target_scope`；已有版本的查看、回滚和重放只从持久化版本行解析 Scope，忽略请求中的替代 Scope。
- 保存、删除、回滚、锁定、解锁、恢复/丢弃压制行和导入在持久化前必须携带当前
  `expected_grant_version`；预览后撤权或授权版本变化返回固定 403，不产生部分写入。
- 动作映射：读取 `LIST/VIEW`，普通变更 `UPDATE/DELETE`，回滚与压制行处理 `REPLAY`，
  锁定/解锁 `UPDATE/UNLOCK`，加密导出/导入 `EXPORT/IMPORT`。
- 导入预览只校验封包，不授予提交权限；真正导入仍按封包目标 Scope 和当前授权版本重新鉴权。

### 0.4 安全响应头 LKG（TASK-P1D-REV-001）

- Framework 发布 `SecurityPolicyLkgRepositoryInterface` 和
  `SecurityHeaderPolicyOverrideProviderInterface`；SystemConfig 分别提供 ORM
  LKG 仓库和当前 Scope 配置 Provider。
- verified LKG 持久化在 `system_config_security_policy_lkg`，唯一键为
  `(schema_version, scope_key)`；进程重启或 Worker 切换后必须可读取。
- `registerSecurityPolicyLkg` 是对象 Scope `UPDATE` 操作，必须携带显式
  TargetScope 和当前 `expected_grant_version`。
- `SystemConfig::saveScopeConfig()` 在任何写入前重建完整 CSP/CORS 候选；
  LKG 缺失、摘要不匹配、存储不可用或候选弱于 Env Global 基线时固定拒绝，
  不产生部分写入。
- 配置模板由 Framework 通过 Extends 提供：
  `Framework/Extends/module/Weline_SystemConfig/Config/backend/security-headers.phtml`。

### 1. 配置存储
- 数据库配置存储
- 文件配置存储
- 缓存配置存储

### 2. 配置管理
- 配置项创建和编辑
- 配置分组管理
- 配置继承机制

### 3. 配置读取
- 高性能配置读取
- 配置缓存机制
- 配置验证

### 4. 配置界面
- 后台配置界面
- 配置表单生成
- 配置预览

### 5. 配置导入导出
- 配置备份
- 配置恢复
- 配置迁移

## 使用方法

### 配置模板定义
模块通过 Extends 模式把 PHTML 配置模板注册给 `Weline_SystemConfig`，SystemConfig 从 Extends registry 收集模板，不在 Web 运行时扫描模块目录。

模块只负责提供配置模板。全局 scope 切换、模块搜索、配置搜索、继承开关、保存、校验和缓存失效都由 SystemConfig 配置中心统一处理。

推荐路径：

```text
app/code/{Vendor}/{Module}/extends/module/Weline_SystemConfig/Config/{area}/{code}.phtml
```

示例：

```html
<!--
@meta.title 站点基础配置
@meta.description 配置站点名称、描述和维护模式。
@config.area frontend
@config.sort 10
-->

<w:config:group code="general" label="基本设置" sort="10">
    <w:config:field
        key="your_module/general/site_name"
        label="网站名称"
        type="text"
        value-type="string"
        default="Weline"
        required="true"
        scope="global,website,store" />

    <w:config:field
        key="your_module/general/maintenance_mode"
        label="维护模式"
        type="select"
        value-type="bool"
        default="0"
        scope="global,website,store"
        options="0:关闭,1:开启" />
</w:config:group>
```

### 后台引导高亮

业务模块可以从自己的上线检查、向导或工作台跳转到统一配置中心，并让 SystemConfig 高亮目标配置项。该能力属于通用后台配置体验，不绑定具体业务模块。

支持的 URL 参数：

- `module`: 配置模板所属模块，例如 `WeShop_Payment`
- `area`: 配置区域，例如 `backend` 或 `frontend`
- `guide_key`: 需要高亮的完整配置 key；支持逗号/分号/空格分隔的多个 key（也可传 `guide_keys`）
- `guide_locate`: 当前要定位的 key（多目标时用于标记「当前定位」；缺省为第一个 key）
- `guide_title`: 顶部引导卡片标题
- `guide_summary`: 顶部引导卡片说明
- `guide_step`: 向导步骤编号或短标签
- `guide_return`: 保存或查看后返回的后台引导 URL

`guide_return` 只接受站内相对 URL 或与当前请求同 host 的 `http/https` URL；外部域名、
协议相对 URL 和脚本协议会被配置中心丢弃，避免配置向导被复用成开放跳转入口。

示例：

```php
$url = $urlBuilder->getBackendUrl('weline_systemconfig/backend/config', [
    'module' => 'Vendor_Module',
    'area' => 'backend',
    'guide_key' => 'vendor_module/oauth/client_id,vendor_module/oauth/client_secret',
    'guide_locate' => 'vendor_module/oauth/client_secret',
    'guide_title' => (string)__('配置 OAuth 应用'),
    'guide_summary' => (string)__('请依次填写 Client ID 与 Client Secret。'),
    'guide_step' => '03',
    'guide_return' => $urlBuilder->getBackendUrl('vendor_module/backend/readiness'),
]);
```

配置中心会在顶部展示引导卡片，并在右侧提供悬浮定位菜单。多个 `guide_key` 都会标记为引导目标；当前项由 `guide_locate` 标识。点击菜单项时：若目标已在当前页则平滑滚动定位，若不在当前页（例如模块筛选不同）则刷新到对应模块页面后再定位。保存或回滚配置后，引导参数会继续保留。

### 配置读取
```php
use Weline\SystemConfig\Helper\Config;

$config = new Config();

// 读取单个配置
$siteName = $config->get('your_module/site_name', '默认值');

// 读取配置组
$moduleConfigs = $config->getGroup('your_module');

// 读取所有配置
$allConfigs = $config->getAll();
```

`w_query('system_config', ...)` 是模块之间读取 SystemConfig 的推荐入口。默认读取保持旧行为：

```php
$enabled = w_query('system_config', 'getConfig', [
    'module' => 'Vendor_Module',
    'area' => 'backend',
    'key' => 'vendor_module/general/enabled',
    'default' => 0,
]);

$map = w_query('system_config', 'getConfigs', [
    'module' => 'Vendor_Module',
    'area' => 'backend',
]);
```

PHP 服务需要在热路径读取配置时，可注入或从进程容器取得
`Weline\SystemConfig\Api\ConfigReader`。该只读 API 保留 `getConfig()`、
`getConfigMapByModule()`、scope/locale 归一化和 fallback 查询，不向调用模块暴露
`SystemConfig` ORM Model；写入仍必须走 SystemConfig 的保存边界。

需要写入的 PHP 服务使用 `Weline\SystemConfig\Api\ConfigStore`。新后台和站点级写入
必须调用 `setScopedConfig()` / `deleteScopedConfig()` 并传入显式 scope；`setConfig()`
只保留给既有全局配置写入兼容，不得用当前后台请求的隐式 scope 代替管理员选择。

`ConfigStore::resolveConfig()` 是原子生产者的读后写快照入口：它从当前数据库事务直接解析值，
不命中可能仍是旧值的 cache envelope。跨模块原子写入必须先用
`ConfigStore::useConnection()` 绑定调用方已激活的 Framework 主库连接。

## 提交后缓存失效

`SystemConfig::saveScopeConfig()` 和版本回滚不再在 commit 前删除请求或共享缓存。
`ConfigCacheInvalidationService` 会按 `module + area + scope + locale` 合并重复失效：

- 如果调用方已在 `TransactionCoordinator` 主库事务中，只登记去重 afterCommit 回调；回滚不会触碰缓存。
- 如果是 standalone 写入，在业务 SQL 成功后立即执行同一失效逻辑。
- Website 的两个 start-page key 还会在同事务推进
  `website/{code}/config/start-page` namespace。Website 完整生产者可显式延迟这次 bump，
  由后续 `ResourceChange` critical Observer 统一执行，保证固定顺序。
- `setScopedConfig()` / `deleteScopedConfig()` 的公开 bool 契约不变；业务编排必须对每一次返回值使用 `=== true`，非 true 必须作为整体保存失败。

### 继承影响图（TASK-P1C-003 / TEST-P1C-06）

- `ScopeConfigCacheInvalidator`：写父层时发现真实后代，**跳过**有显式覆盖（且未 suppressed）的单 key 后代。
- 先按旧 version vector 删键，再 bump `system_config_scope_gen:{scope}`；读侧 cache key 含祖先 generation 向量（`KeyBuilder::systemConfigVersionVectorToken`），未知继承者不会脏读。
- 指标写入 `RequestContext system_config.cache_invalidation.last_plan`。
- 失败策略：宁可临时扩大失效并重建，不可保留脏读。

Theme 虚拟布局这类需要配置批次、版本列表、回滚预检和 fallback 的必需依赖模块，使用
`Weline\SystemConfig\Api\Scope\ScopedConfigRepositoryInterface`。该 Provider 精确委托现有
SystemConfig 批次语义，不重新实现 scope 系统；跨模块只接收数组，并通过
`ScopedConfigData` 读取稳定字段键，不得引用 `Model\SystemConfig`、
`Model\SystemConfigVersion` 或它们的 schema 常量。

需要字段元信息时显式传 `return_type`：

```php
$field = w_query('system_config', 'getConfig', [
    'module' => 'Vendor_Module',
    'area' => 'backend',
    'code' => 'general',
    'key' => 'vendor_module/general/enabled',
    'return_type' => 'field',
]);

$fields = w_query('system_config', 'getConfigs', [
    'module' => 'Vendor_Module',
    'area' => 'backend',
    'return_type' => 'fields',
]);
```

字段对象包含 `value`、`display_value`、`label`、`description`、`type`、`value_type`、
`default`、`group`、`scope`、`options`、`field_found`、`value_found`、`has_override`、
`base_version`、`is_sensitive`、`source` 和 `template`。字段属性不通过
`.value` 或 `.label` 后缀读取；配置 key 始终是完整 key。

### 配置写入
```php
use Weline\SystemConfig\Helper\Config;

$config = new Config();

// 设置单个配置
$config->set('your_module/site_name', '新网站名称');

// 批量设置配置
$configs = [
    'your_module/site_name' => '新网站名称',
    'your_module/site_description' => '新网站描述'
];
$config->setMultiple($configs);
```

### 配置验证
```php
use Weline\SystemConfig\Validator\ConfigValidator;

$validator = new ConfigValidator();
$validator->addRule('site_name', 'required|min:2|max:50');
$validator->addRule('site_description', 'max:200');

$data = [
    'site_name' => '测试网站',
    'site_description' => '这是一个测试网站'
];

if ($validator->validate($data)) {
    // 验证通过，保存配置
    $config->setMultiple($data);
} else {
    $errors = $validator->getErrors();
}
```

## 配置说明

### 配置类型
系统支持以下配置类型：

- `text`: 文本输入框
- `textarea`: 多行文本输入框
- `select`: 下拉选择框
- `checkbox`: 复选框
- `radio`: 单选框
- `file`: 文件上传
- `image`: 图片上传
- `color`: 颜色选择器
- `date`: 日期选择器
- `datetime`: 日期时间选择器

### 配置存储
配置值统一存入 `system_config`；配置模板只声明后台表单、分组、字段、校验和默认值，不直接写入配置值。

SystemConfig 只保存 `<w:config:field>` 声明过的 key，并按当前显式选择的 scope 写入。未声明字段、非法 scope、env 锁定字段和校验失败字段都应拒绝保存。

### 配置分组
配置分组由 `<w:config:group>` 声明，不再通过独立数组维护。配置模板可以写普通 PHTML 逻辑生成选项或说明，但最终可保存字段必须落在 `<w:config:field>` 白名单内。

## 依赖关系

- Weline_Framework

## 版本信息

- 当前版本：1.0.0
- 作者：秋枫雁飞
- 邮箱：aiweline@qq.com
- 网址：aiweline.com

## 配置继承机制

### 配置层级
```
默认配置 -> 模块配置 -> 主题配置 -> 用户配置
```

### 配置覆盖
```php
// 获取配置时按优先级返回
$value = $config->get('setting_name');

// 获取特定层级的配置
$defaultValue = $config->getDefault('setting_name');
$moduleValue = $config->getModule('setting_name');
$themeValue = $config->getTheme('setting_name');
$userValue = $config->getUser('setting_name');
```

## 配置界面

### 后台配置界面
```php
namespace Your\Module\Controller\Admin;

use Weline\Admin\Controller\AbstractAdminController;

class ConfigController extends AbstractAdminController
{
    public function index()
    {
        $config = new \Your\Module\Config\YourConfig();
        $this->assign('configs', $config->getConfigs());
        return $this->fetch('config/index');
    }
    
    public function save()
    {
        $data = $this->getRequest()->getPost();
        $config = new Config();
        
        if ($config->setMultiple($data)) {
            $this->success('配置保存成功');
        } else {
            $this->error('配置保存失败');
        }
    }
}
```

### 配置表单模板
```html
<!-- 配置表单模板 -->
<form method="post" action="{$url}">
    {foreach $configs as $key => $config}
        <div class="form-group">
            <label>{$config.label}</label>
            
            {if $config.type == 'text'}
                <input type="text" name="{$key}" value="{$config.value}" class="form-control">
            {elseif $config.type == 'textarea'}
                <textarea name="{$key}" class="form-control">{$config.value}</textarea>
            {elseif $config.type == 'select'}
                <select name="{$key}" class="form-control">
                    {foreach $config.options as $option_value => $option_label}
                        <option value="{$option_value}" {if $config.value == $option_value}selected{/if}>
                            {$option_label}
                        </option>
                    {/foreach}
                </select>
            {/if}
            
            {if $config.description}
                <small class="form-text text-muted">{$config.description}</small>
            {/if}
        </div>
    {/foreach}
    
    <button type="submit" class="btn btn-primary">保存配置</button>
</form>
```

## 配置导入导出

跨实例导入/导出必须使用 AEAD envelope（TASK-P1D-003），见：

- `app/code/Weline/Framework/doc/3-开发/配置包AEAD信封.md`
- `Weline\SystemConfig\Service\ConfigEnvelopeService`

历史明文 Helper 已 fail-closed：

```php
use Weline\SystemConfig\Helper\ConfigExport; // throws config_envelope_plaintext_export_forbidden
use Weline\SystemConfig\Helper\ConfigImport; // throws config_envelope_plaintext_import_forbidden
```

推荐：

```php
use Weline\SystemConfig\Service\ConfigEnvelopeService;
use Weline\Framework\Runtime\ScopeIdentity;

$svc = ConfigEnvelopeService::fromEnv(); // 需 security.config_envelope.enabled=true
$envelope = $svc->export($configData, ScopeIdentity::website(0, 'default'), 'backup.json');
// 一次性下载 envelope JSON；目标实例：
$svc->import($envelope, function (array $payload, array $aad): void {
    // AEAD 已验证且 package_uuid 已唯一 claim；在此事务写配置。
    // apply 失败则账本 markFailed，uuid 不可重放。
}, 'backup.json', ScopeIdentity::website(0, 'default'));
```

## 性能优化

### 1. 配置缓存
- 启用配置缓存
- 合理设置缓存时间
- 及时清理过期缓存

### 2. 数据库优化
- 配置表索引优化
- 批量操作优化
- 查询语句优化

### 3. 内存优化
- 配置数据内存缓存
- 减少重复查询
- 优化配置读取算法

## 安全考虑

### 1. 敏感配置加密
```php
// 加密敏感配置
$config->set('database/password', 'password123', true);

// 读取时自动解密
$password = $config->get('database/password');
```

### 2. 配置访问控制
```php
// 检查配置访问权限
if ($acl->isAllowed($userId, 'system::config', 'read')) {
    $value = $config->get('sensitive_setting');
} else {
    throw new \Exception('无权限访问此配置');
}
```

### 3. 配置验证
- 输入数据验证
- 配置值类型检查
- 配置值范围验证

## 调试和测试

### 配置调试
```php
// 开启配置调试
$config->setDebug(true);

// 查看配置读取过程
$value = $config->get('setting_name');
$debug = $config->getDebugInfo();
```

### 配置测试
```php
// 配置功能测试
class ConfigTest extends TestCase
{
    public function testConfigReadWrite()
    {
        $config = new Config();
        
        // 测试写入
        $config->set('test/setting', 'test_value');
        
        // 测试读取
        $value = $config->get('test/setting');
        $this->assertEquals('test_value', $value);
    }
}
```

## 最佳实践

### 1. 配置命名
- 使用模块前缀
- 使用小写字母和下划线
- 保持命名一致性

### 2. 配置组织
- 按功能分组
- 合理设置默认值
- 提供配置说明

### 3. 配置管理
- 定期备份配置
- 版本控制配置变更
- 记录配置变更日志

### 4. 性能优化
- 合理使用缓存
- 避免频繁配置读取
- 优化配置查询 
