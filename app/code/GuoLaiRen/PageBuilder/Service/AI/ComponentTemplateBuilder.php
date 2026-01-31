<?php

declare(strict_types=1);

/*
 * AI 组件模板构建器
 * 
 * 负责根据规约生成符合标准的组件模板代码：
 * 1. 生成 @component_start / @component_end 元数据块
 * 2. 生成 @fields_start / @fields_end 配置字段块
 * 3. 生成 PHP 配置解析代码
 * 4. 生成 HTML 结构和 CSS 样式
 */

namespace GuoLaiRen\PageBuilder\Service\AI;

class ComponentTemplateBuilder
{
    /**
     * 构建完整的组件模板
     * 
     * @param array $spec 组件规格定义
     * @return string 完整的 PHTML 模板内容
     */
    public function buildTemplate(array $spec): string
    {
        $metadata = $this->buildMetadata($spec['metadata'] ?? []);
        $fields = $this->buildFieldsDefinition($spec['fields'] ?? []);
        $phpCode = $this->buildPhpCode($spec);
        $html = $this->buildHtmlStructure($spec['structure'] ?? []);
        $styles = $this->buildStyles($spec['styles'] ?? []);
        
        // 组合所有部分
        $template = "<?php\n";
        $template .= "/**\n";
        $template .= " * AI 生成组件 - {$spec['metadata']['name']}\n";
        $template .= " * \n";
        $template .= " * @var \\Weline\\Framework\\View\\Template \$this\n";
        $template .= " * \n";
        $template .= $metadata;
        $template .= " * \n";
        $template .= $fields;
        $template .= " */\n\n";
        $template .= $phpCode;
        $template .= "\n?>\n";
        $template .= $styles;
        $template .= "\n";
        $template .= $html;
        
        return $template;
    }
    
    /**
     * 构建组件元数据块
     * 
     * @param array $metadata 元数据数组
     * @return string 元数据注释块
     */
    public function buildMetadata(array $metadata): string
    {
        $lines = [];
        $lines[] = " * @component_start";
        
        // 必需字段
        $lines[] = " * code: " . ($metadata['code'] ?? 'content-ai-' . date('ymdHi'));
        $lines[] = " * name: " . ($metadata['name'] ?? 'AI 生成组件');
        
        // 可选字段
        if (!empty($metadata['name_en'])) {
            $lines[] = " * name_en: " . $metadata['name_en'];
        }
        if (!empty($metadata['description'])) {
            $lines[] = " * description: " . $metadata['description'];
        }
        
        $lines[] = " * category: " . ($metadata['category'] ?? 'content');
        $lines[] = " * region: " . ($metadata['region'] ?? $metadata['category'] ?? 'content');
        $lines[] = " * type: " . ($metadata['type'] ?? 'section');
        $lines[] = " * ai_generated: true";
        $lines[] = " * created_at: " . date('Y-m-d H:i:s');
        
        if (!empty($metadata['icon'])) {
            $lines[] = " * icon: " . $metadata['icon'];
        }
        
        $lines[] = " * @component_end";
        
        return implode("\n", $lines) . "\n";
    }
    
    /**
     * 构建配置字段定义块
     * 
     * @param array $fields 字段定义数组
     * @return string 字段注释块
     */
    public function buildFieldsDefinition(array $fields): string
    {
        if (empty($fields)) {
            // 提供默认字段
            $fields = $this->getDefaultFields();
        }
        
        $lines = [];
        $lines[] = " * @fields_start";
        $lines[] = " * ";
        
        $currentGroup = null;
        
        foreach ($fields as $field) {
            // 处理分组
            $group = $field['group'] ?? 'content';
            if ($group !== $currentGroup) {
                if ($currentGroup !== null) {
                    $lines[] = " * ";
                }
                $groupLabel = $this->getGroupLabel($group);
                $lines[] = " * group:{$group} => {$groupLabel}";
                $currentGroup = $group;
            }
            
            // 构建字段定义
            $key = $field['key'] ?? $field['name'];
            $label = $field['label'] ?? ucfirst($field['name'] ?? 'Field');
            $type = $field['type'] ?? 'text';
            $default = $field['default'] ?? '';
            
            // 处理选项（用于 select 类型）
            $options = '';
            if ($type === 'select' && !empty($field['options'])) {
                $options = '|' . implode(',', $field['options']);
            }
            
            $lines[] = " * {$group}.{$key} => {$label}:{$type}:{$default}{$options}";
        }
        
        $lines[] = " * ";
        $lines[] = " * @fields_end";
        
        return implode("\n", $lines) . "\n";
    }
    
