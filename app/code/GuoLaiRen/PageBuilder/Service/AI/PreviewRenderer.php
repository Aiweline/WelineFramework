<?php

declare(strict_types=1);

/**
 * AI组件预览渲染器
 * 
 * 提供模拟的 $this 上下文来渲染组件模板
 */

namespace GuoLaiRen\PageBuilder\Service\AI;

/**
 * 模拟的 Page 对象
 * 用于预览时提供必要的页面方法
 */
class MockPage
{
    private array $data = [];
    
    public function __construct(array $data = [])
    {
        $this->data = array_merge([
            'title' => '预览页面',
            'description' => '这是AI组件的预览模式',
            'url' => '/preview',
        ], $data);
    }
    
    /**
     * 获取导航页面
     */
    public function getNavigationPages(array $options = [], int $limit = 10): array
    {
        return [
            ['title' => '首页', 'url' => '/', 'active' => true],
            ['title' => '关于我们', 'url' => '/about', 'active' => false],
            ['title' => '产品服务', 'url' => '/services', 'active' => false],
            ['title' => '新闻动态', 'url' => '/news', 'active' => false],
            ['title' => '联系我们', 'url' => '/contact', 'active' => false],
        ];
    }
    
    /**
     * 获取数据
     */
    public function getData(?string $key = null)
    {
        if ($key === null) {
            return $this->data;
        }
        return $this->data[$key] ?? null;
    }
    
    /**
     * 设置数据
     */
    public function setData(string $key, $value): self
    {
        $this->data[$key] = $value;
        return $this;
    }
    
    /**
     * 获取标题
     */
    public function getTitle(): string
    {
        return $this->data['title'] ?? '预览页面';
    }
    
    /**
     * 获取描述
     */
    public function getDescription(): string
    {
        return $this->data['description'] ?? '';
    }
    
    /**
     * 获取URL
     */
    public function getUrl(): string
    {
        return $this->data['url'] ?? '/';
    }
    
    /**
     * 获取子页面
     */
    public function getChildren(): array
    {
        return $this->getNavigationPages();
    }
    
    /**
     * 检查是否有子页面
     */
    public function hasChildren(): bool
    {
        return true;
    }
    
    /**
     * 魔术方法 - 处理未定义的方法调用
     */
    public function __call(string $method, array $args)
    {
        // 返回空数组或空字符串，防止方法不存在导致的错误
        if (str_starts_with($method, 'get')) {
            return str_contains($method, 'Array') || str_contains($method, 'List') || str_contains($method, 'Pages') ? [] : '';
        }
        return null;
    }
}

class PreviewRenderer
{
    /**
     * 模拟数据存储
     */
    private array $data = [];
    
    public function __construct()
    {
        $this->initMockData();
    }
    
    /**
     * 初始化模拟数据
     */
    private function initMockData(): void
    {
        // 创建模拟的 Page 对象
        $mockPage = new MockPage([
            'title' => '预览页面',
            'description' => 'AI组件预览模式',
            'url' => '/preview',
        ]);
        
        // 预设完整的模拟数据
        $this->data = [
            'page' => $mockPage,
            'is_preview' => true,
            'component_config' => $this->getMockComponentConfig(),
            'style_settings' => $this->getMockStyleSettings(),
            'style' => $this->getMockStyleSettings(),
        ];
    }
    
    /**
     * 获取模拟的组件配置
     */
    private function getMockComponentConfig(): array
    {
        return [
            // 内容配置
            'content.title' => '示例标题',
            'content.subtitle' => '示例副标题',
            'content.description' => '这是预览模式下的示例描述内容，用于展示组件的显示效果。',
            
            // 按钮配置
            'button.text' => '了解更多',
            'button.url' => '#',
            'button.style' => 'primary',
            
            // 布局配置
            'layout.container_width' => '1200',
            'layout.padding_top' => '80',
            'layout.padding_bottom' => '80',
            'layout.text_align' => 'center',
            
            // 样式配置
            'style.bg_type' => 'color',
            'style.bg_color' => '#ffffff',
            'style.text_color' => '#333333',
            'style.title_color' => '#1a1a1a',
            'style.accent_color' => '#7c3aed',
            
            // Logo配置
            'logo.display' => 'yes',
            'logo.text' => 'Brand Name',
            'logo.width' => '40',
            
            // 导航配置
            'navigation.display' => 'yes',
            'navigation.items' => "首页=>/\n关于我们=>/about\n产品服务=>/services\n联系我们=>/contact",
            'navigation.use_subpages' => 'no',
            
            // CTA配置
            'cta.show' => 'yes',
            'cta.text' => '立即咨询',
            'cta.url' => '#contact',
            
            // 品牌配置
            'brand.name' => 'Brand Name',
            'brand.description' => '专业可靠的服务提供商',
            
            // 社交媒体配置
            'social.show' => 'yes',
            'social.facebook' => 'https://facebook.com',
            'social.twitter' => 'https://twitter.com',
            
            // 版权配置
            'copyright.text' => 'All rights reserved.',
            'copyright.year' => date('Y'),
        ];
    }
    
