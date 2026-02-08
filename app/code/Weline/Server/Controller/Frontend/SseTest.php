<?php
declare(strict_types=1);

/**
 * Weline Server - SSE 前台测试控制器
 * 
 * 用于测试 WLS 的 SSE（Server-Sent Events）流式响应功能
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Controller\Frontend;

use Weline\Framework\Controller\PcController;
use Weline\Framework\Http\Sse\SseWriter;

/**
 * SseTest - SSE 前台测试控制器
 * 
 * 测试路由：
 * - GET /server/sse-test/stream - 测试 SSE 流式输出
 * - GET /server/sse-test/index - SSE 测试页面
 */
class SseTest extends PcController
{
    /**
     * SSE 测试页面
     */
    public function getIndex(): string
    {
        $streamUrl = $this->_url->getFrontendUrl('server/sse-test/stream');
        
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>SSE 测试 - Weline Server</title>
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
        .info { background: #16213e; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .info code { background: #0f3460; padding: 2px 6px; border-radius: 3px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🌊 WLS SSE 流式响应测试</h1>
        <div class="info">
            <p><strong>测试说明：</strong></p>
            <p>点击「开始测试」按钮，服务器将通过 SSE 每 500ms 推送一条进度消息，共 10 条。</p>
            <p>流地址：<code>{$streamUrl}</code></p>
        </div>
        <div class="status" id="status">状态：就绪</div>
        <button id="startBtn" onclick="startSSE()">开始测试</button>
        <button id="stopBtn" onclick="stopSSE()" disabled>停止</button>
        <button onclick="clearOutput()">清空输出</button>
        <div id="output"></div>
    </div>
    <script>
        let eventSource = null;
        
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
                addOutput('🚀 开始: ' + data.message, 'start');
            });
            
            eventSource.addEventListener('progress', function(e) {
                const data = JSON.parse(e.data);
                addOutput('📊 进度: ' + data.progress + '% - ' + data.message, 'progress');
            });
            
            eventSource.addEventListener('message', function(e) {
                const data = JSON.parse(e.data);
                addOutput('💬 消息: ' + (data.chunk || data.message || JSON.stringify(data)), 'message');
            });
            
            eventSource.addEventListener('done', function(e) {
                const data = JSON.parse(e.data);
                addOutput('✅ 完成: ' + data.message, 'complete');
                eventSource.close();
                startBtn.disabled = false;
                stopBtn.disabled = true;
                status.textContent = '状态：完成';
            });
            
            eventSource.addEventListener('error', function(e) {
                if (e.data) {
                    const data = JSON.parse(e.data);
                    addOutput('❌ 错误: ' + data.message, 'error');
                }
            });
            
            eventSource.onerror = function(e) {
                if (eventSource.readyState === EventSource.CLOSED) {
                    status.textContent = '状态：连接已关闭';
                    startBtn.disabled = false;
                    stopBtn.disabled = true;
                } else {
                    status.textContent = '状态：连接错误';
                    addOutput('❌ 连接错误', 'error');
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
            addOutput('⏹️ 用户停止', 'error');
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
            usleep(500000); // 500ms
        }
        
        // 发送完成事件
        $sse->complete([
            'message' => '所有任务处理完成！',
            'timestamp' => date('Y-m-d H:i:s'),
        ]);
    }
}
