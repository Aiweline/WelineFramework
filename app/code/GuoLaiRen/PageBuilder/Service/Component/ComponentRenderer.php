<?php

declare(strict_types=1);

/**
 * 单组件渲染服务
 * 
 * 用于局部刷新时渲染单个组件的 HTML，避免全量刷新
 * 
 * 功能：
 * 1. 渲染单个组件并返回 HTML
 * 2. 支持可视化编辑器模式（带包装器）
 * 3. 支持嵌套组件渲染
 * 
 * @author GuoLaiRen
 * @since 2.0.0
 */

namespace GuoLaiRen\PageBuilder\Service\Component;

use GuoLaiRen\PageBuilder\Model\Component;
use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\Template\TemplatePathResolver;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;

class ComponentRenderer
{
    private ?ComponentResolver $componentResolver = null;
    private ?TemplatePathResolver $pathResolver = null;
    private ?Template $template = null;
    
    /**
     * 单例实例
     */
    private static ?self $instance = null;
    
    public function __construct(
        ?ComponentResolver $componentResolver = null,
        ?TemplatePathResolver $pathResolver = null
    ) {
        $this->componentResolver = $componentResolver;
        $this->pathResolver = $pathResolver;
    }
    
    /**
     * 获取 ComponentResolver（延迟加载）
     */
    private function getComponentResolver(): ComponentResolver
    {
        if ($this->componentResolver === null) {
            $this->componentResolver = ComponentResolver::getInstance();
        }
        return $this->componentResolver;
    }
    
    /**
     * 获取 TemplatePathResolver（延迟加载）
     */
    private function getPathResolver(): TemplatePathResolver
    {
        if ($this->pathResolver === null) {
            $this->pathResolver = TemplatePathResolver::getInstance();
        }
        return $this->pathResolver;
    }
    
    /**
     * 获取 Template 实例
     */
    private function getTemplate(): Template
    {
        if ($this->template === null) {
            $this->template = Template::getInstance();
        }
        return $this->template;
    }
    
    /**
     * 渲染单个组件
     * 
     * @param string $componentCode 组件代码
     * @param string $instanceId 组件实例ID
     * @param string $styleCode 模板代码
     * @param array $config 组件配置
     * @param array $options 渲染选项
     *   - region: 组件所在区域
     *   - index: 组件索引
     *   - visual_mode: 是否可视化编辑模式
     *   - page: Page 对象
     *   - style_settings: 样式设置
     *   - children: 嵌套子组件配置
     * @return RenderResult 渲染结果
     */
    public function renderSingle(
        string $componentCode,
        string $instanceId,
        string $styleCode,
        array $config = [],
        array $options = []
    ): RenderResult {
        $region = $options['region'] ?? 'content';
        $index = $options['index'] ?? 0;
        $visualMode = $options['visual_mode'] ?? false;
        $page = $options['page'] ?? null;
        $styleSettings = $options['style_settings'] ?? [];
        $children = $options['children'] ?? [];
        
        // 解析组件模板路径
        $templatePath = $this->resolveComponentTemplatePath($componentCode, $styleCode);
        if ($templatePath === null) {
            return RenderResult::fail(
                sprintf('组件 [%s] 模板文件未找到', $componentCode),
                'TEMPLATE_NOT_FOUND'
            );
        }
        
        // 获取实际使用的模板代码（可能是跨模板组件）
        $actualStyleCode = $this->getActualStyleCode($componentCode, $styleCode);
        
        // 准备模板变量
        $templateVars = [
            'page' => $page,
            'style' => $styleSettings,
            'style_settings' => $styleSettings,
            'component_config' => $config,
            'component_code' => $componentCode,
            'component_instance_id' => $instanceId,
            'is_visual_mode' => $visualMode,
            'children' => $children,
        ];
        
        try {
            // 渲染组件
            $template = $this->getTemplate();
            foreach ($templateVars as $key => $value) {
                $template->assign($key, $value);
            }
            
            $componentHtml = $template->fetch($templatePath, $templateVars);
            
            if (!is_string($componentHtml)) {
                $componentHtml = '';
            }
            
            // 可视化编辑模式下添加包装器
            if ($visualMode) {
                $componentHtml = $this->wrapForVisualEditor(
                    $componentHtml,
                    $componentCode,
                    $instanceId,
                    $region,
                    $index,
                    $actualStyleCode,
                    !empty($children)
                );
            }
            
            return RenderResult::success($componentHtml, [
                'instance_id' => $instanceId,
                'component_code' => $componentCode,
                'style_code' => $actualStyleCode,
                'region' => $region,
                'index' => $index,
            ]);
            
        } catch (\Throwable $e) {
            return RenderResult::fail(
                sprintf('渲染组件 [%s] 失败: %s', $componentCode, $e->getMessage()),
                'RENDER_ERROR',
                ['exception' => $e->getMessage()]
            );
        }
    }
    
