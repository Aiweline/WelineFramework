<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\PreviewTokenService;
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
    private PreviewTokenService $previewTokenService;
    private bool $isEnabled = true;

    public function __construct(
        SlotRendererService $slotRenderer,
        WelineTheme $welineTheme,
        Request $request,
        ThemeCacheGenerator $cacheGenerator,
        Url $url,
        PreviewTokenService $previewTokenService
    ) {
        $this->slotRenderer = $slotRenderer;
        $this->welineTheme = $welineTheme;
        $this->request = $request;
        $this->cacheGenerator = $cacheGenerator;
        $this->url = $url;
        $this->previewTokenService = $previewTokenService;
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
        
        // === 第一步：处理预览模式（独立于插槽处理）===
        // 检测 URL 参数中的预览 token，如果有效则设置 Cookie（实现预览状态持久化）
        $urlToken = $this->request->getParam(PreviewTokenService::TOKEN_KEY);
        if ($urlToken && $this->previewTokenService->validateToken($urlToken)) {
            // 自动设置 Cookie，这样后续页面跳转不需要每次都带 token 参数
            $this->previewTokenService->setPreviewCookie($urlToken);
        }
        
        // 预览模式下注入退出预览浮窗和 AJAX 拦截器（非编辑器 iframe 模式）
        // 这个逻辑必须在插槽检查之前执行，因为即使页面没有插槽，也需要显示退出按钮
        if ($this->previewTokenService->isPreviewMode()) {
            $editorMode = $this->request->getParam('editor_mode');
            // 只在真实前端预览时注入，不在编辑器 iframe 中注入
            if ($editorMode !== '1' && $editorMode !== 'true') {
                $html = $this->injectPreviewExitButton($html);
                $html = $this->injectPreviewInterceptor($html);
            }
        }

        // === 第二步：处理插槽替换 ===
        // 检查是否包含插槽标记（支持新旧两种方式）
        // 注意：不再强制检查 isLayoutTemplate，因为 fetch_file_after 事件
        // 获取的是完整渲染后的 HTML，包含所有子模板（如 partials）的内容
        if (strpos($html, 'data-wslot') === false && strpos($html, 'widget-slot-area') === false) {
            // 没有插槽标记，但可能已经注入了预览退出按钮，所以更新事件数据
            $event->setData('content', $html);
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
            // 更新事件数据（可能已注入预览退出按钮）
            $event->setData('content', $html);
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
            
            // 防止重复点击
            btnConfirmYes.disabled = true;
            
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
                    deleteStatus.textContent = '✓ ' + (data.message || '删除成功') + '，即将刷新...';
                    // 立即隐藏整个警告面板
                    const panel = document.getElementById('orphan-widgets-warning');
                    if (panel) {
                        setTimeout(() => panel.remove(), 800);
                    }
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    deleteStatus.style.background = '#f8d7da';
                    deleteStatus.style.color = '#721c24';
                    deleteStatus.textContent = '✗ ' + (data.message || '删除失败');
                    btnConfirmYes.disabled = false;
                }
            })
            .catch(error => {
                console.error('删除失败:', error);
                deleteStatus.style.background = '#f8d7da';
                deleteStatus.style.color = '#721c24';
                deleteStatus.textContent = '✗ 删除失败，请查看控制台';
                btnConfirmYes.disabled = false;
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
     * 2. 预览 Token（URL参数/Cookie/Header）- 新增
     * 3. editor_mode=1（后台编辑器 iframe，默认加载 draft）
     * 4. preview_mode=1（前台草稿预览，加载 draft）
     * 5. 默认（前台正常访问，加载 published）
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
        
        // 2. 预览 Token 检测（支持 URL参数/Cookie/Header）
        if ($this->previewTokenService->isPreviewMode()) {
            return ThemeLayout::STATUS_DRAFT;
        }
        
        // 3. 后台编辑器 iframe 模式：默认加载 draft
        $editorMode = $this->request->getParam('editor_mode');
        if ($editorMode === '1' || $editorMode === 'true') {
            return ThemeLayout::STATUS_DRAFT;
        }
        
        // 4. 前台草稿预览模式：加载 draft（向后兼容）
        $previewMode = $this->request->getParam('preview_mode');
        if ($previewMode === '1' || $previewMode === 'true') {
            return ThemeLayout::STATUS_DRAFT;
        }
        
        // 5. 检查 referer 是否来自编辑器（备用方案）
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
     * 用于判断是否需要显示调试信息（如孤儿部件警告）和注入预览浮窗
     * 
     * @return bool
     */
    private function isEditorOrPreviewMode(): bool
    {
        // 预览 Token 模式
        if ($this->previewTokenService->isPreviewMode()) {
            return true;
        }
        
        // 后台编辑器模式
        $editorMode = $this->request->getParam('editor_mode');
        if ($editorMode === '1' || $editorMode === 'true') {
            return true;
        }
        
        // 前台预览模式（向后兼容）
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
            
            // 目录名直接作为页面类型（与 ThemeLayout 常量一致）
            // layouts 目录名即为 page_type 值
            $mapping = [
                'homepage' => ThemeLayout::PAGE_TYPE_HOME,      // homepage
                'category' => ThemeLayout::PAGE_TYPE_CATEGORY,  // category
                'product' => ThemeLayout::PAGE_TYPE_PRODUCT,    // product
                'product_list' => ThemeLayout::PAGE_TYPE_PRODUCT_LIST,  // product_list
                'cms_page' => ThemeLayout::PAGE_TYPE_CMS,       // cms_page
                'cart' => ThemeLayout::PAGE_TYPE_CART,          // cart
                'checkout' => ThemeLayout::PAGE_TYPE_CHECKOUT,  // checkout
                'account' => ThemeLayout::PAGE_TYPE_ACCOUNT,    // account
                'search' => ThemeLayout::PAGE_TYPE_SEARCH,      // search
                'default' => ThemeLayout::PAGE_TYPE_DEFAULT,    // default
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
    
    /**
     * 注入预览退出浮窗
     * 
     * 在预览模式下，在页面底部右侧注入一个可拖动的浮窗，
     * 提供"退出预览"和"发布并退出"两个操作
     * 
     * @param string $html 原始 HTML
     * @return string 注入浮窗后的 HTML
     */
    private function injectPreviewExitButton(string $html): string
    {
        // 获取预览 Token 数据
        $tokenData = $this->previewTokenService->getCurrentPreviewData();
        $token = $this->previewTokenService->getTokenFromRequest() ?? '';
        
        // 构建编辑器返回 URL（与后台菜单路由一致：theme/backend/theme-editor）
        $editorUrl = $this->url->getBackendUrl('theme/backend/theme-editor/index');
        if ($tokenData && isset($tokenData['theme_id'])) {
            $editorUrl = $this->url->getBackendUrl('theme/backend/theme-editor/index', [
                'theme_id' => $tokenData['theme_id'],
                'page_type' => $tokenData['page_type'] ?? 'homepage'
            ]);
        }
        
        // API URL（与 index.phtml 中 data-api-* 一致）
        $exitPreviewUrl = $this->url->getBackendUrl('theme/backend/theme-editor/exit-preview');
        $publishAndExitUrl = $this->url->getBackendUrl('theme/backend/theme-editor/publish-and-exit');
        
        // 浮窗 HTML 和内联样式/脚本
        $floatHtml = <<<HTML
<!-- Weline Theme Preview Exit Button -->
<div id="weline-preview-exit-float" style="
    position: fixed !important;
    bottom: 20px !important;
    right: 20px !important;
    left: auto !important;
    top: auto !important;
    z-index: 2147483647 !important;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
    border-radius: 12px !important;
    box-shadow: 0 8px 32px rgba(102, 126, 234, 0.4) !important;
    padding: 12px 16px !important;
    cursor: move !important;
    user-select: none !important;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
    min-width: 140px !important;
    transition: transform 0.2s, box-shadow 0.2s !important;
    margin: 0 !important;
    float: none !important;
    display: block !important;
    width: auto !important;
    height: auto !important;
">
    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px; color: white;">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"/>
            <path d="M12 16v-4M12 8h.01"/>
        </svg>
        <span style="font-weight: 600; font-size: 14px;">预览模式</span>
    </div>
    <div style="display: flex; flex-direction: column; gap: 8px;">
        <button id="weline-preview-exit-btn" style="
            padding: 8px 16px;
            background: rgba(255,255,255,0.95);
            color: #667eea;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s;
            width: 100%;
        " onmouseover="this.style.background='#fff';this.style.transform='translateY(-1px)'" 
           onmouseout="this.style.background='rgba(255,255,255,0.95)';this.style.transform='translateY(0)'">
            退出预览
        </button>
        <button id="weline-preview-publish-btn" style="
            padding: 8px 16px;
            background: rgba(255,255,255,0.2);
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s;
            width: 100%;
        " onmouseover="this.style.background='rgba(255,255,255,0.3)'" 
           onmouseout="this.style.background='rgba(255,255,255,0.2)'">
            发布并退出
        </button>
    </div>
    <div style="
        position: absolute;
        top: -8px;
        left: 50%;
        transform: translateX(-50%);
        width: 40px;
        height: 4px;
        background: rgba(255,255,255,0.5);
        border-radius: 2px;
    "></div>
</div>
<script>
(function() {
    var floatEl = document.getElementById('weline-preview-exit-float');
    var exitBtn = document.getElementById('weline-preview-exit-btn');
    var publishBtn = document.getElementById('weline-preview-publish-btn');
    var token = '{$token}';
    var editorUrl = '{$editorUrl}';
    var exitUrl = '{$exitPreviewUrl}';
    var publishUrl = '{$publishAndExitUrl}';
    
    // 拖动功能
    var isDragging = false;
    var startX, startY, startLeft, startBottom;
    
    // 从 localStorage 恢复位置
    var savedPos = localStorage.getItem('weline_preview_float_pos');
    if (savedPos) {
        try {
            var pos = JSON.parse(savedPos);
            floatEl.style.right = pos.right + 'px';
            floatEl.style.bottom = pos.bottom + 'px';
        } catch(e) {}
    }
    
    floatEl.addEventListener('mousedown', function(e) {
        if (e.target.tagName === 'BUTTON') return;
        isDragging = true;
        startX = e.clientX;
        startY = e.clientY;
        startLeft = floatEl.offsetLeft;
        startBottom = window.innerHeight - floatEl.offsetTop - floatEl.offsetHeight;
        floatEl.style.transition = 'none';
    });
    
    document.addEventListener('mousemove', function(e) {
        if (!isDragging) return;
        var dx = e.clientX - startX;
        var dy = e.clientY - startY;
        var newRight = window.innerWidth - startLeft - floatEl.offsetWidth - dx;
        var newBottom = startBottom - dy;
        
        // 边界限制
        newRight = Math.max(10, Math.min(newRight, window.innerWidth - floatEl.offsetWidth - 10));
        newBottom = Math.max(10, Math.min(newBottom, window.innerHeight - floatEl.offsetHeight - 10));
        
        floatEl.style.right = newRight + 'px';
        floatEl.style.bottom = newBottom + 'px';
        floatEl.style.left = 'auto';
        floatEl.style.top = 'auto';
    });
    
    document.addEventListener('mouseup', function() {
        if (isDragging) {
            isDragging = false;
            floatEl.style.transition = 'transform 0.2s, box-shadow 0.2s';
            // 保存位置到 localStorage
            localStorage.setItem('weline_preview_float_pos', JSON.stringify({
                right: parseInt(floatEl.style.right),
                bottom: parseInt(floatEl.style.bottom)
            }));
        }
    });
    
    // 退出预览按钮
    exitBtn.addEventListener('click', function() {
        exitBtn.disabled = true;
        exitBtn.textContent = '处理中...';
        
        fetch(exitUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'include',
            body: JSON.stringify({ token: token })
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                // 清除预览 Cookie
                document.cookie = 'weline_preview_token=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT';
                localStorage.removeItem('weline_preview_float_pos');
                window.location.href = editorUrl;
            } else {
                alert(data.message || '退出预览失败');
                exitBtn.disabled = false;
                exitBtn.textContent = '退出预览';
            }
        })
        .catch(function(err) {
            alert('网络错误，请重试');
            exitBtn.disabled = false;
            exitBtn.textContent = '退出预览';
        });
    });
    
    // 发布并退出按钮
    publishBtn.addEventListener('click', function() {
        if (!confirm('确认发布当前预览内容并退出？\\n\\n发布后，所有访客将看到最新的更改。')) {
            return;
        }
        
        publishBtn.disabled = true;
        publishBtn.textContent = '发布中...';
        
        fetch(publishUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'include',
            body: JSON.stringify({ token: token })
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                document.cookie = 'weline_preview_token=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT';
                localStorage.removeItem('weline_preview_float_pos');
                // 跳转到前台首页（非预览模式）
                window.location.href = data.redirect_url || '/';
            } else {
                alert(data.message || '发布失败');
                publishBtn.disabled = false;
                publishBtn.textContent = '发布并退出';
            }
        })
        .catch(function(err) {
            alert('网络错误，请重试');
            publishBtn.disabled = false;
            publishBtn.textContent = '发布并退出';
        });
    });
})();
</script>
<!-- /Weline Theme Preview Exit Button -->
HTML;
        
        // 在 </body> 前插入浮窗 HTML
        if (stripos($html, '</body>') !== false) {
            $html = str_ireplace('</body>', $floatHtml . '</body>', $html);
        } else {
            // 如果没有 </body> 标签，追加到末尾
            $html .= $floatHtml;
        }
        
        return $html;
    }
    
    /**
     * 注入预览请求拦截器
     * 
     * 拦截所有 fetch 和 XMLHttpRequest 请求，自动添加预览 token header，
     * 确保整个预览会话中所有 AJAX 请求都携带预览标识
     * 
     * @param string $html 原始 HTML
     * @return string 注入拦截器后的 HTML
     */
    private function injectPreviewInterceptor(string $html): string
    {
        $token = $this->previewTokenService->getTokenFromRequest() ?? '';
        $tokenHeader = PreviewTokenService::TOKEN_HEADER;
        $tokenKey = PreviewTokenService::TOKEN_KEY;
        
        if (empty($token)) {
            return $html;
        }
        
        $interceptorScript = <<<HTML
<!-- Weline Theme Preview Request Interceptor -->
<script>
(function() {
    var previewToken = '{$token}';
    var tokenHeader = '{$tokenHeader}';
    var tokenKey = '{$tokenKey}';
    
    // 拦截 fetch 请求
    var originalFetch = window.fetch;
    window.fetch = function(input, init) {
        init = init || {};
        init.headers = init.headers || {};
        
        // 添加预览 token header
        if (init.headers instanceof Headers) {
            init.headers.set(tokenHeader, previewToken);
        } else if (Array.isArray(init.headers)) {
            init.headers.push([tokenHeader, previewToken]);
        } else {
            init.headers[tokenHeader] = previewToken;
        }
        
        // 确保携带 credentials（以发送 Cookie）
        if (!init.credentials) {
            init.credentials = 'same-origin';
        }
        
        return originalFetch.call(this, input, init);
    };
    
    // 拦截 XMLHttpRequest
    var originalXHROpen = XMLHttpRequest.prototype.open;
    var originalXHRSend = XMLHttpRequest.prototype.send;
    
    XMLHttpRequest.prototype.open = function(method, url, async, user, password) {
        this._previewIntercepted = true;
        return originalXHROpen.apply(this, arguments);
    };
    
    XMLHttpRequest.prototype.send = function(body) {
        if (this._previewIntercepted) {
            this.setRequestHeader(tokenHeader, previewToken);
        }
        return originalXHRSend.apply(this, arguments);
    };
    
    // 为动态创建的链接添加预览参数
    document.addEventListener('click', function(e) {
        var link = e.target.closest('a');
        if (link && link.href && link.href.indexOf(window.location.origin) === 0) {
            // 如果链接没有预览 token，添加它
            if (link.href.indexOf(tokenKey + '=') === -1) {
                var separator = link.href.indexOf('?') !== -1 ? '&' : '?';
                // 不修改 href，而是在导航时添加（避免影响显示）
            }
        }
    }, true);
    
    console.log('[Weline Preview] 请求拦截器已启用，Token:', previewToken.substring(0, 20) + '...');
})();
</script>
<!-- /Weline Theme Preview Request Interceptor -->
HTML;
        
        // 在 <head> 结束前或 <body> 开始后尽早注入
        if (stripos($html, '</head>') !== false) {
            $html = str_ireplace('</head>', $interceptorScript . '</head>', $html);
        } elseif (stripos($html, '<body') !== false) {
            // 在 <body> 标签后注入
            $html = preg_replace('/(<body[^>]*>)/i', '$1' . $interceptorScript, $html, 1);
        } else {
            // 在开头注入
            $html = $interceptorScript . $html;
        }
        
        return $html;
    }
}
