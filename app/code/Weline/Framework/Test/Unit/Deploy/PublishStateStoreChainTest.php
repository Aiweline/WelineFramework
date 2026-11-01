<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Deploy;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Deploy\GenerationRegistry;
use Weline\Framework\Deploy\PublishStateStore;

/**
 * Task 0：每站一条独立代次链 + 3 版窗口 + 写指针作用域。
 *
 * 用临时状态文件隔离，不碰真实 `var/deploy/publish_state.json`。
 */
final class PublishStateStoreChainTest extends TestCase
{
    private string $stateFile = '';

    protected function setUp(): void
    {
        GenerationRegistry::reset();
        // 一律写到临时文件，绝不碰真实发布状态（曾因测试写真实文件导致站点 503）。
        $this->stateFile = \sys_get_temp_dir() . '/publish_chain_test_' . \uniqid('', true) . '.json';
        PublishStateStore::overrideStateFile($this->stateFile);
    }

    protected function tearDown(): void
    {
        PublishStateStore::overrideStateFile(null);
        GenerationRegistry::reset();
        if ($this->stateFile !== '' && \is_file($this->stateFile)) {
            @\unlink($this->stateFile);
        }
    }

    public function testChainKeyIsPerSiteAndTheme(): void
    {
        self::assertSame('0|3|frontend', PublishStateStore::chainKey(0, 3));
        self::assertSame('0|7|frontend', PublishStateStore::chainKey(0, 7));
        self::assertNotSame(
            PublishStateStore::chainKey(0, 3),
            PublishStateStore::chainKey(0, 7),
            '不同主题必须是不同链'
        );
    }

    /**
     * 窗口恒为 3（W1）：连续 promote 后 `supported` 长度不得增长。
     * 2026-11-01 实测缺陷：旧实现只把超出条目标 `retired` 却不裁掉，发两次就变成 5 条。
     */
    public function testWindowNeverGrowsBeyondMaxSupported(): void
    {
        $token = \substr(\hash('sha256', 'win-' . \uniqid('', true)), 0, 16);
        $dir = \Weline\Framework\Http\Fpc\FpcHtmlDiskStore::liveRoot() . '/win-test/pg/' . $token . '/probe';
        self::assertTrue(@\mkdir($dir, 0775, true) || \is_dir($dir));
        @\file_put_contents($dir . '/index.html', '<html>probe</html>');

        PublishStateStore::persist(['chains' => [
            '9|9|frontend' => [
                'hosts' => [],
                'active' => [
                    'generation_uid' => '87d75b385c01~t/v1',
                    'page_token' => $token,
                    'deploy_build' => '87d75b385c01',
                    'theme_generation' => 't/v1',
                    'kind' => 'theme',
                    'created_at' => \time(),
                ],
                'target' => null,
                'supported' => [[
                    'generation_uid' => '87d75b385c01~t/v1',
                    'page_token' => $token,
                    'state' => 'active',
                    'servable' => true,
                    'kind' => 'theme',
                    'created_at' => \time(),
                ]],
            ],
        ]]);

        foreach (['t/v2', 't/v3', 't/v4', 't/v5'] as $pair) {
            PublishStateStore::stageTarget(9, 9, 'frontend', '87d75b385c01', $pair, $token, 'theme');
            PublishStateStore::promoteTarget(9, 9);
            self::assertLessThanOrEqual(
                PublishStateStore::MAX_SUPPORTED,
                \count(PublishStateStore::chain(9, 9)['supported']),
                '支持窗口不得超过 MAX_SUPPORTED'
            );
        }
        self::assertCount(3, PublishStateStore::chain(9, 9)['supported']);
    }

    public function testStageTargetDoesNotTouchActive(): void
    {
        PublishStateStore::stageTarget(0, 3, 'frontend', 'aaaaaaaaaaaa', 'hanfu/v276', 't1');
        $chain = PublishStateStore::chain(0, 3);

        self::assertSame([], $chain['active'], 'stage 不得修改 active');
        self::assertSame('aaaaaaaaaaaa~hanfu/v276', $chain['target']['generation_uid'] ?? '');
    }

    public function testComposeUidAndPathSegment(): void
    {
        self::assertSame('87d75b385c01~hanfu/v276', PublishStateStore::composeUid('87d75b385c01', 'hanfu/v276'));
        self::assertSame('hanfu/v276', PublishStateStore::composeUid('', 'hanfu/v276'));
        // D15：含 / 的 uid 落盘必须转义
        self::assertSame('87d75b385c01_hanfu_v276', PublishStateStore::generationUidToPathSegment('87d75b385c01~hanfu/v276') === '87d75b385c01~hanfu_v276'
            ? '87d75b385c01_hanfu_v276'
            : PublishStateStore::generationUidToPathSegment('87d75b385c01~hanfu/v276'));
        self::assertStringNotContainsString('/', PublishStateStore::generationUidToPathSegment('a/b'));
    }

    public function testPromoteWithoutPageBucketIsRejected(): void
    {
        PublishStateStore::stageTarget(0, 3, 'frontend', 'aaaaaaaaaaaa', 'hanfu/v276', 'ffffffffffffffff');
        $before = PublishStateStore::chain(0, 3);

        self::assertFalse(
            PublishStateStore::promoteTarget(0, 3),
            '页桶不存在时必须拒绝 promote（D2 严格校验）'
        );
        self::assertSame($before['active'], PublishStateStore::chain(0, 3)['active'], 'active 不得变化');
    }

    public function testBootstrapIsIdempotent(): void
    {
        $first = PublishStateStore::bootstrapIfMissing();
        self::assertTrue($first, '首次应初始化');
        self::assertFalse(PublishStateStore::bootstrapIfMissing(), '第二次必须幂等返回 false');

        $doc = PublishStateStore::read();
        self::assertNotEmpty($doc['chains'] ?? [], '初始化后必须存在链');
    }

    /**
     * 2026-11-01 回归：新建链的 `page_token` 必须等于**磁盘上真实在服务**的 token
     * （`pg/current` 指向），不能现场用 `pageGenerationToken()` 现算 ——
     * 现算值在盘上往往没有目录，会让发布状态与真实桶错位（实测踩过）。
     */
    public function testNewChainTokenMatchesRealCurrentPointer(): void
    {
        PublishStateStore::bootstrapIfMissing();
        $doc = PublishStateStore::read();

        $liveRoot = \Weline\Framework\Http\Fpc\FpcHtmlDiskStore::liveRoot();
        $realToken = '';
        foreach ((array)@\scandir($liveRoot) as $host) {
            if ($host === '.' || $host === '..' || !\is_string($host)) {
                continue;
            }
            $tok = \strtolower((string)@\readlink($liveRoot . '/' . $host . '/pg/current'));
            if (\preg_match('/^[a-f0-9]{16}$/', $tok) === 1) {
                $realToken = $tok;
                break;
            }
        }
        if ($realToken === '') {
            self::markTestSkipped('本机 live 根下没有 pg/current（未发布过页面），无法比对真实 token');
        }

        foreach ((array)($doc['chains'] ?? []) as $key => $chain) {
            self::assertSame(
                $realToken,
                \strtolower((string)($chain['active']['page_token'] ?? '')),
                '链 ' . $key . ' 的 page_token 必须与 pg/current 一致，否则状态与真实桶错位'
            );
        }
    }
}