    /**
     * 获取模拟的样式设置
     */
    private function getMockStyleSettings(): array
    {
        return [
            // 颜色设置
            'primary_color' => '#7c3aed',
            'secondary_color' => '#667eea',
            'text_color' => '#333333',
            'heading_color' => '#1a1a1a',
            'link_color' => '#7c3aed',
            'background_color' => '#ffffff',
            
            // 字体设置
            'font_family' => 'Inter, system-ui, sans-serif',
            'font_size' => '16px',
            'heading_font' => 'Inter, system-ui, sans-serif',
            
            // 间距设置
            'section_padding' => '80px',
            'container_width' => '1200px',
            
            // 边框设置
            'border_radius' => '8px',
            'border_color' => '#e5e7eb',
        ];
    }
    
    /**
     * 获取数据（模拟 Template::getData）
     */
    public function getData(?string $key = null)
    {
        if ($key === null) {
            return $this->data;
        }
        return $this->data[$key] ?? null;
    }
    
    /**
     * 设置数据
     */
    public function setData(string $key, $value): self
    {
        $this->data[$key] = $value;
        return $this;
    }
    
    /**
     * 渲染模板内容
     * 
     * @param string $templateContent 模板内容
     * @return array ['success' => bool, 'html' => string, 'error' => string]
     */
    public function render(string $templateContent): array
    {
        // 创建临时文件
        $tempFile = sys_get_temp_dir() . '/pb_preview_' . uniqid() . '.phtml';
        file_put_contents($tempFile, $templateContent);
        
        // 设置自定义错误处理器
        $errorMessage = '';
        set_error_handler(function($errno, $errstr, $errfile, $errline) use (&$errorMessage) {
            // 忽略 Notice 和 Warning
            if ($errno == E_NOTICE || $errno == E_WARNING) {
                return true;
            }
            $errorMessage = "{$errstr} (line {$errline})";
            return true;
        });
        
        ob_start();
        try {
            // 在 $this 上下文中渲染
            $this->includeTemplate($tempFile);
        } catch (\Throwable $t) {
            $errorMessage = $t->getMessage();
        }
        $html = ob_get_clean();
        
        // 恢复错误处理器
        restore_error_handler();
        
        // 清理临时文件
        @unlink($tempFile);
        
        // 检查是否有错误
        if (!empty($errorMessage)) {
            return [
                'success' => false,
                'html' => $this->generateErrorHtml($errorMessage),
                'error' => '预览渲染错误: ' . $errorMessage,
            ];
        }
        
        // 检查输出是否包含PHP错误信息
        if (preg_match('/<br\s*\/?>\s*<b>(?:Fatal|Parse|Warning|Notice)/i', $html)) {
            return [
                'success' => false,
                'html' => $this->generateErrorHtml('代码执行产生了错误'),
                'error' => '代码执行产生了PHP错误',
            ];
        }
        
        return [
            'success' => true,
            'html' => $html,
            'error' => '',
        ];
    }
    
    /**
     * 在当前对象上下文中包含模板
     * 这样模板中的 $this 就会指向这个对象
     */
    private function includeTemplate(string $file): void
    {
        include $file;
    }
    
    /**
     * 生成错误HTML
     */
    private function generateErrorHtml(string $error): string
    {
        return '<div style="padding:20px;background:#fee;border:1px solid #c00;border-radius:8px;color:#900;font-family:sans-serif;">
            <strong>预览错误:</strong> ' . htmlspecialchars($error) . '
        </div>';
    }
}
