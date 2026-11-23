<?php

declare(strict_types=1);

namespace Weline\Framework\Deploy;

/**
 * 系统端发布（Task 0.1）：`s:up` / `healBuild` 之后推进部署号。
 *
 * 契约（定论 14/15）：
 * - 系统端**只改 `deploy_build`**，不动 `theme_generation`；
 * - 阶段化：`stage()` 只写 target，`promoteAllChains()` 校验后逐链切 active；
 * - 系统更新需**刷新每条链的 hosts**（站点增删域名），再逐链 promote（B3）；
 * - 失败时 active 保持旧值（站点继续服务旧版），由调用方决定退出码（D4）。
 */
final class SystemReleaseManager
{
    /** @return array{staged:bool,deploy_build:string,chains:int} */
    public static function stage(string $deployBuild = ''): array
    {
        $build = \strtolower(\trim($deployBuild));
        if ($build === '') {
            $build = \strtolower((string)StaticBuildId::current());
        }
        if ($build === '') {
            return ['staged' => false, 'deploy_build' => '', 'chains' => 0];
        }
        $doc = PublishStateStore::read();
        $chains = \is_array($doc['chains'] ?? null) ? $doc['chains'] : [];
        if ($chains === []) {
            PublishStateStore::bootstrapIfMissing();
            $doc = PublishStateStore::read();
            $chains = \is_array($doc['chains'] ?? null) ? $doc['chains'] : [];
        }
        $count = 0;
        foreach ($chains as $key => $row) {
            if (!\is_array($row)) {
                continue;
            }
            [$websiteId, $themeId, $area] = self::parseChainKey((string)$key);
            $active = \is_array($row['active'] ?? null) ? $row['active'] : [];
            $themeGeneration = \trim((string)($active['theme_generation'] ?? ''));
            if ($themeGeneration === '') {
                continue;
            }
            // 复用该链**当前已在服务的页桶**：系统更新只换 deploy_build，
            // 新桶由后续渲染按新 token 产出。若这里现场用 pageGenerationToken() 现算，
            // 会得到一个磁盘上还不存在的桶 → 严格校验必然拒绝 promote（实测踩过）。
            $pageToken = \strtolower(\trim((string)($active['page_token'] ?? '')));
            if ($pageToken === '') {
                $pageToken = FpcHtmlDiskStore::pageGenerationToken();
            }
            // 自愈：active.page_token 可能因页桶被清理/世代轮换而失效（promote 必然失败的死循环）。
            // 校验桶真实存在；不存在时回退到该链 hosts 当前 pg/current 实际指向的 token。
            $chainHosts = \is_array($row['hosts'] ?? null) ? $row['hosts'] : [];
            if ($pageToken !== '' && !PublishStateStore::pageBucketPopulated($pageToken, $chainHosts)) {
                $liveToken = self::currentServedPageToken($chainHosts);
                if ($liveToken !== '' && PublishStateStore::pageBucketPopulated($liveToken, $chainHosts)) {
                    $pageToken = $liveToken;
                }
            }
            PublishStateStore::stageTarget(
                $websiteId,
                $themeId,
                $area,
                $build,
                $themeGeneration,
                $pageToken,
                'system'
            );
            $count++;
        }

        return ['staged' => $count > 0, 'deploy_build' => $build, 'chains' => $count];
    }

