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

    public function testDevAllowsPostLoginNavigateWithoutSecFetchUser(): void
    {
        if (!\defined('DEV')) {
            \define('DEV', true);
        }
        self::assertTrue(DEV);

        $service = $this->service();
        $method = new \ReflectionMethod($service, 'isTopLevelDocumentNavigation');
        $method->setAccessible(true);

        $hadContext = \Weline\Framework\Context::hasCurrent();
        $context = \Weline\Framework\Context::current();
        $keys = [
            'input.server.HTTP_SEC_FETCH_MODE',
            'input.server.HTTP_SEC_FETCH_DEST',
            'input.server.HTTP_SEC_FETCH_USER',
        ];
        $previous = [];
        foreach ($keys as $key) {
            $previous[$key] = $context->get($key);
        }
        try {
            $context->set('input.server.HTTP_SEC_FETCH_MODE', 'navigate');
            $context->set('input.server.HTTP_SEC_FETCH_DEST', 'document');
            $context->set('input.server.HTTP_SEC_FETCH_USER', '');
            self::assertTrue($method->invoke($service));

            $context->set('input.server.HTTP_SEC_FETCH_USER', '?1');
            self::assertTrue($method->invoke($service));

            $context->set('input.server.HTTP_SEC_FETCH_DEST', 'iframe');
            self::assertFalse($method->invoke($service));
        } finally {
            foreach ($previous as $key => $value) {
                $context->set($key, $value);
            }
            if (!$hadContext) {
                \Weline\Framework\Context::leave();
            }
        }
    }

    private function service(): BackendWorkerAttestationResponseService
    {
        $provider = $this->createMock(FrontendWorkerBackendAttestationProviderInterface::class);
        $store = $this->createMock(FrontendWorkerStateStoreInterface::class);
        $sessions = new FrontendWorkerSessionService($store);

        return new BackendWorkerAttestationResponseService($provider, $sessions);
    }
}
