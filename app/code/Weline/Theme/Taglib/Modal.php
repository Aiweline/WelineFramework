<?php
declare(strict_types=1);

namespace Weline\Theme\Taglib;

use Weline\Taglib\TaglibInterface;

/**
 * 通用模态框组件
 * 
 * 提供主题变量兼容的模态框，支持暗色/亮色模式
 * 可用于进度提示、确认框、信息展示等场景
 */
class Modal implements TaglibInterface
{
    public static function name(): string
    {
        return 'theme:modal';
    }

    public static function tag(): bool
    {
        return false;
    }

    public static function tag_start(): bool
    {
        return false;
    }

    public static function tag_end(): bool
    {
        return false;
    }

    public static function attr(): array
    {
        return [
            'id' => true,            // 组件唯一ID（必填）
            'title' => false,        // 标题
            'size' => false,         // 尺寸: sm, md, lg, xl, fullscreen
            'closable' => false,     // 是否可关闭，默认 true
            'backdrop' => false,     // 点击背景是否关闭，默认 true
            'centered' => false,     // 是否垂直居中，默认 true
            'class' => false,        // 额外CSS类
            'style' => false,        // 内联样式
        ];
    }

    public static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            $id = $attributes['id'] ?? 'weline-modal-' . uniqid();
            $title = $attributes['title'] ?? '';
            $size = $attributes['size'] ?? 'md';
            $closable = ($attributes['closable'] ?? 'true') !== 'false';
            $backdrop = ($attributes['backdrop'] ?? 'true') !== 'false';
            $centered = ($attributes['centered'] ?? 'true') !== 'false';
            $class = $attributes['class'] ?? '';
            $style = $attributes['style'] ?? '';

            $t_copy = addslashes((string)__('复制'));
            $t_copied = addslashes((string)__('已复制'));
            $t_copy_failed = addslashes((string)__('复制失败'));
            $t_close = addslashes((string)__('关闭'));

            $code = \Weline\Taglib\Taglib::attributes($attributes);

            $sizeClass = match ($size) {
                'sm' => 'weline-modal--sm',
                'lg' => 'weline-modal--lg',
                'xl' => 'weline-modal--xl',
                'fullscreen' => 'weline-modal--fullscreen',
                default => '',
            };

            $centeredClass = $centered ? 'weline-modal--centered' : '';
            $closableAttr = $closable ? 'data-closable="true"' : 'data-closable="false"';
            $backdropAttr = $backdrop ? 'data-backdrop="true"' : 'data-backdrop="false"';

            $html = [];
            $html[] = '<?php ' . $code . ' ?>';

            // 主容器（默认隐藏）
            $html[] = '<div class="weline-modal ' . htmlspecialchars($sizeClass) . ' ' . htmlspecialchars($centeredClass) . ' ' . htmlspecialchars($class) . '" id="<?= htmlspecialchars($Taglib__id) ?>" ' . $closableAttr . ' ' . $backdropAttr . ' style="display: none; ' . htmlspecialchars($style) . '">';
            
            // 遮罩层
            $html[] = '  <div class="weline-modal-backdrop"></div>';
            
            // 模态框容器
            $html[] = '  <div class="weline-modal-dialog">';
            $html[] = '    <div class="weline-modal-content">';
            
            // 头部（可选）
            $html[] = '      <div class="weline-modal-header" id="<?= htmlspecialchars($Taglib__id) ?>_header">';
            $html[] = '        <h5 class="weline-modal-title" id="<?= htmlspecialchars($Taglib__id) ?>_title"><?= htmlspecialchars($Taglib__title ?? \'\') ?></h5>';
            $html[] = '        <div class="weline-modal-actions">';
            $html[] = '          <button type="button" class="weline-modal-copy" id="<?= htmlspecialchars($Taglib__id) ?>_copy" title="' . $t_copy . '" aria-label="' . $t_copy . '" data-copy-label="' . $t_copy . '" data-copied-label="' . $t_copied . '" data-copy-failed-label="' . $t_copy_failed . '">';
            $html[] = '            <span class="weline-modal-copy-text">' . $t_copy . '</span>';
            $html[] = '          </button>';
            if ($closable) {
                $html[] = '        <button type="button" class="weline-modal-close" id="<?= htmlspecialchars($Taglib__id) ?>_close" title="' . $t_close . '">';
                $html[] = '          <i class="mdi mdi-close"></i>';
                $html[] = '        </button>';
            }
            $html[] = '        </div>';
            $html[] = '      </div>';
            
