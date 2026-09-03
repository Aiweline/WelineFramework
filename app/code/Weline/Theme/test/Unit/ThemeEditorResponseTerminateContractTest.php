<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 6) . \DIRECTORY_SEPARATOR);

/**
 * 主题编辑器经 query-bin 直调时，2xx ResponseTerminateException 不得被吞成
 * 「Response terminate with status 200」业务失败（对齐 AdminControllerBridge / Ai agents）。
 */
final class ThemeEditorResponseTerminateContractTest extends TestCase
{
    public function testThemeQueryProviderAbsorbs2xxTerminateLikeAdminBridge(): void
    {
        $src = file_get_contents(BP . 'app/code/Weline/Theme/extends/module/Weline_Framework/Query/ThemeQueryProvider.php');
        self::assertNotFalse($src);
        self::assertStringContainsString('use Weline\\Framework\\Http\\ResponseTerminateException;', $src);
        self::assertStringContainsString("set('meta.type', 'admin_bridge')", $src);
        self::assertStringContainsString('catch (ResponseTerminateException $e)', $src);
        self::assertStringContainsString('$e->getBody()', $src);
        self::assertStringContainsString('if ($status < 200 || $status >= 300)', $src);
        self::assertStringNotContainsString(
            'if (method_exists($e, \'getBody\'))',
            $src,
            'Must not catch arbitrary Throwable via getBody; only ResponseTerminateException 2xx'
        );
    }

    public function testLayoutConfigPayloadsRethrowResponseTerminateException(): void
    {
        $src = file_get_contents(BP . 'app/code/Weline/Theme/Controller/Backend/ThemeEditor.php');
        self::assertNotFalse($src);
        self::assertStringContainsString('use Weline\\Framework\\Http\\ResponseTerminateException;', $src);

        foreach ([
            'getLayoutOptionsPayload',
            'saveLayoutSelectionPayload',
            'getLayoutConfigPayload',
            'saveLayoutConfigPayload',
        ] as $method) {
            self::assertStringContainsString("function {$method}(", $src);
        }

        self::assertGreaterThanOrEqual(
            4,
            substr_count($src, 'catch (ResponseTerminateException $e)'),
            'Layout config related payloads must rethrow ResponseTerminateException'
        );
        self::assertStringContainsString(
            'query-bin / fetchJson 终止不可吞成「Response terminate with status 200」业务失败',
            $src
        );
    }
}
