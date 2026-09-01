<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 6) . \DIRECTORY_SEPARATOR);

final class ThemeAiPublishPlaceableContractTest extends TestCase
{
    public function testPublishResponseBuildsWidgetViaToWidgetArray(): void
    {
        $src = file_get_contents(BP . 'app/code/Weline/Theme/Controller/Backend/Ai.php');
        self::assertNotFalse($src);
        self::assertStringContainsString('function postPublish(', $src);
        self::assertStringContainsString('function postPrepareRefine(', $src);
        self::assertStringContainsString("toWidgetArray()", $src);
        self::assertStringContainsString("'widget' => \$widget", $src);
        self::assertStringContainsString('revertVersion($versionId)', $src);
        self::assertStringContainsString('use Weline\\Framework\\Http\\ResponseTerminateException;', $src);
        self::assertGreaterThanOrEqual(
            4,
            substr_count($src, 'catch (ResponseTerminateException $terminate)'),
            'JSON actions must rethrow ResponseTerminateException instead of swallowing fetchJson'
        );
    }
}
