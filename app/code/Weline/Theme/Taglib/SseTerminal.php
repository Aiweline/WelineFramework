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
            'path' => false,         // 后台路由 path（如 blog/backend/post/trigger-sse），优先于 url，自动解析为 getBackendUrl）
            'title' => false,        // 终端标题
            'height' => false,       // 终端高度，默认 300px
            'auto-scroll' => false,  // 是否自动滚动到底部，默认 true
            'show-timestamp' => false, // 是否显示时间戳，默认 true
            'show-toolbar' => false, // 是否显示工具栏，默认 true
            'class' => false,        // 额外CSS类
            'style' => false,        // 内联样式
        ];
    }

    public static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            $id = $attributes['id'] ?? 'sse-terminal-' . uniqid();
            $url = $attributes['url'] ?? '';
            $title = $attributes['title'] ?? __('终端输出');
            $height = $attributes['height'] ?? '300px';
            $autoScroll = !isset($attributes['auto-scroll']) || $attributes['auto-scroll'] !== 'false';
            $showTimestamp = !isset($attributes['show-timestamp']) || $attributes['show-timestamp'] !== 'false';
            $showToolbar = !isset($attributes['show-toolbar']) || $attributes['show-toolbar'] !== 'false';
            $class = $attributes['class'] ?? '';
            $style = $attributes['style'] ?? '';

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

            // 解析属性
            $code = \Weline\Taglib\Taglib::attributes($attributes);
            // path 优先：若提供 path 则用 getBackendUrl 解析为完整 URL
            $code .= "\nif (!empty(\$Taglib__path ?? '')) { \$Taglib__url = (string)\$this->getBackendUrl(\$Taglib__path); }";

            $html = [];
            $html[] = '<?php ' . $code . ' ?>';

            // 组件容器
            $html[] = '<div class="weline-sse-terminal ' . htmlspecialchars($class) . '" id="<?= htmlspecialchars($Taglib__id) ?>" style="' . htmlspecialchars($style) . '" data-component="sse-terminal">';
            
            // 标题栏
            if ($showToolbar) {
                $html[] = '  <div class="weline-sse-terminal-header">';
                $html[] = '    <div class="weline-sse-terminal-title">';
                $html[] = '      <span class="weline-sse-terminal-icon"><i class="mdi mdi-console"></i></span>';
                $html[] = '      <span class="weline-sse-terminal-title-text"><?= htmlspecialchars($Taglib__title ?? \'' . addslashes($title) . '\') ?></span>';
                $html[] = '    </div>';
                $html[] = '    <div class="weline-sse-terminal-status">';
                $html[] = '      <span class="weline-sse-terminal-status-dot"></span>';
                $html[] = '      <span class="weline-sse-terminal-status-text" id="<?= htmlspecialchars($Taglib__id) ?>_status">' . $t_disconnected . '</span>';
                $html[] = '    </div>';
                $html[] = '    <div class="weline-sse-terminal-actions">';
                $html[] = '      <button type="button" class="weline-sse-terminal-btn" id="<?= htmlspecialchars($Taglib__id) ?>_btn_toggle" title="' . $t_start . '">';
                $html[] = '        <i class="mdi mdi-play"></i>';
                $html[] = '      </button>';
                $html[] = '      <button type="button" class="weline-sse-terminal-btn" id="<?= htmlspecialchars($Taglib__id) ?>_btn_copy" title="' . $t_copy . '">';
                $html[] = '        <i class="mdi mdi-content-copy"></i>';
                $html[] = '      </button>';
                $html[] = '      <button type="button" class="weline-sse-terminal-btn" id="<?= htmlspecialchars($Taglib__id) ?>_btn_clear" title="' . $t_clear . '">';
                $html[] = '        <i class="mdi mdi-delete-sweep"></i>';
                $html[] = '      </button>';
                $html[] = '    </div>';
                $html[] = '  </div>';
            }
            
            // 输出区域
            $html[] = '  <div class="weline-sse-terminal-body" id="<?= htmlspecialchars($Taglib__id) ?>_body" style="height: <?= htmlspecialchars($Taglib__height ?? \'' . $height . '\') ?>">';
            $html[] = '    <div class="weline-sse-terminal-content" id="<?= htmlspecialchars($Taglib__id) ?>_content"></div>';
            $html[] = '  </div>';
            
            // 进度条（可选）
            $html[] = '  <div class="weline-sse-terminal-progress" id="<?= htmlspecialchars($Taglib__id) ?>_progress" style="display:none;">';
            $html[] = '    <div class="weline-sse-terminal-progress-bar" id="<?= htmlspecialchars($Taglib__id) ?>_progress_bar"></div>';
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
            $html[] = '.weline-sse-terminal.error .weline-sse-terminal-status-dot { background: var(--backend-color-danger, #f38ba8); }';
            $html[] = '.weline-sse-terminal-actions { display: flex; gap: 4px; }';
            $html[] = '.weline-sse-terminal-btn { width: 28px; height: 28px; border: none; border-radius: 4px; background: transparent; color: var(--backend-color-text-muted, #6c7086); cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s; }';
            $html[] = '.weline-sse-terminal-btn:hover { background: var(--backend-color-hover-bg, #313244); color: var(--backend-color-text-primary, #cdd6f4); }';
            $html[] = '.weline-sse-terminal-btn.active { color: var(--backend-color-danger, #f38ba8); }';
            $html[] = '.weline-sse-terminal-body { overflow-y: auto; padding: 12px; background: var(--backend-color-card-bg, #1e1e2e); }';
            $html[] = '.weline-sse-terminal-content { min-height: 100%; }';
            $html[] = '.weline-sse-terminal-line { padding: 2px 0; line-height: 1.5; word-break: break-all; display: flex; gap: 8px; }';
            $html[] = '.weline-sse-terminal-time { color: var(--backend-color-text-muted, #6c7086); flex-shrink: 0; font-size: 11px; }';
            $html[] = '.weline-sse-terminal-text { flex: 1; }';
            $html[] = '.weline-sse-terminal-line.info { color: var(--backend-color-text-primary, #cdd6f4); }';
            $html[] = '.weline-sse-terminal-line.success { color: var(--backend-color-success, #a6e3a1); }';
            $html[] = '.weline-sse-terminal-line.warning { color: var(--backend-color-warning, #f9e2af); }';
            $html[] = '.weline-sse-terminal-line.error { color: var(--backend-color-danger, #f38ba8); }';
            $html[] = '.weline-sse-terminal-line.progress { color: var(--backend-color-info, #89dceb); }';
            $html[] = '.weline-sse-terminal-line.debug { color: var(--backend-color-text-muted, #6c7086); font-style: italic; }';
            $html[] = '.weline-sse-terminal-line.start { color: var(--backend-color-primary, #89b4fa); font-weight: 500; }';
            $html[] = '.weline-sse-terminal-line.done { color: var(--backend-color-success, #a6e3a1); font-weight: 500; }';
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
            $html[] = 'var id = <?= json_encode($Taglib__id) ?>;';
            $html[] = 'var initialUrl = <?= json_encode($Taglib__url ?? \'\') ?>;';
            $html[] = 'var autoScroll = ' . ($autoScroll ? 'true' : 'false') . ';';
            $html[] = 'var showTimestamp = ' . ($showTimestamp ? 'true' : 'false') . ';';

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

