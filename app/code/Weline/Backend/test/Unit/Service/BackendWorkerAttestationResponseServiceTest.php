<?php

declare(strict_types=1);

namespace Weline\Backend\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Backend\Service\BackendWorkerAttestationResponseService;
use Weline\Framework\Runtime\FrontendWorkerBackendAttestationProviderInterface;
use Weline\Framework\Service\Query\FrontendWorkerSessionService;
use Weline\Framework\Service\Query\Store\FrontendWorkerStateStoreInterface;

/**
 * 后台页 attestation 槽位：缺槽可注入、已装饰幂等、单槽可替换。
 */
final class BackendWorkerAttestationResponseServiceTest extends TestCase
{
    public function testCountInertSlotsMatchesVariants(): void
    {
        $service = $this->service();
        $html = '<head>'
            . '<meta name="weline-worker-backend-bootstrap-slot" content="">'
            . '<meta name=\'weline-worker-backend-bootstrap-slot\' content="" />'
            . '</head>';
        self::assertSame(2, $service->countInertSlots($html));
        self::assertSame(1, $service->countInertSlots(
            '<meta name="weline-worker-backend-bootstrap-slot" content="">'
        ));
        self::assertSame(0, $service->countInertSlots('<head><title>x</title></head>'));
    }

    public function testReplaceInertSlotSwapsExactMeta(): void
    {
        $service = $this->service();
        $html = '<head><meta name="weline-worker-backend-bootstrap-slot" content=""><title>A</title></head>';
        $meta = '<meta name="weline-worker-backend-bootstrap" content="abcdefghijklmnopqrstuvwxyZ0123456789-_ABCDE">';
        $out = $service->replaceInertSlot($html, $meta);
        self::assertNotNull($out);
        self::assertSame(0, $service->countInertSlots($out));
        self::assertTrue($service->htmlContainsBootstrapMeta($out));
        self::assertStringContainsString('content="abcdefghijklmnopqrstuvwxyZ0123456789-_ABCDE"', $out);
    }

    public function testInjectBootstrapMetaHealsMissingSlot(): void
    {
        $service = $this->service();
        $html = '<!doctype html><html><head><title>登录</title></head><body>ok</body></html>';
        $meta = '<meta name="weline-worker-backend-bootstrap" content="abcdefghijklmnopqrstuvwxyZ0123456789-_ABCDE">';
        $out = $service->injectBootstrapMeta($html, $meta);
        self::assertNotNull($out);
        self::assertTrue($service->htmlContainsBootstrapMeta($out));
        self::assertStringContainsString($meta . "\n</head>", $out);
    }

    public function testHtmlContainsBootstrapMetaIgnoresScriptStrings(): void
    {
        $service = $this->service();
        $html = '<head><script>document.querySelector(\'meta[name="weline-worker-backend-bootstrap"]\')</script></head>';
        self::assertFalse($service->htmlContainsBootstrapMeta($html));
        $html .= '<meta name="weline-worker-backend-bootstrap" content="abcdefghijklmnopqrstuvwxyZ0123456789-_ABCDE">';
        self::assertTrue($service->htmlContainsBootstrapMeta($html));
    }

    public function testStripExtraInertSlotsKeepsFirstOnly(): void
    {
        $service = $this->service();
        $html = '<head>'
            . '<meta name="weline-worker-backend-bootstrap-slot" content="">'
            . '<title>A</title>'
            . '<meta name="weline-worker-backend-bootstrap-slot" content="">'
            . '</head>';
        $out = $service->stripExtraInertSlots($html);
        self::assertSame(1, $service->countInertSlots($out));
        self::assertStringContainsString('<title>A</title>', $out);
    }

