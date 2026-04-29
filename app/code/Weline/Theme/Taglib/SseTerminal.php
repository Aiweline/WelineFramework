<?php
declare(strict_types=1);

namespace Weline\Theme\Taglib;

use Weline\Taglib\TaglibInterface;

/**
 * SSE 终端组件
 * 
 * 提供类似命令行终端的 SSE 流式输出显示界面
 * 支持实时显示进度、日志、错误等信息
 */
class SseTerminal implements TaglibInterface
{
    public static function name(): string
    {
        return 'theme:sse-terminal';
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
            'url' => false,          // SSE 端点 URL（可通过 JS 设置）
            'path' => false,         // 后台路由 path（如 blog/backend/post/trigger-sse），优先于 url
            'title' => false,        // 终端标题
            'height' => false,       // 终端高度，默认 300px
            'events' => false,       // 监听的事件名，逗号分隔，如 start,progress,done,failed
            'auto-scroll' => false,  // 是否自动滚动到底部，默认 true
            'show-timestamp' => false, // 是否显示时间戳，默认 true
            'show-toolbar' => false, // 是否显示工具栏，默认 true
            'show-start-toggle' => false, // 是否显示播放/停止（仅 POST 流时请 false，由页面按钮 term.start(url,{method,body}) 启动）
            'allow-html' => false,   // 是否将消息按 HTML 渲染（仅限可信后端内容，有 XSS 风险）
            'class' => false,        // 额外CSS类
            'style' => false,        // 内联样式
            'max-stream-chars' => false, // 流式 chunk 单块最大字符数，超出截断尾部保留，防 DOM/内存拖垮浏览器，0 表示不限制
            'show-thinking-toggle' => false, // 是否显示「思考输出」切换按钮（默认 true）
            'thinking-default' => false,     // 思考输出默认值，'on'|'off'（默认 'on'，关闭时新到 thinking 事件不渲染）
            'thinking-storage-key' => false, // 持久化 key，默认 weline_sse_terminal_thinking_{id}
        ];
    }

    public static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            $id = $attributes['id'] ?? 'sse-terminal-' . uniqid();
            $url = $attributes['url'] ?? '';
            $title = $attributes['title'] ?? __('终端输出');
            $height = $attributes['height'] ?? '300px';
            $eventsAttr = \trim((string) ($attributes['events'] ?? ''));
            $eventsList = $eventsAttr !== ''
                ? \array_map('trim', \array_filter(\explode(',', $eventsAttr)))
                : ['start', 'progress', 'chunk', 'total', 'done', 'error', 'info', 'warning', 'success', 'debug', 'thinking', 'reasoning'];
            // 用户显式传 events 时也强制确保 thinking/reasoning 在列表内（除非显式排除），便于面板开关一致工作。
            if ($eventsAttr !== '') {
                foreach (['thinking', 'reasoning'] as $reqEvent) {
                    if (!\in_array($reqEvent, $eventsList, true)) {
                        $eventsList[] = $reqEvent;
                    }
                }
            }
            $autoScroll = !isset($attributes['auto-scroll']) || $attributes['auto-scroll'] !== 'false';
            $showTimestamp = !isset($attributes['show-timestamp']) || $attributes['show-timestamp'] !== 'false';
            $showToolbar = !isset($attributes['show-toolbar']) || $attributes['show-toolbar'] !== 'false';
            $showStartToggle = !isset($attributes['show-start-toggle']) || $attributes['show-start-toggle'] !== 'false';
            $allowHtml = isset($attributes['allow-html']) && \in_array(\strtolower((string) $attributes['allow-html']), ['true', '1', 'yes'], true);
            $class = $attributes['class'] ?? '';
            $style = $attributes['style'] ?? '';
            $maxStreamChars = isset($attributes['max-stream-chars']) ? (int) $attributes['max-stream-chars'] : 400000;
            if ($maxStreamChars < 0) {
                $maxStreamChars = 0;
            }
            $showThinkingToggle = !isset($attributes['show-thinking-toggle']) || $attributes['show-thinking-toggle'] !== 'false';
            $thinkingDefault = isset($attributes['thinking-default'])
                ? \strtolower((string)$attributes['thinking-default'])
                : 'on';
            if (!\in_array($thinkingDefault, ['on', 'off'], true)) {
                $thinkingDefault = 'on';
            }
            $thinkingStorageKey = isset($attributes['thinking-storage-key']) && (string)$attributes['thinking-storage-key'] !== ''
                ? (string)$attributes['thinking-storage-key']
                : 'weline_sse_terminal_thinking_' . $id;

            // 翻译文本
            $t_connecting = addslashes(__('正在连接...'));
            $t_connected = addslashes(__('已连接'));
            $t_disconnected = addslashes(__('已断开'));
            $t_error = addslashes(__('连接错误'));
            $t_connection_failed = addslashes(__('连接失败（可能为网络问题或服务端异常），请查看下方日志或服务器终端。'));
            $t_clear = addslashes(__('清空'));
            $t_copy = addslashes(__('复制'));
            $t_stop = addslashes(__('停止'));
            $t_start = addslashes(__('开始'));
            $t_copied = addslashes(__('已复制'));
            $t_dns_response = addslashes(__('【DNS 供应商返回】'));
            $t_thinking_on = addslashes(__('思考输出：开（点击关闭）'));
            $t_thinking_off = addslashes(__('思考输出：关（点击开启）'));
            $t_thinking_label_on = addslashes(__('思考'));

            // 解析属性
            $t_reconnecting = addslashes(__('连接重试中...'));
            $t_url_not_configured = 'URL not configured';
            $code = \Weline\Taglib\Taglib::attributes($attributes);
            // path 优先：若提供 path 则用 getBackendUrl 解析为完整 URL
            $code .= "\nif (!empty(\$Taglib__path ?? '')) { \$Taglib__url = (string)\$this->getBackendUrl(\$Taglib__path); }";

            $html = [];
            $html[] = '<?php ' . $code . ' ?>';

            // 组件容器
            $html[] = '<div class="weline-sse-terminal ' . htmlspecialchars($class) . '" id="' . htmlspecialchars($id) . '" style="' . htmlspecialchars($style) . '" data-component="sse-terminal">';
            
            // 标题栏
            if ($showToolbar) {
                $html[] = '  <div class="weline-sse-terminal-header">';
                $html[] = '    <div class="weline-sse-terminal-title">';
                $html[] = '      <span class="weline-sse-terminal-icon"><i class="mdi mdi-console"></i></span>';
                $html[] = '      <span class="weline-sse-terminal-title-text">' . htmlspecialchars($title) . '</span>';
                $html[] = '    </div>';
                $html[] = '    <div class="weline-sse-terminal-status">';
                $html[] = '      <span class="weline-sse-terminal-status-dot"></span>';
                $html[] = '      <span class="weline-sse-terminal-status-text" id="' . htmlspecialchars($id) . '_status">' . $t_disconnected . '</span>';
                $html[] = '    </div>';
                $html[] = '    <div class="weline-sse-terminal-actions">';
                if ($showThinkingToggle) {
                    // 思考输出按钮：高亮表示开启；点击切换；状态写 localStorage 按 key 记忆。
                    $html[] = '      <button type="button" class="weline-sse-terminal-btn weline-sse-terminal-btn-thinking" id="' . htmlspecialchars($id) . '_btn_thinking" title="' . $t_thinking_on . '" aria-pressed="true">';
                    $html[] = '        <i class="mdi mdi-brain"></i>';
                    $html[] = '      </button>';
                }
                if ($showStartToggle) {
                    $html[] = '      <button type="button" class="weline-sse-terminal-btn" id="' . htmlspecialchars($id) . '_btn_toggle" title="' . $t_start . '">';
                    $html[] = '        <i class="mdi mdi-play"></i>';
                    $html[] = '      </button>';
                }
                $html[] = '      <button type="button" class="weline-sse-terminal-btn" id="' . htmlspecialchars($id) . '_btn_copy" title="' . $t_copy . '">';
                $html[] = '        <i class="mdi mdi-content-copy"></i>';
                $html[] = '      </button>';
                $html[] = '      <button type="button" class="weline-sse-terminal-btn" id="' . htmlspecialchars($id) . '_btn_clear" title="' . $t_clear . '">';
                $html[] = '        <i class="mdi mdi-delete-sweep"></i>';
                $html[] = '      </button>';
                $html[] = '    </div>';
                $html[] = '  </div>';
            }
            
            // 输出区域
            $html[] = '  <div class="weline-sse-terminal-body" id="' . htmlspecialchars($id) . '_body" style="height: ' . htmlspecialchars($height) . '">';
            $html[] = '    <div class="weline-sse-terminal-content" id="' . htmlspecialchars($id) . '_content"></div>';
            $html[] = '  </div>';
            
            // 进度条（可选）
            $html[] = '  <div class="weline-sse-terminal-progress" id="' . htmlspecialchars($id) . '_progress" style="display:none;">';
            $html[] = '    <div class="weline-sse-terminal-progress-bar" id="' . htmlspecialchars($id) . '_progress_bar"></div>';
            $html[] = '  </div>';
            
            $html[] = '</div>';

            // 样式（使用主题变量）
            $html[] = '<style>';
            $html[] = '.weline-sse-terminal { border-radius: 8px; overflow: hidden; font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace; font-size: 13px; background: var(--backend-color-card-bg, #1e1e2e); border: 1px solid var(--backend-color-border-default, #313244); box-shadow: var(--backend-shadow-md); }';
            $html[] = '.weline-sse-terminal-header { display: flex; align-items: center; justify-content: space-between; padding: 8px 12px; background: var(--backend-color-sidebar-bg, #181825); border-bottom: 1px solid var(--backend-color-border-default, #313244); gap: 12px; }';
            $html[] = '.weline-sse-terminal-title { display: flex; align-items: center; gap: 8px; color: var(--backend-color-text-secondary, #a6adc8); font-weight: 500; }';
            $html[] = '.weline-sse-terminal-icon { color: var(--backend-color-primary, #89b4fa); }';
            $html[] = '.weline-sse-terminal-status { display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--backend-color-text-muted, #6c7086); }';
            $html[] = '.weline-sse-terminal-status-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--backend-color-text-muted, #6c7086); transition: background 0.3s; }';
            $html[] = '.weline-sse-terminal.connected .weline-sse-terminal-status-dot { background: var(--backend-color-success, #a6e3a1); box-shadow: 0 0 6px var(--backend-color-success, #a6e3a1); }';
            $html[] = '.weline-sse-terminal.connecting .weline-sse-terminal-status-dot { background: var(--backend-color-info, #89dceb); box-shadow: 0 0 6px var(--backend-color-info, #89dceb); }';
            $html[] = '.weline-sse-terminal.error .weline-sse-terminal-status-dot { background: var(--backend-color-danger, #f38ba8); }';
            $html[] = '.weline-sse-terminal-actions { display: flex; gap: 4px; }';
            $html[] = '.weline-sse-terminal-btn { width: 28px; height: 28px; border: none; border-radius: 4px; background: transparent; color: var(--backend-color-text-muted, #6c7086); cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s; }';
            $html[] = '.weline-sse-terminal-btn:hover { background: var(--backend-color-hover-bg, #313244); color: var(--backend-color-text-primary, #cdd6f4); }';
            $html[] = '.weline-sse-terminal-btn.active { color: var(--backend-color-danger, #f38ba8); }';
            $html[] = '.weline-sse-terminal-btn-thinking { color: var(--backend-color-info, #89dceb); }';
            $html[] = '.weline-sse-terminal-btn-thinking[aria-pressed="false"] { color: var(--backend-color-text-muted, #6c7086); opacity: 0.55; }';
            $html[] = '.weline-sse-terminal-line.thinking { color: var(--backend-color-info, #89dceb); opacity: 0.85; font-style: italic; }';
            $html[] = '.weline-sse-terminal-body { overflow-y: auto; padding: 12px; background: var(--backend-color-card-bg, #1e1e2e); }';
            $html[] = '.weline-sse-terminal-content { min-height: 100%; }';
            $html[] = '.weline-sse-terminal-line { padding: 2px 0; line-height: 1.5; word-break: break-all; display: flex; gap: 8px; }';
            $html[] = '.weline-sse-terminal-time { color: var(--backend-color-text-muted, #6c7086); flex-shrink: 0; font-size: 11px; }';
            $html[] = '.weline-sse-terminal-text { flex: 1; white-space: pre-wrap; word-break: break-word; }';
            $html[] = '.weline-sse-terminal-line.info { color: var(--backend-color-text-primary, #cdd6f4); }';
            $html[] = '.weline-sse-terminal-line.success { color: var(--backend-color-success, #a6e3a1); }';
            $html[] = '.weline-sse-terminal-line.warning { color: var(--backend-color-warning, #f9e2af); }';
            $html[] = '.weline-sse-terminal-line.error { color: var(--backend-color-danger, #f38ba8); }';
            $html[] = '.weline-sse-terminal-line.progress { color: var(--backend-color-info, #89dceb); height: auto !important; }';
            $html[] = '.weline-sse-terminal-line.debug { color: var(--backend-color-text-muted, #6c7086); font-style: italic; }';
            $html[] = '.weline-sse-terminal-line.start { color: var(--backend-color-primary, #89b4fa); font-weight: 500; }';
            $html[] = '.weline-sse-terminal-line.total { color: var(--backend-color-success, #a6e3a1); font-weight: 500; }';
            $html[] = '.weline-sse-terminal-line.done { color: var(--backend-color-success, #a6e3a1); font-weight: 500; }';
            $html[] = '.weline-sse-terminal-line.weline-sse-terminal-streaming .weline-sse-terminal-text { white-space: pre-wrap; word-break: break-word; }';
            $html[] = '.weline-sse-terminal-progress { height: 3px; background: var(--backend-color-border-default, #313244); }';
            $html[] = '.weline-sse-terminal-progress-bar { height: 100%; width: 0%; background: linear-gradient(90deg, var(--backend-color-primary, #89b4fa), var(--backend-color-info, #89dceb)); transition: width 0.3s; }';
            $html[] = '.weline-sse-terminal::-webkit-scrollbar { width: 6px; }';
            $html[] = '.weline-sse-terminal::-webkit-scrollbar-track { background: transparent; }';
            $html[] = '.weline-sse-terminal::-webkit-scrollbar-thumb { background: var(--backend-color-border-default, #313244); border-radius: 3px; }';
            $html[] = '.weline-sse-terminal-body::-webkit-scrollbar { width: 6px; }';
            $html[] = '.weline-sse-terminal-body::-webkit-scrollbar-track { background: transparent; }';
            $html[] = '.weline-sse-terminal-body::-webkit-scrollbar-thumb { background: var(--backend-color-border-default, #313244); border-radius: 3px; }';
            $html[] = '</style>';

            // JavaScript
            $html[] = '<script>(function(){';
            $html[] = '"use strict";';
            $html[] = 'var id = ' . json_encode($id) . ';';
            $html[] = 'var initialUrl = <?= json_encode($Taglib__url ?? \'\') ?>;';
            $html[] = 'var commonEvents = ' . \json_encode($eventsList) . ';';
            $html[] = 'var autoScroll = ' . ($autoScroll ? 'true' : 'false') . ';';
            $html[] = 'var showTimestamp = ' . ($showTimestamp ? 'true' : 'false') . ';';
            $html[] = 'var allowHtml = ' . ($allowHtml ? 'true' : 'false') . ';';
            $html[] = 'var maxStreamChars = ' . $maxStreamChars . ';';
            $html[] = 'var urlNotConfiguredText = ' . json_encode($t_url_not_configured, JSON_UNESCAPED_UNICODE) . ';';
            $html[] = 'var thinkingShowToggle = ' . ($showThinkingToggle ? 'true' : 'false') . ';';
            $html[] = 'var thinkingDefaultEnabled = ' . ($thinkingDefault === 'on' ? 'true' : 'false') . ';';
            $html[] = 'var thinkingStorageKey = ' . json_encode($thinkingStorageKey, JSON_UNESCAPED_UNICODE) . ';';
            $html[] = 'var thinkingTitleOn = ' . json_encode($t_thinking_on, JSON_UNESCAPED_UNICODE) . ';';
            $html[] = 'var thinkingTitleOff = ' . json_encode($t_thinking_off, JSON_UNESCAPED_UNICODE) . ';';
            $html[] = 'var thinkingLabel = ' . json_encode($t_thinking_label_on, JSON_UNESCAPED_UNICODE) . ';';
            $tStreamTrunc = addslashes(__('【输出过长，已省略前部】'));
            $html[] = 'var streamTruncMsg = ' . json_encode($tStreamTrunc . "\n", JSON_UNESCAPED_UNICODE) . ';';

            $html[] = <<<JS

