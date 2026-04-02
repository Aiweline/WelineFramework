<?php

namespace Weline\Server\Controller\Test;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\Sse\SseWriter;
use Weline\Framework\Runtime\SchedulerSystem;

/**
 * SSE 短轮询测试控制器
 *
 * 用于验证 SSE 短轮询机制是否正常工作
 * 警告：仅用于测试，生产环境应删除此控制器
 */
class SseTest extends FrontendController
{
    /**
     * 测试 SSE 短轮询（无需认证）
     */
    public function getTest(): void
    {
        $sse = new SseWriter();
        $sse->start();

        $sse->sendEvent('start', ['message' => 'Test SSE connection started', 'timestamp' => time()]);
        $sse->sendEvent('test', ['message' => 'This is a test event', 'timestamp' => time()]);

        // 短轮询：只轮询 3 次（3 秒）
        $maxPolls = 3;
        $pollInterval = 1000;  // 1 秒

        for ($i = 0; $i < $maxPolls; $i++) {
            if (!$sse->isAlive()) {
                break;
            }

            $pollCount = $i + 1;
            $sse->sendEvent('poll', [
                'count' => $pollCount,
                'timestamp' => time(),
                'message' => "Poll {$pollCount} of {$maxPolls}"
            ]);

            if ($i < $maxPolls - 1) {
                SchedulerSystem::yieldDelay($pollInterval);
            }
        }

        $sse->complete([
            'success' => true,
            'message' => 'Test complete - SSE short polling works! Please reconnect to continue.',
            'total_time' => '~3 seconds'
        ]);
    }
}
