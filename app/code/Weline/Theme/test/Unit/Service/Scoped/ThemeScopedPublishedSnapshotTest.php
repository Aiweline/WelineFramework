<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\Scoped;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Model\ThemeScopePatch;

final class ThemeScopedPublishedSnapshotTest extends TestCase
{
    public function testSnapshotAvoidsEditorProvenanceAndReusesTheCompletePayload(): void
    {
        $result = $this->probe();
        self::assertSame(['payload', 'release_id', 'source_scope'], $result['keys']);
        self::assertSame(0, $result['first_reads'][ThemeScopePatch::class] ?? 0);
        self::assertGreaterThan(0, $result['editor_patch_reads']);
        self::assertSame('/shop.cn.default.png', $result['first']['brand']['favicon']);
        self::assertSame($result['first'], $result['second']);
        self::assertSame(0, $result['repeat_reads']);
        self::assertSame($result['first'], $result['new_request']);
        self::assertGreaterThan(0, $result['new_request_reads']);
    }

    public function testTransactionReadsDoNotReplayOrRetainUncommittedPointers(): void
    {
        $result = $this->probe();
        self::assertSame('/shop.default.default.png', $result['inside']['brand']['favicon']);
        self::assertSame('/shop.cn.default.png', $result['after_rollback']['brand']['favicon']);
        self::assertTrue($result['unrelated_retained']);
        self::assertSame(['nodes' => []], $result['after_publish']);
    }

    public function testPublishedLocaleAndTargetFallbackRemainIsolated(): void
    {
        $result = $this->probe();
        self::assertSame(['marker' => 'scope-default-locale'], $result['default_locale']);
        self::assertSame(['marker' => 'target-99'], $result['target_locale']);
        self::assertSame(['nodes' => []], $result['missing_target']);
    }

    private function probe(): array
    {
        $process = proc_open([PHP_BINARY, __DIR__ . '/Fixtures/theme_rollback_invalidation.php', 'snapshot'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error . $output);
        $result = json_decode($output, true);
        self::assertIsArray($result, $error . $output);
        return $result;
    }
}
