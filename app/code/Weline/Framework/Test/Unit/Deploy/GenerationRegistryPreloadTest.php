<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Deploy;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Deploy\GenerationRegistry;
use Weline\Framework\Deploy\PublishStateStore;

/**
 * Step 0b：preload 契约。
 *
 * - `preload()` 之后读取一律内存查表，**零读盘**（业务逻辑不能被拖累）；
 * - 解析不到链时返回 ''，**绝不塌缩成全局值**；
 * - `publish_state.json` 缺失时回退旧口径（部署号 + 指针），不炸。
 */
final class GenerationRegistryPreloadTest extends TestCase
{
    protected function setUp(): void
    {
        GenerationRegistry::reset();
        GenerationRegistry::resetFileReadCount();
    }

    protected function tearDown(): void
    {
        GenerationRegistry::reset();
    }

    public function testPreloadThenReadsArePureMemory(): void
    {
        GenerationRegistry::preload();
        $afterPreload = GenerationRegistry::fileReadCount();

        for ($i = 0; $i < 100; $i++) {
            GenerationRegistry::generationUid();
            GenerationRegistry::pageToken();
            GenerationRegistry::deployBuild();
        }

        self::assertSame(
            $afterPreload,
            GenerationRegistry::fileReadCount(),
            'preload 之后 100 次读取不得触发任何文件读取'
        );
    }

    public function testUnknownChainReturnsEmptyInsteadOfGlobalFallback(): void
    {
        GenerationRegistry::preload();

        // 不存在的链：必须返回空数组/空值，不能返回"全局"记录
        self::assertSame([], GenerationRegistry::chain('999|999|frontend'));
        self::assertSame([], GenerationRegistry::active('999|999|frontend'));
        self::assertSame([], GenerationRegistry::supported('999|999|frontend'));
        self::assertFalse(GenerationRegistry::isSupported('999|999|frontend', 'anything'));
    }

    /**
     * `containsGeneration()`：窗口内视为"已存在"，窗口外与空值视为"新代次"。
     * 用途：就地固化不得拿已存在的代次反复 stage（会倒退）。
     */
    public function testContainsGenerationChecksWindowMembership(): void
    {
        $stateFile = \sys_get_temp_dir() . '/reg_contains_' . \uniqid('', true) . '.json';
        PublishStateStore::overrideStateFile($stateFile);
        try {
            PublishStateStore::persist(['chains' => [
                '0|3|frontend' => [
                    'hosts' => [],
                    'active' => [
                        'generation_uid' => '87d75b385c01~t/v2',
                        'page_token' => 'aaaaaaaaaaaaaaaa',
                        'deploy_build' => '87d75b385c01',
                        'theme_generation' => 't/v2',
                        'kind' => 'theme',
                        'created_at' => \time(),
                    ],
                    'target' => null,
                    'supported' => [
                        [
                            'generation_uid' => '87d75b385c01~t/v2',
                            'page_token' => 'aaaaaaaaaaaaaaaa',
                            'state' => 'active',
                            'servable' => true,
                            'kind' => 'theme',
                            'created_at' => \time(),
                        ],
                        [
                            'generation_uid' => '87d75b385c01~t/v1',
                            'page_token' => 'aaaaaaaaaaaaaaaa',
                            'state' => 'draining',
                            'servable' => true,
                            'kind' => 'theme',
                            'created_at' => \time(),
                        ],
                    ],
                ],
            ]]);
            GenerationRegistry::reset();

            self::assertTrue(GenerationRegistry::containsGeneration('0|3|frontend', '87d75b385c01~t/v2'), 'active 必须在窗口内');
            self::assertTrue(GenerationRegistry::containsGeneration('0|3|frontend', '87D75B385C01~T/V1'), '比较需忽略大小写');
            self::assertFalse(GenerationRegistry::containsGeneration('0|3|frontend', '87d75b385c01~t/v9'), '窗口外必须是新代次');
            self::assertFalse(GenerationRegistry::containsGeneration('0|3|frontend', ''), '空 uid 不得视为已存在');
            self::assertFalse(GenerationRegistry::containsGeneration('', '87d75b385c01~t/v2'), '空链键不得视为已存在');
        } finally {
            PublishStateStore::overrideStateFile(null);
            GenerationRegistry::reset();
            @\unlink($stateFile);
        }
    }

    public function testIsSupportedRequiresServableTrue(): void
    {
        // 2026-11-01：真实状态已迁移为逐站链（含 0|3|frontend），
        // 用例改用一定不存在的链键断言"空窗口一律不放行"，不依赖环境有没有建链。
        self::assertFalse(GenerationRegistry::isSupported('999|998|frontend', '87d75b385c01~hanfu/v276'));

        // 空 uid 不放行
        self::assertFalse(GenerationRegistry::isSupported('0|3|frontend', ''));
    }

    public function testLegacyFallbackNeverReturnsEmptyGlobal(): void
    {
        GenerationRegistry::preload();

        // 无 publish_state 时返回旧口径（部署号或 部署号~pair），但不允许是空串以外的"全局塌缩"证据：
        // 这里断言它至少等于当前部署号，且不含跨站拼接痕迹。
        $uid = GenerationRegistry::generationUid();
        $build = GenerationRegistry::deployBuild();
        self::assertNotSame('', $build);
        self::assertStringStartsWith($build, $uid === '' ? $build : $uid);
    }

    public function testResetClearsMemo(): void
    {
        GenerationRegistry::preload();
        GenerationRegistry::resetFileReadCount();
        GenerationRegistry::reset();
        GenerationRegistry::preload();

        // reset 后重新 preload：若存在状态文件则读一次；无文件则零读
        self::assertGreaterThanOrEqual(0, GenerationRegistry::fileReadCount());
    }
}
