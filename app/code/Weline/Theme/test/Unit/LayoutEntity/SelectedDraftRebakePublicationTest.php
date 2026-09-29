<?php
declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;

final class SelectedDraftRebakePublicationTest extends TestCase
{
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
