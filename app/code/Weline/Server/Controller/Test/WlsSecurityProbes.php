<?php

declare(strict_types=1);

namespace Weline\Server\Controller\Test;

use Weline\DeveloperWorkspace\Service\PanelAccessService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\Response;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Security\AttackDetector;
use Weline\Server\Security\WorkerPolicyKernel;
use Weline\Server\Service\Control\IpcControlGateway;
use Weline\Server\Service\Security\SecurityProbeCatalog;
use Weline\Server\Service\Security\SecurityProbeTokenService;

/**
 * Weline 前端面板「安全探针」会话端点。
 *
 * PanelAccess 通过后签发探针豁免 token + 实战封禁凭证，并提供本机解封。
 */
class WlsSecurityProbes extends FrontendController
{
    public function getIndex(): Response
    {
        if (!$this->canAccessPanel()) {
            return $this->deniedResponse();
        }

        /** @var SecurityProbeTokenService $tokenService */
        $tokenService = ObjectManager::getInstance(SecurityProbeTokenService::class);
        $issued = $tokenService->issue();
        $liveBan = $tokenService->issueLiveBan();
        $cases = [];
        foreach (SecurityProbeCatalog::cases() as $case) {
            if (!\is_array($case)) {
                continue;
            }
            $group = \trim((string)($case['group'] ?? ''));
            $severity = \trim((string)($case['severity'] ?? ''));
            $cases[] = \array_merge($case, [
                'group_label' => SecurityProbeCatalog::groupLabel($group),
                'severity_label' => SecurityProbeCatalog::severityLabel($severity),
            ]);
        }

        $clientIp = $this->resolveClientIp();

        return $this->jsonPayload([
            'success' => true,
            'header' => SecurityProbeCatalog::HEADER_NAME,
            'token' => (string)($issued['token'] ?? ''),
            'expires_at' => (int)($issued['expires_at'] ?? 0),
            'live_ban_header' => SecurityProbeCatalog::LIVE_BAN_HEADER,
            'live_ban_token' => (string)($liveBan['token'] ?? ''),
            'live_ban_expires_at' => (int)($liveBan['expires_at'] ?? 0),
            'client_ip' => $clientIp,
            'banned' => $this->isClientBanned($clientIp),
            'unlock_path' => '/server/test/wls-security-probes/unlock',
            'cases' => $cases,
            'origin' => $this->resolvePublicOrigin(),
            'case_count' => \count($cases),
        ]);
    }

    public function getUnlock(): Response
    {
        return $this->postUnlock();
    }

    public function postUnlock(): Response
    {
        if (!$this->canAccessPanel()) {
            return $this->deniedResponse();
        }

        $clientIp = $this->resolveClientIp();
        // Worker 已在到达 PHP 前按 peer + 探针/实战凭证清掉本连接封禁；
        // 此处再补一次 IPC/本地清理，并兼容 IP 解析失败的环境。
        $ipcOk = false;
        if ($clientIp !== '' && $clientIp !== '0.0.0.0' && $clientIp !== '::') {
            $ipc = new IpcControlGateway();
            $result = $ipc->securityUnblock('default', $clientIp, false);
            $ipcOk = !empty($result['success']);
            try {
                /** @var AttackDetector $detector */
                $detector = ObjectManager::getInstance(AttackDetector::class);
                $detector->unblock($clientIp);
            } catch (\Throwable) {
            }
            try {
                WorkerPolicyKernel::instance()->clearSecurityBans($clientIp, false);
            } catch (\Throwable) {
            }
        } else {
            // IP 不可解析时：依赖请求头上的探针凭证已在 Worker 侧完成 peer 解封。
            $ipcOk = true;
            $clientIp = (string)__('（本连接 peer，由 Worker 解封）');
        }

        $stillBanned = false;
        try {
            $resolved = $this->resolveClientIp();
            if ($resolved !== '') {
                $stillBanned = WorkerPolicyKernel::instance()->isSecurityBanned($resolved);
                $clientIp = $resolved;
            }
        } catch (\Throwable) {
        }

        return $this->jsonPayload([
            'success' => $ipcOk || !$stillBanned,
            'message' => ($ipcOk || !$stillBanned)
                ? (string)__('已请求解封本机 IP。')
                : (string)__('解封命令未确认成功，请稍后重试或使用 CLI。'),
            'client_ip' => $clientIp,
            'banned' => $stillBanned,
        ], ($ipcOk || !$stillBanned) ? 200 : 502);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonPayload(array $payload, int $statusCode = 200): Response
    {
        return Response::json($payload, $statusCode)
            ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->setHeader('Pragma', 'no-cache');
    }

    private function deniedResponse(): Response
    {
        return $this->jsonPayload([
            'success' => false,
            'message' => (string)__('安全探针仅在开发模式或授权调试模式下可用。'),
        ], 403);
    }

    private function canAccessPanel(): bool
    {
        return (new PanelAccessService())->canAccessApi($this->request);
    }

    private function resolvePublicOrigin(): string
    {
        $scheme = $this->request->isSecure() ? 'https' : 'http';
        $host = \trim((string)($this->request->getServer('HTTP_HOST') ?? ''));
        if ($host === '') {
            $host = 'localhost';
        }

        return $scheme . '://' . $host;
    }

    private function resolveClientIp(): string
    {
        $candidates = [];
        try {
            $candidates[] = (string)$this->request->getClientIp();
        } catch (\Throwable) {
        }
        foreach (['WLS_CANONICAL_REMOTE_ADDR', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'] as $key) {
            try {
                $candidates[] = (string)($this->request->getServer($key) ?? '');
            } catch (\Throwable) {
            }
            $candidates[] = (string)($_SERVER[$key] ?? '');
        }
        foreach ($candidates as $raw) {
            $raw = \trim((string)$raw);
            if ($raw === '') {
                continue;
            }
            if (\str_contains($raw, ',')) {
                $raw = \trim(\explode(',', $raw, 2)[0]);
            }
            if ($raw === '' || $raw === '0.0.0.0' || $raw === '::') {
                continue;
            }
            if (\filter_var($raw, \FILTER_VALIDATE_IP)) {
                return $raw;
            }
        }

        return '';
    }

    private function isClientBanned(string $clientIp): bool
    {
        if ($clientIp === '') {
            return false;
        }
        try {
            return WorkerPolicyKernel::instance()->isSecurityBanned($clientIp);
        } catch (\Throwable) {
            return false;
        }
    }
}