var eventSource = null;
var isRunning = false;
var currentUrl = initialUrl;

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
    on: function(event, callback) { eventCallbacks[event] = callback; },
    setProgress: setProgress
};

var eventCallbacks = {};

function formatTime() {
    var now = new Date();
    return now.toLocaleTimeString('zh-CN', { hour12: false });
}

function log(text, type) {
    type = type || 'info';
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
    textEl.textContent = text;
    line.appendChild(textEl);
    
    content.appendChild(line);
    
    if (autoScroll) {
        body.scrollTop = body.scrollHeight;
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
    container.classList.remove('connected', 'error');
    if (status === 'connected') {
        container.classList.add('connected');
    } else if (status === 'error') {
        container.classList.add('error');
    }
    if (statusText) statusText.textContent = text;
}

function start(url) {
    if (isRunning) return;
    
    url = url || currentUrl;
    if (!url) {
        log('$t_error' + ': URL 未设置', 'error');
        return;
    }
    
    currentUrl = url;
    isRunning = true;
    
    if (btnToggle) {
        btnToggle.innerHTML = '<i class="mdi mdi-stop"></i>';
        btnToggle.classList.add('active');
        btnToggle.title = '$t_stop';
    }
    
    log('$t_connecting', 'info');
    setStatus('connecting', '$t_connecting');
    
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
            setStatus('error', '$t_error');
            log('$t_error', 'error');
            log('$t_connection_failed', 'error');
        }
        if (eventCallbacks.error) eventCallbacks.error(e);
    };
    
    // 默认消息处理
    eventSource.onmessage = function(e) {
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
    
    // 常用事件类型
    var commonEvents = ['start', 'progress', 'done', 'error', 'info', 'warning', 'success', 'debug', 'article_start', 'article_done', 'article_error'];
    commonEvents.forEach(function(eventName) {
        eventSource.addEventListener(eventName, function(e) {
            try {
                var data = JSON.parse(e.data || '{}');
                var msg = data.message || data.result || data.keyword || JSON.stringify(data);
                var type = eventName;
                
                // 映射事件类型到显示类型
                if (eventName === 'article_start') {
                    msg = (data.index || 0) + '/' + (data.total || 0) + ': ' + (data.keyword || '');
                    type = 'progress';
                } else if (eventName === 'article_done') {
                    msg = (data.keyword || '') + ' ✓';
                    type = 'success';
                } else if (eventName === 'article_error') {
                    msg = (data.keyword || '') + ': ' + (data.error || '');
                    type = 'error';
                }
                
                log(msg, type);
                
                if (data.progress !== undefined) {
                    setProgress(data.progress);
                }
                
                if (eventName === 'done') {
                    stop();
                }
            } catch (err) {
                log(e.data || eventName, eventName);
            }
            
            if (eventCallbacks[eventName]) eventCallbacks[eventName](e);
        });
    });
}

function stop() {
    if (eventSource) {
        eventSource.close();
        eventSource = null;
    }
    isRunning = false;
    
    if (btnToggle) {
        btnToggle.innerHTML = '<i class="mdi mdi-play"></i>';
        btnToggle.classList.remove('active');
        btnToggle.title = '$t_start';
    }
    
    setStatus('disconnected', '$t_disconnected');
    if (eventCallbacks.stop) eventCallbacks.stop();
}

function clear() {
    content.innerHTML = '';
    progressBar.style.width = '0%';
    progressContainer.style.display = 'none';
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

if (btnCopy) {
    btnCopy.addEventListener('click', function() {
        var text = [];
        content.querySelectorAll('.weline-sse-terminal-line').forEach(function(line) {
            text.push(line.textContent);
        });
        navigator.clipboard.writeText(text.join('\\n')).then(function() {
            if (typeof AdminToast !== 'undefined') {
                AdminToast.success('$t_copied');
            } else {
                alert('$t_copied');
            }
        });
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
terminal.on('done', callback);      // 任务完成
terminal.on('stop', callback);      // 连接停止
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
