<?php

declare(strict_types=1);

/**
 * WLS Fiber / 连接池并发安全探针
 *
 * 通过「多路同时到达 Worker」暴露连接池租约与协议交错风险；单线程顺序 HTTP 客户端无法替代。
 *
 * @author Weline
 */
namespace Weline\Server\Controller\Frontend;

use Weline\Framework\Controller\PcController;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Server\Shared\Client\SharedStateClient;

class FiberConcurrencyProbe extends PcController
{
    /**
     * 说明页：如何本地做真并发（curl_multi / 本仓库 PHPUnit 用例）
     */
    public function getIndex(): string
    {
        if (!$this->isProbeEnabled()) {
            return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Fiber probe</title></head><body><p>'
                . htmlspecialchars(__('WLS Fiber concurrency probe is disabled.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</p></body></html>';
        }

        $exampleCid = 'demo-' . bin2hex(random_bytes(4));
        $stressUrl = $this->_url->getFrontendUrl('server/fiber-concurrency-probe/stress', ['cid' => $exampleCid]);

        $p1 = htmlspecialchars(__('WLS fiber probe: parallel clients are required; sequential PHP curl does not simulate users.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $p2 = htmlspecialchars(__('WLS fiber probe: use curl_multi, ab, hey, or PHPUnit WlsFiberPoolConcurrencyHttpTest.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $p3 = htmlspecialchars(__('WLS fiber probe: set env WLS_FIBER_CONCURRENCY_PROBE=1 when DEV is off.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $stressSafe = htmlspecialchars($stressUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <title>Fiber concurrency probe</title>
    <style>body{font-family:system-ui,sans-serif;max-width:720px;margin:2rem auto;line-height:1.5}code{background:#f0f0f0;padding:2px 6px}</style>
</head>
<body>
    <h1>Fiber / pool probe</h1>
    <p>{$p1}</p>
    <p>{$p2}</p>
    <p>{$p3}</p>
    <p>Example: <code>{$stressSafe}</code></p>
</body>
</html>
HTML;
    }

    /**
     * 压测端点：经 SharedStateClient 走连接池；在 Scheduler 下多次 yield 拉大交错窗口。
     *
     * @return array<string, mixed>
     */
    public function getStress(): array
    {
        if (!$this->isProbeEnabled()) {
            return ['ok' => false, 'err' => __('WLS Fiber concurrency probe is disabled.')];
        }

        $cid = (string) $this->request->getParam('cid', '');
        if (!\preg_match('/^[a-zA-Z0-9_-]{8,128}$/', $cid)) {
            return ['ok' => false, 'err' => __('Invalid cid parameter for fiber probe.')];
        }

        $client = new SharedStateClient();
        for ($i = 0; $i < 4; $i++) {
            if (SchedulerSystem::isSchedulerActive() && \Fiber::getCurrent() !== null) {
                SchedulerSystem::yield();
            }
        }

        $pong = $client->ping();

        return [
            'ok' => $pong,
            'cid' => $cid,
            'ping' => $pong ? 1 : 0,
        ];
    }

    private function isProbeEnabled(): bool
    {
        if (\defined('DEV') && DEV) {
            return true;
        }
        if (\defined('ENV_TEST') && ENV_TEST === true) {
            return true;
        }

        return \getenv('WLS_FIBER_CONCURRENCY_PROBE') === '1';
    }
}