var container = document.getElementById(id);
var content = document.getElementById(id + '_content');
var body = document.getElementById(id + '_body');
var statusText = document.getElementById(id + '_status');
var progressContainer = document.getElementById(id + '_progress');
var progressBar = document.getElementById(id + '_progress_bar');
var btnToggle = document.getElementById(id + '_btn_toggle');
var btnCopy = document.getElementById(id + '_btn_copy');
var btnClear = document.getElementById(id + '_btn_clear');
var btnThinking = document.getElementById(id + '_btn_thinking');

// 思考输出开关：true 时把 thinking/reasoning 事件渲染到面板，false 时只触发 callback、不写 DOM
var thinkingEnabled = thinkingDefaultEnabled;
try {
    if (thinkingShowToggle && thinkingStorageKey && typeof window.localStorage !== 'undefined') {
        var stored = window.localStorage.getItem(thinkingStorageKey);
        if (stored === 'on') { thinkingEnabled = true; }
        else if (stored === 'off') { thinkingEnabled = false; }
    }
} catch (e) {}

function persistThinkingState() {
    try {
        if (thinkingShowToggle && thinkingStorageKey && typeof window.localStorage !== 'undefined') {
            window.localStorage.setItem(thinkingStorageKey, thinkingEnabled ? 'on' : 'off');
        }
    } catch (e) {}
}

