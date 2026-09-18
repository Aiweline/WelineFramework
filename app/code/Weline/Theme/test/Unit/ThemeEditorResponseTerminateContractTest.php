<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 6) . \DIRECTORY_SEPARATOR);

/**
 * 主题编辑器经 query-bin 直调时，ResponseTerminateException 不得逃逸成
 * text/html（否则 Frontend worker 报 Invalid Weline binary magic）。
 */
final class ThemeEditorResponseTerminateContractTest extends TestCase
{
    public function testThemeQueryProviderAbsorbsAllTerminateForEditorBridge(): void
    {
        $src = file_get_contents(BP . 'app/code/Weline/Theme/extends/module/Weline_Framework/Query/ThemeQueryProvider.php');
        self::assertNotFalse($src);
        self::assertStringContainsString('use Weline\\Framework\\Http\\ResponseTerminateException;', $src);
        self::assertStringContainsString("set('meta.type', 'admin_bridge')", $src);
        self::assertStringContainsString('catch (ResponseTerminateException $e)', $src);
        self::assertStringContainsString('$response = $e->getBody();', $src);
        self::assertStringContainsString('Absorb ALL terminates', $src);
        self::assertStringContainsString('catch (\\Throwable $e)', $src);
        self::assertStringContainsString('Editor bridge received HTML instead of JSON', $src);
        self::assertStringContainsString('editorBridgeAllowsHtmlResponse', $src);
        self::assertStringContainsString('/theme/backend/widget/paramrender/form', $src);
        self::assertStringNotContainsString('/theme/backend/theme-editor/layout-preview', $src);
        self::assertDoesNotMatchRegularExpression(
            '/if \(\$status < 200 \|\| \$status >= 300\) \{\s*throw \$e;/',
            $src,
            'Must not rethrow non-2xx Terminate from editor bridge (breaks WQB1)'
        );
        self::assertStringNotContainsString(
            'if (method_exists($e, \'getBody\'))',
            $src,
            'Must not catch arbitrary Throwable via getBody; only ResponseTerminateException'
        );
    }

    public function testSaveWidgetRejectsStructuredScopeFalseMismatchAndCatchesThrowable(): void
    {
        $src = file_get_contents(BP . 'app/code/Weline/Theme/Controller/Backend/ThemeEditor.php');
        self::assertNotFalse($src);
        self::assertStringContainsString('Structured scope ({identity: ScopeIdentity}|ScopeIdentity array) is also', $src);
        self::assertStringContainsString('if (!is_array($scopeValue))', $src);
        self::assertStringContainsString('Catch Error too: uncaught errors inside query-bin become HTML pages', $src);
        self::assertStringContainsString('} catch (\\Throwable $e) {', $src);
        self::assertStringContainsString('function tryBuildPreviewHtmlForWidget(', $src);
        self::assertStringContainsString('Nested widget/preview render must never terminate', $src);
        self::assertStringContainsString(
            '$previewHtml = $this->tryBuildPreviewHtmlForWidget(',
            $src,
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