    /**
     * 渲染组件用于可视化编辑器预览
     * 
     * @param string $componentCode 组件代码
     * @param string $styleCode 模板代码
     * @param array $config 组件配置
     * @return RenderResult
     */
    public function renderPreview(
        string $componentCode,
        string $styleCode,
        array $config = []
    ): RenderResult {
        $instanceId = 'preview-' . uniqid();
        
        return $this->renderSingle(
            $componentCode,
            $instanceId,
            $styleCode,
            $config,
            [
                'region' => 'content',
                'index' => 0,
                'visual_mode' => false,  // 预览不需要编辑包装器
                'is_preview' => true,
            ]
        );
    }
    
    /**
     * 批量渲染组件
     * 
     * @param array $components 组件列表 [['code' => '', 'instance_id' => '', 'config' => []], ...]
     * @param string $styleCode 模板代码
     * @param array $options 渲染选项
     * @return array RenderResult 数组
     */
    public function renderBatch(
        array $components,
        string $styleCode,
        array $options = []
    ): array {
        $results = [];
        
        foreach ($components as $index => $comp) {
            $code = $comp['code'] ?? '';
            $instanceId = $comp['instance_id'] ?? $comp['id'] ?? uniqid('comp-');
            $config = $comp['config'] ?? [];
            $children = $comp['children'] ?? [];
            
            $componentOptions = array_merge($options, [
                'index' => $index,
                'children' => $children,
            ]);
            
            $results[$instanceId] = $this->renderSingle(
                $code,
                $instanceId,
                $styleCode,
                $config,
                $componentOptions
            );
        }
        
        return $results;
    }
    
    /**
     * 解析组件模板路径
     * 
     * @param string $componentCode 组件代码
     * @param string $styleCode 模板代码
     * @return string|null 模板引用路径
     */
    private function resolveComponentTemplatePath(string $componentCode, string $styleCode): ?string
    {
        // 使用 ComponentResolver 解析
        return $this->getComponentResolver()->resolveComponentTemplateReference(
            $componentCode,
            $styleCode
        );
    }
    
    /**
     * 获取组件实际使用的模板代码
     * 
     * @param string $componentCode 组件代码
     * @param string $preferredStyleCode 首选模板代码
     * @return string 实际使用的模板代码
     */
    private function getActualStyleCode(string $componentCode, string $preferredStyleCode): string
    {
        $component = $this->getComponentResolver()->resolve($componentCode, $preferredStyleCode);
        if ($component) {
            return $component->getData(Component::fields_STYLE_CODE) ?: $preferredStyleCode;
        }
        return $preferredStyleCode;
    }
    