function applyThinkingButtonState() {
    if (!btnThinking) return;
    btnThinking.setAttribute('aria-pressed', thinkingEnabled ? 'true' : 'false');
    btnThinking.title = thinkingEnabled ? thinkingTitleOn : thinkingTitleOff;
}
applyThinkingButtonState();

function setThinkingEnabled(next) {
    var bool = !!next;
    if (thinkingEnabled === bool) {
        applyThinkingButtonState();
        return;
    }
    thinkingEnabled = bool;
    persistThinkingState();
    applyThinkingButtonState();
    if (eventCallbacks.thinking_toggle) {
        try { eventCallbacks.thinking_toggle({ enabled: thinkingEnabled }); } catch (e) {}
    }
}

var eventSource = null;
var postAbortController = null;
var isRunning = false;
var currentUrl = initialUrl;
var eventSourceSeq = 0;
var manualStopRequested = false;
var terminalCompleted = false;

// 公共 API 暴露到 window
window.WelineSseTerminal = window.WelineSseTerminal || {};
window.WelineSseTerminal[id] = {
    start: start,
    stop: stop,
    clear: clear,
    log: log,
    setUrl: function(url) { currentUrl = url; },
    getUrl: function() { return currentUrl; },
    isRunning: function() { return isRunning; },
    getTransportState: function() { return container ? (container.dataset.transportState || 'disconnected') : 'disconnected'; },
    on: function(event, callback) { eventCallbacks[event] = callback; },
    setProgress: setProgress,
    setStatus: setStatus,
    setThinkingEnabled: setThinkingEnabled,
    getThinkingEnabled: function() { return thinkingEnabled; },
    toggleThinking: function() { setThinkingEnabled(!thinkingEnabled); }
};

