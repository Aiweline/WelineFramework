<?php

declare(strict_types=1);

namespace Weline\CacheManager\Service;

use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Http\Fpc\FpcHtmlDiskStore;
use Weline\Framework\Http\Fpc\FpcMode;
use Weline\Framework\Http\Fpc\FpcPierceStamp;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeControlBroadcasterInterface;

/**
 * CacheManager shell for Framework FpcMode + optional Server pierce publish/reload.
 */
final class FullPageCacheAdminService
{
    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $base = FpcMode::status();
        $edge = $this->pierceAdminStatus();

        return \array_merge($base, [
            'edge' => $edge,
            'unified_path' => 'admin/system/full-page-cache',
        ]);
    }

    /**
     * @return array{ok:bool, message:string, status:array<string, mixed>}
     */
    public function saveToggles(bool $fpcEnabled, bool $autoPierceEnabled): array
    {
        $fpc = FpcMode::setFullPageCacheEnabled($fpcEnabled);
        $pierce = FpcMode::setDevAutoPierceEnabled($autoPierceEnabled);
        $ok = (bool)($fpc['ok'] ?? false) && (bool)($pierce['ok'] ?? false);
        $sync = $this->syncWorkersAfterToggle();

        return [
            'ok' => $ok,
            'message' => $ok ? 'saved' : 'save_partial_failure',
            'status' => $this->status(),
            'fpc' => $fpc,
            'auto_pierce' => $pierce,
            'worker_sync' => $sync,
        ];
    }

    /**
     * Persist alone is not enough:
     * - WLS workers memoize env.php
     * - Nginx try_files serves html-disk HIT without PHP, so 「开启即打穿」在磁盘未轮换时无效
     *
     * @return array{pools_cleared:list<string>, html_disk_purged:bool, broadcast:bool}
     */
    private function syncWorkersAfterToggle(): array
    {
        $cleared = [];
        try {
            /** @var CacheManager $cacheManager */
            $cacheManager = ObjectManager::getInstance(CacheManager::class);
            foreach (['fpc', 'frontend_cache', 'router', 'router_cache'] as $pool) {
                try {
                    if (\method_exists($cacheManager, 'hasPool') && !$cacheManager->hasPool($pool)) {
                        continue;
                    }
                    $cacheManager->pool($pool)->clear();
                    $cleared[] = $pool;
                } catch (\Throwable) {
                    // best-effort: missing pool must not fail the toggle save
                }
            }
        } catch (\Throwable) {
        }

        $htmlDiskPurged = false;
        try {
            FpcHtmlDiskStore::purgeAll();
            if (\method_exists(FpcHtmlDiskStore::class, 'purgeLegacyHashTrees')) {
                FpcHtmlDiskStore::purgeLegacyHashTrees();
            }
            $htmlDiskPurged = true;
        } catch (\Throwable) {
        }

        $broadcast = false;
        try {
            $broadcaster = ObjectManager::getInstance(RuntimeControlBroadcasterInterface::class);
            if ($broadcaster instanceof RuntimeControlBroadcasterInterface) {
                if (\method_exists($broadcaster, 'cacheClearAndWait')) {
                    $broadcaster->cacheClearAndWait(null, 5.0);
                } else {
                    $broadcaster->cacheClear(null);
                }
                $broadcast = true;
            }
        } catch (\Throwable) {
        }

        return [
            'pools_cleared' => $cleared,
            'html_disk_purged' => $htmlDiskPurged,
            'broadcast' => $broadcast,
        ];
    }

    /**
     * @return array{ok:bool, message:string, status:array<string, mixed>, nginx?:array<string, mixed>}
     */
    public function rotatePierce(bool $reloadNginx = true): array
    {
        $admin = $this->pierceAdmin();
        if ($admin !== null) {
            $result = $admin->rotateAndPublish($reloadNginx);

            return [
                'ok' => (bool)($result['ok'] ?? false),
                'message' => (string)($result['message'] ?? ''),
                'status' => $this->status(),
                'nginx' => $result['nginx'] ?? [],
                'cdn' => $result['cdn'] ?? [],
            ];
        }

        try {
            FpcPierceStamp::rotateSecret();
            FpcPierceStamp::ensureSecret();
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => $e->getMessage(),
                'status' => $this->status(),
            ];
        }

        return [
            'ok' => true,
            'message' => 'rotated_without_nginx',
            'status' => $this->status(),
            'cdn' => $this->syncCdnPierceRule(),
        ];
    }

    /**
     * 换章后重推 CDN 规则（Cdn 模块可选；失败不影响换章本身）。
     *
     * @return array<string, mixed>
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
     * @return array{ok:bool, message:string, cookie:array<string, mixed>, status:array<string, mixed>}
     */
    public function issuePierceCookie(int $ttlSeconds = 28800): array
    {
        $admin = $this->pierceAdmin();
        if ($admin !== null) {
            $admin->ensurePublished(false);
            $cookie = $admin->issueBrowserCookie($ttlSeconds);

            return [
                'ok' => true,
                'message' => 'cookie_issued',
                'cookie' => $cookie,
                'status' => $this->status(),
            ];
        }

        $secret = FpcPierceStamp::ensureSecret();
        $ttlSeconds = \max(300, \min(86400 * 7, $ttlSeconds));

        return [
            'ok' => true,
            'message' => 'cookie_issued_local',
            'cookie' => [
                'name' => FpcPierceStamp::COOKIE_NAME,
                'value' => $secret,
                'options' => [
                    'expires' => \time() + $ttlSeconds,
                    'path' => '/',
                    'httponly' => true,
                    'samesite' => 'Lax',
                    'secure' => true,
                ],
            ],
            'status' => $this->status(),
        ];
    }

    /**
     * @return array{available:bool, message:string}
     */
    private function pierceAdminStatus(): array
    {
        if ($this->pierceAdmin() === null) {
            return [
                'available' => false,
                'message' => 'server_module_unavailable',
            ];
        }

        return [
            'available' => true,
            'message' => 'server_pierce_admin_ready',
        ];
    }

    private function pierceAdmin(): ?object
    {
        $class = 'Weline\\Server\\Service\\Fpc\\FpcPierceStampAdminService';
        if (!\class_exists($class)) {
            return null;
        }

        try {
            return ObjectManager::getInstance($class);
        } catch (\Throwable) {
            return null;
        }
    }
}
