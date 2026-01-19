# HTML lang 属性 BCP 47 规范

## 概述

WelineFramework 的 I18n 国际化系统支持 BCP 47 语言标签规范。在 HTML 模板中设置 `lang` 属性时，必须使用符合 BCP 47 规范的格式。

## BCP 47 规范说明

BCP 47（Best Current Practice 47）是用于标识人类语言的标准化格式，由 RFC 5646 定义。该规范使用连字符（`-`）作为分隔符，而不是下划线（`_`）。

### 规范格式

BCP 47 语言标签由以下部分组成（使用连字符分隔）：

- **语言代码**（Language subtag）：2-3 个字符，小写，例如 `en`、`zh`
- **脚本代码**（Script subtag，可选）：4 个字符，首字母大写，例如 `Hans`、`Hant`
- **地区代码**（Region subtag，可选）：2 个大写字母或 3 个数字，例如 `CN`、`US`

### 格式转换

框架内部使用下划线格式（`_`）存储语言代码，例如：
- `zh_Hans_CN` - 简体中文（中国大陆）
- `en_US` - 英语（美国）
- `zh_Hant_TW` - 繁体中文（台湾）

在 HTML 模板中设置 `lang` 属性时，需要将下划线转换为连字符，符合 BCP 47 规范：
- `zh-Hans-CN` - 简体中文（中国大陆）
- `en-US` - 英语（美国）
- `zh-Hant-TW` - 繁体中文（台湾）

## 使用方法

### 前端模板

在前端布局模板中，使用 `{{lang}}` 变量，该变量会自动转换为 BCP 47 格式：

```phtml
<!DOCTYPE html>
<html lang="{{lang}}">
<head>
    <!-- ... -->
</head>
```

**注意**：`{{lang}}` 变量由框架自动提供，已经转换为 BCP 47 格式（连字符分隔）。

### 后端模板

在后端布局模板中，使用 `{{htmlLang}}` 变量：

```phtml
<!DOCTYPE html>
<html lang="{{htmlLang}}">
<head>
    <!-- ... -->
</head>
```

**注意**：`{{htmlLang}}` 变量由框架自动提供，已经转换为 BCP 47 格式（连字符分隔）。


### 手动转换

如果需要在模板中手动转换，可以使用 PHP 的 `str_replace()` 函数：

```phtml
<?php
$userLang = $_SERVER['WELINE_USER_LANG'] ?? State::getLang() ?? 'zh_Hans_CN';
$htmlLang = str_replace('_', '-', $userLang);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($htmlLang) ?>">
```

## 常见语言标签对照表

| 框架内部格式 | BCP 47 格式 | 说明 |
|------------|-----------|------|
| `zh_Hans_CN` | `zh-Hans-CN` | 简体中文（中国大陆） |
| `zh_Hant_TW` | `zh-Hant-TW` | 繁体中文（台湾） |
| `zh_Hant_HK` | `zh-Hant-HK` | 繁体中文（香港） |
| `en_US` | `en-US` | 英语（美国） |
| `en_GB` | `en-GB` | 英语（英国） |
| `ja_JP` | `ja-JP` | 日语（日本） |
| `ko_KR` | `ko-KR` | 韩语（韩国） |
| `fr_FR` | `fr-FR` | 法语（法国） |
| `de_DE` | `de-DE` | 德语（德国） |
| `es_ES` | `es-ES` | 西班牙语（西班牙） |
| `ru_RU` | `ru-RU` | 俄语（俄罗斯） |

## 注意事项

1. **仅 HTML lang 属性需要转换**：框架内部使用的语言代码（如下划线格式）保持不变，只有输出到 HTML `lang` 属性时才需要转换为 BCP 47 格式。

2. **JavaScript 中的语言代码**：在 JavaScript 代码中使用的语言代码可以保持框架内部格式（下划线），因为这是框架内部使用的格式。

3. **SEO 和可访问性**：正确设置 `lang` 属性有助于：
   - 搜索引擎优化（SEO）
   - 屏幕阅读器等辅助技术的语言识别
   - 浏览器自动翻译功能
   - 内容语言检测

4. **自动转换**：框架已经自动处理了转换，模板中直接使用 `{{lang}}` 或 `{{htmlLang}}` 变量即可，无需手动转换。

## 相关规范文档

- **BCP 47 规范**：https://datatracker.ietf.org/doc/html/rfc5646
- **MDN BCP 47 说明**：https://developer.mozilla.org/en-US/docs/Glossary/BCP_47_language_tag
- **W3C 语言标签选择指南**：https://www.w3.org/International/questions/qa-choosing-language-tags

## 实现细节

框架在 `Template.php` 的 `initLanguage()` 方法中自动处理 BCP 47 格式转换：

- `lang` 变量：自动转换为 BCP 47 格式（连字符分隔），用于 HTML `lang` 属性
- `htmlLang` 变量：与 `lang` 相同，保持向后兼容

开发者无需手动处理转换，直接使用 `{{lang}}` 或 `{{htmlLang}}` 变量即可。
