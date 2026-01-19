# AI翻译标签使用指南

## 概述

AI翻译标签是一个前端组件，可以自动监听输入框，在输入框上方显示AI翻译按钮，点击后自动翻译输入框内的文本。

## 功能特性

1. **自动监听输入框**：通过指定目标输入框的ID或选择器，自动在输入框上方显示翻译按钮
2. **智能语言检测**：默认根据当前框架选择的语言进行翻译，也可以指定目标语言
3. **实时翻译**：点击按钮后立即翻译，翻译结果自动填充到输入框
4. **支持多种输入类型**：支持 `input`、`textarea` 等表单元素
5. **动态元素支持**：支持动态添加的元素，自动初始化翻译按钮

## 使用方法

### 基本用法

在模板文件中使用 `<w:ai-translate>` 标签：

```phtml
<!-- 方式1：通过ID指定输入框 -->
<input type="text" id="my-input" name="title" />
<w:ai-translate target="my-input" />

<!-- 方式2：通过选择器指定 -->
<textarea id="description" name="description"></textarea>
<w:ai-translate target="#description" />

<!-- 方式3：通过name属性指定 -->
<input type="text" name="content" />
<w:ai-translate target="content" />
```

### 高级用法

```phtml
<!-- 指定目标语言 -->
<w:ai-translate 
    target="my-input" 
    target-language="en" 
/>

<!-- 指定源语言（默认auto自动检测） -->
<w:ai-translate 
    target="my-input" 
    source-language="zh" 
    target-language="en" 
/>

<!-- 指定翻译渠道 -->
<w:ai-translate 
    target="my-input" 
    provider-code="google" 
/>

<!-- 自定义按钮位置和样式 -->
<w:ai-translate 
    target="my-input" 
    position="bottom"
    button-text="翻译"
    button-class="custom-translate-btn"
/>
```

## 参数说明

| 参数 | 类型 | 必填 | 默认值 | 说明 |
|------|------|------|--------|------|
| `target` | string | 是 | - | 目标输入框的ID、选择器或name属性 |
| `target-language` | string | 否 | 当前语言 | 目标语言代码（如：zh, en, ja等） |
| `source-language` | string | 否 | auto | 源语言代码，auto表示自动检测 |
| `provider-code` | string | 否 | - | 指定翻译渠道代码（如：google, baidu等） |
| `position` | string | 否 | top | 按钮位置：top（上方）或 bottom（下方） |
| `button-text` | string | 否 | AI翻译 | 按钮显示的文本 |
| `button-class` | string | 否 | ai-translate-btn | 按钮的CSS类名 |

## 在PageBuilder中使用

在PageBuilder的页面编辑表单中，AI翻译标签会自动集成到所有 `texts` 分组的配置项中。

### 自动集成示例

当你在模板的 `@fields_start` 中定义了 `texts` 分组：

```php
/**
 * @fields_start
 * 
 * group:texts => 文本内容设置（支持多语言）
 * texts.nav_home => 导航-首页:text:Home
 * texts.nav_about => 导航-关于:text:About
 * 
 * @fields_end
 */
```

在后台编辑页面时，这些输入框会自动显示AI翻译按钮。

## API接口

### 翻译接口

**URL**: `/translation-service/api/translate`

**方法**: POST

**参数**:
- `text`: 要翻译的文本（必填）
- `target_language`: 目标语言代码（可选，默认当前语言）
- `source_language`: 源语言代码（可选，默认auto）
- `provider_code`: 指定渠道代码（可选）

**响应**:
```json
{
    "success": true,
    "data": {
        "original_text": "Hello",
        "translated_text": "你好",
        "source_language": "auto",
        "target_language": "zh"
    }
}
```

### 批量翻译接口

**URL**: `/translation-service/api/batch-translate`

**方法**: POST

**参数**:
- `texts`: 要翻译的文本数组（JSON格式，必填）
- `target_language`: 目标语言代码（可选）
- `source_language`: 源语言代码（可选）
- `provider_code`: 指定渠道代码（可选）

**响应**:
```json
{
    "success": true,
    "data": {
        "original_texts": ["Hello", "World"],
        "translated_texts": ["你好", "世界"],
        "source_language": "auto",
        "target_language": "zh"
    }
}
```

## 一键读取所有文字配置项

### 接口说明

**URL**: `/pagebuilder/backend/template/getTextConfigs`

**方法**: GET

**参数**:
- `style_code`: 模板代码（必填）
- `page_id`: 页面ID（可选，用于获取已保存的值）
- `locale`: 语言代码（可选，用于获取特定语言的配置）

**响应**:
```json
{
    "success": true,
    "data": {
        "text_configs": [
            {
                "key": "texts.nav_home",
                "label": "导航-首页",
                "type": "text",
                "value": "Home",
                "default": "Home",
                "file": "header",
                "group": "texts"
            }
        ],
        "total": 1,
        "style_code": "tpmst",
        "style_name": "TPMST模板"
    }
}
```

### 使用场景

这个接口主要用于：
1. **批量翻译**：获取所有文字配置项，批量翻译后保存
2. **翻译预览**：在翻译前预览所有需要翻译的文本
3. **翻译统计**：统计需要翻译的文本数量和字符数

## 自定义样式

可以通过CSS自定义翻译按钮的样式：

```css
/* 自定义按钮样式 */
.custom-translate-btn {
    background: #4caf50;
    color: white;
    border-radius: 20px;
    padding: 8px 16px;
}

.custom-translate-btn:hover {
    background: #45a049;
}
```

## 注意事项

1. **翻译服务配置**：使用前需要在后台配置翻译渠道（系统服务 → 翻译服务 → 渠道配置）
2. **语言代码格式**：支持 ISO 639-1（如：zh, en）、ISO 639-2（如：zho, eng）、BCP 47（如：zh-CN, en-US）等格式
3. **网络请求**：翻译功能需要网络请求，请确保网络连接正常
4. **字符限制**：不同翻译渠道可能有字符数限制，请参考各渠道的文档

## 故障排除

### 按钮不显示
- 检查 `target` 参数是否正确
- 检查目标输入框是否存在
- 检查浏览器控制台是否有JavaScript错误

### 翻译失败
- 检查翻译服务是否已配置
- 检查翻译渠道是否已启用
- 检查网络连接是否正常
- 查看浏览器控制台的错误信息

### 翻译结果不正确
- 检查源语言和目标语言设置是否正确
- 尝试指定源语言而不是使用auto
- 检查文本内容是否包含特殊字符

## 示例代码

### 完整示例

```phtml
<form>
    <div class="form-group">
        <label>页面标题</label>
        <input type="text" id="page-title" name="title" class="form-control" />
        <w:ai-translate target="page-title" />
    </div>
    
    <div class="form-group">
        <label>页面描述</label>
        <textarea id="page-description" name="description" class="form-control"></textarea>
        <w:ai-translate target="#page-description" target-language="en" />
    </div>
    
    <button type="submit">保存</button>
</form>
```

## 技术支持

如有问题，请参考：
- 翻译服务模块文档：`app/code/Weline/TranslationService/doc/使用指南.md`
- PageBuilder模块文档：`app/code/GuoLaiRen/PageBuilder/README.md`
