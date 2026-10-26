<?php

declare(strict_types=1);

namespace Weline\Server\Service\Fpc;

use Weline\Framework\Http\Fpc\FpcPierceStamp;
use Weline\Server\Service\Edge\Nginx\FpcPierceNginxMapWriter;
use Weline\Server\Service\Edge\Nginx\ManagedNginxService;

/**
 * Panel-facing pierce-stamp rotate / publish / cookie issue.
 */
final class FpcPierceStampAdminService
{
    public function __construct(
        private readonly FpcPierceNginxMapWriter $mapWriter = new FpcPierceNginxMapWriter(),
    ) {
    }

    /**
     * @return array{
     *   secret:string,
     *   secret_masked:string,
     *   cookie_name:string,
     *   query_name:string,
     *   query_suffix:string
     * }
     */
    public function status(): array
    {
        $secret = FpcPierceStamp::ensureSecret();

        return [
            'secret' => $secret,
            'secret_masked' => $this->mask($secret),
            'cookie_name' => FpcPierceStamp::COOKIE_NAME,
            'query_name' => FpcPierceStamp::QUERY_NAME,
            'query_suffix' => FpcPierceStamp::buildQuerySuffix($secret),
        ];
    }

    /**
     * @return array{ok:bool, message:string, status:array<string, mixed>, nginx:array<string, mixed>, cdn:array<string, mixed>}
     */
    public function rotateAndPublish(bool $reloadNginx = true): array
    {
        $secret = FpcPierceStamp::rotateSecret();
        $map = $this->mapWriter->publish($secret);
        $nginx = [
            'map' => $map,
            'reload' => ['ok' => true, 'message' => 'skipped'],
        ];
        if ($reloadNginx && (bool)($map['ok'] ?? false)) {
            try {
                $nginx['reload'] = ManagedNginxService::fromEnv()->reload();
            } catch (\Throwable $e) {
                $nginx['reload'] = [
                    'ok' => false,
                    'message' => $e->getMessage(),
                ];
            }
        }
        // 换章后 CDN 规则里仍是旧 secret：必须重推，否则新章不打穿 CF、旧章继续可用。
        $cdn = $this->syncCdnPierceRule();
        $ok = (bool)($map['ok'] ?? false)
            && (bool)(($nginx['reload']['ok'] ?? false) || ($nginx['reload']['message'] ?? '') === 'skipped');

        return [
            'ok' => $ok,
            'message' => $ok ? 'rotated' : 'rotate_partial_failure',
            'status' => $this->status(),
            'nginx' => $nginx,
            'cdn' => $cdn,
        ];
    }

    /**
     * 穿章轮换后的 CDN Cache Rules 重推（Cdn 模块可选；失败不阻断换章）。
     *
     * @return array{ok:bool, message:string, domains?:list<array<string,mixed>>}
     */
    private function syncCdnPierceRule(): array
    {
        $class = 'Weline\\Cdn\\Service\\PierceRuleSyncService';
        if (!\class_exists($class)) {
            return ['ok' => false, 'message' => 'cdn_module_absent'];
        }
        try {
            return \Weline\Framework\Manager\ObjectManager::getInstance($class)->pushAll();
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array{ok:bool, message:string, status:array<string, mixed>, nginx:array<string, mixed>}
     */
    public function ensurePublished(bool $reloadNginx = false): array
    {
        $secret = FpcPierceStamp::ensureSecret();
        $map = $this->mapWriter->publish($secret);
        $nginx = [
            'map' => $map,
            'reload' => ['ok' => true, 'message' => 'skipped'],
        ];
        if ($reloadNginx && (bool)($map['ok'] ?? false)) {
            try {
                $nginx['reload'] = ManagedNginxService::fromEnv()->reload();
            } catch (\Throwable $e) {
                $nginx['reload'] = [
                    'ok' => false,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'ok' => (bool)($map['ok'] ?? false),
            'message' => (string)($map['message'] ?? ''),
            'status' => $this->status(),
            'nginx' => $nginx,
        ];
    }

    /**
     * @return array{name:string, value:string, options:array<string, mixed>}
     */
    public function issueBrowserCookie(int $ttlSeconds = 28800): array
    {
        $secret = FpcPierceStamp::ensureSecret();
        $ttlSeconds = \max(300, \min(86400 * 7, $ttlSeconds));

        return [
            'name' => FpcPierceStamp::COOKIE_NAME,
            'value' => $secret,
            'options' => [
                'expires' => \time() + $ttlSeconds,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => true,
            ],
        ];
    }

    private function mask(string $secret): string
    {
        $len = \strlen($secret);
        if ($len <= 12) {
            return \str_repeat('*', $len);
        }

        return \substr($secret, 0, 6) . \str_repeat('*', $len - 10) . \substr($secret, -4);
    }
}
