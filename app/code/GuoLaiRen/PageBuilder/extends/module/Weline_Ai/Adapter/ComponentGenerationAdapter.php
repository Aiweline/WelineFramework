<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder Module
 * 组件生成场景适配器
 * 
 * 功能：
 * - 为PageBuilder模块的AI组件生成提供场景适配
 * - 优化提示词格式，确保AI返回符合组件规约的代码
 * - 处理响应，提取组件模板代码
 */

namespace GuoLaiRen\PageBuilder\Extends\Module\Weline_Ai\Adapter;

use GuoLaiRen\PageBuilder\Extends\Module\Weline_Ai\Style\PageBuilderStyleProvider;
use Weline\Ai\Interface\AdapterModelBindingInterface;
use Weline\Ai\Interface\AdapterSkillBindingInterface;
use Weline\Ai\Interface\AdapterStyleBindingInterface;
use Weline\Ai\Interface\ScenarioAdapterInterface;

/**
 * 组件生成场景适配器
 * 
 * 用于PageBuilder模块的AI组件生成功能，包括：
 * - 组件模板代码生成
 * - 组件配置字段生成
 * - HTML结构和CSS样式生成
 */
class ComponentGenerationAdapter implements ScenarioAdapterInterface, AdapterSkillBindingInterface, AdapterStyleBindingInterface, AdapterModelBindingInterface
{
    public function getDefaultModelBindings(): array
    {
        return ['text2text' => 'deepseek-v4-flash'];
    }

    public function getDefaultSkillCodes(): array
    {
        return ['claude-design', 'impeccable', 'weline-pixel-events'];
    }

    public function getDefaultStyleCodes(): array
    {
        return [PageBuilderStyleProvider::CARD_GAME_STYLE_CODE];
    }

    /**
     * 获取适配器代码
     * 
     * @return string
     */
    public function getCode(): string
    {
        return 'pagebuilder_component_generation';
    }

    /**
     * 获取适配器名称
     * 
     * @return string
     */
    public function getName(): string
    {
        return '页面构建器组件生成适配器';
    }

    /**
     * 获取适配器描述
     * 
     * @return string
     */
    public function getDescription(): string
    {
        return 'PageBuilder模块专用的AI组件生成场景适配器，支持组件模板代码、配置字段、HTML结构和CSS样式的生成。自动优化提示词格式，确保AI返回符合PageBuilder组件规约的代码。';
    }

    /**
     * 获取适配器版本
     * 
     * @return string
     */
    public function getVersion(): string
    {
        return '1.0.0';
    }

    /**
     * 获取支持的模型类型
     * 
     * @return array
     */
    public function getSupportedModelTypes(): array
    {
        return ['*']; // 支持所有模型
    }