var eventCallbacks = {};

function formatTime() {
    var now = new Date();
    return now.toLocaleTimeString('zh-CN', { hour12: false });
}

var streamingLine = null;

function log(text, type) {
    type = type || 'info';
    streamingLine = null;

    var raw = (text === undefined || text === null) ? '' : text;
    var s = typeof raw === 'string' ? raw : String(raw);
    // 兼容不同换行符，确保 '\\n' 能正确分行展示
    s = s.replace(/\\r\\n/g, '\\n').replace(/\\r/g, '\\n');

    // 一条日志消息里如果包含换行符，则按行拆成多个 DOM 行，保证“完整逐行显示”
    var lines = s.split('\\n');
    for (var i = 0; i < lines.length; i++) {
        var lineText = lines[i];
        var line = document.createElement('div');
        line.className = 'weline-sse-terminal-line ' + type;
        
        if (showTimestamp) {
            var time = document.createElement('span');
            time.className = 'weline-sse-terminal-time';
            time.textContent = '[' + formatTime() + ']';
            line.appendChild(time);
        }
        
        var textEl = document.createElement('span');
        textEl.className = 'weline-sse-terminal-text';
        if (allowHtml && typeof lineText === 'string' && lineText.indexOf('<') >= 0) {
            textEl.innerHTML = lineText;
        } else {
            textEl.textContent = lineText;
        }
        line.appendChild(textEl);
        
        content.appendChild(line);
    }
    
    if (autoScroll) {
        body.scrollTop = body.scrollHeight;
    }
}

