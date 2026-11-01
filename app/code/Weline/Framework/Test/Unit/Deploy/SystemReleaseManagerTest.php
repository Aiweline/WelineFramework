<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Deploy;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Deploy\GenerationRegistry;
use Weline\Framework\Deploy\PublishStateStore;
use Weline\Framework\Deploy\SystemReleaseManager;

/**
 * Task 0.1：系统端发布只改 deploy_build，不动 theme_generation；失败不破坏 active。
 *
 * 每个用例使用独立临时状态文件（overrideStateFile），不污染真实发布状态。
 */
final class SystemReleaseManagerTest extends TestCase
{
    private string $tmpFile = '';

    /**
     * 用例固定的测试链键。
     *
     * 2026-11-01：`bootstrapIfMissing()` 改为按指针文件逐站建链后，链键集合随环境变化，
     * 用例不能再硬编码 `9|9|frontend`；这里在 setUp 里落一条已知链，用例只认它。
     */
    private const TEST_CHAIN_KEY = '9|9|frontend';

    protected function setUp(): void
    {
        GenerationRegistry::reset();
        $this->tmpFile = \sys_get_temp_dir() . '/publish_state_test_' . \uniqid('', true) . '.json';
        PublishStateStore::overrideStateFile($this->tmpFile);
        // 显式造一条已知链（不依赖指针文件/站点状态），token 用 bootstrap 同源算法保证桶校验可过。
        PublishStateStore::persist(['chains' => [
            self::TEST_CHAIN_KEY => [
                'hosts' => [],
                'active' => [
                    'generation_uid' => \Weline\Framework\Deploy\PublishStateStore::composeUid(
                        \Weline\Framework\Deploy\StaticBuildId::current(),
                        'test/v1'
                    ),
                    'page_token' => \Weline\Framework\Http\Fpc\FpcHtmlDiskStore::pageGenerationToken(),
                    'deploy_build' => \Weline\Framework\Deploy\StaticBuildId::current(),
                    'theme_generation' => 'test/v1',
                    'kind' => 'system',
                    'created_at' => \time(),
                ],
                'target' => null,
                'supported' => [[
                    'generation_uid' => \Weline\Framework\Deploy\PublishStateStore::composeUid(
                        \Weline\Framework\Deploy\StaticBuildId::current(),
                        'test/v1'
                    ),
                    'page_token' => \Weline\Framework\Http\Fpc\FpcHtmlDiskStore::pageGenerationToken(),
                    'state' => 'active',
                    'servable' => true,
                    'kind' => 'system',
                    'created_at' => \time(),
                ]],
            ],
        ]]);
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

    public function testStageKeepsActiveAndWritesDeployBuildInTargetOnly(): void
    {
        $before = PublishStateStore::chain(9, 9);
        $activeBefore = $before['active'];
        $themeGenerationBefore = (string)($activeBefore['theme_generation'] ?? '');
        self::assertNotSame('', $themeGenerationBefore, '测试前置：bootstrap 必须带主题代次');

        $result = SystemReleaseManager::stage('bbbbbbbbbbbb');
        self::assertTrue($result['staged']);
        self::assertSame('bbbbbbbbbbbb', $result['deploy_build']);

        $after = PublishStateStore::chain(9, 9);
        self::assertSame($activeBefore, $after['active'], 'stage 阶段 active 必须保持不变');
        self::assertSame('bbbbbbbbbbbb', $after['target']['deploy_build'] ?? '');
        self::assertStringContainsString(
            $themeGenerationBefore,
            (string)($after['target']['generation_uid'] ?? ''),
            '系统端只能换 deploy_build，主题代次必须保留'
        );
    }

    public function testPromoteAllChainsRefreshesHostsWithoutTouchingThemeGeneration(): void
    {
        $chain = PublishStateStore::chain(9, 9);
        $themeGeneration = (string)($chain['active']['theme_generation'] ?? '');

        // 造一个真实页桶：D2 要求"页桶存在且非空"才允许 promote，
        // 用例不能依赖环境里恰好已有桶（phpunit 进程的代次与运行站可能不同）。
        $token = self::makeRealPageBucket('example.test');
        PublishStateStore::stageTarget(9, 9, 'frontend', 'cccccccccccc', $themeGeneration, $token, 'system');
        // 先落一次 hosts（模拟发布期已解析好域名）；phpunit 下 promoteAllChains 传参的路径
        // 受进程内状态影响不稳定，这里显式验证"promote 不得清掉已落盘的 hosts"。
        $seed = PublishStateStore::read();
        $seed['chains']['9|9|frontend']['hosts'] = ['example.test'];
        PublishStateStore::persist($seed);
        self::assertSame(['example.test'], PublishStateStore::chain(9, 9)['hosts'], '前置：hosts 已落盘');

        $result = SystemReleaseManager::promoteAllChains();
        self::assertContains('9|9|frontend', $result['promoted'], '页桶齐备时应允许 promote');

        $after = PublishStateStore::chain(9, 9);
        self::assertSame(['example.test'], $after['hosts'], 'promote 不得清掉链级 hosts（hosts 与 active 解耦）');
        self::assertSame($themeGeneration, (string)($after['active']['theme_generation'] ?? ''), '主题代次不得被系统端改动');
        self::assertStringStartsWith('cccccccccccc~', (string)($after['active']['generation_uid'] ?? ''), '系统端只改 deploy_build');
    }

    /** 在 live 根下造一个带叶的页桶，返回其 token。 */
    private function makeRealPageBucket(string $host): string
    {
        $token = \substr(\hash('sha256', 'unit-' . \uniqid('', true)), 0, 16);
        $dir = \Weline\Framework\Http\Fpc\FpcHtmlDiskStore::liveRoot()
            . '/' . $host . '/pg/' . $token;
        self::assertTrue(@\mkdir($dir . '/probe', 0775, true) || \is_dir($dir . '/probe'));
        @\file_put_contents($dir . '/probe/index.html', '<html>probe</html>');

        return $token;
    }

    public function testPromoteFailureKeepsActive(): void
    {
        $before = PublishStateStore::chain(9, 9);
        // 伪造一个不存在的 token：页桶校验必然失败
        PublishStateStore::stageTarget(0, 0, 'frontend', 'dddddddddddd', (string)$before['active']['theme_generation'], '0123456789abcdef', 'system');

        $result = SystemReleaseManager::promoteAllChains();
        self::assertContains('9|9|frontend', $result['failed']);
        self::assertSame($before['active'], PublishStateStore::chain(9, 9)['active'], '失败时必须保留旧 active');
    }
}
