<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;

final class ThemeLayoutEntityFormalCompileCandidatesContractTest extends TestCase
{
    public function testPinnedClosureCapturesCandidateMapNotBareCandidates(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityFormalLocaleCompileService.php',
        );
        self::assertStringContainsString('$candidateMap = $candidates;', $src);
        self::assertMatchesRegularExpression(
            '/runPinnedToIdentityWebsite\(\$identity,\s*function\s*\(\)\s*use\s*\([^)]*\$candidateMap[^)]*\)/s',
            $src,
        );
        self::assertStringContainsString('snapshotFromPublishedCandidates(', $src);
        self::assertStringContainsString('?array $candidates', $src);
        self::assertStringContainsString('$candidates = is_array($candidates) ? $candidates : [];', $src);
    }
}
