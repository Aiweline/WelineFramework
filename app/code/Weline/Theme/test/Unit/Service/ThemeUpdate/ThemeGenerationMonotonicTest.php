<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\ThemeUpdate;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Deploy\GenerationRegistry;
use Weline\Framework\Deploy\PublishStateStore;

/**
 * 单调保护（2026-11-01 实测缺陷回归）：
 *
 * 普通发版**只允许推进**到新代次。若把窗口内的旧代次再 stage 一次并 promote，
 * 必须被拒绝；否则会出现"版本倒退"（实测 `theme:update` 把 active 从 v277 退回 v276）。
 * 真正的回滚走 `kind=rollback`，允许显式倒退。
 */
final class ThemeGenerationMonotonicTest extends TestCase
{
    private string $tmpFile = '';
    private const CHAIN_KEY = '9|9|frontend';

    protected function setUp(): void
    {
        GenerationRegistry::reset();
        $this->tmpFile = \sys_get_temp_dir() . '/monotonic_test_' . \uniqid('', true) . '.json';
        PublishStateStore::overrideStateFile($this->tmpFile);
    }

    protected function tearDown(): void
    {
        PublishStateStore::overrideStateFile(null);
        GenerationRegistry::reset();
        if ($this->tmpFile !== '' && \is_file($this->tmpFile)) {
            @\unlink($this->tmpFile);
        }
    }

    /** 造一条链，active 与窗口内容由参数决定。 */
    private function seedChain(string $activeUid, array $supportedUids, string $token): void
    {
        $supported = [];
        foreach ($supportedUids as $uid) {
            $supported[] = [
                'generation_uid' => $uid,
                'page_token' => $token,
                'state' => $uid === $activeUid ? 'active' : 'draining',
                'servable' => true,
                'kind' => 'theme',
                'created_at' => \time(),
            ];
        }
        PublishStateStore::persist(['chains' => [
            self::CHAIN_KEY => [
                'hosts' => [],
                'active' => [
                    'generation_uid' => $activeUid,
                    'page_token' => $token,
                    'deploy_build' => '87d75b385c01',
                    'theme_generation' => 'x',
                    'kind' => 'theme',
                    'created_at' => \time(),
                ],
                'target' => null,
                'supported' => $supported,
            ],
        ]]);
        GenerationRegistry::reset();
    }

    public function testPromoteToOldWindowEntryIsRejected(): void
    {
        $token = self::makeBucket();
        $this->seedChain('87d75b385c01~t/v3', ['87d75b385c01~t/v3', '87d75b385c01~t/v2', '87d75b385c01~t/v1'], $token);

        PublishStateStore::stageTarget(9, 9, 'frontend', '87d75b385c01', 't/v2', $token, 'theme');
        self::assertFalse(
            PublishStateStore::promoteTarget(9, 9),
            '普通发版不得把 active 切回窗口内旧代次'
        );
        self::assertSame('87d75b385c01~t/v3', PublishStateStore::chain(9, 9)['active']['generation_uid']);
    }

    public function testRollbackKindMayMoveBackwards(): void
    {
        $token = self::makeBucket();
        $this->seedChain('87d75b385c01~t/v3', ['87d75b385c01~t/v3', '87d75b385c01~t/v2'], $token);

        PublishStateStore::stageTarget(9, 9, 'frontend', '87d75b385c01', 't/v2', $token, 'rollback');
        self::assertTrue(
            PublishStateStore::promoteTarget(9, 9),
            '显式回滚（kind=rollback）必须允许倒退'
        );
    }

    public function testPromoteToNewGenerationStillWorks(): void
    {
        $token = self::makeBucket();
        $this->seedChain('87d75b385c01~t/v1', ['87d75b385c01~t/v1'], $token);

        PublishStateStore::stageTarget(9, 9, 'frontend', '87d75b385c01', 't/v2', $token, 'theme');
        self::assertTrue(PublishStateStore::promoteTarget(9, 9), '推进到新代次必须放行');
        self::assertSame('87d75b385c01~t/v2', PublishStateStore::chain(9, 9)['active']['generation_uid']);
    }

    /** 在 live 根下造一个有叶的页桶，返回 token。 */
    private static function makeBucket(): string
    {
        $token = \substr(\hash('sha256', 'monotonic-' . \uniqid('', true)), 0, 16);
        $dir = \Weline\Framework\Http\Fpc\FpcHtmlDiskStore::liveRoot()
            . '/monotonic-test/pg/' . $token . '/probe';
        self::assertTrue(@\mkdir($dir, 0775, true) || \is_dir($dir));
        @\file_put_contents($dir . '/index.html', '<html>probe</html>');

        return $token;
    }
}