// 思考输出独立流式行：与正常 chunk 区分行/颜色，关闭开关时跳过 DOM 注入。
var thinkingStreamingLine = null;
function appendThinkingChunkDom(s) {
    if (!s) return;
    if (!thinkingStreamingLine) {
        thinkingStreamingLine = document.createElement('div');
        thinkingStreamingLine.className = 'weline-sse-terminal-line weline-sse-terminal-streaming thinking';
        if (showTimestamp) {
            var time = document.createElement('span');
            time.className = 'weline-sse-terminal-time';
            time.textContent = '[' + formatTime() + ']';
            thinkingStreamingLine.appendChild(time);
        }
        var labelEl = document.createElement('span');
        labelEl.className = 'weline-sse-terminal-text weline-sse-terminal-thinking-label';
        labelEl.style.opacity = '0.6';
        labelEl.style.flex = '0 0 auto';
        labelEl.textContent = thinkingLabel + '：';
        thinkingStreamingLine.appendChild(labelEl);
        var textEl0 = document.createElement('span');
        textEl0.className = 'weline-sse-terminal-text';
        thinkingStreamingLine.appendChild(textEl0);
        content.appendChild(thinkingStreamingLine);
    }
    var textEls = thinkingStreamingLine.querySelectorAll('.weline-sse-terminal-text');
    var textEl = textEls.length > 0 ? textEls[textEls.length - 1] : thinkingStreamingLine.lastChild;
    if (textEl) {
        textEl.textContent += s;
        if (maxStreamChars > 0 && textEl.textContent.length > maxStreamChars) {
            var keep = Math.floor(maxStreamChars * 0.85);
            textEl.textContent = streamTruncMsg + textEl.textContent.slice(-keep);
        }
    }
    if (autoScroll) body.scrollTop = body.scrollHeight;
}

var chunkRafPending = '';
var chunkRafScheduled = false;
function appendChunkDom(s) {
    if (!s) return;
    if (!streamingLine) {
        streamingLine = document.createElement('div');
        streamingLine.className = 'weline-sse-terminal-line weline-sse-terminal-streaming chunk';
        if (showTimestamp) {
            var time = document.createElement('span');
            time.className = 'weline-sse-terminal-time';
            time.textContent = '[' + formatTime() + ']';
            streamingLine.appendChild(time);
        }
        var textEl0 = document.createElement('span');
        textEl0.className = 'weline-sse-terminal-text';
        streamingLine.appendChild(textEl0);
        content.appendChild(streamingLine);
    }
    var textEl = streamingLine.querySelector('.weline-sse-terminal-text') || streamingLine.lastChild;
    if (textEl) {
        textEl.textContent += s;
        if (maxStreamChars > 0 && textEl.textContent.length > maxStreamChars) {
            var keep = Math.floor(maxStreamChars * 0.85);
            textEl.textContent = streamTruncMsg + textEl.textContent.slice(-keep);
        }
    }
    if (autoScroll) body.scrollTop = body.scrollHeight;
}
function flushChunkRaf() {
    chunkRafScheduled = false;
    var batch = chunkRafPending;
    chunkRafPending = '';
    if (batch) appendChunkDom(batch);
}
function appendChunk(text) {
    var s = typeof text === 'string' ? text : '';
    if (!s) return;
    chunkRafPending += s;
    if (!chunkRafScheduled) {
        chunkRafScheduled = true;
        requestAnimationFrame(flushChunkRaf);
    }
}

