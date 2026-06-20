<?php
declare(strict_types=1);

/**
 * Weline Server - SSE 测试控制器
 * 
 * 用于测试 WLS 的 SSE（Server-Sent Events）流式响应功能
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Http\Sse\SseWriter;
use Weline\Framework\Runtime\SchedulerSystem;

/**
 * SseTest - SSE 测试控制器
 * 
 * 测试路由：
 * - GET /admin/server/sse-test/stream - 测试 SSE 流式输出
 * - GET /admin/server/sse-test/index - SSE 测试页面
 * - GET /admin/server/sse-test/concurrent-test - SSE 与静态请求并发测试页（3 轮）
 */
class SseTest extends BackendController
{
    /**
     * SSE 测试页面
     */
    public function getIndex(): string
    {
        $streamUrl = $this->_url->getBackendUrl('server/sse-test/stream');
        
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>SSE 测试</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #1a1a2e; color: #eee; }
        .container { max-width: 800px; margin: 0 auto; }
        h1 { color: #00d9ff; }
        #output { 
            background: #16213e; 
            padding: 15px; 
            border-radius: 8px; 
            min-height: 300px; 
            max-height: 500px; 
            overflow-y: auto;
            font-family: monospace;
            font-size: 14px;
        }
        .event { margin: 5px 0; padding: 5px 10px; border-radius: 4px; }
        .event-start { background: #0f3460; border-left: 3px solid #00d9ff; }
        .event-progress { background: #1a1a2e; border-left: 3px solid #e94560; }
        .event-message { background: #16213e; border-left: 3px solid #0f3460; }
        .event-complete { background: #0d7377; border-left: 3px solid #14ffec; }
        .event-error { background: #e94560; border-left: 3px solid #ff6b6b; }
        button { 
            background: #e94560; 
            color: white; 
            border: none; 
            padding: 10px 20px; 
            border-radius: 5px; 
            cursor: pointer; 
            margin: 10px 5px 10px 0;
            font-size: 14px;
        }
        button:hover { background: #ff6b6b; }
        button:disabled { background: #666; cursor: not-allowed; }
        .status { margin: 10px 0; padding: 10px; background: #0f3460; border-radius: 5px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🌊 WLS SSE 流式响应测试</h1>
        <div class="status" id="status">状态：就绪</div>
        <button id="startBtn" data-sse-test-action="start">开始测试</button>
        <button id="stopBtn" data-sse-test-action="stop" disabled>停止</button>
        <button data-sse-test-action="clear">清空输出</button>
        <a href="{$this->_url->getBackendUrl('server/sse-test/concurrent-test')}" style="margin-left:0.5rem;">SSE 并发测试（3 轮）</a>
        <div id="output"></div>
    </div>
    <script>
        let eventSource = null;

        document.addEventListener('click', function(event) {
            const trigger = event.target.closest('[data-sse-test-action]');
            if (!trigger) {
                return;
            }

            const action = trigger.dataset.sseTestAction;
            if (action === 'start') {
                startSSE();
                return;
            }
            if (action === 'stop') {
                stopSSE();
                return;
            }
            if (action === 'clear') {
                clearOutput();
            }
        });
        
        function startSSE() {
            const output = document.getElementById('output');
            const status = document.getElementById('status');
            const startBtn = document.getElementById('startBtn');
            const stopBtn = document.getElementById('stopBtn');
            
            if (eventSource) {
                eventSource.close();
            }
            
            startBtn.disabled = true;
            stopBtn.disabled = false;
            status.textContent = '状态：连接中...';
            
            eventSource = new EventSource('{$streamUrl}');
            
            eventSource.onopen = function() {
                status.textContent = '状态：已连接';
                addOutput('连接已建立', 'start');
            };
            
            eventSource.addEventListener('start', function(e) {
                const data = JSON.parse(e.data);
                addOutput('开始: ' + data.message, 'start');
            });
            
            eventSource.addEventListener('progress', function(e) {
                const data = JSON.parse(e.data);
                addOutput('进度: ' + data.progress + '% - ' + data.message, 'progress');
            });
            
            eventSource.addEventListener('message', function(e) {
                const data = JSON.parse(e.data);
                addOutput('消息: ' + (data.chunk || data.message || JSON.stringify(data)), 'message');
            });
            
            eventSource.addEventListener('done', function(e) {
                const data = JSON.parse(e.data);
                addOutput('完成: ' + data.message, 'complete');
                eventSource.close();
                startBtn.disabled = false;
                stopBtn.disabled = true;
                status.textContent = '状态：完成';
            });
            
            eventSource.addEventListener('error', function(e) {
                if (e.data) {
                    const data = JSON.parse(e.data);
                    addOutput('错误: ' + data.message, 'error');
                }
            });
            
            eventSource.onerror = function(e) {
                if (eventSource.readyState === EventSource.CLOSED) {
                    status.textContent = '状态：连接已关闭';
                    startBtn.disabled = false;
                    stopBtn.disabled = true;
                } else {
                    status.textContent = '状态：连接错误';
                    addOutput('连接错误', 'error');
                }
            };
        }
        
        function stopSSE() {
            if (eventSource) {
                eventSource.close();
                eventSource = null;
            }
            document.getElementById('startBtn').disabled = false;
            document.getElementById('stopBtn').disabled = true;
            document.getElementById('status').textContent = '状态：已停止';
            addOutput('用户停止', 'error');
        }
        
        function clearOutput() {
            document.getElementById('output').innerHTML = '';
        }
        
        function addOutput(text, type) {
            const output = document.getElementById('output');
            const div = document.createElement('div');
            div.className = 'event event-' + type;
            div.textContent = new Date().toLocaleTimeString() + ' - ' + text;
            output.appendChild(div);
            output.scrollTop = output.scrollHeight;
        }
    </script>
</body>
</html>
HTML;
    }
    
    /**
     * SSE 流式输出测试
     * 
     * 模拟一个耗时任务，通过 SSE 实时推送进度
     */
    public function getStream(): void
    {
        $sse = new SseWriter();
        $sse->start();
        
        // 发送开始事件
        $sse->sendEvent('start', [
            'message' => '任务开始执行',
            'timestamp' => date('Y-m-d H:i:s'),
        ]);
        
        // 模拟耗时任务，每隔 500ms 发送进度
        $total = 10;
        for ($i = 1; $i <= $total; $i++) {
            // 检查连接是否仍然有效
            if (!$sse->isAlive()) {
                break;
            }
            
            $progress = ($i / $total) * 100;
            
            $sse->sendEvent('progress', [
                'progress' => $progress,
                'current' => $i,
                'total' => $total,
                'message' => "处理第 {$i}/{$total} 项...",
            ]);
            
            // 模拟处理耗时
            SchedulerSystem::usleep(500000); // 500ms — WLS 下挂起 Fiber，不阻塞 Worker
        }
        
        // 发送完成事件
        $sse->complete([
            'message' => '所有任务处理完成！',
            'timestamp' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * SSE 与静态请求并发测试页
     *
     * 点击「开始」后：共 3 轮，每轮同时发起 1 个 SSE 连接 + 循环请求若干静态资源，
     * 用于验证 SSE 连接时其他请求是否无阻塞。
     */
    public function getConcurrentTest(): string
    {
        $streamUrl = $this->_url->getBackendUrl('server/sse-test/stream');
        $staticUrls = [
            $this->_url->getBackendUrl('server/sse-test/index'),
            $this->_url->getBackendUrl('server/sse-test/concurrent-test'),
            $this->_url->getBackendUrl(''),
        ];
        $streamUrlJson = \json_encode($streamUrl, JSON_UNESCAPED_SLASHES);
        $staticUrlsJson = \json_encode($staticUrls, JSON_UNESCAPED_SLASHES);

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>SSE 并发测试</title>
    <style>
        .wls-sse-concurrent { max-width: 900px; margin: 0 auto; padding: 1rem; }
        .wls-sse-concurrent h1 { color: var(--backend-color-primary); margin-bottom: 1rem; }
        .wls-sse-concurrent .status { padding: 0.75rem; border-radius: var(--backend-border-radius); background: var(--backend-color-surface); margin-bottom: 1rem; }
        .wls-sse-concurrent button { padding: 0.5rem 1rem; border-radius: var(--backend-border-radius); cursor: pointer; margin-right: 0.5rem; }
        .wls-sse-concurrent button:disabled { opacity: 0.6; cursor: not-allowed; }
        .wls-sse-concurrent table { width: 100%; border-collapse: collapse; background: var(--backend-color-card-bg); border: 1px solid var(--backend-color-border-default); border-radius: var(--backend-border-radius); }
        .wls-sse-concurrent th, .wls-sse-concurrent td { padding: 0.5rem 0.75rem; text-align: left; border-bottom: 1px solid var(--backend-color-border-light); }
        .wls-sse-concurrent th { background: var(--backend-color-surface); color: var(--backend-color-text-primary); }
        .wls-sse-concurrent #log { margin-top: 1rem; padding: 0.75rem; background: var(--backend-color-surface); border-radius: var(--backend-border-radius); font-family: monospace; font-size: 0.85rem; max-height: 200px; overflow-y: auto; }
    </style>
</head>
<body>
    <div class="wls-sse-concurrent">
        <h1>SSE 连接时无阻塞 — 并发测试</h1>
        <div class="status" id="status">就绪。点击「开始」将执行 3 轮：每轮 1 个 SSE 连接 + 持续请求静态资源。</div>
        <button id="startBtn" type="button">开始</button>
        <table id="resultTable">
            <thead><tr><th>轮次</th><th>SSE 时长 (ms)</th><th>静态请求次数</th><th>平均响应 (ms)</th></tr></thead>
            <tbody id="resultBody"></tbody>
        </table>
        <div id="log"></div>
    </div>
    <script>
    (function () {
        'use strict';
        var streamUrl = {$streamUrlJson};
        var staticUrls = {$staticUrlsJson};
        var totalRounds = 3;
        var pollIntervalMs = 150;

        var statusEl = document.getElementById('status');
        var startBtn = document.getElementById('startBtn');
        var resultBody = document.getElementById('resultBody');
        var logEl = document.getElementById('log');

        function log(msg) {
            var line = document.createElement('div');
            line.textContent = new Date().toLocaleTimeString() + ' ' + msg;
            logEl.appendChild(line);
            logEl.scrollTop = logEl.scrollHeight;
        }

        function runOneRound(roundIndex) {
            return new Promise(function (resolve) {
                var sseStart = 0;
                var sseEnd = 0;
                var requestCount = 0;
                var totalTime = 0;
                var intervalId = null;
                var es = new EventSource(streamUrl);

                es.onopen = function () {
                    sseStart = performance.now();
                    log('第' + (roundIndex + 1) + '轮 SSE 已连接，开始请求静态资源');
                    intervalId = setInterval(function () {
                        staticUrls.forEach(function (url) {
                            var t0 = performance.now();
                            fetch(url, { method: 'GET', credentials: 'same-origin' })
                                .then(function (r) {
                                    requestCount++;
                                    totalTime += (performance.now() - t0);
                                })
                                .catch(function () { requestCount++; });
                        });
                    }, pollIntervalMs);
                };

                es.addEventListener('done', function () {
                    es.close();
                    sseEnd = performance.now();
                    if (intervalId) clearInterval(intervalId);
                    var sseDuration = Math.round(sseEnd - sseStart);
                    var avg = requestCount > 0 ? Math.round(totalTime / requestCount) : 0;
                    log('第' + (roundIndex + 1) + '轮 SSE 结束，静态请求 ' + requestCount + ' 次，平均 ' + avg + ' ms');
                    resolve({ round: roundIndex + 1, sseDuration: sseDuration, requestCount: requestCount, avgMs: avg });
                });

                es.onerror = function () {
                    if (es.readyState === EventSource.CLOSED) return;
                    es.close();
                    if (intervalId) clearInterval(intervalId);
                    sseEnd = sseStart > 0 ? performance.now() : sseStart;
                    resolve({ round: roundIndex + 1, sseDuration: Math.round((sseEnd - sseStart) || 0), requestCount: requestCount, avgMs: requestCount > 0 ? Math.round(totalTime / requestCount) : 0 });
                };
            });
        }

        function appendRow(row) {
            var tr = document.createElement('tr');
            tr.innerHTML = '<td>' + row.round + '</td><td>' + row.sseDuration + '</td><td>' + row.requestCount + '</td><td>' + row.avgMs + '</td>';
            resultBody.appendChild(tr);
        }

        startBtn.addEventListener('click', function () {
            startBtn.disabled = true;
            resultBody.innerHTML = '';
            logEl.innerHTML = '';
            statusEl.textContent = '执行中：共 ' + totalRounds + ' 轮…';

            var chain = Promise.resolve();
            for (var r = 0; r < totalRounds; r++) {
                (function (roundIndex) {
                    chain = chain.then(function () { return runOneRound(roundIndex); }).then(function (res) { appendRow(res); });
                })(r);
            }
            chain.then(function () {
                startBtn.disabled = false;
                statusEl.textContent = '3 轮已完成。若每轮静态请求次数较多且平均响应较低，则 SSE 连接时无阻塞。';
            }).catch(function (e) {
                startBtn.disabled = false;
                statusEl.textContent = '出错: ' + (e && e.message ? e.message : String(e));
            });
        });
    })();
    </script>
</body>
</html>
HTML;
    }
}
