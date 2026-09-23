<?php
declare(strict_types=1);
namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Response;
use Weline\Theme\Controller\Backend\ThemeEditor;

final class BlankLayoutHtmlResponseTest extends TestCase
{
    public function testOrdinaryCompilerKeepsJsonAndControllerLayout(): void
    {
        require_once dirname(__DIR__, 2) . '/Controller/Backend/ThemeEditor.php';
        $request = $this->getMockBuilder(Request::class)->disableOriginalConstructor()->onlyMethods(['getParam'])->getMock();
        $request->method('getParam')->willReturn('');
        $controller = $this->getMockBuilder(ThemeEditor::class)->disableOriginalConstructor()->onlyMethods(['getCompileLayoutPayload'])->getMock();
        $layout = new \ReflectionProperty($controller, 'layoutType');
        $controller->method('getCompileLayoutPayload')->willReturnCallback(static function () use ($layout, $controller): array {
            self::assertSame('default.default', $layout->getValue($controller));
            return ['success' => true, 'html' => '<html></html>'];
        });
        (new \ReflectionProperty($controller, 'request'))->setValue($controller, $request);
        self::assertSame(['success' => true, 'html' => '<html></html>'], json_decode($controller->getCompileLayout(), true));
    }

    public function testOtherLayoutsCannotUseTheHtmlBranch(): void
    {
        require_once dirname(__DIR__, 2) . '/Controller/Backend/ThemeEditor.php';
        $request = $this->getMockBuilder(Request::class)->disableOriginalConstructor()->onlyMethods(['getParam'])->getMock();
        $request->method('getParam')->willReturnCallback(static fn($key, $default = '') => ['render' => 'html', 'layout_type' => '../private', 'layout_option' => 'full', 'editor_area' => 'frontend'][$key] ?? $default);
        $controller = $this->getMockBuilder(ThemeEditor::class)->disableOriginalConstructor()->onlyMethods(['getCompileLayoutPayload'])->getMock();
        $controller->expects(self::never())->method('getCompileLayoutPayload');
        (new \ReflectionProperty($controller, 'request'))->setValue($controller, $request);
        self::assertSame(404, $controller->getCompileLayout()->getStatusCode());
    }

    public function testExplicitHtmlUsesRenderedDocumentAndKeepsErrorsNonSuccessful(): void
    {
        require_once dirname(__DIR__, 2) . '/Controller/Backend/ThemeEditor.php';
        foreach ([['success' => true, 'html' => '<html><head></head><body>blank</body></html>'], ['success' => false, 'message' => 'theme_editor_context_mismatch', 'status_code' => 400]] as $payload) {
            $request = $this->getMockBuilder(Request::class)->disableOriginalConstructor()->onlyMethods(['getParam'])->getMock();
            $request->method('getParam')->willReturnCallback(static fn($key, $default = '') => ['render' => 'html', 'layout_type' => 'blank', 'layout_option' => 'full', 'editor_area' => 'frontend'][$key] ?? $default);
            $controller = $this->getMockBuilder(ThemeEditor::class)->disableOriginalConstructor()->onlyMethods(['getCompileLayoutPayload'])->getMock();
            $layout = new \ReflectionProperty($controller, 'layoutType');
            $originalLayout = $layout->getValue($controller);
            $controller->method('getCompileLayoutPayload')->willReturnCallback(static function () use ($payload, $layout, $controller): array {
                self::assertNull($layout->getValue($controller), 'Frontend document fetch must disable BackendController automatic shell wrapping.');
                return $payload;
            });
            (new \ReflectionProperty($controller, 'request'))->setValue($controller, $request);
            $result = $controller->getCompileLayout();
            self::assertSame($originalLayout, $layout->getValue($controller), 'Restore the controller layout after both successful and failed compilation.');
            self::assertInstanceOf(Response::class, $result);
            self::assertSame($payload['success'] ? 200 : 400, $result->getStatusCode());
            if ($payload['success']) {
                self::assertSame($payload['html'], $result->getBody());
                self::assertSame('text/html; charset=utf-8', $result->getHeader('Content-Type'));
            }
        }
    }
}
