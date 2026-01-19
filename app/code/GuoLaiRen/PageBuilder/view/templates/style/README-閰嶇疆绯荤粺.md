# PageBuilder 样式配置系统

## 概述

本系统允许每个样式（Style）定义独立的配置项，这些配置项按照 Header、Content、Footer 三个部分组织，用户可以在后台编辑页面时配置这些参数，并在前台页面内容中使用这些配置值。

## 配置优先级系统

本系统实现了三级配置优先级：

**优先级（从高到低）：**
1. **翻译配置（最高优先级）** - 存储在 `Page\LocalDescription.config.style_config` 中
2. **页面保存配置（中等优先级）** - 存储在 `Page.style_setting` 中
3. **默认配置（最低优先级）** - 定义在样式的 `.phtml` 文件中

这意味着：
- 当样式模板添加新字段时，会自动使用默认配置
- 当样式模板删除字段时，保存的配置会被自动忽略
- 翻译的配置值会覆盖页面保存的值，实现多语言定制

## 核心功能

### 1. 样式配置定义

每个样式的三个文件（`header.phtml`、`footer.phtml`、`content.phtml`）都可以在文件顶部定义配置项：

```php
/**
 * @fields_start
 *
 * logo_position => Logo位置:select:left|left,center,right
 * background_color => 背景颜色:color:#ffffff
 * height => 高度:number:80|px
 *
 * @fields_end
 */
```

**配置格式说明：**
- `key => label:type:default|unit/options`
- **key**: 配置键名（会自动添加文件前缀，如 `header.logo_position`）
- **label**: 显示标签（支持多语言）
- **type**: 字段类型
  - `text`: 文本输入
  - `number`: 数字输入
  - `select`: 下拉选择
  - `color`: 颜色选择器
  - `textarea`: 多行文本
  - `image`: 图片URL
- **default**: 默认值
- **unit**: 单位（如 px, %, em）
- **options**: 选项列表（用逗号分隔，如 `left,center,right`）

### 2. 后台配置界面

在页面编辑表单中：

1. **选择样式**：首先选择页面使用的样式模板
2. **查看配置**：选择样式后，系统自动加载并显示该样式的配置项
3. **分组显示**：配置项按 Header、Content、Footer 分组显示在独立的卡片中
4. **实时预览**：颜色选择器提供实时预览
5. **智能提示**：显示样式描述和配置说明

**配置保存机制：**
- 每个样式的配置独立存储
- 切换样式时，之前样式的配置会被保留
- 再次切换回原样式时，配置会自动恢复

### 3. 在页面内容中使用配置（使用 {{}} 语法）

**重要：** `{{variable.key}}` 语法**仅**在后台编辑的页面内容中使用，不能在 `.phtml` 模板文件中使用。

在后台编辑器中输入的 HTML 内容可以使用：

```html
<div style="background: {{style.header.background_color}}">
    <h1 style="font-size: {{style.content.title_size}}px">
        {{page.title}}
    </h1>
</div>
```

**支持的变量：**
- `{{style.xxx}}`: 样式配置值（如 `{{style.header.background_color}}`）
- `{{page.xxx}}`: 页面数据（如 `{{page.title}}`）
- `{{content.xxx}}`: 内容数据

### 4. 在模板文件中使用配置（使用 PHP 语法）

**重要：** 在 `.phtml` 模板文件中**必须**使用 PHP 语法，不能使用 `{{}}` 语法。

在 `header.phtml`、`footer.phtml`、`content.phtml` 中：

```php
<?php
// 获取样式配置数组
$style = $this->getData('style') ?: [];
?>

<header style="background: <?= $style['header.background_color'] ?? '#fff' ?>; height: <?= $style['header.height'] ?? '80' ?>px;">
    <div class="container">
        <!-- 使用 PHP 语法访问配置 -->
        <div style="text-align: <?= $style['header.logo_position'] ?? 'left' ?>;">
            Logo
        </div>
    </div>
</header>
```