function setProgress(percent) {
    if (percent >= 0 && percent <= 100) {
        progressContainer.style.display = 'block';
        progressBar.style.width = percent + '%';
    }
    if (percent >= 100 || percent < 0) {
        setTimeout(function() {
            progressContainer.style.display = 'none';
            progressBar.style.width = '0%';
        }, 500);
    }
}

function setStatus(status, text) {
    container.classList.remove('connected', 'connecting', 'error');
    if (status === 'connected') {
        container.classList.add('connected');
    } else if (status === 'connecting') {
        container.classList.add('connecting');
    } else if (status === 'error') {
        container.classList.add('error');
    }
    if (container) {
        container.dataset.transportState = status;
    }
    if (statusText) statusText.textContent = text;
}

function dispatchSseEvent(eventName, data, rawEvent) {
    var shouldFinalizeStream = eventName === 'done';
    if (shouldFinalizeStream) {
        terminalCompleted = true;
    }
    try {
        var callbackEvent = rawEvent || { data: JSON.stringify(data) };
        if (eventName === 'chunk' && data.content !== undefined) {
            var chunkContent = typeof data.content === 'string' ? data.content : '';
            if (eventCallbacks.chunk) {
                eventCallbacks.chunk(callbackEvent);
            } else {
                appendChunk(chunkContent);
            }
            if (data.progress !== undefined) setProgress(data.progress);
            return;
        }
        // 思考输出（thinking/reasoning）：受 thinkingEnabled 控制是否渲染。
        // callback 不受开关影响，业务侧仍可观察事件。
        if (eventName === 'thinking' || eventName === 'reasoning') {
            if (eventCallbacks[eventName]) {
                try { eventCallbacks[eventName](callbackEvent); } catch (e) {}
            }
            if (!thinkingEnabled) {
                return;
            }
            var thinkText = (data && typeof data.content === 'string') ? data.content
                : ((data && typeof data.message === 'string') ? data.message : '');
            if (thinkText !== '') {
                appendThinkingChunkDom(thinkText);
            }
            return;
        }
        var msg = data.message || data.result || data.keyword || data.msg;
        if (!msg) msg = (Object.keys(data).length > 0 ? JSON.stringify(data) : eventName);
        var type = (eventName === 'failed' || eventName === 'error') ? 'error' : eventName;
        var hasCallback = !!eventCallbacks[eventName];
        if (eventName === 'error') {
            setStatus('error', String(msg || '$t_error'));
        }
        if (hasCallback) {
            eventCallbacks[eventName](callbackEvent);
        } else {
            log(msg, type);
        }
        if (data.dns_response) {
            var dr = data.dns_response;
            var drStr = typeof dr === 'string' ? dr : JSON.stringify(dr, null, 2);
            log('$t_dns_response ' + drStr, 'debug');
        }
        if (data.progress !== undefined) {
            setProgress(data.progress);
        }
        // Stream finalization is handled in finally so callbacks cannot skip close().
        // done 表示服务端流已结束：必须关闭原生 EventSource，否则 TCP 关闭后浏览器会
        // 自动重连同一 URL，状态栏长期显示「连接重试中...」且可能重复打后端。
        // 回调仍先于 stop() 执行；若需在完成后立刻发起新流，请在回调里 setTimeout(0, () => term.start(...))。
    } catch (err) {
        log(typeof data === 'string' ? data : eventName, eventName);
        // catch 中不再重复调用 callback，避免重复调用
    } finally {
        // done is terminal. Always close EventSource, even if consumer callbacks throw.
        if (shouldFinalizeStream) {
            stop({ internal: true });
        }
    }
}

function appendQueryParam(url, key, value) {
    var sep = url.indexOf('?') >= 0 ? '&' : '?';
    return url + sep + encodeURIComponent(key) + '=' + encodeURIComponent(value);
}

function buildEventSourceUrl(baseUrl, options) {
    var resolved = String(baseUrl || '');
    var opts = options || {};
    if (!opts || !opts.method || String(opts.method).toUpperCase() !== 'POST' || !opts.body) {
        return resolved;
    }
    var body = opts.body;
    try {
        if (typeof FormData !== 'undefined' && body instanceof FormData) {
            body.forEach(function(v, k) {
                if (typeof v === 'string' || typeof v === 'number' || typeof v === 'boolean') {
                    resolved = appendQueryParam(resolved, String(k), String(v));
                }
            });
            return resolved;
        }
        if (typeof URLSearchParams !== 'undefined' && body instanceof URLSearchParams) {
            body.forEach(function(v, k) {
                resolved = appendQueryParam(resolved, String(k), String(v));
            });
            return resolved;
        }
        if (typeof body === 'string' && body.trim() !== '') {
            var trimmed = body.replace(/^\?/, '');
            return resolved + (resolved.indexOf('?') >= 0 ? '&' : '?') + trimmed;
        }
    } catch (e) {
    }
    return resolved;
}

