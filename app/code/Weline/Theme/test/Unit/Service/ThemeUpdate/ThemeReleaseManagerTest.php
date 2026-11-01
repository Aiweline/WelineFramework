<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\ThemeUpdate;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Deploy\GenerationRegistry;
use Weline\Framework\Deploy\PublishStateStore;
use Weline\Theme\Service\ThemeUpdate\ThemeReleaseManager;

/**
 * Task 0.2：主题发版 / 回滚 / 切版本必须经权威号（定论 15 的另一半入口）。
 *
 * 验收点：
 *  - 主题发版后 `generation_uid` 变化且 active 前进；
 *  - **回滚到目标代次桶已被删** → 返回 false 且 active 不变（防回滚到 404）；
 *  - 回滚成功 → `generation_uid` 回到目标值。
 */
final class ThemeReleaseManagerTest extends TestCase
{
    private string $tmpFile = '';

    protected function setUp(): void
    {
        GenerationRegistry::reset();
        $this->tmpFile = \sys_get_temp_dir() . '/theme_release_test_' . \uniqid('', true) . '.json';
        PublishStateStore::overrideStateFile($this->tmpFile);
        PublishStateStore::bootstrapIfMissing();
        GenerationRegistry::reset();
    }

    protected function tearDown(): void
    {
        PublishStateStore::overrideStateFile(null);
        GenerationRegistry::reset();
        if ($this->tmpFile !== '' && \is_file($this->tmpFile)) {
            @\unlink($this->tmpFile);
        }
    }

    public function testStageRejectsEmptyPair(): void
    {
        $result = ThemeReleaseManager::stage(3, 0, 'frontend', '');
        self::assertFalse($result['staged'], '空 pair_segment 不得 stage');
        self::assertSame('', $result['generation_uid']);
    }

    public function testStageComposesUidFromDeployBuildAndPair(): void
    {
        $result = ThemeReleaseManager::stage(3, 0, 'frontend', 'hanfu/v999');
        self::assertTrue($result['staged']);
        $deployBuild = GenerationRegistry::deployBuild();
        self::assertStringStartsWith($deployBuild . '~', $result['generation_uid']);

        $chain = PublishStateStore::chain(0, 3);
        self::assertSame('hanfu/v999', $chain['target']['theme_generation'] ?? '', 'stage 只写 target，不动 active');
        self::assertSame($deployBuild, $chain['target']['deploy_build'] ?? '', '主题端不得改 deploy_build');
    }

    public function testRollbackRejectsWhenTargetNotServable(): void
    {
        // 空窗口 / 不存在的代次 → 必须拒绝，且 active 不变
        $before = PublishStateStore::chain(0, 3)['active'];
        $ok = ThemeReleaseManager::rollback(3, 0, 'frontend', 'deadbeefdead~hanfu/v000');
        self::assertFalse($ok, '目标代次不在窗口内必须拒绝回滚');
        self::assertSame($before, PublishStateStore::chain(0, 3)['active'], 'active 不得变化');
    }

    public function testRollbackToServableWindowItemSwitchesActive(): void
    {
        // 2026-11-01：bootstrap 改为按指针文件逐站建链后，链键集合随环境变化，
        // 用例显式造一条已知链（不依赖站点状态）。
        $uid = PublishStateStore::composeUid(
            \Weline\Framework\Deploy\StaticBuildId::current(),
            'rollback/v7'
        );
        $token = \Weline\Framework\Http\Fpc\FpcHtmlDiskStore::pageGenerationToken();
        PublishStateStore::persist(['chains' => [
            '9|9|frontend' => [
                'hosts' => [],
                'active' => [
                    'generation_uid' => $uid,
                    'page_token' => $token,
                    'deploy_build' => \Weline\Framework\Deploy\StaticBuildId::current(),
                    'theme_generation' => 'rollback/v7',
                    'kind' => 'theme',
                    'created_at' => \time(),
                ],
                'target' => null,
                'supported' => [[
                    'generation_uid' => $uid,
                    'page_token' => $token,
                    'state' => 'active',
                    'servable' => true,
                    'kind' => 'theme',
                    'created_at' => \time(),
                ]],
            ],
        ]]);
        GenerationRegistry::reset();

        $activeUid = (string)(PublishStateStore::chain(9, 9)['active']['generation_uid'] ?? '');
        self::assertSame($uid, $activeUid, '测试前置：已知链必须就位');

        // 把当前 active 作为窗口内可服务目标回滚（等价于"切回该版本"）：必须幂等成功
        $ok = ThemeReleaseManager::rollback(9, 9, 'frontend', $activeUid);
        self::assertTrue($ok, '窗口内 servable 的目标应允许切回');
        self::assertSame(
            $activeUid,
            (string)(PublishStateStore::chain(9, 9)['active']['generation_uid'] ?? ''),
            '回滚后 generation_uid 必须等于目标代次'
        );
    }
}