**PHP 语法访问配置的推荐方式：**
```php
// 方式1：使用 ?? 运算符提供默认值
<?= $style['header.background_color'] ?? '#ffffff' ?>

// 方式2：使用 isset 检查
<?= isset($style['header.background_color']) ? $style['header.background_color'] : '#ffffff' ?>

// 方式3：使用三元运算符（PHP 7.0+）
<?= $style['header.background_color'] ?: '#ffffff' ?>
```

### 5. 翻译配置

管理员可以为不同语言配置不同的样式参数。翻译的配置存储在 `Page\LocalDescription` 的 `config` 字段中：

**数据结构：**
```json
{
  "style_config": {
    "header.logo_position": "center",
    "header.background_color": "#ff0000",
    "content.title_size": "36",
    "footer.show_copyright": "no"
  }
}
```

**配置方式：**
1. 通过后台页面编辑界面的语言标签进行翻译
2. 每个语言可以有独立的样式配置
3. 未翻译的配置项会使用页面保存的默认配置

## 数据存储结构

### Page 表

新增 `style_setting` 字段（TEXT类型），存储JSON格式的配置数据：

```json
{
  "default": {
    "header.logo_position": "left",
    "header.background_color": "#ffffff",
    "header.height": "80",
    "content.max_width": "1200",
    "content.padding": "40",
    "footer.show_copyright": "yes"
  },
  "modern": {
    "header.logo_position": "center",
    "header.background_color": "#000000",
    "header.height": "100"
  }
}
```

**键名规则：**
- 第一层：样式代码（style code）
- 第二层：`{文件名}.{配置键名}`

## 工作流程

### 后台编辑流程

1. **创建/编辑页面**
   - 选择样式模板
   - 系统加载该样式的配置定义（通过解析 `@fields_start` 块）
   - 显示配置表单，按 Header、Content、Footer 分组

2. **保存页面**
   - 收集表单中的 `style_settings[key]` 数据
   - 获取页面原有的所有样式配置
   - 更新当前样式的配置，保留其他样式的配置
   - 保存到 `style_setting` 字段

3. **切换样式**
   - AJAX 请求获取新样式的配置定义
   - 动态渲染配置表单
   - 如果之前配置过该样式，自动填充已保存的值
   - 保存时不影响其他样式的配置

### 前台渲染流程（含优先级合并）

1. **加载页面数据**
   - 获取页面的 `style` 和 `style_setting` 字段
   - 从 `style_setting` 中提取当前样式的配置
   - 加载当前语言的本地化描述（LocalDescription）

2. **三级配置合并（按优先级）**
   
   **第一步：使用默认配置（最低优先级）**
   ```php
   // 从样式定义文件解析配置
   $styleConfigs = $style->parseStyleConfig();
   foreach ($styleConfigs as $key => $config) {
       $finalSettings[$key] = $config['default'];
   }
   ```
   
   **第二步：用页面保存的配置覆盖（中等优先级）**
   ```php
   foreach ($pageStyleSettings as $key => $value) {
       // 只覆盖样式定义中存在的配置项（动态同步）
       if (isset($styleConfigs[$key])) {
           $finalSettings[$key] = $value;
       }
   }
   ```
   
   **第三步：用翻译的配置覆盖（最高优先级）**
   ```php
   if (isset($localizedContent['config']['style_config'])) {
       foreach ($localizedContent['config']['style_config'] as $key => $value) {
           // 只覆盖样式定义中存在的配置项（动态同步）
           if (isset($styleConfigs[$key])) {
               $finalSettings[$key] = $value;
           }
       }
   }
   ```

3. **渲染内容**
   - 将最终配置数据传递给模板（`$style` 变量）
   - 解析内容中的 `{{variable.key}}` 占位符
   - 渲染 header、content、footer

## API 接口

### getStyleConfig (AJAX)

**URL**: `/backend/pagebuilder/index/getStyleConfig`

**参数**:
- `style_code`: 样式代码

**返回**:
```json
{
  "success": true,
  "data": {
    "header": {
      "label": "头部配置",
      "configs": {
        "header.logo_position": {
          "key": "header.logo_position",
          "label": "Logo位置",
          "type": "select",
          "default": "left",
          "options": ["left", "center", "right"]
        }
      }
    }
  }
}
```