    /**
     * 适配提示词
     * 
     * 确保提示词包含组件生成的特殊要求
     * 
     * @param string $prompt 原始提示词
     * @param array $params 额外参数
     * @return string 适配后的提示词
     */
    public function adaptPrompt(string $prompt, array $params = []): string
    {
        if (!empty($params['component_config_generation'])) {
            return $prompt;
        }

        if ($this->isJsonComponentPayloadRequest($prompt)) {
            return $prompt . $this->getJsonComponentPayloadContract();
        }

        // 检查是否是微调模式
        $isRefineMode = !empty($params['refine_mode']) && !empty($params['existing_code']);
        
        if ($isRefineMode) {
            // 微调模式：提示词已经包含了现有代码和调整要求，只需要添加微调规约
            $refineRequirement = "\n\n【微调规约】\n";
            $refineRequirement .= "1. 必须保持原有的组件结构、元数据块和字段定义\n";
            $refineRequirement .= "2. 只调整需要修改的部分，不要改变整体结构\n";
            $refineRequirement .= "3. 必须保持 @component_start / @component_end 元数据块不变（除非明确要求修改）\n";
            $refineRequirement .= "4. 必须保持 @fields_start / @fields_end 字段定义块不变（除非明确要求修改）\n";
            $refineRequirement .= "5. 只返回修改后的完整组件代码，不要包含其他说明\n";
            $refineRequirement .= "6. 确保代码可以直接使用，符合PageBuilder组件规约\n";
            
            // 如果是header组件，添加nav固定代码块约束
            if ($this->isHeaderComponent($params['existing_code'])) {
                $refineRequirement .= "\n【重要-Header Nav 固定结构约束】\n";
                $refineRequirement .= "导航(nav)部分的核心结构必须保持不变，只能调整样式（颜色、字体、间距等）\n";
            }
            
            return $prompt . $refineRequirement;
        }
        
        // 检查提示词是否已经包含组件规约要求
        if (stripos($prompt, '@component_start') !== false || 
            stripos($prompt, 'component_start') !== false) {
            // 已经包含组件规约要求，直接返回
            return $prompt;
        }

        // 检查是否是header组件生成请求
        $isHeaderComponent = $this->isHeaderComponentRequest($prompt);
        
        // 添加组件生成的特殊要求
        $componentRequirement = "\n\n【重要-组件生成规约】\n";
        $componentRequirement .= "1. 必须生成完整的PHP组件模板代码（.phtml格式）\n";
        $componentRequirement .= "2. 必须包含 @component_start / @component_end 元数据块\n";
        $componentRequirement .= "3. 必须包含 @fields_start / @fields_end 配置字段定义块\n";
        $componentRequirement .= "4. 必须使用 \$componentId 作为组件唯一标识\n";
        $componentRequirement .= "5. HTML结构必须包含在 <section> 或 <div> 标签中\n";
        $componentRequirement .= "6. CSS样式可以使用 <style> 标签内联，或使用组件ID作为选择器\n";
        $componentRequirement .= "7. 配置字段格式：group:content => 内容设置\n";
        $componentRequirement .= "8. 配置字段定义格式：content.title => 标题:text:默认标题\n";
        $componentRequirement .= "9. 所有用户输入的内容必须使用 htmlspecialchars() 进行转义\n";
        $componentRequirement .= "10. 组件代码必须符合PageBuilder组件规约\n";
        
        // 添加禁止规则
        $componentRequirement .= $this->getProhibitedRules();
        
        // 添加正确代码示例
        $componentRequirement .= $this->getCorrectCodeExamples();

        // 如果是header组件，添加nav固定代码块约束
        if ($isHeaderComponent) {
            $componentRequirement .= $this->getHeaderNavConstraint();
        }

        return $prompt . $componentRequirement;
    }

    private function isJsonComponentPayloadRequest(string $prompt): bool
    {
        if (stripos($prompt, 'CRITICAL OUTPUT CONTRACT FOR PAGEBUILDER COMPONENT JSON') === false) {
            return false;
        }

        return stripos($prompt, 'JSON object') !== false
            && stripos($prompt, 'css_extra') !== false
            && stripos($prompt, 'js_content') !== false
            && (
                stripos($prompt, 'html_content') !== false
                || stripos($prompt, 'html_extra') !== false
                || stripos($prompt, 'footer_extra_text') !== false
            );
    }

    private function getJsonComponentPayloadContract(): string
    {
        return "\n\n[PageBuilder JSON component adapter contract]\n"
            . "This scenario is JSON-field generation, not full PHTML component generation.\n"
            . "Return one JSON object only. Do not output @component_start, @fields_start, full PHTML templates, markdown fences, or explanatory prose.\n"
            . "Use only the JSON keys required by the current prompt. For content blocks those keys are extra_fields, php_variables, css_extra, css_responsive, html_content, and js_content.\n"
            . "Keep extra_fields, php_variables, and js_content as empty strings unless the current prompt explicitly requires otherwise.\n"
            . "html_content is the visitor HTML fragment for the current block only. Do not switch to framework wrapper code, do not create id='componentId', and do not output neighboring blocks.\n"
            . "css_extra/css_responsive contain scoped CSS only, using #componentId descendant selectors and the exact component class prefix supplied by the current prompt. For content blocks in this workflow the supplied prefix is `pb-c`, so generated content classes must be shaped like `pb-c-root` and never `.pb` or `pb` by itself.\n"
            . "Prefer the current prompt's fixed safe skeleton. Do not invent complex nested HTML or CSS functions when the prompt supplies a simpler skeleton.\n"
            . "If the current prompt provides a verified image template/final_url, copy it into html_content with the same src, data-pb-ai-image-role, and data-pb-ai-asset-slot values.\n"
            . "The current prompt's strong contract, locale, image-slot rule, and component prefix override any generic adapter guidance.\n";
    }
    