    public function testProductionDocumentNavigationMintsWithoutSecFetchUser(): void
    {
        self::assertTrue(BackendWorkerAttestationResponseService::shouldMintDocumentProof(
            'navigate',
            'document',
            '',
            '',
            false,
        ));
        self::assertTrue(BackendWorkerAttestationResponseService::shouldMintDocumentProof(
            'navigate',
            'document',
            '?1',
            '',
            false,
        ));
        self::assertFalse(BackendWorkerAttestationResponseService::shouldMintDocumentProof(
            'navigate',
            'document',
            '',
            'prefetch',
            false,
        ));
        self::assertFalse(BackendWorkerAttestationResponseService::shouldMintDocumentProof(
            'navigate',
            'document',
            '?1',
            'prerender',
            false,
        ));
        self::assertFalse(BackendWorkerAttestationResponseService::shouldMintDocumentProof(
            '',
            '',
            '',
            '',
            false,
        ));
        self::assertTrue(BackendWorkerAttestationResponseService::shouldMintDocumentProof(
            '',
            '',
            '',
            '',
            true,
        ));
        self::assertFalse(BackendWorkerAttestationResponseService::shouldMintDocumentProof(
            'cors',
            'empty',
            '',
            '',
            false,
        ));
        self::assertFalse(BackendWorkerAttestationResponseService::shouldMintDocumentProof(
            'navigate',
            'iframe',
            '?1',
            '',
            false,
        ));
        // Service Worker re-fetch of an intercepted navigation keeps
        // Sec-Fetch-Mode: navigate but collapses Sec-Fetch-Dest to empty.
        // Must still mint, otherwise the editor page loses its backend
        // Worker session and every auth=backend editorRequest 403s.
        self::assertTrue(BackendWorkerAttestationResponseService::shouldMintDocumentProof(
            'navigate',
            'empty',
            '',
            '',
            false,
        ));
        self::assertTrue(BackendWorkerAttestationResponseService::shouldMintDocumentProof(
            'navigate',
            '',
            '?1',
            '',
            false,
        ));
        self::assertFalse(BackendWorkerAttestationResponseService::shouldMintDocumentProof(
            'navigate',
            'empty',
            '',
            'prefetch',
            false,
        ));
    }

    public function testDevAllowsPostLoginNavigateWithoutSecFetchUser(): void
    {
        $service = $this->service();
        $method = new \ReflectionMethod($service, 'isTopLevelDocumentNavigation');
        $method->setAccessible(true);

        $hadContext = \Weline\Framework\Context::hasCurrent();
        $context = \Weline\Framework\Context::current();
        $previous = $context->get('input.server');
        try {
            $context->set('input.server', [
                'HTTP_SEC_FETCH_MODE' => 'navigate',
                'HTTP_SEC_FETCH_DEST' => 'document',
                'HTTP_SEC_FETCH_USER' => '',
            ]);
            self::assertTrue($method->invoke($service));

            $context->set('input.server', [
                'HTTP_SEC_FETCH_MODE' => 'navigate',
                'HTTP_SEC_FETCH_DEST' => 'document',
                'HTTP_SEC_FETCH_USER' => '?1',
            ]);
            self::assertTrue($method->invoke($service));

            $context->set('input.server', [
                'HTTP_SEC_FETCH_MODE' => 'navigate',
                'HTTP_SEC_FETCH_DEST' => 'document',
                'HTTP_SEC_FETCH_USER' => '',
                'HTTP_SEC_PURPOSE' => 'prefetch',
            ]);
            self::assertFalse($method->invoke($service));

            $context->set('input.server', [
                'HTTP_SEC_FETCH_MODE' => 'navigate',
                'HTTP_SEC_FETCH_DEST' => 'iframe',
                'HTTP_SEC_FETCH_USER' => '?1',
            ]);
            self::assertFalse($method->invoke($service));
        } finally {
            $context->set('input.server', \is_array($previous) ? $previous : []);
            if (!$hadContext) {
                \Weline\Framework\Context::leave();
            }
        }
    }

    private function service(): BackendWorkerAttestationResponseService
    {
        // FrontendWorkerSessionService consts read VAR_DIR/DS at class load;
        // bootstrap them when PHPUnit runs without the app bootstrap.
        if (!\defined('BP')) {
            \define('BP', \dirname(__DIR__, 5) . DIRECTORY_SEPARATOR);
        }
        if (!\defined('DS')) {
            \define('DS', DIRECTORY_SEPARATOR);
        }
        if (!\defined('VAR_DIR')) {
            \Weline\Framework\App\DirectoryList::defineBootstrapConstants(\rtrim(BP, '/\\'));
        }
        $provider = $this->createMock(FrontendWorkerBackendAttestationProviderInterface::class);
        $store = $this->createMock(FrontendWorkerStateStoreInterface::class);
        $sessions = new FrontendWorkerSessionService($store);

        return new BackendWorkerAttestationResponseService($provider, $sessions);
    }
}
