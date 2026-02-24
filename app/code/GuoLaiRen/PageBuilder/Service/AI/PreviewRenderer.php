<?php

declare(strict_types=1);

/**
 * AI组件预览渲染器
 * 
 * 使用框架 Template 编译流程渲染 AI 生成的 phtml 模板
 * 流程：保存临时 phtml → 框架 taglib 编译 → 获取编译后 HTML
 * 与部件预览（Widget Preview）逻辑一致
 */

namespace GuoLaiRen\PageBuilder\Service\AI;

use Weline\Framework\View\Template;

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
     * 渲染模板内容（使用框架 Template 编译流程）
     * 
     * 流程与部件预览一致：
     * 1. 保存临时 phtml 文件
     * 2. 通过 Template::tmp_replace() 编译模板标签（<lang>、taglib 等）
     * 3. 保存编译后的文件
     * 4. 通过 Template::ob_file() 执行并捕获 HTML 输出
     * 
     * @param string $templateContent 模板内容（phtml 源码）
     * @return array ['success' => bool, 'html' => string, 'error' => string]
     */
    public function render(string $templateContent): array
    {
        // 临时文件路径
        $uid = uniqid('pb_preview_', true);
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pb_preview';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0770, true);
        }
        $tplFile = $tempDir . DIRECTORY_SEPARATOR . $uid . '.phtml';
        $comFile = $tempDir . DIRECTORY_SEPARATOR . 'com_' . $uid . '.phtml';
        
        try {
            // 1. 保存临时 phtml 文件
            file_put_contents($tplFile, $templateContent);
            
            // 2. 获取框架 Template 实例，进行标签编译
            $template = Template::getInstance();
            
            // 注入模拟数据到 Template（与部件预览一致）
            $template->addData($this->data);
            
            // 3. 编译模板内容（处理 <lang>、taglib 等框架标签）
            $compiledContent = $template->tmp_replace($templateContent, $comFile);
            
            // 4. 保存编译后的文件
            file_put_contents($comFile, $compiledContent);

            // 5. 设置自定义错误处理器
            $errorMessage = '';
            set_error_handler(function ($errno, $errstr, $errfile, $errline) use (&$errorMessage) {
                // 忽略 Notice 和 Warning
                if ($errno == E_NOTICE || $errno == E_WARNING) {
                    return true;
                }
                $errorMessage = "{$errstr} (line {$errline})";
                return true;
            });
            
            // 6. 通过 Template::ob_file() 执行编译后的文件并捕获输出
            $html = $template->ob_file($comFile);
            
            // 恢复错误处理器
            restore_error_handler();
            
            // 检查是否有错误
            if (!empty($errorMessage)) {
                return [
                    'success' => false,
                    'html' => $this->generateErrorHtml($errorMessage),
                    'error' => '预览渲染错误: ' . $errorMessage,
                ];
            }
            
            // 检查输出是否包含 PHP 错误信息
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
            
        } catch (\Throwable $t) {
            // 确保恢复错误处理器
            restore_error_handler();
            return [
                'success' => false,
                'html' => $this->generateErrorHtml($t->getMessage()),
                'error' => '预览渲染错误: ' . $t->getMessage(),
            ];
        } finally {
            // 清理临时文件
            @unlink($tplFile);
            @unlink($comFile);
        }
    }
    
    /**
     * 从已存在的 phtml 文件渲染预览（先编译再 ob_file）
     * 用于 component-stream 完成后：先写入 phtml 文件，再调用本方法由 ob 服务渲染返回 HTML
     *
     * @param string $phtmlPath 已存在的 phtml 模板文件路径
     * @return array ['success' => bool, 'html' => string, 'error' => string]
     */
    public function renderFromFile(string $phtmlPath): array
    {
        if (!is_file($phtmlPath)) {
            return [
                'success' => false,
                'html' => $this->generateErrorHtml('模板文件不存在: ' . $phtmlPath),
                'error' => '模板文件不存在',
            ];
        }
        $templateContent = file_get_contents($phtmlPath);
        if ($templateContent === false) {
            return [
                'success' => false,
                'html' => $this->generateErrorHtml('无法读取模板文件'),
                'error' => '无法读取模板文件',
            ];
        }
        $comFile = dirname($phtmlPath) . DIRECTORY_SEPARATOR . 'com_' . basename($phtmlPath);
        try {
            $template = Template::getInstance();
            $template->addData($this->data);
            $compiledContent = $template->tmp_replace($templateContent, $comFile);
            file_put_contents($comFile, $compiledContent);
            $errorMessage = '';
            set_error_handler(function ($errno, $errstr, $errfile, $errline) use (&$errorMessage) {
                if ($errno == E_NOTICE || $errno == E_WARNING) {
                    return true;
                }
                $errorMessage = "{$errstr} (line {$errline})";
                return true;
            });
            $html = $template->ob_file($comFile);
            restore_error_handler();
            if (!empty($errorMessage)) {
                return [
                    'success' => false,
                    'html' => $this->generateErrorHtml($errorMessage),
                    'error' => '预览渲染错误: ' . $errorMessage,
                ];
            }
            if (preg_match('/<br\s*\/?>\s*<b>(?:Fatal|Parse|Warning|Notice)/i', $html)) {
                return [
                    'success' => false,
                    'html' => $this->generateErrorHtml('代码执行产生了错误'),
                    'error' => '代码执行产生了PHP错误',
                ];
            }
            return ['success' => true, 'html' => $html, 'error' => ''];
        } catch (\Throwable $t) {
            restore_error_handler();
            return [
                'success' => false,
                'html' => $this->generateErrorHtml($t->getMessage()),
                'error' => '预览渲染错误: ' . $t->getMessage(),
            ];
        } finally {
            @unlink($comFile);
        }
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
