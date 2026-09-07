<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
final class ThemeEditorDraftResetServiceTest extends TestCase
{
    public static function resourceRevisions(): iterable
    {
        foreach ([
            'theme_binding' => '/theme_id',
            'layout' => '/nodes/0123456789abcdef0123456789abcdef/config/title',
            'meta' => '/values/title',
            'appearance' => '/tokens/color',
            'i18n' => '/translations/0123456789abcdef0123456789abcdef/title',
        ] as $resource => $path) {
            yield $resource . ' published' => [$resource, $path, 7];
            yield $resource . ' unpublished' => [$resource, $path, 6];
        }
    }

    #[DataProvider('resourceRevisions')]
    public function testResetPreservesOldPatchesAndWritesANewInheritDraft(
        string $resource,
        string $path,
        int $publishedRevisionId,
    ): void {
        $command = escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg(__DIR__ . '/fixtures/theme-editor-draft-reset.php') . ' '
            . escapeshellarg($resource) . ' ' . escapeshellarg($path) . ' ' . $publishedRevisionId;
        exec($command, $lines, $exitCode);
        self::assertSame(0, $exitCode, implode("\n", $lines));
        $result = json_decode(implode("\n", $lines), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($result['history_before'], $result['history_after'], 'Reset must preserve every historical patch.');
        self::assertSame([], $result['owned_after'], 'Reset must clear current ownership using inherit commands.');
        self::assertSame(4, $result['revision_after'], 'Reset must advance the optimistic revision.');
        self::assertSame(8, $result['draft_revision_after']);
        self::assertSame($publishedRevisionId, $result['published_revision_after']);
        self::assertSame(1, $result['count']);
    }
}
