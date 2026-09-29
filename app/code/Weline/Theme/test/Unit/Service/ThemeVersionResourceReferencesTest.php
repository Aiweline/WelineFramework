<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\Version\ThemeVersionResourceSnapshotService;

final class ThemeVersionResourceReferencesTest extends TestCase
{
    public function testFrozenIntentWinsOverNewerPublishedData(): void
    {
        self::assertTrue(class_exists(ThemeVersionResourceSnapshotService::class), 'Snapshots must read their own immutable references.');
        $reader = new ThemeVersionResourceSnapshotService();
        $result = $reader->readReferencedPayload(
            ['intent_revision_id' => 7, 'release_id' => 5],
            static fn(int $id): array => ['resolved' => $id === 7, 'draft_payload' => ['nodes' => ['old-intent']]],
            static fn(int $id): array => ['nodes' => ['new-release']],
        );
        self::assertTrue($result['resolved']);
        self::assertSame(['nodes' => ['old-intent']], $result['payload']);
        self::assertSame(7, $result['draft_revision_id']);
    }

    public function testMissingIntentNeverFallsBackToCurrentOrAnotherRelease(): void
    {
        self::assertTrue(class_exists(ThemeVersionResourceSnapshotService::class));
        $result = (new ThemeVersionResourceSnapshotService())->readReferencedPayload(
            ['intent_revision_id' => 8, 'release_id' => 9],
            static fn(int $id): array => ['resolved' => false],
            static fn(int $id): array => ['nodes' => ['must-not-show']],
        );
        self::assertFalse($result['resolved']);
        self::assertSame('historical_intent_reference_missing', $result['reason']);
        self::assertNull($result['payload']);
    }

    public function testEmptyPayloadIsAResolvedUninstallAndUnreferencedHistoryFails(): void
    {
        self::assertTrue(class_exists(ThemeVersionResourceSnapshotService::class));
        $reader = new ThemeVersionResourceSnapshotService();
        $empty = $reader->readReferencedPayload(['release_id' => 4], static fn(): array => [], static fn(): array => ['nodes' => []]);
        self::assertTrue($empty['resolved']);
        self::assertSame(['nodes' => []], $empty['payload']);
        $missing = $reader->readReferencedPayload([], static fn(): array => [], static fn(): array => ['nodes' => ['latest']]);
        self::assertFalse($missing['resolved']);
    }
}