## 示例

### 定义配置

在 `header.phtml` 顶部：

```php
/**
 * @fields_start
 *
 * logo_position => Logo位置:select:left|left,center,right
 * nav_position => 导航位置:select:right|left,center,right
 * background_color => 背景颜色:color:#ffffff
 * text_color => 文字颜色:color:#333333
 * height => 头部高度:number:80|px
 *
 * @fields_end
 */
```

### 在模板中使用

```php
<header style="
    background: <?= $style['header.background_color'] ?? '#fff' ?>;
    height: <?= $style['header.height'] ?? '80' ?>px;
    color: <?= $style['header.text_color'] ?? '#333' ?>;
">
    <!-- Header content -->
</header>
```

### 在页面内容中使用

```html
<div class="hero" style="background: {{style.content.background_color}}">
    <h1 style="font-size: {{style.content.title_size}}px">
        {{page.title}}
    </h1>
</div>
```

## 技术细节

### 配置解析

`Style::parseStyleConfig()` 方法负责：
1. 扫描 `header.phtml`、`footer.phtml`、`content.phtml`
2. 提取 `@fields_start` 到 `@fields_end` 之间的配置
3. 解析每行配置，提取 key、label、type、default、unit/options
4. 自动添加文件前缀（header./footer./content.）

### 配置分组

`Style::getConfigGroups()` 方法负责：
1. 调用 `parseStyleConfig()` 获取所有配置
2. 按照文件前缀分组（header、footer、content）
3. 返回分组后的配置结构，用于后台渲染

### 变量替换

`Page::parseContentVariables()` 方法负责：
1. 使用正则表达式匹配 `{{variable.key}}` 格式
2. 从模板数据中提取对应的值
3. HTML 转义输出，防止 XSS

## 最佳实践

1. **配置定义位置**：始终将 `@fields_start` 块放在文件顶部
2. **命名规范**：使用小写字母和下划线，如 `background_color`
3. **默认值**：为每个配置提供合理的默认值
4. **单位标注**：数字类型建议添加单位，如 `80|px`
5. **选项列表**：select 类型必须提供选项列表
6. **标签翻译**：使用 `__()` 函数支持多语言

## 两种语法的使用场景

### 场景对比表

| 使用位置 | 语法 | 示例 | 说明 |
|---------|------|------|------|
| 页面内容（后台编辑器） | `{{variable.key}}` | `{{style.header.background_color}}` | 由 `parseContentVariables()` 解析 |
| 模板文件（.phtml） | PHP 语法 | `<?= $style['header.background_color'] ?? '#fff' ?>` | 直接 PHP 输出 |

### 错误示例 ❌

```php
<!-- 错误：在 header.phtml 中使用 {{}} -->
<header style="background: {{style.header.background_color}};">
    ❌ 这样不会工作！PHP 不认识 {{}} 语法
</header>
```

### 正确示例 ✅

**在模板文件中（header.phtml）：**
```php
<?php
$style = $this->getData('style') ?: [];
?>
<header style="background: <?= $style['header.background_color'] ?? '#fff' ?>;">
    ✅ 这样才正确！
</header>
```

**在页面内容中（后台编辑器输入的 HTML）：**
```html
<div class="hero" style="background: {{style.content.background_color}};">
    <h1>{{page.title}}</h1>
    ✅ 这样可以工作！会被 parseContentVariables() 解析
</div>
```

## 注意事项

1. **配置键名**：会自动添加文件前缀（header./footer./content.），无需手动添加
2. **样式切换**：切换样式不会丢失之前的配置，每个样式独立存储
3. **语法选择**：
   - ✅ 页面内容：使用 `{{variable.key}}`
   - ✅ 模板文件：使用 PHP 语法
   - ❌ 不能混用：PHP 代码中不能使用 `{{}}`