function start(url, options) {
    if (isRunning) return;

    var resolvedUrl = url || currentUrl;
    if (!resolvedUrl) {
        log('$t_error' + ': ' + urlNotConfiguredText, 'error');
        return;
    }

    options = options || {};
    currentUrl = resolvedUrl;
    isRunning = true;
    manualStopRequested = false;
    terminalCompleted = false;
    
    if (btnToggle) {
        btnToggle.innerHTML = '<i class="mdi mdi-stop"></i>';
        btnToggle.classList.add('active');
        btnToggle.title = '$t_stop';
    }
    
    log('$t_connecting', 'info');
    setStatus('connecting', '$t_connecting');
    
    resolvedUrl = buildEventSourceUrl(resolvedUrl, options);
    
    var source = new EventSource(resolvedUrl);
    var sourceSeq = ++eventSourceSeq;
    eventSource = source;
    
    source.onopen = function() {
        if (eventSource !== source || sourceSeq !== eventSourceSeq) {
            return;
        }
        setStatus('connected', '$t_connected');
        log('$t_connected', 'success');
        if (eventCallbacks.open) eventCallbacks.open();
    };
    
    source.onerror = function(e) {
        if (eventSource !== source || sourceSeq !== eventSourceSeq) {
            return;
        }
        if (terminalCompleted) {
            stop({ keepStatus: true, internal: true });
            return;
        }
        if (e && typeof e.data === 'string' && e.data !== '') {
            // SSE business error events carry data and are handled by the registered error listener below.
            return;
        }
        var isBenignTransition = false;
        if (source.readyState === EventSource.CLOSED) {
            if (manualStopRequested) {
                stop({ keepStatus: true, internal: true });
                setStatus('disconnected', '$t_disconnected');
                isBenignTransition = true;
            } else {
                setStatus('connecting', '$t_reconnecting');
                isBenignTransition = true;
            }
        } else if (source.readyState === EventSource.CONNECTING) {
            // 完全遵循原生 EventSource：CONNECTING 由浏览器自动重连管理
            setStatus('connecting', '$t_reconnecting');
            isBenignTransition = true;
        } else {
            setStatus('error', '$t_error');
            log('$t_error', 'error');
            log('$t_connection_failed', 'error');
            isBenignTransition = false;
        }
        // CONNECTING/CLOSED 多数是浏览器自动重连或流结束过程，不应向上层冒泡为业务错误。
        if (!isBenignTransition && eventCallbacks.error) {
            eventCallbacks.error(e);
        }
    };
    
    source.onmessage = function(e) {
        if (eventSource !== source || sourceSeq !== eventSourceSeq) {
            return;
        }
        try {
            var data = JSON.parse(e.data);
            if (data.message) {
                log(data.message, data.type || 'info');
            }
            if (data.progress !== undefined) {
                setProgress(data.progress);
            }
        } catch (err) {
            log(e.data, 'info');
        }
        if (eventCallbacks.message) eventCallbacks.message(e);
    };
    
    commonEvents.forEach(function(eventName) {
        source.addEventListener(eventName, function(e) {
            if (eventSource !== source || sourceSeq !== eventSourceSeq) {
                return;
            }
            if (eventName === 'error' && (typeof e.data !== 'string' || e.data === '')) {
                return;
            }
            try {
                var data = JSON.parse(e.data || '{}');
                dispatchSseEvent(eventName, data, e);
            } catch (err) {
                log(e.data || eventName, eventName);
                if (eventCallbacks[eventName]) eventCallbacks[eventName](e);
            } finally {
                if (eventName === 'done' && eventSource === source && sourceSeq === eventSourceSeq) {
                    terminalCompleted = true;
                    stop({ internal: true });
                }
            }
        });
    });
}

function stop(options) {
    options = options || {};
    var keepStatus = !!(options.keepStatus || options.suppressTransportError);
    var internalStop = !!options.internal;
    if (!internalStop) {
        manualStopRequested = true;
    }
    if (postAbortController) {
        postAbortController.abort();
        postAbortController = null;
    }
    if (eventSource) {
        var closingSource = eventSource;
        eventSource = null;
        closingSource.close();
    }
    isRunning = false;
    
    if (btnToggle) {
        btnToggle.innerHTML = '<i class="mdi mdi-play"></i>';
        btnToggle.classList.remove('active');
        btnToggle.title = '$t_start';
    }
    
    if (!keepStatus) {
        setStatus('disconnected', '$t_disconnected');
    }
    if (eventCallbacks.stop) eventCallbacks.stop();
}

