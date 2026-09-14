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
        self::assertSame(0, $result['new_request_reads'], 'A new request must reuse the published payload.');
        self::assertSame($result['first'], $result['shared']);
        self::assertSame(0, $result['shared_reads'], 'Resetting process L1 must reuse the same shared pool.');
        self::assertGreaterThan(0, $result['shared_pool_reads']);
        self::assertSame($result['first'], $result['other_ambient']);
        self::assertSame(0, $result['other_ambient_reads'], 'The resource identity, not the ambient request scope, owns the raw snapshot.');
        self::assertSame('/shop.default.default.png', $result['scope_payloads'][0]['brand']['favicon']);
        self::assertSame('/shop.cn.default.png', $result['scope_payloads'][1]['brand']['favicon']);
        self::assertCount(4, $result['resource_payloads']);
        foreach ($result['resource_payloads'] as $resource => $payload) {
            self::assertSame($resource, $payload['resource_marker']);
        }
        self::assertSame($result['binding_first'], $result['binding_next']);
        self::assertGreaterThan(0, $result['binding_next_reads'], 'Binding projection must retain its existing non-shared path.');
    }

    public function testTransactionReadsDoNotReplayOrRetainUncommittedPointers(): void
    {
        $result = $this->probe();
        self::assertSame('/shop.default.default.png', $result['inside']['brand']['favicon']);
        self::assertSame('/shop.cn.default.png', $result['after_rollback']['brand']['favicon']);
        self::assertTrue($result['unrelated_retained']);
        self::assertSame(['nodes' => []], $result['after_publish']);
        self::assertSame($result['after_publish'], $result['after_publish_next']);
        self::assertGreaterThan($result['generation_before_publish'], $result['generation_after_publish']);
    }

    public function testPublishedLocaleAndTargetFallbackRemainIsolated(): void
    {
        $result = $this->probe();
        self::assertSame(['marker' => 'scope-default-locale'], $result['default_locale']);
        self::assertSame(['marker' => 'target-99'], $result['target_locale']);
        self::assertSame(['nodes' => []], $result['missing_target']);
        self::assertSame($result['default_locale'], $result['default_locale_again']);
        self::assertSame($result['target_locale'], $result['target_locale_again']);
        self::assertSame($result['missing_target'], $result['missing_target_again']);
        self::assertSame(0, $result['isolated_repeat_reads'], 'Locale, explicit target and successful empty fallback must each reuse their own shared entry.');
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