    /**
     * 构建 PHP 配置解析代码
     * 
     * @param array $spec 组件规格
     * @return string PHP 代码
     */
    public function buildPhpCode(array $spec): string
    {
        $code = $spec['metadata']['code'] ?? 'ai-component';
        $prefix = str_replace('-', '_', $code);
        
        $php = <<<PHP
// ========================================
// 组件初始化
// ========================================
\$componentId = '{$prefix}_' . uniqid();

\$page = \$this->getData('page');
\$styleSettings = \$this->getData('style') ?: \$this->getData('style_settings') ?: [];
\$componentConfig = \$this->getData('component_config') ?: [];
\$config = array_merge(\$styleSettings, \$componentConfig);
\$isPreview = (bool)\$this->getData('is_preview');

// ========================================
// 辅助函数
// ========================================
\$getConfig = function(\$key, \$default = '') use (\$config) {
    \$keys = explode('.', \$key);
    \$value = \$config;
    foreach (\$keys as \$k) {
        if (!isset(\$value[\$k])) {
            return \$default;
        }
        \$value = \$value[\$k];
    }
    return \$value !== '' ? \$value : \$default;
};

// ========================================
// 配置解析
// ========================================

PHP;
        
        // 添加配置变量解析
        $fields = $spec['fields'] ?? $this->getDefaultFields();
        $parsedVars = [];
        
        foreach ($fields as $field) {
            $group = $field['group'] ?? 'content';
            $key = $field['key'] ?? $field['name'];
            $default = $field['default'] ?? '';
            $varName = $this->fieldToVarName($key);
            
            // 避免重复
            if (in_array($varName, $parsedVars)) {
                continue;
            }
            $parsedVars[] = $varName;
            
            $defaultQuoted = is_string($default) ? "'" . addslashes($default) . "'" : $default;
            $php .= "\${$varName} = \$getConfig('{$group}.{$key}', {$defaultQuoted});\n";
        }
        
        return $php;
    }
    
    /**
     * 构建 HTML 结构
     * 
     * @param array $structure HTML 结构定义
     * @return string HTML 代码
     */
    public function buildHtmlStructure(array $structure): string
    {
        if (empty($structure)) {
            // 返回默认结构
            return $this->getDefaultHtmlStructure();
        }
        
        return $this->renderStructure($structure);
    }
    
    /**
     * 构建 CSS 样式
     * 
     * @param array $styles 样式定义
     * @return string 内联样式代码
     */
    public function buildStyles(array $styles): string
    {
        if (empty($styles)) {
            return $this->getDefaultStyles();
        }
        
        $css = "<style>\n";
        $css .= "#<?= \$componentId ?> {\n";
        
        foreach ($styles as $property => $value) {
            if (is_string($value)) {
                $css .= "    {$property}: {$value};\n";
            }
        }
        
        $css .= "}\n";
        
        // 添加响应式样式
        if (!empty($styles['responsive'])) {
            foreach ($styles['responsive'] as $breakpoint => $breakpointStyles) {
                $css .= "\n@media (max-width: {$breakpoint}) {\n";
                $css .= "    #<?= \$componentId ?> {\n";
                foreach ($breakpointStyles as $property => $value) {
                    $css .= "        {$property}: {$value};\n";
                }
                $css .= "    }\n";
                $css .= "}\n";
            }
        }
        
        $css .= "</style>";
        
        return $css;
    }
    
    /**
     * 根据 AI 生成的规格创建组件模板
     * 
     * @param string $name 组件名称
     * @param string $category 组件分类
     * @param string $description 组件描述
     * @param array $fields 配置字段
     * @param string $htmlContent HTML 内容
     * @param string $cssContent CSS 样式
     * @return string 完整模板
     */
    public function createFromAIOutput(
        string $name,
        string $category,
        string $description,
        array $fields,
        string $htmlContent,
        string $cssContent = ''
    ): string {
        $code = \GuoLaiRen\PageBuilder\Model\Component::generateAIComponentCode($category, $name);
        
        $spec = [
            'metadata' => [
                'code' => $code,
                'name' => $name,
                'name_en' => $this->transliterate($name),
                'description' => $description,
                'category' => $category,
                'region' => $category,
                'type' => 'section',
                'icon' => 'bi-robot',
            ],
            'fields' => $fields,
            'structure' => [], // 将使用自定义 HTML
            'styles' => [],    // 将使用自定义 CSS
        ];
        
        // 构建模板
        $template = "<?php\n";
        $template .= "/**\n";
        $template .= " * AI 生成组件 - {$name}\n";
        $template .= " * \n";
        $template .= " * @var \\Weline\\Framework\\View\\Template \$this\n";
        $template .= " * \n";
        $template .= $this->buildMetadata($spec['metadata']);
        $template .= " * \n";
        $template .= $this->buildFieldsDefinition($fields);
        $template .= " */\n\n";
        $template .= $this->buildPhpCode($spec);
        $template .= "\n?>\n";
        
        // 添加样式
        if (!empty($cssContent)) {
            $template .= "<!-- AI 生成组件样式 -->\n";
            $template .= "<style>\n{$cssContent}\n</style>\n\n";
        }
        
        // 添加 HTML 内容
        $template .= "<!-- AI 生成组件开始: <?= \$componentId ?> -->\n";
        $template .= "<section id=\"<?= \$componentId ?>\" class=\"ai-component ai-{$code}\">\n";
        $template .= $this->indentHtml($htmlContent, 4);
        $template .= "\n</section>\n";
        $template .= "<!-- AI 生成组件结束: <?= \$componentId ?> -->\n";
        
        return $template;
    }
    
