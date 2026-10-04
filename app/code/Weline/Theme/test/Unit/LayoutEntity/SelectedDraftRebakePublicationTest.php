<?php
declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;

final class SelectedDraftRebakePublicationTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('inheritedOwners')]
    public function testInheritedLayoutWriteInvalidatesTheActualArtifactOwner(string $ownerScope): void
    {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/selected-draft-rebake.php')
            . ' inherited-layout-write normal ' . escapeshellarg($ownerScope) . ' 2>&1', $output, $exit);
        self::assertSame(0, $exit, implode("\n", $output));
        $result = json_decode(implode("\n", $output), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('formal:1116:R8', $result['artifact']);
        self::assertStringContainsString('/scope/' . str_replace('.', '/', $ownerScope) . '/', $result['path']);
        self::assertSame([[999997, $ownerScope, 'normal']], $result['cache_calls']);
    }

    public static function inheritedOwners(): iterable
    {
        yield 'Global owner' => ['default.default.default'];
        yield 'Website owner' => ['default.__website__.default'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modeWrites')]
    public function testWritesInvalidateTheSameModeAsThePublishedArtifacts(string $operation, string $mode): void
    {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/selected-draft-rebake.php')
            . ' ' . escapeshellarg($operation) . ' ' . escapeshellarg($mode) . ' 2>&1', $output, $exit);
        self::assertSame(0, $exit, implode("\n", $output));
        $result = json_decode(implode("\n", $output), true, flags: JSON_THROW_ON_ERROR);
        self::assertNotEmpty($result['cache_calls']);
        foreach ($result['cache_calls'] as $call) {
            self::assertSame([999997, 'fixture.store.channel', $mode], $call);
        }
        if ($operation === 'published-write') {
            self::assertStringContainsString('/' . $mode . '/', $result['published_path']);
            self::assertSame('formal:1116:R8', $result['published_artifact']);
            self::assertSame('selected-draft-before-publication', $result['shared_draft']);
        } else {
            self::assertSame('draft:1121:R1', $result['shared_draft']);
            self::assertSame('formal:1116:R8', $result['sealed_version']);
        }
    }

    public static function modeWrites(): iterable
    {
        foreach (['test', 'dev'] as $mode) {
            yield 'save ' . $mode => ['published-write', $mode];
            yield 'injection rebake ' . $mode => ['rebake', $mode];
        }
    }

    public function testRebakePublishesSelectedDraftAndSealedVersionsWhileOldDraftRemainsAnInMemoryCandidate(): void
    {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/selected-draft-rebake.php') . ' 2>&1', $output, $exit);
        self::assertSame(0, $exit, implode("\n", $output));
        $result = json_decode(implode("\n", $output), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('draft:1121:R1', $result['shared_draft'], 'A historical unselected draft must not overwrite the selected draft path.');
        self::assertSame('formal:1116:R8', $result['sealed_version']);
        self::assertSame('formal:1114:R3', $result['historical_sealed_version']);
        self::assertSame([1121, 1116, 1114], $result['rebake_generated_versions']);
        self::assertSame('draft:1117:R4', $result['historical_candidate']);
        self::assertSame($result['shared_draft'], $result['shared_draft_after_historical_candidate']);
        self::assertTrue($result['version_rows_unchanged']);
    }
}