            // 主体
            $html[] = '      <div class="weline-modal-body" id="<?= htmlspecialchars($Taglib__id) ?>_body">';
            $html[] = '      </div>';
            
            // 底部（可选）
            $html[] = '      <div class="weline-modal-footer" id="<?= htmlspecialchars($Taglib__id) ?>_footer" style="display: none;">';
            $html[] = '      </div>';
            
            $html[] = '    </div>';
            $html[] = '  </div>';
            $html[] = '</div>';

            // 样式（仅首次渲染）
            $html[] = '<style id="weline-modal-styles">';
            $html[] = self::getStyles();
            $html[] = '</style>';

            // JavaScript
            $html[] = '<script>(function(){';
            $html[] = '"use strict";';
            $html[] = 'var id = <?= json_encode($Taglib__id) ?>;';
            $html[] = self::getJavaScript();
            $html[] = '})();</script>';

            return implode("\n", $html);
        };
    }

    private static function getStyles(): string
    {
        return <<<CSS
/* Weline Modal 基础样式 */
.weline-modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 10000; display: none; }
.weline-modal.show { display: block; animation: welineModalFadeIn 0.2s ease; }
.weline-modal-backdrop { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.6); backdrop-filter: blur(4px); }
.weline-modal-dialog { position: relative; width: 100%; height: 100%; display: flex; align-items: flex-start; justify-content: center; padding: 40px 16px; overflow-y: auto; }
.weline-modal--centered .weline-modal-dialog { align-items: center; padding: 16px; }
.weline-modal-content { position: relative; background: var(--backend-color-card-bg, #fff); border-radius: var(--backend-border-radius-lg, 12px); box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3); max-width: 500px; width: 100%; animation: welineModalSlideIn 0.25s ease; }
.weline-modal--sm .weline-modal-content { max-width: 360px; }
.weline-modal--lg .weline-modal-content { max-width: 700px; }
.weline-modal--xl .weline-modal-content { max-width: 900px; }
.weline-modal--fullscreen .weline-modal-content { max-width: none; width: calc(100% - 32px); height: calc(100% - 32px); border-radius: var(--backend-border-radius-md, 8px); display: flex; flex-direction: column; }
.weline-modal--fullscreen .weline-modal-body { flex: 1; overflow-y: auto; }
.weline-modal-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 16px 20px; border-bottom: 1px solid var(--backend-color-border-light, #e9ecef); }
.weline-modal-title { margin: 0; min-width: 0; flex: 1 1 auto; font-size: 1.1rem; font-weight: 600; color: var(--backend-color-text-primary, #212529); overflow-wrap: anywhere; }
.weline-modal-actions { display: inline-flex; align-items: center; justify-content: flex-end; gap: 8px; flex: 0 0 auto; }
.weline-modal-copy { height: 32px; min-width: 48px; padding: 0 12px; border: 1px solid var(--backend-color-border-light, #e9ecef); border-radius: var(--backend-border-radius, 6px); background: var(--backend-color-bg-secondary, #f8f9fa); color: var(--backend-color-text-secondary, #6c757d); cursor: pointer; display: inline-flex; align-items: center; justify-content: center; font-size: 0.86rem; font-weight: 600; line-height: 1; transition: background-color 0.2s, border-color 0.2s, color 0.2s; white-space: nowrap; }
.weline-modal-copy:hover { background: var(--backend-color-bg-tertiary, #e9ecef); color: var(--backend-color-text-primary, #212529); }
.weline-modal-copy.is-copied { border-color: var(--backend-color-success, #34c38f); color: var(--backend-color-success, #198754); }
.weline-modal-copy.is-error { border-color: var(--backend-color-danger, #f46a6a); color: var(--backend-color-danger, #dc3545); }
.weline-modal-close { width: 32px; height: 32px; border: none; border-radius: var(--backend-border-radius, 6px); background: transparent; color: var(--backend-color-text-secondary, #6c757d); cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s; font-size: 1.2rem; }
.weline-modal-close:hover { background: var(--backend-color-bg-tertiary, #e9ecef); color: var(--backend-color-text-primary, #212529); }
.weline-modal-body { padding: 20px; color: var(--backend-color-text-primary, #212529); }
.weline-modal-footer { display: flex; align-items: center; justify-content: flex-end; gap: 10px; padding: 16px 20px; border-top: 1px solid var(--backend-color-border-light, #e9ecef); }

/* 动画 */
@keyframes welineModalFadeIn { from { opacity: 0; } to { opacity: 1; } }
@keyframes welineModalSlideIn { from { opacity: 0; transform: scale(0.95) translateY(-20px); } to { opacity: 1; transform: scale(1) translateY(0); } }
@keyframes welineModalFadeOut { from { opacity: 1; } to { opacity: 0; } }

/* 进度样式预设 */
.weline-modal-progress { text-align: center; padding: 20px 0; }
.weline-modal-progress .spinner-border { width: 3rem; height: 3rem; color: var(--backend-color-primary, #556ee6); margin-bottom: 16px; }
.weline-modal-progress h5 { color: var(--backend-color-text-primary, #212529); margin-bottom: 12px; font-weight: 600; }
.weline-modal-progress p { color: var(--backend-color-text-secondary, #6c757d); margin-bottom: 8px; }
.weline-modal-progress .progress { height: 20px; background: var(--backend-color-bg-tertiary, #e9ecef); border-radius: var(--backend-border-radius, 6px); overflow: hidden; margin-bottom: 8px; }
.weline-modal-progress .progress-bar { background: linear-gradient(90deg, var(--backend-color-primary, #556ee6), var(--backend-color-success, #34c38f)); transition: width 0.3s ease; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 600; color: var(--backend-color-text-inverse, #fff); }
.weline-modal-progress .progress-bar-striped { background-image: linear-gradient(45deg, rgba(255, 255, 255, 0.15) 25%, transparent 25%, transparent 50%, rgba(255, 255, 255, 0.15) 50%, rgba(255, 255, 255, 0.15) 75%, transparent 75%, transparent); background-size: 1rem 1rem; }
.weline-modal-progress .progress-bar-animated { animation: progress-bar-stripes 1s linear infinite; }
@keyframes progress-bar-stripes { 0% { background-position: 1rem 0; } 100% { background-position: 0 0; } }
.weline-modal-progress small { color: var(--backend-color-text-tertiary, #adb5bd); }
CSS;
    }

    private static function getJavaScript(): string
    {
        return <<<JS

var modal = document.getElementById(id);
var backdrop = modal.querySelector('.weline-modal-backdrop');
var closeBtn = document.getElementById(id + '_close');
var copyBtn = document.getElementById(id + '_copy');
var headerEl = document.getElementById(id + '_header');
var titleEl = document.getElementById(id + '_title');
var bodyEl = document.getElementById(id + '_body');
var footerEl = document.getElementById(id + '_footer');

var isClosable = modal.dataset.closable === 'true';
var isBackdropClose = modal.dataset.backdrop === 'true';

// 关闭按钮
if (closeBtn) {
    closeBtn.addEventListener('click', function() {
        hide();
    });
}

if (copyBtn) {
    copyBtn.addEventListener('click', function() {
        copyBody();
    });
}

// 点击背景关闭
if (backdrop && isBackdropClose) {
    backdrop.addEventListener('click', function() {
        if (isClosable) hide();
    });
}

// ESC 键关闭
function handleKeydown(e) {
    if (e.key === 'Escape' && isClosable) {
        hide();
    }
}

function show() {
    modal.style.display = 'block';
    requestAnimationFrame(function() {
        modal.classList.add('show');
    });
    document.body.style.overflow = 'hidden';
    document.addEventListener('keydown', handleKeydown);
}

function hide() {
    modal.classList.remove('show');
    setTimeout(function() {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }, 200);
    document.removeEventListener('keydown', handleKeydown);
}

function setTitle(text) {
    if (titleEl) titleEl.textContent = text;
}

function setBody(html) {
    if (bodyEl) bodyEl.innerHTML = html;
}

function setFooter(html) {
    if (footerEl) {
        footerEl.innerHTML = html;
        footerEl.style.display = html ? 'flex' : 'none';
    }
}

function showHeader(visible) {
    if (headerEl) headerEl.style.display = visible ? 'flex' : 'none';
}

function showFooter(visible) {
    if (footerEl) footerEl.style.display = visible ? 'flex' : 'none';
}

function setClosable(val) {
    isClosable = val;
    modal.dataset.closable = val ? 'true' : 'false';
    if (closeBtn) closeBtn.style.display = val ? 'flex' : 'none';
}

function setCopyable(val) {
    if (copyBtn) copyBtn.style.display = val ? 'inline-flex' : 'none';
}

function defaultCopyLabel() {
    return copyBtn ? (copyBtn.getAttribute('data-copy-label') || copyBtn.getAttribute('aria-label') || copyBtn.textContent || '') : '';
}

function restoreCopyButton() {
    if (!copyBtn) return;
    copyBtn.textContent = defaultCopyLabel();
    copyBtn.classList.remove('is-copied', 'is-error');
}

function notifyCopy(success) {
    if (!copyBtn) return;
    copyBtn.textContent = copyBtn.getAttribute(success ? 'data-copied-label' : 'data-copy-failed-label') || defaultCopyLabel();
    copyBtn.classList.toggle('is-copied', success);
    copyBtn.classList.toggle('is-error', !success);
    window.setTimeout(restoreCopyButton, 1600);
}

function fallbackCopy(text) {
    var textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', 'readonly');
    textarea.style.position = 'fixed';
    textarea.style.top = '-9999px';
    textarea.style.left = '-9999px';
    document.body.appendChild(textarea);
    textarea.select();
    var copied = false;
    try {
        copied = document.execCommand('copy');
    } catch (error) {
        copied = false;
    }
    document.body.removeChild(textarea);
    return copied ? Promise.resolve() : Promise.reject(new Error('copy failed'));
}

function writeClipboard(text) {
    if (!text) return Promise.reject(new Error('empty'));
    if (navigator.clipboard && window.isSecureContext && typeof navigator.clipboard.writeText === 'function') {
        return navigator.clipboard.writeText(text);
    }
    return fallbackCopy(text);
}

function copyBody() {
    var text = bodyEl ? (bodyEl.innerText || bodyEl.textContent || '').trim() : '';
    return writeClipboard(text).then(function() {
        notifyCopy(true);
        return true;
    }).catch(function() {
        notifyCopy(false);
        return false;
    });
}

// 进度模式快捷方法
function showProgress(options) {
    options = options || {};
    var title = options.title || '';
    var subtitle = options.subtitle || '';
    var progress = options.progress || 0;
    var count = options.count || '';
    
    setClosable(false);
    showHeader(false);
    showFooter(false);
    
    var html = '<div class="weline-modal-progress">';
    html += '  <div class="spinner-border" role="status"></div>';
    if (title) html += '  <h5 id="' + id + '_progress_title">' + escapeHtml(title) + '</h5>';
    if (subtitle) html += '  <p id="' + id + '_progress_subtitle">' + escapeHtml(subtitle) + '</p>';
    html += '  <div class="progress">';
    html += '    <div class="progress-bar progress-bar-striped progress-bar-animated" id="' + id + '_progress_bar" style="width: ' + progress + '%">' + Math.round(progress) + '%</div>';
    html += '  </div>';
    if (count) html += '  <small id="' + id + '_progress_count">' + escapeHtml(count) + '</small>';
    html += '</div>';
    
    setBody(html);
    show();
}

function updateProgress(options) {
    options = options || {};
    
    if (options.title !== undefined) {
        var titleEl = document.getElementById(id + '_progress_title');
        if (titleEl) titleEl.textContent = options.title;
    }
    if (options.subtitle !== undefined) {
        var subtitleEl = document.getElementById(id + '_progress_subtitle');
        if (subtitleEl) subtitleEl.textContent = options.subtitle;
    }
    if (options.progress !== undefined) {
        var barEl = document.getElementById(id + '_progress_bar');
        if (barEl) {
            var pct = Math.round(options.progress);
            barEl.style.width = pct + '%';
            barEl.textContent = pct + '%';
        }
    }
    if (options.count !== undefined) {
        var countEl = document.getElementById(id + '_progress_count');
        if (countEl) countEl.textContent = options.count;
    }
}

function escapeHtml(text) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(text || ''));
    return div.innerHTML;
}

// 公共 API
window.WelineModal = window.WelineModal || {};
window.WelineModal[id] = {
    show: show,
    hide: hide,
    setTitle: setTitle,
    setBody: setBody,
    setFooter: setFooter,
    showHeader: showHeader,
    showFooter: showFooter,
    setClosable: setClosable,
    setCopyable: setCopyable,
    copyBody: copyBody,
    showProgress: showProgress,
    updateProgress: updateProgress,
    isVisible: function() { return modal.classList.contains('show'); },
    getElement: function() { return modal; },
    getBodyElement: function() { return bodyEl; }
};

JS;
    }

    public static function tag_self_close(): bool
    {
        return true;
    }

    public static function tag_self_close_with_attrs(): bool
    {
        return true;
    }

    public static function parent(): ?string
    {
        return null;
    }

    public static function document(): string
    {
        return <<<DOC
<h3><code>&lt;w:theme:modal&gt;</code> 通用模态框组件</h3>

<p><strong>功能</strong>：提供主题变量兼容的模态框，支持暗色/亮色模式自动适配</p>

<h4>属性说明</h4>
<ul>
    <li><code>id</code>：组件唯一ID（必填）</li>
    <li><code>title</code>：标题文本</li>
    <li><code>size</code>：尺寸 - sm/md/lg/xl/fullscreen，默认 md</li>
    <li><code>closable</code>：是否可关闭，默认 true</li>
    <li><code>backdrop</code>：点击背景是否关闭，默认 true</li>
    <li><code>centered</code>：是否垂直居中，默认 true</li>
</ul>

<h4>基础用法</h4>
<pre>
&lt;w:theme:modal id="myModal" title="标题" /&gt;

&lt;script&gt;
var modal = window.WelineModal['myModal'];

// 显示模态框
modal.show();

// 设置内容
modal.setBody('&lt;p&gt;这是模态框内容&lt;/p&gt;');

// 关闭
modal.hide();
&lt;/script&gt;
</pre>

<h4>进度模式用法</h4>
<pre>
&lt;w:theme:modal id="progressModal" /&gt;

&lt;script&gt;
var modal = window.WelineModal['progressModal'];

// 显示进度（自动隐藏标题栏和关闭按钮）
modal.showProgress({
    title: '正在处理...',
    subtitle: '当前任务名称',
    progress: 0,
    count: '0 / 10'
});

// 更新进度
modal.updateProgress({
    subtitle: '处理第 3 项',
    progress: 30,
    count: '3 / 10'
});

// 完成后关闭
modal.hide();
&lt;/script&gt;
</pre>

<h4>API 方法</h4>
<ul>
    <li><code>show()</code> - 显示模态框</li>
    <li><code>hide()</code> - 隐藏模态框</li>
    <li><code>setTitle(text)</code> - 设置标题</li>
    <li><code>setBody(html)</code> - 设置主体内容</li>
    <li><code>setFooter(html)</code> - 设置底部内容</li>
    <li><code>showHeader(bool)</code> - 显示/隐藏头部</li>
    <li><code>showFooter(bool)</code> - 显示/隐藏底部</li>
    <li><code>setClosable(bool)</code> - 设置是否可关闭</li>
    <li><code>setCopyable(bool)</code> - 显示/隐藏复制按钮</li>
    <li><code>copyBody()</code> - 复制当前弹窗主体内容</li>
    <li><code>showProgress(options)</code> - 进度模式</li>
    <li><code>updateProgress(options)</code> - 更新进度</li>
    <li><code>isVisible()</code> - 是否可见</li>
    <li><code>getElement()</code> - 获取 DOM 元素</li>
</ul>
DOC;
    }
}