    /**
     * 获取默认字段定义
     */
    private function getDefaultFields(): array
    {
        return [
            ['group' => 'content', 'key' => 'title', 'label' => '标题', 'type' => 'text', 'default' => 'AI 组件标题'],
            ['group' => 'content', 'key' => 'description', 'label' => '描述', 'type' => 'textarea', 'default' => '这是一个 AI 生成的组件'],
            ['group' => 'style', 'key' => 'bg_color', 'label' => '背景颜色', 'type' => 'color', 'default' => '#ffffff'],
            ['group' => 'style', 'key' => 'text_color', 'label' => '文字颜色', 'type' => 'color', 'default' => '#333333'],
            ['group' => 'style', 'key' => 'padding', 'label' => '内边距', 'type' => 'number', 'default' => '40'],
        ];
    }
    
    /**
     * 获取默认 HTML 结构
     */
    private function getDefaultHtmlStructure(): string
    {
        return <<<HTML
<!-- AI 生成组件开始: <?= \$componentId ?> -->
<section id="<?= \$componentId ?>" class="ai-component">
    <div class="ai-component-inner">
        <?php if (!empty(\$title)): ?>
        <h2 class="ai-component-title"><?= htmlspecialchars(\$title) ?></h2>
        <?php endif; ?>
        
        <?php if (!empty(\$description)): ?>
        <p class="ai-component-description"><?= htmlspecialchars(\$description) ?></p>
        <?php endif; ?>
    </div>
</section>
<!-- AI 生成组件结束: <?= \$componentId ?> -->
HTML;
    }
    
    /**
     * 获取默认样式
     */
    private function getDefaultStyles(): string
    {
        return <<<CSS
<style>
#<?= \$componentId ?> {
    background-color: <?= htmlspecialchars(\$bgColor ?? '#ffffff') ?>;
    color: <?= htmlspecialchars(\$textColor ?? '#333333') ?>;
    padding: <?= htmlspecialchars(\$padding ?? '40') ?>px 20px;
    width: 100%;
}

#<?= \$componentId ?> .ai-component-inner {
    max-width: 1200px;
    margin: 0 auto;
    text-align: center;
}

#<?= \$componentId ?> .ai-component-title {
    font-size: 32px;
    font-weight: 700;
    margin-bottom: 20px;
}

#<?= \$componentId ?> .ai-component-description {
    font-size: 16px;
    line-height: 1.6;
    opacity: 0.8;
}

@media (max-width: 767px) {
    #<?= \$componentId ?> {
        padding: 30px 15px;
    }
    
    #<?= \$componentId ?> .ai-component-title {
        font-size: 24px;
    }
}
</style>
CSS;
    }
    
    /**
     * 获取分组标签
     */
    private function getGroupLabel(string $group): string
    {
        $labels = [
            'content' => '内容设置',
            'style' => '样式设置',
            'display' => '显示控制',
            'advanced' => '高级设置',
            'button' => '按钮设置',
            'image' => '图片设置',
            'layout' => '布局设置',
        ];
        
        return $labels[$group] ?? ucfirst($group);
    }
    
    /**
     * 将字段名转换为变量名
     */
    private function fieldToVarName(string $key): string
    {
        // 转换为驼峰命名
        $parts = explode('_', str_replace('-', '_', $key));
        $varName = $parts[0];
        for ($i = 1; $i < count($parts); $i++) {
            $varName .= ucfirst($parts[$i]);
        }
        return $varName;
    }
    
    /**
     * 渲染结构数组为 HTML
     */
    private function renderStructure(array $structure, int $indent = 0): string
    {
        $html = '';
        $indentStr = str_repeat('    ', $indent);
        
        foreach ($structure as $element) {
            $tag = $element['tag'] ?? 'div';
            $attrs = $element['attributes'] ?? [];
            $content = $element['content'] ?? '';
            $children = $element['children'] ?? [];
            
            $attrStr = '';
            foreach ($attrs as $name => $value) {
                $attrStr .= " {$name}=\"{$value}\"";
            }
            
            $html .= "{$indentStr}<{$tag}{$attrStr}>";
            
            if (!empty($children)) {
                $html .= "\n";
                $html .= $this->renderStructure($children, $indent + 1);
                $html .= "{$indentStr}</{$tag}>\n";
            } elseif (!empty($content)) {
                $html .= $content;
                $html .= "</{$tag}>\n";
            } else {
                $html .= "</{$tag}>\n";
            }
        }
        
        return $html;
    }
    
    /**
     * 简单的中文转拼音/英文
     */
    private function transliterate(string $text): string
    {
        // 简单处理：如果是英文就返回，否则返回通用名称
        if (preg_match('/^[a-zA-Z0-9\s\-_]+$/', $text)) {
            return ucwords($text);
        }
        return 'AI Generated Component';
    }
    
    /**
     * 缩进 HTML 内容
     */
    private function indentHtml(string $html, int $spaces = 4): string
    {
        $indent = str_repeat(' ', $spaces);
        $lines = explode("\n", $html);
        return implode("\n", array_map(fn($line) => $indent . $line, $lines));
    }
}