function clear() {
    content.innerHTML = '';
    progressBar.style.width = '0%';
    progressContainer.style.display = 'none';
    streamingLine = null;
    thinkingStreamingLine = null;
    chunkRafPending = '';
    chunkRafScheduled = false;
}

// 绑定按钮事件
if (btnToggle) {
    btnToggle.addEventListener('click', function() {
        if (isRunning) {
            stop();
        } else {
            start();
        }
    });
}

if (btnThinking) {
    btnThinking.addEventListener('click', function() {
        setThinkingEnabled(!thinkingEnabled);
    });
}

if (btnCopy) {
    btnCopy.addEventListener('click', function() {
        var text = [];
        content.querySelectorAll('.weline-sse-terminal-line').forEach(function(line) {
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

// 如果设置了初始 URL，自动开始
if (initialUrl && container.hasAttribute('data-auto-start')) {
    start();
}

JS;
            $html[] = '})();</script>';

            return implode("\n", $html);
        };
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
<h3><code>&lt;w:theme:sse-terminal&gt;</code> SSE 终端组件</h3>

<p><strong>功能</strong>：类似命令行终端的 SSE 流式输出显示界面，支持实时显示进度、日志、错误等信息</p>

<h4>属性说明</h4>
<ul>
    <li><code>id</code>：组件唯一ID（必填）</li>
    <li><code>url</code>：SSE 端点 URL（可选，也可通过 JS 设置）</li>
    <li><code>path</code>：后台路由 path（如 blog/backend/post/trigger-sse），自动解析为完整 URL，优先于 url</li>
    <li><code>title</code>：终端标题，默认"终端输出"</li>
    <li><code>height</code>：终端高度，默认 300px</li>
    <li><code>auto-scroll</code>：是否自动滚动到底部，默认 true</li>
    <li><code>show-timestamp</code>：是否显示时间戳，默认 true</li>
    <li><code>show-toolbar</code>：是否显示工具栏，默认 true</li>
    <li><code>show-start-toggle</code>：是否显示播放/停止（默认 true）。仅 POST 流时请设 false，由页面 <code>term.start(url,{method:\'POST\',body:fd})</code> 启动，避免误触 GET 导致 404</li>
    <li><code>allow-html</code>：是否按 HTML 渲染消息（默认 false，设为 true 时支持富文本，仅限可信后端内容）</li>
    <li><code>class</code>：额外CSS类</li>
    <li><code>style</code>：内联样式</li>
</ul>

<h4>使用示例</h4>
<pre>
&lt;!-- 使用 path（推荐，后台 SSE 直接填路由） --&gt;
&lt;w:theme:sse-terminal 
    id="my-terminal"
    path="blog/backend/post/trigger-ai-publish-sse"
    title="任务执行"
/&gt;

&lt;!-- 使用 url（完整 URL） --&gt;
&lt;w:theme:sse-terminal 
    id="my-terminal"
    url="/backend/xxx/sse"
    title="任务执行"
/&gt;

&lt;!-- 通过 JS 控制 --&gt;
&lt;w:theme:sse-terminal id="task-terminal" title="AI 生成"/&gt;
&lt;script&gt;
var terminal = window.WelineSseTerminal['task-terminal'];
terminal.setUrl('/api/generate-sse');
terminal.on('done', function(e) {
    console.log('完成');
});
document.getElementById('startBtn').onclick = function() {
    terminal.start();
};
&lt;/script&gt;
</pre>

<h4>JavaScript API</h4>
<pre>
var terminal = window.WelineSseTerminal['terminal-id'];

// 方法
terminal.start(url);        // 开始连接（可选 URL）
terminal.stop();            // 停止连接
terminal.clear();           // 清空输出
terminal.log(text, type);   // 输出日志，type: info/success/warning/error/progress/debug
terminal.setUrl(url);       // 设置 URL
terminal.setProgress(50);   // 设置进度条百分比 0-100

// 事件回调
terminal.on('open', callback);      // 连接成功
terminal.on('error', callback);     // 连接错误
terminal.on('message', callback);   // 收到消息
terminal.on('done', callback);      // 任务完成（收到 done 事件后会自动 stop，防止浏览器重连）
terminal.on('stop', callback);      // 连接停止（含流正常结束后的自动关闭）
terminal.on('progress', callback);  // 进度更新
terminal.on('start', callback);     // 任务开始
</pre>

<h4>后端 SSE 事件格式</h4>
<p>使用 <code>Weline\Framework\Http\Sse\SseWriter</code> 发送事件：</p>
<pre>
\$sse = new SseWriter();
\$sse->start();

// 发送开始事件
\$sse->sendEvent('start', ['message' => '开始执行...']);

// 发送进度
\$sse->sendEvent('progress', [
    'message' => '处理中...',
    'progress' => 50,  // 进度百分比
    'index' => 5,
    'total' => 10
]);

// 发送完成
\$sse->complete(['message' => '执行完成！']);
</pre>
DOC;
    }
}
