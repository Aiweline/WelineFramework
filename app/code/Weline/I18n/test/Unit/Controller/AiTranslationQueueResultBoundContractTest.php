<?php

declare(strict_types=1);

namespace Weline\I18n\test\Unit\Controller;

use PHPUnit\Framework\TestCase;

final class AiTranslationQueueResultBoundContractTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\defined('BP')) {
            \define('BP', \dirname(__DIR__, 7) . DIRECTORY_SEPARATOR);
        }
    }

    public function testBuildLocaleRowsBoundsQueueResultBeforeTemplateAssign(): void
    {
        $controller = $this->read('app/code/Weline/I18n/Controller/Backend/AiTranslation.php');

        self::assertMatchesRegularExpression(
            '/QUEUE_RESULT_DISPLAY_MAX_BYTES\s*=\s*\d+/',
            $controller
        );
        self::assertStringContainsString('boundQueueResultForDisplay', $controller);
        self::assertStringContainsString(
            "'queue_result' => \$this->boundQueueResultForDisplay(\$queueResult)",
            $controller
        );
        self::assertLessThan(
            65536,
            $this->extractDisplayMaxBytes($controller),
            'AI translation page must keep display result well under WLS capture_limit (16MB).'
        );
    }

    private function extractDisplayMaxBytes(string $controller): int
    {
        if (!\preg_match('/QUEUE_RESULT_DISPLAY_MAX_BYTES\s*=\s*(\d+)/', $controller, $m)) {
            self::fail('QUEUE_RESULT_DISPLAY_MAX_BYTES missing');
        }

        return (int)$m[1];
    }

    private function read(string $relativePath): string
    {
        $path = BP . \ltrim($relativePath, '/\\');
        self::assertFileExists($path);

        return (string)\file_get_contents($path);
    }
}
