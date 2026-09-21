<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Framework\Event\Event;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;
use Weline\Theme\Observer\UrlGenerateParamsCarryEditorThemeId;
use Weline\Theme\Service\PreviewContextService;
use Weline\Theme\Service\PreviewTokenService;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

final class UrlGenerateParamsCarryEditorThemeIdTest extends TestCase
{
    public function testInjectsMinimalEditorIdentityOnSameHostAbsoluteUrl(): void
    {
        $preview = $this->makePreviewContext(
            editorMode: '1',
            themeId: 3,
            token: null,
        );
        $url = $this->createMock(Url::class);
        $url->method('isLink')->willReturn(true);

        $_SERVER['HTTP_HOST'] = 'shop.test';
        $observer = new UrlGenerateParamsCarryEditorThemeId($preview, $url);
        $event = new Event(['data' => 'https://shop.test/products?page=2']);
        $observer->execute($event);

        $out = (string)$event->getData('data');
        self::assertStringContainsString('theme_id=3', $out);
        self::assertStringContainsString('frontend_theme_id=3', $out);
        self::assertStringContainsString('editor_mode=1', $out);
        self::assertStringContainsString('shell=theme-editor', $out);
        self::assertStringContainsString('page=2', $out);
    }

    public function testSkipsBackendThemeListUrl(): void
    {
        $preview = $this->makePreviewContext(
            editorMode: '1',
            themeId: 3,
            token: null,
        );
        $url = $this->createMock(Url::class);
        $url->method('isLink')->willReturn(true);

        $_SERVER['HTTP_HOST'] = 'shop.test';
        $observer = new UrlGenerateParamsCarryEditorThemeId($preview, $url);
        $event = new Event(['data' => 'https://shop.test/weline_admin/theme/backend/index']);
        $observer->execute($event);

        self::assertSame(
            'https://shop.test/weline_admin/theme/backend/index',
            (string)$event->getData('data'),
            'Editor chrome back link must stay free of canvas identity query'
        );
    }

    public function testSkipsRelativeBackendPathWithoutHost(): void
    {
        $preview = $this->makePreviewContext(
            editorMode: '1',
            themeId: 3,
            token: null,
        );
        $url = $this->createMock(Url::class);
        $url->method('isLink')->willReturn(false);

        $observer = new UrlGenerateParamsCarryEditorThemeId($preview, $url);
        $event = new Event(['data' => '/theme/backend/index']);
        $observer->execute($event);

        self::assertSame('/theme/backend/index', (string)$event->getData('data'));
    }

    public function testEarlyReturnsWhenNotCanvas(): void
    {
        $preview = $this->makePreviewContext(
            editorMode: '',
            themeId: 3,
            token: null,
        );
        $url = $this->createMock(Url::class);
        $observer = new UrlGenerateParamsCarryEditorThemeId($preview, $url);
        $event = new Event(['data' => 'https://shop.test/products']);
        $observer->execute($event);

        self::assertSame('https://shop.test/products', (string)$event->getData('data'));
    }

    public function testSkipsExternalHosts(): void
    {
        $preview = $this->makePreviewContext(
            editorMode: '1',
            themeId: 3,
            token: null,
        );
        $url = $this->createMock(Url::class);
        $url->method('isLink')->willReturn(true);

        $_SERVER['HTTP_HOST'] = 'shop.test';
        $observer = new UrlGenerateParamsCarryEditorThemeId($preview, $url);
        $event = new Event(['data' => 'https://external.test/path']);
        $observer->execute($event);

        self::assertSame('https://external.test/path', (string)$event->getData('data'));
    }

    public function testDoesNotOverwriteExistingThemeId(): void
    {
        $preview = $this->makePreviewContext(
            editorMode: '1',
            themeId: 3,
            token: null,
        );
        $url = $this->createMock(Url::class);
        $url->method('isLink')->willReturn(false);

        $observer = new UrlGenerateParamsCarryEditorThemeId($preview, $url);
        $event = new Event(['data' => '/products?theme_id=9']);
        $observer->execute($event);

        $out = (string)$event->getData('data');
        $query = [];
        \parse_str((string)(\parse_url($out, \PHP_URL_QUERY) ?: ''), $query);
        self::assertSame('9', (string)($query['theme_id'] ?? ''));
        self::assertSame('3', (string)($query['frontend_theme_id'] ?? ''));
        self::assertSame('1', (string)($query['editor_mode'] ?? ''));
    }

    public function testGateRejectsWhenTokenPresent(): void
    {
        $preview = $this->makePreviewContext(
            editorMode: '1',
            themeId: 3,
            token: 'tok-abc',
        );
        self::assertFalse($preview->shouldCarryEditorIdentityOnGeneratedUrls());
    }

    public function testGateRejectsWithoutThemeId(): void
    {
        $preview = $this->makePreviewContext(
            editorMode: '1',
            themeId: 0,
            token: null,
        );
        self::assertFalse($preview->shouldCarryEditorIdentityOnGeneratedUrls());
    }

    public function testGateAcceptsFrontendThemeIdFallback(): void
    {
        $preview = $this->makePreviewContext(
            editorMode: '1',
            themeId: 0,
            token: null,
            frontendThemeId: 7,
        );
        self::assertTrue($preview->shouldCarryEditorIdentityOnGeneratedUrls());
        $params = $preview->getEditorIdentityCarryQueryParams();
        self::assertSame(7, $params['theme_id']);
        self::assertSame(7, $params['frontend_theme_id']);
    }

    private function makePreviewContext(
        string $editorMode,
        int $themeId,
        ?string $token,
        int $frontendThemeId = 0,
    ): PreviewContextService {
        $request = $this->createMock(Request::class);
        $request->method('getParam')->willReturnCallback(
            static function (string $key, mixed $default = null) use ($editorMode, $themeId, $frontendThemeId) {
                return match ($key) {
                    'editor_mode' => $editorMode,
                    'shell' => '',
                    'theme_id' => $themeId,
                    'frontend_theme_id' => $frontendThemeId,
                    'editor_context' => null,
                    default => $default,
                };
            }
        );

        $tokenService = $this->createMock(PreviewTokenService::class);
        $tokenService->method('getTokenFromRequest')->willReturn($token);

        $ref = new ReflectionClass(PreviewContextService::class);
        /** @var PreviewContextService $service */
        $service = $ref->newInstanceWithoutConstructor();
        $ref->getProperty('request')->setValue($service, $request);
        $ref->getProperty('previewTokenService')->setValue($service, $tokenService);

        return $service;
    }
}