4. **颜色格式**：使用十六进制格式（如 #ffffff）
5. **数据类型**：数字类型的值以字符串形式存储，使用时注意类型转换
6. **动态同步**：样式定义变化时（新增/删除字段），配置会自动同步
7. **优先级规则**：翻译配置 > 页面配置 > 默认配置，不可逆转
8. **翻译存储**：翻译的配置存储在 `LocalDescription.config.style_config` 节点下

## 动态配置同步机制

### 场景1：样式添加新字段

**样式定义更新：**
```php
// 在 header.phtml 中添加新字段
/**
 * @fields_start
 * logo_position => Logo位置:select:left|left,center,right
 * nav_style => 导航样式:select:horizontal|horizontal,vertical  // 新增字段
 * @fields_end
 */
```

**结果：**
- 新字段 `header.nav_style` 自动使用默认值 `horizontal`
- 页面无需重新保存即可生效
- 已保存的其他配置不受影响

### 场景2：样式删除字段

**样式定义更新：**
```php
// 从 header.phtml 删除字段
// logo_position => Logo位置:select:left|left,center,right  // 已删除
```

**结果：**
- 数据库中保存的 `header.logo_position` 值会被自动忽略
- 前台渲染时不会使用该配置
- 数据库中的值仍然保留（以防恢复）

### 场景3：多语言配置覆盖

**中文页面（zh_Hans_CN）：**
- 默认配置：`header.background_color = #ffffff`
- 页面配置：`header.background_color = #000000`
- 翻译配置：`header.background_color = #ff0000`
- **最终使用：`#ff0000`（翻译配置优先）**

**英文页面（en_US）：**
- 默认配置：`header.background_color = #ffffff`
- 页面配置：`header.background_color = #000000`
- 翻译配置：未设置
- **最终使用：`#000000`（页面配置优先）**

## 翻译配置工作流程

### 后台翻译配置

1. **编辑页面**
   - 进入页面编辑界面
   - 配置基础样式参数（这是页面级配置）

2. **添加语言翻译**
   - 点击"添加语言"或切换语言标签
   - 系统显示当前样式的所有配置项
   - 修改需要覆盖的配置值

3. **保存翻译**
   - 翻译的配置保存到 `LocalDescription.config.style_config`
   - 每个语言独立存储
   - 未修改的配置项不会存储（节省空间）

### 前台渲染优先级

```
┌─────────────────────────────────┐
│  样式定义（header.phtml）        │
│  background_color: #ffffff       │  ← 默认值（最低优先级）
└─────────────────────────────────┘
              ↓ 覆盖
┌─────────────────────────────────┐
│  页面配置（Page.style_setting）  │
│  background_color: #000000       │  ← 页面级配置（中等优先级）
└─────────────────────────────────┘
              ↓ 覆盖
┌─────────────────────────────────┐
│  翻译配置（LocalDescription）    │
│  background_color: #ff0000       │  ← 翻译配置（最高优先级）
└─────────────────────────────────┘
              ↓
        最终使用：#ff0000
```

## 扩展性

### 添加新的字段类型

1. 在 `form.phtml` 的 `renderStyleConfig()` 函数中添加新的 case
2. 实现对应的 HTML 输入控件
3. 根据需要添加 JavaScript 事件监听

### 添加新的样式文件

如需为样式添加新的配置文件（如 `sidebar.phtml`）：

1. 在 `Style::parseStyleConfig()` 中添加新文件到 `$files` 数组
2. 使用相应的前缀（如 `sidebar.`）
3. 更新 `form.phtml` 的 `groupOrder` 和 `groupIcons`

## 版本历史

- **v1.1** (2025-10-16): 优先级系统
  - 实现三级配置优先级（翻译 > 页面 > 默认）
  - 支持动态配置同步（新增/删除字段自动同步）
  - 集成 I18n 翻译系统
  - 支持多语言定制化样式配置
  - 优化配置合并逻辑

- **v1.0** (2025-10-16): 初始版本
  - 支持 Header、Content、Footer 三个配置区域
  - 支持 6 种字段类型（text, number, select, color, textarea, image）
  - 实现配置保存和恢复机制
  - 支持模板变量替换 `{{variable.key}}`
  - 实现按样式独立存储配置

