【重要 - 返回 JSON 格式要求】
- 必须输出合法 JSON：仅使用双引号表示字符串，勿用单引号
- 字符串内的换行必须写成 \n（两个字符：反斜杠+n），禁止在字符串中输出真实换行符
- 最后一个键值对后勿加逗号（避免 "key": 1, } 导致解析失败）
- 若输出被截断，至少保证已输出的部分可被解析（字符串未闭合时会被后端尝试修复）

【重要 - 语言与本地化】⭐⭐⭐
- **必须**根据当前页面语言生成所有用户可见文本！
- 如果页面是中文（zh_CN/zh_Hans_CN），所有标签、描述、备注、占位符都必须用中文
- 如果页面是英文（en_US），所有文本都必须用英文
- 禁止在中文页面生成英文文案，禁止在英文页面生成中文文案
- 配置字段的 label/description 也必须使用对应语言

【重要 - CSS 类名唯一化】⭐⭐⭐
- 框架已提供唯一的 $componentId（如 footer-abc12345、header-def67890）
- 所有 CSS 类名必须使用 $componentId 作为前缀（如 .footer-abc12345-brand）
- **禁止**使用 ai-footer-*、ai-header-*、ai-content-* 等固定前缀
- **禁止**使用通用类名（如 .footer、.header、.brand、.container）
- CSS 选择器格式：#<?= $componentId ?> .<?= $cls ?>-xxx
- HTML class 格式：class="<?= $cls ?>-xxx"

【重要 - 框架已提供的变量，不要重复定义】
- $page, $config, $componentConfig, $styleSettings - 数据变量
- $getConfig - 配置读取函数
- $componentId - 组件唯一ID（如 footer-abc12345）
- $cls - 等同于 $componentId，用于构建类名
- $showLogo, $showNav, $showCta, $navItems 等 - Header框架变量
- $title, $subtitle, $description 等 - Content框架变量
- $brandName, $brandDesc, $col1Items, $col2Items 等 - Footer框架变量

【重要 - php_variables 格式要求】
- 只用于定义简单变量，如：$myVar = $getConfig('key', 'default');
- 每行必须是完整的语句，以分号结尾
- 禁止包含 PHP 开始或结束标签
- 禁止使用 if/foreach/for/while 等控制结构
- 禁止定义函数或类
- 如果不需要额外变量，php_variables 应该为空字符串

【重要 - js_content 格式要求】
- 只提供组件内部的JavaScript逻辑代码
- 框架已提供 component 变量指向组件DOM元素
- 不要包含 document.addEventListener('DOMContentLoaded', ...)
- 不要包含 (function(){...})() 自执行函数包装
- 直接写操作 component 元素的代码即可
- 禁止使用任何 PHP 标签
- js_content 必须是纯 JavaScript，不能混合 PHP 代码
- 字符串引号必须成对且正确转义：推荐统一使用双引号，避免单引号冲突
- 禁止在 js_content 中使用 $componentId 或 "# $componentId" 这样的 PHP 变量，请使用 component / component.id
- 选择器必须与 HTML 中的 class 一致
- 禁止使用 alert()，请使用 FrontendToast.warning / .error 或主题 toast

【正确的 js_content 示例】
```
const buttons = component.querySelectorAll('[class*="-btn"]');
buttons.forEach(btn => {
    btn.addEventListener('click', () => btn.classList.toggle('active'));
});

// 如需使用配置值，通过 data-* 属性获取
const config = JSON.parse(component.dataset.config || '{}');
```

【错误的 js_content 示例 - 绝对不要这样写】
```
// 错误1：不要使用 DOMContentLoaded 包装
document.addEventListener('DOMContentLoaded', function() { });

// 错误2：不要在 JS 中嵌入服务端代码
if (serverVar) { }  // 禁止在JS中使用服务端变量

// 错误3：单引号不转义
const text = 'I'm broken';

// 错误4：使用固定的 ai- 前缀（已废弃）
component.querySelector('.ai-footer-brand');  // 错误！
```