    /**
     * 检查是否是header组件生成请求
     * 
     * @param string $prompt 提示词
     * @return bool
     */
    private function isHeaderComponentRequest(string $prompt): bool
    {
        $headerKeywords = [
            'header', '头部', '导航', 'nav', 'navigation', '菜单', 'menu',
            '顶部', 'top', '头部组件', 'header组件'
        ];
        
        $promptLower = strtolower($prompt);
        foreach ($headerKeywords as $keyword) {
            if (stripos($promptLower, strtolower($keyword)) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 检查代码是否是header组件
     * 
     * @param string $code 组件代码
     * @return bool
     */
    private function isHeaderComponent(string $code): bool
    {
        // 检查元数据中的region或category
        if (preg_match('/region:\s*header/i', $code)) {
            return true;
        }
        if (preg_match('/category:\s*header/i', $code)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * 获取禁止规则列表
     * 
     * @return string
     */
    private function getProhibitedRules(): string
    {
        $rules = "\n\n【禁止事项 - 违反这些规则会导致组件无法渲染】\n";
        $rules .= "1. ❌ 禁止在 html_content 中使用 PHP 标签\n";
        $rules .= "2. ❌ 禁止使用反引号 ` 字符（会导致模板语法错误）\n";
        $rules .= "3. ❌ 禁止使用未定义的变量（所有变量必须先定义或使用 \$getConfig 获取）\n";
        $rules .= "4. ❌ 禁止在 php_variables 中使用 PHP 开始/结束标签\n";
        $rules .= "5. ❌ 禁止直接使用 \$this->getData()，必须使用 \$getConfig() 辅助函数\n";
        $rules .= "6. ❌ 禁止使用超全局变量（\$_GET, \$_POST, \$_SESSION 等）\n";
        $rules .= "7. ❌ 禁止使用危险函数（eval, exec, shell_exec, system 等）\n";
        $rules .= "8. ❌ 禁止使用全局CSS选择器（body, html, * 等），必须使用 #\$componentId 前缀\n";
        $rules .= "9. ❌ 禁止输出未转义的用户数据（必须使用 htmlspecialchars）\n";
        $rules .= "10. ❌ 禁止使用 array() 语法，应使用 [] 短数组语法\n";
        $rules .= "11. ❌ 禁止在 php_variables 中重复定义框架已有的变量（\$getConfig, \$page, \$config, \$componentId 等已在框架中定义）\n";
        $rules .= "12. ❌ 禁止在 js_content 中包含 document.addEventListener 包装，框架已处理\n";
        $rules .= "13. ❌ js_content 只提供组件内部的JavaScript逻辑，不要包含IIFE包装\n";
        $rules .= "14. ❌ 禁止在 php_variables 中包含 ?> 结束标签（会破坏模板结构）\n";
        $rules .= "15. ❌ php_variables 只用于定义简单变量，不要包含 if/foreach/for/while 等控制结构\n";
        $rules .= "16. ❌ php_variables 中的所有代码必须是完整的语句，每行以分号结尾\n";
        $rules .= "17. ❌ 禁止在 js_content 中使用任何 PHP 标签（<?php, <?=, ?>）\n";
        $rules .= "18. ❌ js_content 必须是纯 JavaScript 代码，不能混合 PHP\n";
        $rules .= "19. ❌ 如果需要在 JS 中使用配置值，应该通过 data-* 属性或全局变量传递，不要用 PHP\n";
        
        return $rules;
    }
    
    /**
     * 获取正确代码示例
     * 
     * @return string
     */
    private function getCorrectCodeExamples(): string
    {
        $examples = "\n\n【正确代码示例 - 请参照此格式编写】\n\n";
        
        // PHP变量准备示例
        $examples .= "【✓ 正确的PHP变量准备代码】\n";
        $examples .= "```php\n";
        $examples .= "// 定义辅助函数\n";
        $examples .= "\$getConfig = function(\$key, \$default = '') use (\$config) {\n";
        $examples .= "    return isset(\$config[\$key]) && \$config[\$key] !== '' ? \$config[\$key] : \$default;\n";
        $examples .= "};\n\n";
        $examples .= "// 获取配置值\n";
        $examples .= "\$title = \$getConfig('content.title', '默认标题');\n";
        $examples .= "\$description = \$getConfig('content.description', '默认描述');\n";
        $examples .= "\$buttonText = \$getConfig('button.text', '点击了解更多');\n\n";
        $examples .= "// 循环处理\n";
        $examples .= "\$items = [];\n";
        $examples .= "\$itemCount = (int)\$getConfig('items.count', 3);\n";
        $examples .= "for (\$i = 1; \$i <= \$itemCount; \$i++) {\n";
        $examples .= "    \$items[] = [\n";
        $examples .= "        'title' => \$getConfig('item_' . \$i . '_title', '项目 ' . \$i),\n";
        $examples .= "        'desc' => \$getConfig('item_' . \$i . '_desc', '描述'),\n";
        $examples .= "    ];\n";
        $examples .= "}\n";
        $examples .= "```\n\n";
        
        // CSS示例
        $examples .= "【✓ 正确的CSS样式写法】\n";
        $examples .= "```css\n";
        $examples .= "/* 必须使用组件ID前缀进行样式隔离 */\n";
        $examples .= "#<?= \$componentId ?> {\n";
        $examples .= "    padding: 40px 20px;\n";
        $examples .= "}\n\n";
        $examples .= "#<?= \$componentId ?> .section-title {\n";
        $examples .= "    font-size: 2rem;\n";
        $examples .= "    color: #333;\n";
        $examples .= "}\n\n";
        $examples .= "#<?= \$componentId ?> .card {\n";
        $examples .= "    background: #fff;\n";
        $examples .= "    border-radius: 8px;\n";
        $examples .= "}\n";
        $examples .= "```\n\n";
        
        // HTML示例
        $examples .= "【✓ 正确的HTML输出写法】\n";
        $examples .= "```html\n";
        $examples .= "<div id=\"<?= \$componentId ?>\" class=\"component-wrapper\">\n";
        $examples .= "    <h2><?= htmlspecialchars(\$title) ?></h2>\n";
        $examples .= "    <p><?= nl2br(htmlspecialchars(\$description)) ?></p>\n";
        $examples .= "    \n";
        $examples .= "    <?php foreach (\$items as \$item): ?>\n";
        $examples .= "    <div class=\"item\">\n";
        $examples .= "        <h3><?= htmlspecialchars(\$item['title']) ?></h3>\n";
        $examples .= "        <p><?= htmlspecialchars(\$item['desc']) ?></p>\n";
        $examples .= "    </div>\n";
        $examples .= "    <?php endforeach; ?>\n";
        $examples .= "</div>\n";
        $examples .= "```\n\n";
        
        // JavaScript示例
        $examples .= "【✓ 正确的JavaScript写法 (js_content)】\n";
        $examples .= "```javascript\n";
        $examples .= "// 框架已提供 component 变量指向组件DOM元素\n";
        $examples .= "// 只写组件内部逻辑，不要包含 document.addEventListener 或 IIFE 包装\n";
        $examples .= "\n";
        $examples .= "// 获取元素\n";
        $examples .= "const buttons = component.querySelectorAll('.btn');\n";
        $examples .= "const cards = component.querySelectorAll('.card');\n";
        $examples .= "\n";
        $examples .= "// 添加事件监听\n";
        $examples .= "buttons.forEach(btn => {\n";
        $examples .= "    btn.addEventListener('click', function() {\n";
        $examples .= "        this.classList.toggle('active');\n";
        $examples .= "    });\n";
        $examples .= "});\n";
        $examples .= "\n";
        $examples .= "// 动画效果\n";
        $examples .= "const observer = new IntersectionObserver(entries => {\n";
        $examples .= "    entries.forEach(entry => {\n";
        $examples .= "        if (entry.isIntersecting) {\n";
        $examples .= "            entry.target.classList.add('visible');\n";
        $examples .= "        }\n";
        $examples .= "    });\n";
        $examples .= "}, { threshold: 0.1 });\n";
        $examples .= "\n";
        $examples .= "cards.forEach(card => observer.observe(card));\n";
        $examples .= "```\n\n";
        
        // 错误示例
        $examples .= "【✗ 错误示例 - 不要这样写】\n";
        $examples .= "```\n";
        $examples .= "// ❌ 错误：使用反引号\n";
        $examples .= "\$template = `some text`;\n\n";
        $examples .= "// ❌ 错误：在php_variables中使用PHP标签\n";
        $examples .= "<?php \$value = 1; ?>\n\n";
        $examples .= "// ❌ 错误：使用未定义的变量\n";
        $examples .= "\$result = \$undefinedVariable + 1;\n\n";
        $examples .= "// ❌ 错误：直接使用\$this\n";
        $examples .= "\$data = \$this->getData('key');\n\n";
        $examples .= "// ❌ 错误：使用全局CSS选择器\n";
        $examples .= "body { background: #fff; }\n";
        $examples .= "div { margin: 0; }\n\n";
        $examples .= "// ❌ 错误：输出未转义的数据\n";
        $examples .= "<div><?= \$userInput ?></div>\n";
        $examples .= "```\n";
        
        return $examples;
    }
    
    /**
     * 获取Header Nav固定代码块约束
     * 
     * @return string
     */
    private function getHeaderNavConstraint(): string
    {
        $constraint = "\n\n【重要-Header Nav 固定结构约束】\n";
        $constraint .= "生成header/导航组件时，nav导航部分必须遵循以下固定结构：\n\n";
        
        $constraint .= "1. 【导航数据获取-固定代码】必须使用以下PHP代码获取真实导航数据：\n";
        $constraint .= "```php\n";
        $constraint .= "// 获取数据\n";
        $constraint .= "\$page = \$this->getData('page');\n";
        $constraint .= "\$styleSettings = \$this->getData('style') ?: \$this->getData('style_settings') ?: [];\n";
        $constraint .= "\$componentConfig = \$this->getData('component_config') ?: [];\n";
        $constraint .= "\$config = array_merge(\$styleSettings, \$componentConfig);\n\n";
        $constraint .= "// 获取导航项配置\n";
        $constraint .= "\$useSubpages = (\$config['navigation.use_subpages'] ?? 'no') === 'yes';\n";
        $constraint .= "\$navItems = [];\n\n";
        $constraint .= "// 优先使用真实子页面作为导航\n";
        $constraint .= "if (\$useSubpages && \$page) {\n";
        $constraint .= "    \$navigationPages = \$page->getNavigationPages([], 10);\n";
        $constraint .= "    foreach (\$navigationPages as \$navPage) {\n";
        $constraint .= "        \$navItems[] = [\n";
        $constraint .= "            'text' => \$navPage['title'] ?? '',\n";
        $constraint .= "            'href' => \$navPage['url'] ?? '#',\n";
        $constraint .= "        ];\n";
        $constraint .= "    }\n";
        $constraint .= "}\n\n";
        $constraint .= "// 如果没有子页面，使用配置的导航项\n";
        $constraint .= "if (empty(\$navItems)) {\n";
        $constraint .= "    \$navItemsConfig = \$config['navigation.items'] ?? \"Home=>\\nAbout=>\\nBlog=>\\nFAQs=>\";\n";
        $constraint .= "    \$lines = preg_split('/\\r?\\n/', \$navItemsConfig);\n";
        $constraint .= "    foreach (\$lines as \$line) {\n";
        $constraint .= "        \$line = trim(\$line);\n";
        $constraint .= "        if (empty(\$line)) continue;\n";
        $constraint .= "        if (strpos(\$line, '=>') !== false) {\n";
        $constraint .= "            \$parts = explode('=>', \$line, 2);\n";
        $constraint .= "            \$text = trim(\$parts[0]);\n";
        $constraint .= "            \$href = trim(\$parts[1] ?? '');\n";
        $constraint .= "            if (!empty(\$text)) {\n";
        $constraint .= "                \$navItems[] = ['text' => \$text, 'href' => \$href ?: '#'];\n";
        $constraint .= "            }\n";
        $constraint .= "        }\n";
        $constraint .= "    }\n";
        $constraint .= "}\n";
        $constraint .= "```\n\n";
        
        $constraint .= "2. 【导航HTML结构-固定代码】nav渲染必须使用以下结构：\n";
        $constraint .= "```html\n";
        $constraint .= "<nav class=\"{nav_class}\" id=\"<?= \$componentId ?>-nav\">\n";
        $constraint .= "    <div class=\"{container_class}\">\n";
        $constraint .= "        <!-- Logo区域 -->\n";
        $constraint .= "        <?php if (\$logoDisplay && \$logoUrl): ?>\n";
        $constraint .= "        <div class=\"{logo_class}\">\n";
        $constraint .= "            <img src=\"<?= htmlspecialchars(\$logoUrl) ?>\" alt=\"<?= htmlspecialchars(\$metaTitle ?? '') ?>\" loading=\"lazy\">\n";
        $constraint .= "        </div>\n";
        $constraint .= "        <?php endif; ?>\n";
        $constraint .= "        \n";
        $constraint .= "        <!-- 导航链接 - 必须使用循环渲染 -->\n";
        $constraint .= "        <ul class=\"{links_class}\">\n";
        $constraint .= "            <?php foreach (\$navItems as \$index => \$navItem): ?>\n";
        $constraint .= "                <?php \n";
        $constraint .= "                \$navHref = \$navItem['href'] ?? '#';\n";
        $constraint .= "                \$navText = \$navItem['text'] ?? '';\n";
        $constraint .= "                \$isActive = \$index === 0;\n";
        $constraint .= "                ?>\n";
        $constraint .= "                <li><a href=\"<?= htmlspecialchars(\$navHref) ?>\" class=\"<?= \$isActive ? 'active' : '' ?>\"><?= htmlspecialchars(\$navText) ?></a></li>\n";
        $constraint .= "            <?php endforeach; ?>\n";
        $constraint .= "        </ul>\n";
        $constraint .= "        \n";
        $constraint .= "        <!-- CTA按钮区域（可选） -->\n";
        $constraint .= "    </div>\n";
        $constraint .= "</nav>\n";
        $constraint .= "```\n\n";
        
        $constraint .= "3. 【必须包含的配置字段】：\n";
        $constraint .= "   - navigation.display => 显示导航:select:yes|yes,no\n";
        $constraint .= "   - navigation.items => 导航项配置:textarea:Home=>\\nAbout=>\\nBlog=>|配置格式：名字=>url，一行一个\n";
        $constraint .= "   - navigation.use_subpages => 使用子页面作为导航:select:no|yes,no\n";
        $constraint .= "   - logo.display => 显示Logo:select:yes|yes,no\n";
        $constraint .= "   - logo.url => Logo地址:textarea:\n\n";
        
        $constraint .= "4. 【可调整的内容】：\n";
        $constraint .= "   - CSS样式（颜色、字体、间距、布局方式等）\n";
        $constraint .= "   - 额外的装饰元素\n";
        $constraint .= "   - 动画效果\n";
        $constraint .= "   - 响应式布局方式\n";
        $constraint .= "   - Logo和CTA按钮的位置和样式\n\n";
        
        $constraint .= "5. 【不可更改的内容】：\n";
        $constraint .= "   - 导航数据获取逻辑（必须支持真实页面数据）\n";
        $constraint .= "   - 导航项循环渲染结构\n";
        $constraint .= "   - htmlspecialchars() 转义处理\n";
        $constraint .= "   - \$page->getNavigationPages() 调用方式\n";
        
        return $constraint;
    }

    /**
     * 处理响应
     * 
     * 提取组件代码，移除可能的markdown代码块标记
     * 
     * @param string $response 原始响应
     * @param array $params 额外参数
     * @return string 处理后的响应
     */
    public function processResponse(string $response, array $params = []): string
    {
        // 尝试提取PHP代码（可能包含markdown代码块）
        $code = $response;

        // 移除markdown代码块标记
        if (preg_match('/```(?:php|phtml)?\s*(.*?)\s*```/s', $response, $matches)) {
            $code = $matches[1];
        } elseif (preg_match('/```\s*(.*?)\s*```/s', $response, $matches)) {
            $code = $matches[1];
        }

        // 清理代码
        $code = trim($code);
        
        // 移除可能的 PHP 开始标签前的空白
        $code = preg_replace('/^\s*<\?php\s*/', '<?php' . "\n", $code);
        
        // 确保以 <?php 开头
        if (strpos($code, '<?php') !== 0) {
            $code = '<?php' . "\n" . $code;
        }

        // 验证是否包含必需的组件元数据块
        if (strpos($code, '@component_start') === false) {
            // 如果没有找到组件元数据，尝试从原始响应中提取
            if (preg_match('/(@component_start.*?@component_end)/s', $response, $metaMatches)) {
                // 在代码开头添加元数据块
                $code = preg_replace('/<\?php\s*/', '<?php' . "\n" . $metaMatches[1] . "\n", $code, 1);
            }
        }

        return $code;
    }

    /**
     * 验证输入参数
     * 
     * @param array $params 参数
     * @return array 验证错误列表，空数组表示验证通过
     */
    public function validateParams(array $params = []): array
    {
        $errors = [];

        // 可以在这里添加参数验证逻辑
        // 例如：检查组件名称、分类等必需参数

        return $errors;
    }

    /**
     * 获取参数模板
     * 
     * @return array
     */
    public function getParamTemplate(): array
    {
        return [
            'description' => '组件生成场景适配器参数',
            'fields' => [
                'name' => [
                    'label' => '组件名称',
                    'type' => 'text',
                    'required' => true,
                    'description' => '组件的显示名称',
                ],
                'category' => [
                    'label' => '组件分类',
                    'type' => 'select',
                    'required' => true,
                    'options' => ['header', 'content', 'footer', 'widget'],
                    'description' => '组件的分类',
                ],
                'description' => [
                    'label' => '组件描述',
                    'type' => 'textarea',
                    'required' => false,
                    'description' => '组件的详细描述',
                ],
            ],
        ];
    }

    /**
     * 获取使用示例
     * 
     * @return array
     */
    public function getExamples(): array
    {
        return [
            [
                'title' => '生成内容组件',
                'description' => '根据描述生成一个内容展示组件',
                'input' => '创建一个产品展示卡片组件，包含图片、标题、描述和价格',
                'expected_output' => '完整的PHP组件模板代码，包含元数据块、字段定义、HTML结构和CSS样式',
            ],
            [
                'title' => '生成头部组件',
                'description' => '根据描述生成一个导航头部组件',
                'input' => '创建一个响应式导航栏，包含Logo、菜单项和CTA按钮',
                'expected_output' => '完整的PHP组件模板代码，符合PageBuilder组件规约',
            ],
        ];
    }

    /**
     * 检查是否支持指定模型
     * 
     * @param string $modelCode 模型代码
     * @return bool
     */
    public function supportsModel(string $modelCode): bool
    {
        // 支持所有模型
        return true;
    }
}