    /**
     * 为可视化编辑器包装组件 HTML
     * 
     * @param string $html 组件 HTML
     * @param string $componentCode 组件代码
     * @param string $instanceId 实例ID
     * @param string $region 区域
     * @param int $index 索引
     * @param string $styleCode 模板代码
     * @param bool $hasChildren 是否有子组件
     * @return string 包装后的 HTML
     */
    private function wrapForVisualEditor(
        string $html,
        string $componentCode,
        string $instanceId,
        string $region,
        int $index,
        string $styleCode,
        bool $hasChildren = false
    ): string {
        $escapedCode = htmlspecialchars($componentCode);
        $escapedInstanceId = htmlspecialchars($instanceId);
        $escapedRegion = htmlspecialchars($region);
        $escapedStyleCode = htmlspecialchars($styleCode);
        $hasChildrenAttr = $hasChildren ? 'true' : 'false';
        
        return <<<HTML
<div class="vb-component pb-component" 
     data-instance-id="{$escapedInstanceId}"
     data-component="{$escapedCode}" 
     data-region="{$escapedRegion}" 
     data-index="{$index}" 
     data-style-code="{$escapedStyleCode}"
     data-has-children="{$hasChildrenAttr}">
    <div class="vb-component-header">
        <span class="vb-component-name">
            <i class="mdi mdi-drag component-drag-handle"></i>
            {$escapedCode}
        </span>
        <div class="vb-component-actions">
            <button class="btn btn-sm btn-link" onclick="VB.editComponent('{$escapedInstanceId}')">
                <i class="mdi mdi-pencil"></i>
            </button>
            <button class="btn btn-sm btn-link text-danger" onclick="VB.removeComponent('{$escapedInstanceId}')">
                <i class="mdi mdi-delete"></i>
            </button>
        </div>
    </div>
    <div class="vb-component-content">
        {$html}
    </div>
</div>
HTML;
    }
    
    /**
     * 生成组件占位符 HTML（用于添加组件时的加载状态）
     * 
     * @param string $instanceId 实例ID
     * @param string $region 区域
     * @return string 占位符 HTML
     */
    public function generatePlaceholder(string $instanceId, string $region): string
    {
        $escapedInstanceId = htmlspecialchars($instanceId);
        $escapedRegion = htmlspecialchars($region);
        
        return <<<HTML
<div class="vb-component vb-component-loading" 
     data-instance-id="{$escapedInstanceId}"
     data-region="{$escapedRegion}">
    <div class="vb-component-loading-spinner">
        <i class="mdi mdi-loading mdi-spin"></i>
        <span>加载中...</span>
    </div>
</div>
HTML;
    }
    
    /**
     * 清除缓存
     */
    public function clearCache(): void
    {
        $this->template = null;
    }
    
    /**
     * 获取实例（单例模式）
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
}

/**
 * 渲染结果类
 */
class RenderResult
{
    private bool $success;
    private string $html;
    private string $message;
    private string $errorCode;
    private array $data;
    
    private function __construct(
        bool $success,
        string $html = '',
        string $message = '',
        string $errorCode = '',
        array $data = []
    ) {
        $this->success = $success;
        $this->html = $html;
        $this->message = $message;
        $this->errorCode = $errorCode;
        $this->data = $data;
    }
    
    /**
     * 创建成功结果
     */
    public static function success(string $html, array $data = []): self
    {
        return new self(true, $html, '', '', $data);
    }
    
    /**
     * 创建失败结果
     */
    public static function fail(string $message, string $errorCode = 'RENDER_FAILED', array $data = []): self
    {
        return new self(false, '', $message, $errorCode, $data);
    }
    
    /**
     * 是否成功
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }
    
    /**
     * 获取渲染的 HTML
     */
    public function getHtml(): string
    {
        return $this->html;
    }
    
    /**
     * 获取错误消息
     */
    public function getMessage(): string
    {
        return $this->message;
    }
    
    /**
     * 获取错误代码
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
    
    /**
     * 获取附加数据
     */
    public function getData(): array
    {
        return $this->data;
    }
    
    /**
     * 转换为数组
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'html' => $this->html,
            'message' => $this->message,
            'error_code' => $this->errorCode,
            'data' => $this->data,
        ];
    }
}
