<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\SlotRendererService;
use Weline\Theme\Service\ThemeCacheGenerator;

/**
 * 布局插槽渲染器 Observer
 * 
 * 在控制器模板渲染完成后，处理插槽替换：
 * 1. 检测 HTML 中的 data-wslot / widget-slot-area 元素
 * 2. 从数据库获取该页面的部件布局配置
 * 3. 渲染部件并填充到对应插槽
 * 4. 返回最终的 HTML
 * 
 * 监听事件：Weline_Framework_Controller::fetch_file_after
 * 
 * 状态判断逻辑：
 * 1. 后台可视化编辑器 iframe（editor_mode=1）：默认加载 draft，可通过 status 参数切换
 * 2. 前台预览（preview_mode=1）：加载 draft 数据预览
 * 3. 前台正常访问：加载 published 数据
 * 
 * URL 参数：
 * - editor_mode=1：标识后台编辑器 iframe
 * - preview_mode=1：标识前台草稿预览
 * - status=draft/published：明确指定要加载的版本（优先级最高）
 */
class LayoutSlotRenderer implements ObserverInterface
{
    private SlotRendererService $slotRenderer;
    private WelineTheme $welineTheme;
    private Request $request;
    private ThemeCacheGenerator $cacheGenerator;
    private Url $url;
    private bool $isEnabled = true;

    public function __construct(
        SlotRendererService $slotRenderer,
        WelineTheme $welineTheme,
        Request $request,
        ThemeCacheGenerator $cacheGenerator,
        Url $url
    ) {
        $this->slotRenderer = $slotRenderer;
        $this->welineTheme = $welineTheme;
        $this->request = $request;
        $this->cacheGenerator = $cacheGenerator;
        $this->url = $url;
    }

    public function execute(Event &$event): void
    {
        if (!$this->isEnabled) {
            return;
        }

        // 获取事件数据（fetch_file_after 事件使用 content 和 fileName）
        $html = (string)$event->getData('content');
        $template = (string)$event->getData('fileName');
        
        // 如果 HTML 为空，直接返回
        if (empty($html)) {
            return;
        }
        
        // 判断区域（从模板路径或其他上下文判断）
        $area = $this->detectArea($template);

        // 只处理前端区域
        if ($area !== 'frontend') {
            return;
        }

        // 检查是否包含插槽标记（支持新旧两种方式）
        // 注意：不再强制检查 isLayoutTemplate，因为 fetch_file_after 事件
        // 获取的是完整渲染后的 HTML，包含所有子模板（如 partials）的内容
        if (strpos($html, 'data-wslot') === false && strpos($html, 'widget-slot-area') === false) {
            return;
        }

        // 确定主题 ID
        // 1. 优先使用 URL 参数中的 theme_id（编辑器/预览模式使用）
        // 2. 如果没有 URL 参数，使用当前激活的主题
        $themeId = 0;
        $urlThemeId = $this->request->getParam('theme_id');
        if ($urlThemeId) {
            $themeId = (int)$urlThemeId;
        } else {
            // 获取当前激活的主题
            $activeTheme = $this->welineTheme->getActiveTheme();
            if ($activeTheme) {
                $themeId = (int)$activeTheme->getId();
            }
        }
        
        // 如果没有主题 ID，无法处理插槽
        if (!$themeId) {
            return;
        }

        // 确定页面类型
        $pageType = $this->detectPageType($template);

        // 检测要加载的状态版本
        $status = $this->detectStatus();
        
        // 判断是否为编辑/预览模式（用于显示警告等）
        $isEditorOrPreview = $this->isEditorOrPreviewMode();

        // 生产环境检查：如果有缓存且不是预览模式，可以跳过实时渲染
        // 注意：这里我们仍然执行实时渲染，因为缓存模板应该在更高层级处理
        // 如果需要跳过，取消下面的注释
        // if (!$isPreviewMode && !DEV && $this->cacheGenerator->isCacheValid($themeId)) {
        //     return;
        // }

        // 处理插槽替换
        $processedHtml = $this->slotRenderer->processSlots($html, $themeId, $pageType, $status);
        
        // 检查是否有孤儿部件（找不到对应插槽的部件）
        // 这些部件的配置数据不会被删除，只是无法在当前布局中显示
        if ($this->slotRenderer->hasOrphanWidgets()) {
            $orphans = $this->slotRenderer->getOrphanWidgets();
            
            // 在编辑器或预览模式下，将警告信息添加到 HTML 中显示给编辑者
            if ($isEditorOrPreview) {
                $processedHtml = $this->injectOrphanWarnings($processedHtml, $orphans);
            }
            
            // 记录警告日志（可选）
            // 开发模式下可以输出到控制台
            if (defined('DEV') && DEV) {
                foreach ($orphans as $orphan) {
                    error_log('[Widget Orphan] ' . ($orphan['message'] ?? 'Unknown orphan widget'));
                }
            }
        }

        // 更新事件数据（fetch_file_after 事件使用 content）
        $event->setData('content', $processedHtml);
    }
    
