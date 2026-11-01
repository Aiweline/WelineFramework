<?php

declare(strict_types=1);

namespace Weline\Theme\Service\ThemeUpdate;

use Weline\Framework\Deploy\GenerationRegistry;
use Weline\Framework\Deploy\PublishStateStore;
use Weline\Framework\Http\Fpc\FpcHtmlDiskStore;

/**
 * 主题端发布（Task 0.2）：主题发版 / 回滚 / 切版本。
 *
 * 契约（定论 14/15）：
 * - 主题端**只改 `theme_generation`**，不动 `deploy_build`；
 * - 一切变更都必须经 `promote()` 落到 `active`，不允许只改 DB/只改指针；
 * - 回滚前校验目标代次仍可服务（页桶在），否则拒绝，避免回滚到 404（D10）。
 */
final class ThemeReleaseManager
{
    /** 回滚专用 kind：promoteTarget 单调保护只对"普通发版"生效，回滚可显式倒退。 */
    public const ROLLBACK_KIND = 'rollback';

    /** @return array{staged:bool,generation_uid:string} */
    public static function stage(
        int $themeId,
        int $websiteId,
        string $area,
        string $pairSegment,
        string $kind = 'theme',
        string $pageToken = ''
    ): array {
        $pair = \trim($pairSegment);
        if ($themeId < 1 || $pair === '') {
            return ['staged' => false, 'generation_uid' => ''];
        }
        $deployBuild = GenerationRegistry::deployBuild();
        $token = \strtolower(\trim($pageToken));
        if ($token === '') {
            $token = FpcHtmlDiskStore::pageGenerationToken();
        }
        PublishStateStore::stageTarget($websiteId, $themeId, $area, $deployBuild, $pair, $token, $kind);

        return ['staged' => true, 'generation_uid' => PublishStateStore::composeUid($deployBuild, $pair)];
    }

    /** 切该链 active（校验产物就绪；失败保持旧 active）。 */
    public static function promote(int $themeId, int $websiteId, string $area): bool
    {
        return PublishStateStore::promoteTarget($websiteId, $themeId, $area);
    }

    /**
     * 回滚到指定代次：先校验该代次的页桶仍存在且窗口内仍 servable，再切回。
     * 目标不可服务时返回 false（不改变 active）。
     */
    public static function rollback(int $themeId, int $websiteId, string $area, string $generationUid): bool
    {
        $uid = \trim($generationUid);
        if ($uid === '') {
            return false;
        }
        $chain = PublishStateStore::chain($websiteId, $themeId, $area);
        $target = null;
        foreach ($chain['supported'] as $item) {
            if (\strcasecmp((string)($item['generation_uid'] ?? ''), $uid) === 0) {
                $target = $item;
                break;
            }
        }
        if ($target === null || ($target['servable'] ?? true) === false) {
            return false;
        }
        $token = \strtolower(\trim((string)($target['page_token'] ?? '')));
        if ($token === '') {
            return false;
        }
        // 回滚目标就是当前 active：幂等成功，不重复 promote（重复 promote 会无谓推进窗口）。
        $activeUid = (string)($chain['active']['generation_uid'] ?? '');
        if (\strcasecmp($activeUid, $uid) === 0) {
            return true;
        }
        PublishStateStore::stageTarget(
            $websiteId,
            $themeId,
            $area,
            GenerationRegistry::deployBuild(),
            self::generationPartOf($uid),
            $token,
            self::ROLLBACK_KIND
        );

        return PublishStateStore::promoteTarget($websiteId, $themeId, $area);
    }

    private static function generationPartOf(string $uid): string
    {
        $pos = \strpos($uid, '~');

        return $pos === false ? $uid : \substr($uid, $pos + 1);
    }
}
