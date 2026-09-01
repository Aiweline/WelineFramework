<?php

declare(strict_types=1);

namespace Weline\Payment\Console\Payment\DevRelay;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Payment\Service\DevRelayLocalConnectService;

/**
 * 本机连调：start worker → 等 pair → 调线上 probe → 等待 relayed_ok。
 */
class Loopback extends CommandAbstract
{
    public function tip(): string
    {
        return 'Local↔online DevRelay loopback probe (start worker, inject online probe, wait relay)';
    }

    public function execute(array $args = [], array $data = []): string
    {
        $printing = ObjectManager::getInstance(Printing::class);
        $online = trim((string) ($this->optionValue($args, 'online-base-url') ?? $this->optionValue($args, 'online') ?? ''));
        $token = trim((string) ($this->optionValue($args, 'user-token') ?? $this->optionValue($args, 'token') ?? ''));
        $timeout = max(15, (int) ($this->optionValue($args, 'timeout') ?? 90));
        $keepRunning = $this->hasFlag($args, 'keep-running');

        if ($online === '' || $token === '') {
            $printing->error('需要 --online-base-url= 与 --user-token=');

            return json_encode(['success' => false, 'message' => 'missing args'], JSON_UNESCAPED_UNICODE) ?: '{}';
        }

        /** @var DevRelayLocalConnectService $service */
        $service = ObjectManager::getInstance(DevRelayLocalConnectService::class);
        $startedHere = false;
        try {
            $status = $service->status();
            if (empty($status['running'])) {
                $printing->note('启动本机静默 worker…');
                $service->start($online, $token);
                $startedHere = true;
            } else {
                $printing->note('复用已运行 worker pid=' . ($status['pid'] ?? 0));
            }

            $sessionCode = $this->waitForSession($service, $timeout);
            $printing->success('已 pair session_code=' . $sessionCode);
            // 给 SSE curl 建连留窗口（服务端也会从 seq0 重放，双保险）
            usleep(1_500_000);

            $marker = 'loopback_' . bin2hex(random_bytes(4));
            $printing->note('向线上注入探针 marker=' . $marker);
            $probe = $this->httpJson('POST', rtrim($online, '/') . '/payment/dev-relay/probe', [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
            ], json_encode(['marker' => $marker], JSON_UNESCAPED_SLASHES) ?: '{}');
            if (empty($probe['success'])) {
                throw new \RuntimeException((string) ($probe['message'] ?? 'online probe failed'));
            }
            $eventCode = (string) ($probe['data']['event']['event_code'] ?? $probe['event']['event_code'] ?? '');
            $printing->success('线上已注入 event_code=' . $eventCode);

            $result = $this->waitForRelay($service, $eventCode, $timeout);
            if (empty($result['ok'])) {
                throw new \RuntimeException((string) ($result['error'] ?? 'relay timeout'));
            }
            $printing->success('通路成功 relayed event=' . $eventCode);

            $payload = [
                'success' => true,
                'session_code' => $sessionCode,
                'event_code' => $eventCode,
                'marker' => $marker,
                'status' => $service->status(),
            ];

            if ($startedHere && !$keepRunning) {
                $service->stop();
                $printing->note('已停止本机 worker');
            }

            return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        } catch (\Throwable $throwable) {
            $printing->error($throwable->getMessage());
            if ($startedHere && !$keepRunning) {
                try {
                    $service->stop();
                } catch (\Throwable) {
                }
            }

            return json_encode([
                'success' => false,
                'message' => $throwable->getMessage(),
                'status' => $service->status(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        }
    }

    private function waitForSession(DevRelayLocalConnectService $service, int $timeout): string
    {
        $deadline = time() + $timeout;
        while (time() < $deadline) {
            $status = $service->status();
            if (empty($status['running'])) {
                throw new \RuntimeException('worker 未运行：' . (string) ($status['last_error'] ?? ''));
            }
            $session = trim((string) ($status['session_code'] ?? ''));
            if ($session !== '') {
                return $session;
            }
            $err = trim((string) ($status['last_error'] ?? ''));
            if ($err !== '') {
                throw new \RuntimeException('worker error: ' . $err);
            }
            usleep(500_000);
        }

        throw new \RuntimeException('等待 pair 超时');
    }

    /**
     * @return array{ok:bool,error:string}
     */
    private function waitForRelay(DevRelayLocalConnectService $service, string $eventCode, int $timeout): array
    {
        $deadline = time() + $timeout;
        while (time() < $deadline) {
            $status = $service->status();
            $recent = \is_array($status['recent_events'] ?? null) ? $status['recent_events'] : [];
            foreach ($recent as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                if ($eventCode !== '' && (string) ($row['event_code'] ?? '') === $eventCode) {
                    return [
                        'ok' => !empty($row['ok']),
                        'error' => (string) ($row['error'] ?? ''),
                    ];
                }
            }
            if ($eventCode === '' && (int) ($status['relayed_ok'] ?? 0) > 0) {
                return ['ok' => true, 'error' => ''];
            }
            usleep(500_000);
        }

        return ['ok' => false, 'error' => '等待本机重放超时'];
    }

    /**
     * @param list<string> $headers
     * @return array<string, mixed>
     */
    private function httpJson(string $method, string $url, array $headers, string $body = ''): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body !== '' ? $body : null,
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($errno) {
            throw new \RuntimeException('HTTP ' . $method . ' failed: ' . $err);
        }
        $decoded = json_decode((string) $raw, true);

        return \is_array($decoded) ? $decoded : ['success' => false, 'message' => (string) $raw];
    }

    private function optionValue(array $args, string $name): ?string
    {
        $prefix = '--' . $name . '=';
        foreach ($args as $arg) {
            if (\is_string($arg) && str_starts_with($arg, $prefix)) {
                return substr($arg, strlen($prefix));
            }
        }

        return null;
    }

    private function hasFlag(array $args, string $name): bool
    {
        return \in_array('--' . $name, $args, true);
    }
}
