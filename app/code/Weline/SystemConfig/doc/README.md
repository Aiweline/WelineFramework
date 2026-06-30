# Weline SystemConfig 系统配置模块

## 模块概述

Weline SystemConfig 是系统的配置管理模块，提供了统一的配置存储、读取、管理功能，支持多种配置类型和配置继承机制。

## 当前规划入口

- AI 技能入口：开发或读取模块配置前先使用 [system-config-scope](../../../../.codex/skills/system-config-scope/SKILL.md)。
- [SystemConfig Scope 配置树计划](./scope-config-tree-plan.md)：配置模块子计划，定义 `system_config` 如何升级为统一 scope 配置系统。
- [SystemConfig 与 Theme 虚拟布局总计划](./scope-config-theme-layout-master-plan.md)：跨模块总计划，关联 SystemConfig、Framework Scope、Theme 虚拟布局、产品/分类布局接入。
- [Theme 虚拟布局与产品/分类布局计划](../../Theme/doc/virtual-layout-scope-plan.md)：Theme 模块子计划，说明虚拟布局、源码编辑、可视化编辑、AI 创建和定时恢复策略。

## 主要功能

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

### 配置导出
```php
use Weline\SystemConfig\Helper\ConfigExport;

$exporter = new ConfigExport();
$configData = $exporter->export('your_module');

// 导出为JSON文件
file_put_contents('config_backup.json', json_encode($configData, JSON_PRETTY_PRINT));
```

### 配置导入
```php
use Weline\SystemConfig\Helper\ConfigImport;

$importer = new ConfigImport();
$configData = json_decode(file_get_contents('config_backup.json'), true);

if ($importer->import($configData)) {
    echo '配置导入成功';
} else {
    echo '配置导入失败';
}
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