    /**
     * 在预览模式下注入孤儿部件警告
     * 
     * 在页面底部添加一个警告面板，提示编辑者有些部件无法在当前布局中显示
     */
    private function injectOrphanWarnings(string $html, array $orphans): string
    {
        if (empty($orphans)) {
            return $html;
        }
        
        $warningItems = [];
        $orphanSlotIds = []; // 收集所有孤儿部件的 slot_id
        foreach ($orphans as $orphan) {
            $widgetName = htmlspecialchars((string)($orphan['widget_name'] ?? '未知部件'));
            $slotId = htmlspecialchars((string)($orphan['slot_id'] ?? '未知插槽'));
            $warningItems[] = "<li><strong>{$widgetName}</strong> - 找不到插槽 <code>{$slotId}</code></li>";
            if (!empty($orphan['slot_id'])) {
                $orphanSlotIds[] = $orphan['slot_id'];
            }
        }
        
        // 去重并编码为 JSON
        $orphanSlotIdsJson = htmlspecialchars(json_encode(array_values(array_unique($orphanSlotIds))));
        
        // 生成正确的后台URL（遵循 weline-routing 技能规范）
        $removeOrphanWidgetsUrl = htmlspecialchars($this->url->getBackendUrl('theme/backend/theme-editor/remove-orphan-widgets'));
        
        $warningHtml = <<<HTML
<div id="orphan-widgets-warning" style="
    position: fixed;
    bottom: 20px;
    right: 20px;
    max-width: 400px;
    background: #fff3cd;
    border: 1px solid #ffc107;
    border-radius: 8px;
    padding: 15px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    z-index: 10000;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    font-size: 14px;
">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
        <strong style="color: #856404;">⚠️ 部件警告</strong>
        <button onclick="this.parentElement.parentElement.remove()" style="
            background: none;
            border: none;
            font-size: 18px;
            cursor: pointer;
            color: #856404;
        ">&times;</button>
    </div>
    <p style="margin: 0 0 10px 0; color: #856404;">以下部件无法在当前布局中生效（配置已保留）：</p>
    <ul style="margin: 0; padding-left: 20px; color: #856404;">
HTML;
        $warningHtml .= implode("\n", $warningItems);
        $warningHtml .= <<<HTML
    </ul>
    <p style="margin: 10px 0 5px 0; font-size: 12px; color: #856404;">
        提示：这些部件可能需要重新配置到新的插槽位置。
    </p>
    <div id="orphan-actions" style="display: flex; gap: 8px; margin-top: 10px;">
        <button id="btnConfirmDelete" data-orphan-slots='{$orphanSlotIdsJson}' style="
            flex: 1;
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 4px;
            padding: 6px 12px;
            cursor: pointer;
            font-size: 13px;
        " onmouseover="this.style.background='#c82333'" onmouseout="this.style.background='#dc3545'">
            删除这些部件
        </button>
        <button onclick="document.getElementById('orphan-widgets-warning').remove()" style="
            flex: 1;
            background: #6c757d;
            color: white;
            border: none;
            border-radius: 4px;
            padding: 6px 12px;
            cursor: pointer;
            font-size: 13px;
        " onmouseover="this.style.background='#5a6268'" onmouseout="this.style.background='#6c757d'">
            稍后处理
        </button>
    </div>
    <div id="confirm-message" style="display: none; margin-top: 10px; padding: 10px; background: #f8d7da; border-radius: 4px; color: #721c24; font-size: 13px;">
        <strong>⚠️ 确认删除？</strong>
        <p style="margin: 5px 0;">此操作将永久删除这些无效部件配置，不可恢复。</p>
        <div style="display: flex; gap: 8px; margin-top: 8px;">
            <button id="btnConfirmYes" style="
                flex: 1;
                background: #dc3545;
                color: white;
                border: none;
                border-radius: 4px;
                padding: 6px 12px;
                cursor: pointer;
                font-size: 12px;
            " onmouseover="this.style.background='#c82333'" onmouseout="this.style.background='#dc3545'">
                确认删除
            </button>
            <button id="btnConfirmNo" style="
                flex: 1;
                background: #6c757d;
                color: white;
                border: none;
                border-radius: 4px;
                padding: 6px 12px;
                cursor: pointer;
                font-size: 12px;
            " onmouseover="this.style.background='#5a6268'" onmouseout="this.style.background='#6c757d'">
                取消
            </button>
        </div>
    </div>
    <div id="delete-status" style="display: none; margin-top: 10px; padding: 10px; border-radius: 4px; font-size: 13px;"></div>
</div>
<script>
(function() {
    const btnConfirmDelete = document.getElementById('btnConfirmDelete');
    const confirmMessage = document.getElementById('confirm-message');
    const orphanActions = document.getElementById('orphan-actions');
    const btnConfirmYes = document.getElementById('btnConfirmYes');
    const btnConfirmNo = document.getElementById('btnConfirmNo');
    const deleteStatus = document.getElementById('delete-status');
    
    // 点击删除按钮 - 显示确认消息
    if (btnConfirmDelete) {
        btnConfirmDelete.addEventListener('click', function() {
            orphanActions.style.display = 'none';
            confirmMessage.style.display = 'block';
        });
    }
    
    // 取消删除
    if (btnConfirmNo) {
        btnConfirmNo.addEventListener('click', function() {
            confirmMessage.style.display = 'none';
            orphanActions.style.display = 'flex';
        });
    }
    
    // 确认删除
    if (btnConfirmYes) {
        btnConfirmYes.addEventListener('click', function() {
            const btn = document.querySelector('[data-orphan-slots]');
            if (!btn) return;
            
            const orphanSlots = JSON.parse(btn.getAttribute('data-orphan-slots') || '[]');
            const urlParams = new URLSearchParams(window.location.search);
            const themeId = urlParams.get('theme_id') || '';
            
            // 显示处理中
            confirmMessage.style.display = 'none';
            deleteStatus.style.display = 'block';
            deleteStatus.style.background = '#d1ecf1';
            deleteStatus.style.color = '#0c5460';
            deleteStatus.textContent = '正在删除...';
            
            // 发起删除请求（使用正确的后台URL）
            fetch('{$removeOrphanWidgetsUrl}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    theme_id: themeId,
                    slot_ids: orphanSlots
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    deleteStatus.style.background = '#d4edda';
                    deleteStatus.style.color = '#155724';
                    deleteStatus.textContent = '✓ 删除成功，页面即将刷新...';
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    deleteStatus.style.background = '#f8d7da';
                    deleteStatus.style.color = '#721c24';
                    deleteStatus.textContent = '✗ 删除失败: ' + (data.message || '未知错误');
                }
            })
            .catch(error => {
                console.error('删除失败:', error);
                deleteStatus.style.background = '#f8d7da';
                deleteStatus.style.color = '#721c24';
                deleteStatus.textContent = '✗ 删除失败，请查看控制台';
            });
        });
    }
})();
</script>
HTML;
        
        // 在 </body> 前插入警告
        if (strpos($html, '</body>') !== false) {
            $html = str_replace('</body>', $warningHtml . '</body>', $html);
        } else {
            // 如果没有 </body> 标签，直接追加到末尾
            $html .= $warningHtml;
        }
        
        return $html;
    }

    /**
     * 检测要加载的布局状态版本
     * 
     * 优先级：
     * 1. URL 参数 status=draft/published（最高优先级，用于版本切换）
     * 2. editor_mode=1（后台编辑器 iframe，默认加载 draft）
     * 3. preview_mode=1（前台草稿预览，加载 draft）
     * 4. 默认（前台正常访问，加载 published）
     * 
     * @return string ThemeLayout::STATUS_DRAFT 或 ThemeLayout::STATUS_PUBLISHED
     */
    private function detectStatus(): string
    {
        // 1. 最高优先级：URL 参数明确指定状态（用于版本切换）
        $statusParam = $this->request->getParam('status');
        if ($statusParam === ThemeLayout::STATUS_DRAFT || $statusParam === 'draft') {
            return ThemeLayout::STATUS_DRAFT;
        }
        if ($statusParam === ThemeLayout::STATUS_PUBLISHED || $statusParam === 'published') {
            return ThemeLayout::STATUS_PUBLISHED;
        }
        
        // 2. 后台编辑器 iframe 模式：默认加载 draft
        $editorMode = $this->request->getParam('editor_mode');
        if ($editorMode === '1' || $editorMode === 'true') {
            return ThemeLayout::STATUS_DRAFT;
        }
        
        // 3. 前台草稿预览模式：加载 draft
        $previewMode = $this->request->getParam('preview_mode');
        if ($previewMode === '1' || $previewMode === 'true') {
            return ThemeLayout::STATUS_DRAFT;
        }
        
        // 4. 检查 referer 是否来自编辑器（备用方案）
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        if (strpos($referer, 'theme-editor') !== false) {
            return ThemeLayout::STATUS_DRAFT;
        }
        
        // 默认：前台正常访问，加载已发布版本
        return ThemeLayout::STATUS_PUBLISHED;
    }
    
    /**
     * 检测是否为编辑器或预览模式
     * 
     * 用于判断是否需要显示调试信息（如孤儿部件警告）
     * 
     * @return bool
     */
    private function isEditorOrPreviewMode(): bool
    {
        // 后台编辑器模式
        $editorMode = $this->request->getParam('editor_mode');
        if ($editorMode === '1' || $editorMode === 'true') {
            return true;
        }
        
        // 前台预览模式
        $previewMode = $this->request->getParam('preview_mode');
        if ($previewMode === '1' || $previewMode === 'true') {
            return true;
        }
        
        // 检查 referer 是否来自编辑器
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        if (strpos($referer, 'theme-editor') !== false) {
            return true;
        }
        
        return false;
    }

    /**
     * 检测区域（前端/后端）
     */
    private function detectArea(string $template): string
    {
        // 后端模板特征
        $backendPatterns = [
            '/backend/',
            '/Backend/',
            'Backend::',
        ];

        foreach ($backendPatterns as $pattern) {
            if (strpos($template, $pattern) !== false) {
                return 'backend';
            }
        }

        return 'frontend';
    }

    /**
     * 判断是否为布局模板
     */
    private function isLayoutTemplate(string $template): bool
    {
        // 布局模板路径特征
        $layoutPatterns = [
            '/layouts/',
            'layouts::',
            '/layout/',
        ];

        foreach ($layoutPatterns as $pattern) {
            if (strpos($template, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 从模板路径检测页面类型
     * 
     * 将模板目录名映射到数据库中的页面类型
     */
    private function detectPageType(string $template): string
    {
        // 从模板路径提取页面类型
        if (preg_match('/layouts\/(\w+)\//', $template, $matches)) {
            $layoutDir = $matches[1];
            
            // 映射目录名到页面类型常量
            $mapping = [
                'homepage' => ThemeLayout::PAGE_TYPE_HOME,  // homepage 目录 -> home 类型
                'home' => ThemeLayout::PAGE_TYPE_HOME,
                'category' => ThemeLayout::PAGE_TYPE_CATEGORY,
                'product' => ThemeLayout::PAGE_TYPE_PRODUCT,
                'product_list' => ThemeLayout::PAGE_TYPE_PRODUCT_LIST,  // 产品列表页
                'cms' => ThemeLayout::PAGE_TYPE_CMS,
                'cart' => ThemeLayout::PAGE_TYPE_CART,
                'checkout' => ThemeLayout::PAGE_TYPE_CHECKOUT,
                'account' => ThemeLayout::PAGE_TYPE_ACCOUNT,
                'search' => ThemeLayout::PAGE_TYPE_SEARCH,
                'default' => ThemeLayout::PAGE_TYPE_DEFAULT,
            ];
            
            return $mapping[$layoutDir] ?? $layoutDir;
        }

        // 默认页面类型
        return ThemeLayout::PAGE_TYPE_DEFAULT;
    }

    /**
     * 启用/禁用处理器
     */
    public function setEnabled(bool $enabled): void
    {
        $this->isEnabled = $enabled;
    }
}