    /**
     * 系统更新完成：刷新 hosts 后逐链 promote。
     *
     * 若某链还没有 target（未 stage），先按当前部署号自动 stage 一次，
     * 保证"系统更新后所有链一起前进"无需调用方逐个 stage（B3）。
     *
     * @param array<string,list<string>> $hostsByChain 链键 => 域名列表（发布期解析，请求期不查库）
     * @return array{ok:bool,promoted:list<string>,failed:list<string>}
     */
    public static function promoteAllChains(array $hostsByChain = []): array
    {
        $doc = PublishStateStore::read();
        $chains = \is_array($doc['chains'] ?? null) ? $doc['chains'] : [];
        $changed = false;
        foreach ($hostsByChain as $key => $hosts) {
            if (!\is_array($chains[$key] ?? null) || !\is_array($hosts)) {
                continue;
            }
            $chains[$key]['hosts'] = \array_values(\array_map('strval', $hosts));
            $changed = true;
        }
        if ($changed) {
            PublishStateStore::persist($doc);
        }

        $deployBuild = \strtolower((string)StaticBuildId::current());
        $promoted = [];
        $failed = [];
        foreach (\array_keys($chains) as $key) {
            [$websiteId, $themeId, $area] = self::parseChainKey((string)$key);
            $chain = PublishStateStore::chain($websiteId, $themeId, $area);
            if (($chain['target'] ?? null) === null) {
                // 未 stage 的链：按当前部署号补齐 target（主题代次沿用 active）
                $active = $chain['active'];
                $themeGeneration = \trim((string)($active['theme_generation'] ?? ''));
                $token = \strtolower(\trim((string)($active['page_token'] ?? '')));
                if ($themeGeneration !== '' && $token !== '' && $deployBuild !== '') {
                    PublishStateStore::stageTarget($websiteId, $themeId, $area, $deployBuild, $themeGeneration, $token, 'system');
                }
            }
            if (PublishStateStore::promoteTarget($websiteId, $themeId, $area)) {
                $promoted[] = (string)$key;
            } else {
                $failed[] = (string)$key;
            }
        }

        return ['ok' => $failed === [], 'promoted' => $promoted, 'failed' => $failed];
    }

    /**
     * 该链 hosts 当前 `pg/current` 符号链接实际指向的页桶 token（站点此刻真实在服务的代次）。
     * 多 host 时取第一个有效指向；都取不到返回 ''。
     *
     * @param array<int,mixed> $hosts
     */
    private static function currentServedPageToken(array $hosts): string
    {
        try {
            $liveRoot = FpcHtmlDiskStore::liveRoot();
        } catch (\Throwable) {
            return '';
        }
        $candidates = [];
        foreach ($hosts as $host) {
            $host = \strtolower(\trim((string)$host));
            if ($host !== '' && \preg_match('/^[a-z0-9._:-]+$/D', $host) === 1) {
                $candidates[] = $host;
            }
        }
        // hosts 未登记时回退扫描 liveRoot 下全部 host（与 pageBucketExists 的兜底口径一致）
        if ($candidates === []) {
            foreach ((array)@\scandir($liveRoot) as $name) {
                if ($name !== '.' && $name !== '..' && \is_string($name)
                    && \preg_match('/^[a-z0-9._:-]+$/D', $name) === 1
                    && \is_dir($liveRoot . \DIRECTORY_SEPARATOR . $name)) {
                    $candidates[] = $name;
                }
            }
        }
        foreach ($candidates as $host) {
            $link = $liveRoot . \DIRECTORY_SEPARATOR . $host
                . \DIRECTORY_SEPARATOR . FpcHtmlDiskStore::PAGE_GEN_DIR
                . \DIRECTORY_SEPARATOR . FpcHtmlDiskStore::PAGE_GEN_CURRENT;
            $target = @\readlink($link);
            if (!\is_string($target) || $target === '') {
                continue;
            }
            // readlink 可能是相对路径（如 ../d3f9…）或绝对路径
            $token = \basename(\rtrim($target, '/\\'));
            if ($token !== '' && $token !== '.' && $token !== '..') {
                return \strtolower($token);
            }
        }
        return '';
    }

    /** @return array{0:int,1:int,2:string} */
    private static function parseChainKey(string $key): array
    {
        $parts = \explode('|', $key);
        if (\count($parts) !== 3) {
            return [0, 0, PublishStateStore::AREA_FRONTEND];
        }

        return [(int)$parts[0], (int)$parts[1], (string)$parts[2]];
    }
}
