【重要 - 返回 JSON 格式要求】
- 必须输出合法 JSON：仅使用双引号表示字符串，勿用单引号
- 字符串内的换行必须写成 \n（两个字符：反斜杠+n），禁止在字符串中输出真实换行符
- 最后一个键值对后勿加逗号（避免 "key": 1, } 导致解析失败）
- 若输出被截断，至少保证已输出的部分可被解析（字符串未闭合时会被后端尝试修复）

【重要 - 框架已提供的变量，不要重复定义】
- $page, $config, $componentConfig, $styleSettings - 数据变量
- $getConfig - 配置读取函数
- $componentId - 组件唯一ID
- $showLogo, $showNav, $showCta, $navItems 等 - Header框架变量
- $title, $subtitle, $description 等 - Content框架变量

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
- 字符串引号必须成对且正确转义：推荐统一使用双引号，避免单引号冲突；如必须用单引号，内部单引号必须转义
- 禁止在 js_content 中使用 $componentId 或 "# $componentId" 这样的 PHP 变量，请使用 component / component.id
- 选择器必须与 HTML 中的 class 一致（如 footer 框架为 .ai-footer-social，禁止 invent .pb-footer-social-icons）
- 禁止使用 alert()，请使用 FrontendToast.warning / .error 或主题 toast
- CSS 选择器在 phtml 中必须写成 #<?= $componentId ?>，禁止写成 #= $componentId

【正确的 js_content 示例】
```
const buttons = component.querySelectorAll('.btn');
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
```
