<?php
declare(strict_types=1);

namespace Weline\Theme\Taglib;

use Weline\Taglib\TaglibInterface;

/**
 * SSE 进度组件（带步骤指示器和日志终端）
 * 
 * 提供步骤进度条 + 日志终端的组合界面
 * 适用于多步骤任务的可视化进度展示
 */
class SseProgress implements TaglibInterface
{
    public static function name(): string
    {
        return 'theme:sse-progress';
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
            'url' => false,          // SSE 端点 URL
            'steps' => false,        // 步骤定义 JSON，如 [{"key":"dns","label":"DNS配置","icon":"mdi-dns"}]
            'title' => false,        // 标题
            'height' => false,       // 日志区域高度，默认 250px
            'class' => false,        // 额外CSS类
            'style' => false,        // 内联样式
        ];
    }

    public static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            $id = $attributes['id'] ?? 'sse-progress-' . uniqid();
            $url = $attributes['url'] ?? '';
            $steps = $attributes['steps'] ?? '[]';
            $title = $attributes['title'] ?? __('任务进度');
            $height = $attributes['height'] ?? '250px';
            $class = $attributes['class'] ?? '';
            $style = $attributes['style'] ?? '';

            // 翻译文本
            $t_waiting = addslashes((string)__('等待中'));
            $t_running = addslashes((string)__('执行中'));
            $t_success = addslashes((string)__('成功'));
            $t_failed = addslashes((string)__('失败'));
            $t_skipped = addslashes((string)__('已跳过'));
            $t_connecting = addslashes((string)__('正在连接...'));
            $t_connected = addslashes((string)__('已连接'));
            $t_disconnected = addslashes((string)__('已断开'));
            $t_ready = addslashes((string)__('就绪'));
            $t_copy = addslashes((string)__('复制'));
            $t_clear = addslashes((string)__('清空'));
            $t_copied = addslashes((string)__('已复制'));

            $code = \Weline\Taglib\Taglib::attributes($attributes);

            $html = [];
            $html[] = '<?php ' . $code . ' ?>';

            // 主容器
            $html[] = '<div class="weline-sse-progress ' . htmlspecialchars($class) . '" id="<?= htmlspecialchars($Taglib__id) ?>" style="' . htmlspecialchars($style) . '" data-component="sse-progress">';
            
            // 步骤指示器区域
            $html[] = '  <div class="weline-sse-progress-steps" id="<?= htmlspecialchars($Taglib__id) ?>_steps">';
            $html[] = '  </div>';
            
            // 日志终端区域
            $html[] = '  <div class="weline-sse-progress-terminal">';
            $html[] = '    <div class="weline-sse-progress-terminal-header">';
            $html[] = '      <div class="weline-sse-progress-terminal-title">';
            $html[] = '        <i class="mdi mdi-console"></i>';
            $html[] = '        <span><?= htmlspecialchars($Taglib__title ?? \'' . addslashes($title) . '\') ?></span>';
            $html[] = '      </div>';
            $html[] = '      <div class="weline-sse-progress-terminal-status">';
            $html[] = '        <span class="weline-sse-progress-status-dot"></span>';
            $html[] = '        <span class="weline-sse-progress-status-text" id="<?= htmlspecialchars($Taglib__id) ?>_status">' . $t_ready . '</span>';
            $html[] = '      </div>';
            $html[] = '      <div class="weline-sse-progress-terminal-actions">';
            $html[] = '        <button type="button" class="weline-sse-progress-btn" id="<?= htmlspecialchars($Taglib__id) ?>_btn_copy" title="' . $t_copy . '">';
            $html[] = '          <i class="mdi mdi-content-copy"></i>';
            $html[] = '        </button>';
            $html[] = '        <button type="button" class="weline-sse-progress-btn" id="<?= htmlspecialchars($Taglib__id) ?>_btn_clear" title="' . $t_clear . '">';
            $html[] = '          <i class="mdi mdi-delete-sweep"></i>';
            $html[] = '        </button>';
            $html[] = '      </div>';
            $html[] = '    </div>';
            $html[] = '    <div class="weline-sse-progress-terminal-body" id="<?= htmlspecialchars($Taglib__id) ?>_body" style="height: <?= htmlspecialchars($Taglib__height ?? \'' . $height . '\') ?>">';
            $html[] = '      <div class="weline-sse-progress-terminal-content" id="<?= htmlspecialchars($Taglib__id) ?>_content"></div>';
            $html[] = '    </div>';
            $html[] = '  </div>';
            
            $html[] = '</div>';

            // 样式
            $html[] = '<style>';
            $html[] = self::getStyles();
            $html[] = '</style>';

            // JavaScript
            $html[] = '<script>(function(){';
            $html[] = '"use strict";';
            $html[] = 'var id = <?= json_encode($Taglib__id) ?>;';
            $html[] = 'var initialUrl = <?= json_encode($Taglib__url ?? \'\') ?>;';
            $html[] = 'var stepsData = <?= $Taglib__steps ?? \'[]\' ?>;';
            $html[] = self::getJavaScript($t_waiting, $t_running, $t_success, $t_failed, $t_skipped, $t_connecting, $t_connected, $t_disconnected, $t_ready, $t_copied);
            $html[] = '})();</script>';

            return implode("\n", $html);
        };
    }

    private static function getStyles(): string
    {
        return <<<CSS
.weline-sse-progress { border-radius: var(--backend-border-radius-lg, 12px); overflow: hidden; background: var(--backend-color-card-bg, #fff); border: 1px solid var(--backend-color-border-default, #e9ecef); box-shadow: var(--backend-shadow-sm); }

/* 步骤指示器 */
.weline-sse-progress-steps { display: flex; align-items: center; justify-content: center; padding: 24px 16px; background: var(--backend-color-bg-secondary, #f8f9fa); border-bottom: 1px solid var(--backend-color-border-light, #e9ecef); gap: 0; flex-wrap: wrap; }
.weline-sse-progress-step { display: flex; align-items: center; gap: 8px; padding: 8px 16px; position: relative; }
.weline-sse-progress-step-icon { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1rem; background: var(--backend-color-bg-tertiary, #e9ecef); color: var(--backend-color-text-muted, #adb5bd); border: 2px solid transparent; transition: all 0.3s ease; }
.weline-sse-progress-step-icon i { font-size: 1.1rem; }
.weline-sse-progress-step-label { font-size: 0.85rem; font-weight: 500; color: var(--backend-color-text-muted, #adb5bd); transition: color 0.3s ease; white-space: nowrap; }
.weline-sse-progress-step-arrow { color: var(--backend-color-text-muted, #adb5bd); font-size: 1rem; margin: 0 4px; }

/* 步骤状态 */
.weline-sse-progress-step.waiting .weline-sse-progress-step-icon { background: var(--backend-color-bg-tertiary, #e9ecef); color: var(--backend-color-text-muted, #adb5bd); }
.weline-sse-progress-step.running .weline-sse-progress-step-icon { background: var(--backend-color-primary-light, #e8f4fd); color: var(--backend-color-primary, #556ee6); border-color: var(--backend-color-primary, #556ee6); animation: pulseStep 1.5s infinite; }
.weline-sse-progress-step.running .weline-sse-progress-step-label { color: var(--backend-color-primary, #556ee6); font-weight: 600; }
.weline-sse-progress-step.success .weline-sse-progress-step-icon { background: var(--backend-color-success, #34c38f); color: var(--backend-color-text-inverse, #fff); }
.weline-sse-progress-step.success .weline-sse-progress-step-label { color: var(--backend-color-success, #34c38f); }
.weline-sse-progress-step.failed .weline-sse-progress-step-icon { background: var(--backend-color-danger, #f46a6a); color: var(--backend-color-text-inverse, #fff); }
.weline-sse-progress-step.failed .weline-sse-progress-step-label { color: var(--backend-color-danger, #f46a6a); }
.weline-sse-progress-step.skipped .weline-sse-progress-step-icon { background: var(--backend-color-warning-light, #fff3cd); color: var(--backend-color-warning, #f1b44c); }
.weline-sse-progress-step.skipped .weline-sse-progress-step-label { color: var(--backend-color-text-muted, #adb5bd); text-decoration: line-through; }

@keyframes pulseStep { 0%, 100% { box-shadow: 0 0 0 0 var(--backend-color-primary-light, rgba(85,110,230,0.4)); } 50% { box-shadow: 0 0 0 8px transparent; } }

/* 终端区域 */
.weline-sse-progress-terminal { font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace; font-size: 13px; }
.weline-sse-progress-terminal-header { display: flex; align-items: center; justify-content: space-between; padding: 10px 16px; background: var(--backend-color-sidebar-bg, #343a40); border-bottom: 1px solid var(--backend-color-border-default, #495057); gap: 12px; }
.weline-sse-progress-terminal-title { display: flex; align-items: center; gap: 8px; color: var(--backend-color-text-secondary, #adb5bd); font-weight: 500; font-size: 0.9rem; }
.weline-sse-progress-terminal-title i { color: var(--backend-color-primary, #556ee6); }
.weline-sse-progress-terminal-status { display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--backend-color-text-muted, #6c757d); }
.weline-sse-progress-status-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--backend-color-text-muted, #6c757d); transition: all 0.3s; }
.weline-sse-progress.connected .weline-sse-progress-status-dot { background: var(--backend-color-success, #34c38f); box-shadow: 0 0 6px var(--backend-color-success, #34c38f); }
.weline-sse-progress.error .weline-sse-progress-status-dot { background: var(--backend-color-danger, #f46a6a); }
.weline-sse-progress-terminal-actions { display: flex; gap: 4px; }
.weline-sse-progress-btn { width: 28px; height: 28px; border: none; border-radius: 4px; background: transparent; color: var(--backend-color-text-muted, #6c757d); cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s; }
.weline-sse-progress-btn:hover { background: var(--backend-color-hover-bg, #495057); color: var(--backend-color-text-primary, #f8f9fa); }
.weline-sse-progress-terminal-body { overflow-y: auto; padding: 12px 16px; background: var(--backend-color-sidebar-bg, #212529); color: var(--backend-color-text-secondary, #ced4da); }
.weline-sse-progress-terminal-content { min-height: 100%; }
.weline-sse-progress-terminal-body::-webkit-scrollbar { width: 6px; }
.weline-sse-progress-terminal-body::-webkit-scrollbar-track { background: transparent; }
.weline-sse-progress-terminal-body::-webkit-scrollbar-thumb { background: var(--backend-color-border-default, #495057); border-radius: 3px; }

/* 日志行 */
.weline-sse-progress-line { padding: 3px 0; line-height: 1.6; display: flex; gap: 10px; align-items: flex-start; }
.weline-sse-progress-time { color: var(--backend-color-text-muted, #6c757d); flex-shrink: 0; font-size: 11px; opacity: 0.7; }
.weline-sse-progress-text { flex: 1; word-break: break-word; }
.weline-sse-progress-line.info { color: var(--backend-color-text-secondary, #ced4da); }
.weline-sse-progress-line.success { color: var(--backend-color-success, #34c38f); }
.weline-sse-progress-line.warning { color: var(--backend-color-warning, #f1b44c); }
.weline-sse-progress-line.error { color: var(--backend-color-danger, #f46a6a); }
.weline-sse-progress-line.step { color: var(--backend-color-primary, #556ee6); font-weight: 500; }
.weline-sse-progress-line.done { color: var(--backend-color-success, #34c38f); font-weight: 600; }
.weline-sse-progress-line.debug { color: var(--backend-color-text-muted, #6c757d); font-style: italic; opacity: 0.8; }
CSS;
    }

    private static function getJavaScript(
        string $t_waiting,
        string $t_running,
        string $t_success,
        string $t_failed,
        string $t_skipped,
        string $t_connecting,
        string $t_connected,
        string $t_disconnected,
        string $t_ready,
        string $t_copied
    ): string {
        return <<<JS

var container = document.getElementById(id);
var stepsContainer = document.getElementById(id + '_steps');
var content = document.getElementById(id + '_content');
var body = document.getElementById(id + '_body');
var statusText = document.getElementById(id + '_status');
var btnCopy = document.getElementById(id + '_btn_copy');
var btnClear = document.getElementById(id + '_btn_clear');

var eventSource = null;
var isRunning = false;
var currentUrl = initialUrl;
var steps = [];
var eventCallbacks = {};

// 初始化步骤
function initSteps(stepsDef) {
    steps = stepsDef;
    renderSteps();
}

function renderSteps() {
    if (!stepsContainer || !steps.length) return;
    
    var html = '';
    steps.forEach(function(step, idx) {
        var statusClass = safeClassList(step.status || 'waiting', 'waiting');
        var iconClass = safeClassList(step.icon || 'mdi-checkbox-blank-circle-outline', 'mdi-checkbox-blank-circle-outline');
        
        // 根据状态改变图标
        var displayIcon = iconClass;
        if (statusClass === 'success') displayIcon = 'mdi-check';
        else if (statusClass === 'failed') displayIcon = 'mdi-close';
        else if (statusClass === 'running') displayIcon = 'mdi-loading mdi-spin';
        else if (statusClass === 'skipped') displayIcon = 'mdi-skip-next';
        
        html += '<div class="weline-sse-progress-step ' + statusClass + '" data-step="' + escapeAttr(step.key) + '">';
        html += '  <div class="weline-sse-progress-step-icon"><i class="mdi ' + safeClassList(displayIcon, 'mdi-checkbox-blank-circle-outline') + '"></i></div>';
        html += '  <span class="weline-sse-progress-step-label">' + escapeHtml(step.label) + '</span>';
        html += '</div>';
        
        if (idx < steps.length - 1) {
            html += '<span class="weline-sse-progress-step-arrow"><i class="mdi mdi-chevron-right"></i></span>';
        }
    });
    
    stepsContainer.innerHTML = html;
}

function setStepStatus(stepKey, status) {
    for (var i = 0; i < steps.length; i++) {
        if (steps[i].key === stepKey) {
            steps[i].status = status;
            break;
        }
    }
    renderSteps();
}

function escapeHtml(text) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(text || ''));
    return div.innerHTML;
}

function escapeAttr(text) {
    return escapeHtml(text).replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

function safeClassList(value, fallback) {
    var text = String(value || '').replace(/[^\w\s:-]/g, '').trim();
    return text || fallback || '';
}

function formatTime() {
    var now = new Date();
    return now.toLocaleTimeString('zh-CN', { hour12: false });
}

function log(text, type) {
    type = type || 'info';
    var line = document.createElement('div');
    line.className = 'weline-sse-progress-line ' + type;
    
    var time = document.createElement('span');
    time.className = 'weline-sse-progress-time';
    time.textContent = '[' + formatTime() + ']';
    line.appendChild(time);
    
    var textEl = document.createElement('span');
    textEl.className = 'weline-sse-progress-text';
    textEl.textContent = text;
    line.appendChild(textEl);
    
    content.appendChild(line);
    body.scrollTop = body.scrollHeight;
}

function setStatus(status, text) {
    container.classList.remove('connected', 'error');
    if (status === 'connected') container.classList.add('connected');
    else if (status === 'error') container.classList.add('error');
    if (statusText) statusText.textContent = text;
}

function start(url) {
    if (isRunning) return;
    
    url = url || currentUrl;
    if (!url) {
        log('错误：URL 未设置', 'error');
        return;
    }
    
    currentUrl = url;
    isRunning = true;
    
    log('$t_connecting', 'info');
    setStatus('connecting', '$t_connecting');
    
    // 重置所有步骤状态
    steps.forEach(function(s) { s.status = 'waiting'; });
    renderSteps();
    
    eventSource = new EventSource(url);
    
    eventSource.onopen = function() {
        setStatus('connected', '$t_connected');
        log('$t_connected', 'success');
        if (eventCallbacks.open) eventCallbacks.open();
    };
    
    eventSource.onerror = function(e) {
        if (eventSource.readyState === EventSource.CLOSED) {
            setStatus('disconnected', '$t_disconnected');
            stop();
        } else {
            setStatus('error', '连接错误');
            log('连接错误', 'error');
        }
        if (eventCallbacks.error) eventCallbacks.error(e);
    };
    
    // 默认消息处理
    eventSource.onmessage = function(e) {
        handleMessage(e);
    };
    
    // 步骤相关事件
    var stepEvents = ['step_start', 'step_success', 'step_failed', 'step_skipped', 'step_info', 'step_warning', 'step_error'];
    stepEvents.forEach(function(evt) {
        eventSource.addEventListener(evt, function(e) {
            handleStepEvent(evt, e);
        });
    });
    
    // 完成事件
    eventSource.addEventListener('done', function(e) {
        try {
            var data = JSON.parse(e.data || '{}');
            log(data.message || '✓ 全部完成', 'done');
        } catch (err) {
            log('✓ 全部完成', 'done');
        }
        stop();
        if (eventCallbacks.done) eventCallbacks.done(e);
    });
    
    // 失败事件
    eventSource.addEventListener('failed', function(e) {
        try {
            var data = JSON.parse(e.data || '{}');
            log('✗ ' + (data.message || '任务失败'), 'error');
        } catch (err) {
            log('✗ 任务失败', 'error');
        }
        stop();
        if (eventCallbacks.failed) eventCallbacks.failed(e);
    });
}

function handleMessage(e) {
    try {
        var data = JSON.parse(e.data);
        if (data.message) log(data.message, data.type || 'info');
        if (data.step && data.status) setStepStatus(data.step, data.status);
    } catch (err) {
        log(e.data, 'info');
    }
    if (eventCallbacks.message) eventCallbacks.message(e);
}

function handleStepEvent(eventName, e) {
    try {
        var data = JSON.parse(e.data || '{}');
        var stepKey = data.step || '';
        var message = data.message || '';
        
        if (eventName === 'step_start') {
            setStepStatus(stepKey, 'running');
            log('▶ ' + message, 'step');
        } else if (eventName === 'step_success') {
            setStepStatus(stepKey, 'success');
            log('✓ ' + message, 'success');
        } else if (eventName === 'step_failed') {
            setStepStatus(stepKey, 'failed');
            log('✗ ' + message, 'error');
        } else if (eventName === 'step_skipped') {
            setStepStatus(stepKey, 'skipped');
            log('⊘ ' + message, 'warning');
        } else if (eventName === 'step_info') {
            log('  ' + message, 'info');
        } else if (eventName === 'step_warning') {
            log('⚠ ' + message, 'warning');
        } else if (eventName === 'step_error') {
            log('✗ ' + message, 'error');
        }
    } catch (err) {
        log(e.data || eventName, 'info');
    }
    
    if (eventCallbacks[eventName]) eventCallbacks[eventName](e);
}

function stop() {
    if (eventSource) {
        eventSource.close();
        eventSource = null;
    }
    isRunning = false;
    setStatus('disconnected', '$t_disconnected');
    if (eventCallbacks.stop) eventCallbacks.stop();
}

function clear() {
    content.innerHTML = '';
}

function reset() {
    clear();
    steps.forEach(function(s) { s.status = 'waiting'; });
    renderSteps();
    setStatus('ready', '$t_ready');
}

// 绑定按钮
if (btnCopy) {
    btnCopy.addEventListener('click', function() {
        var text = [];
        content.querySelectorAll('.weline-sse-progress-line').forEach(function(line) {
            text.push(line.textContent);
        });
        var copyText = text.join('\\n');
        var notifyCopied = function() {
            if (typeof BackendToast !== 'undefined') {
                BackendToast.success('$t_copied');
            }
        };
        var fallbackCopy = function(value) {
            var textarea = document.createElement('textarea');
            textarea.value = value;
            textarea.setAttribute('readonly', 'readonly');
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            textarea.style.pointerEvents = 'none';
            document.body.appendChild(textarea);
            textarea.focus();
            textarea.select();
            try {
                var copied = document.execCommand('copy');
                if (copied) {
                    notifyCopied();
                }
            } catch (e) {
            } finally {
                document.body.removeChild(textarea);
            }
        };
        if (navigator.clipboard && window.isSecureContext && typeof navigator.clipboard.writeText === 'function') {
            navigator.clipboard.writeText(copyText).then(function() {
                notifyCopied();
            }).catch(function() {
                fallbackCopy(copyText);
            });
            return;
        }
        fallbackCopy(copyText);
    });
}

if (btnClear) {
    btnClear.addEventListener('click', function() {
        clear();
    });
}

// 初始化
if (stepsData && stepsData.length) {
    initSteps(stepsData);
}

// 公共 API
window.WelineSseProgress = window.WelineSseProgress || {};
window.WelineSseProgress[id] = {
    start: start,
    stop: stop,
    clear: clear,
    reset: reset,
    log: log,
    setUrl: function(url) { currentUrl = url; },
    getUrl: function() { return currentUrl; },
    isRunning: function() { return isRunning; },
    on: function(event, callback) { eventCallbacks[event] = callback; },
    setSteps: initSteps,
    setStepStatus: setStepStatus,
    getSteps: function() { return steps; }
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
<h3><code>&lt;w:theme:sse-progress&gt;</code> SSE 进度组件</h3>

<p><strong>功能</strong>：带步骤指示器的 SSE 进度展示组件，上部为步骤节点指示器，下部为日志终端</p>

<h4>属性说明</h4>
<ul>
    <li><code>id</code>：组件唯一ID（必填）</li>
    <li><code>url</code>：SSE 端点 URL</li>
    <li><code>steps</code>：步骤定义 JSON 数组</li>
    <li><code>title</code>：终端标题</li>
    <li><code>height</code>：日志区域高度，默认 250px</li>
</ul>

<h4>使用示例</h4>
<pre>
&lt;w:theme:sse-progress 
    id="provision-progress"
    title="配置进度"
    steps='[
        {"key":"purchase","label":"购买域名","icon":"mdi-cart"},
        {"key":"dns","label":"DNS配置","icon":"mdi-dns"},
        {"key":"cdn","label":"CDN绑定","icon":"mdi-shield-check"},
        {"key":"ssl","label":"SSL证书","icon":"mdi-lock"}
    ]'
/&gt;

&lt;script&gt;
var progress = window.WelineSseProgress['provision-progress'];
progress.setUrl('/api/provisioning/sse?order_id=123');
progress.start();
&lt;/script&gt;
</pre>

<h4>后端 SSE 事件格式</h4>
<pre>
// 步骤开始
\$sse->sendEvent('step_start', ['step' => 'dns', 'message' => '开始配置 DNS...']);

// 步骤成功
\$sse->sendEvent('step_success', ['step' => 'dns', 'message' => 'DNS 配置完成']);

// 步骤失败
\$sse->sendEvent('step_failed', ['step' => 'cdn', 'message' => 'CDN 绑定失败：...']);

// 步骤信息
\$sse->sendEvent('step_info', ['step' => 'ssl', 'message' => '正在验证域名所有权...']);

// 全部完成
\$sse->sendEvent('done', ['message' => '一站式配置完成！']);
</pre>
DOC;
    }
}
