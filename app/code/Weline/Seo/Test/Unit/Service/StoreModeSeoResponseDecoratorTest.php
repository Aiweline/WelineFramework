<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Event\Event;
use Weline\Framework\Http\HeaderCollector;
use Weline\Framework\Http\Response;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Seo\Observer\StoreModeSeoResponseObserver;
use Weline\Seo\Service\StoreModeSeoHardGate;
use Weline\Seo\Service\StoreModeSeoResponseDecorator;

final class StoreModeSeoResponseDecoratorTest extends TestCase
{
    private StoreModeSeoResponseDecorator $decorator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->decorator = new StoreModeSeoResponseDecorator(new StoreModeSeoHardGate());
        $this->enterRequest(ScopeIdentity::MODE_TEST);
    }

    protected function tearDown(): void
    {
        HeaderCollector::reset();
        WelineEnv::getInstance()->reset();
        Runtime::resetModeCache();
        if (Context::hasCurrent()) {
            Context::leave();
        }
        parent::tearDown();
    }

    public function testTestModeAddsHeaderToResponse(): void
    {
        $response = Response::html('<html><body>test</body></html>');

        $decorated = $this->decorator->decorate($response);

        self::assertSame($response, $decorated);
        self::assertSame(
            StoreModeSeoResponseDecorator::HEADER_VALUE,
            $response->getHeader(StoreModeSeoResponseDecorator::HEADER_NAME),
        );
    }

    public function testDevModeWrapsStringWithoutChangingBody(): void
    {
        $this->enterRequest(ScopeIdentity::MODE_DEV);

        $decorated = $this->decorator->decorate('<html><body>dev</body></html>');

        self::assertInstanceOf(Response::class, $decorated);
        self::assertSame('<html><body>dev</body></html>', $decorated->getBody());
        self::assertSame(
            StoreModeSeoResponseDecorator::HEADER_VALUE,
            $decorated->getHeader(StoreModeSeoResponseDecorator::HEADER_NAME),
        );
    }

    public function testNormalModeLeavesResultUntouched(): void
    {
        $this->enterRequest(ScopeIdentity::MODE_NORMAL);
        $html = '<html><body>normal</body></html>';

        self::assertSame($html, $this->decorator->decorate($html));
    }

    public function testBackendPostStaticAndMediaRequestsAreExcluded(): void
    {
        RequestContext::setWelineArea(RequestContext::AREA_BACKEND);
        self::assertSame('backend', $this->decorator->decorate('backend'));

        $this->enterRequest(ScopeIdentity::MODE_TEST);
        WelineEnv::set('request.method', 'POST', 'unit-test');
        self::assertSame('post', $this->decorator->decorate('post'));

        $this->enterRequest(ScopeIdentity::MODE_TEST);
        Context::current()->set('route.is_static', true);
        self::assertSame('static', $this->decorator->decorate('static'));

        $this->enterRequest(ScopeIdentity::MODE_TEST);
        Context::current()->set('route.is_media', true);
        self::assertSame('media', $this->decorator->decorate('media'));
    }

    public function testHeadRequestReceivesHeader(): void
    {
        WelineEnv::set('request.method', 'HEAD', 'unit-test');

        $decorated = $this->decorator->decorate(new Response(true));

        self::assertInstanceOf(Response::class, $decorated);
        self::assertSame(
            StoreModeSeoResponseDecorator::HEADER_VALUE,
            $decorated->getHeader(StoreModeSeoResponseDecorator::HEADER_NAME),
        );
    }

    public function testWlsRunAfterAndFpcResponseAreDecorated(): void
    {
        $observer = new StoreModeSeoResponseObserver($this->decorator);
        Runtime::setMode(Runtime::WLS);

        $runAfter = new Event(['result' => '<html>wls</html>']);
        $runAfter->setName('Weline_Framework::App::run_after');
        $observer->execute($runAfter);
        $runAfterResult = $runAfter->getData('result');
        self::assertInstanceOf(Response::class, $runAfterResult);
        self::assertSame(
            StoreModeSeoResponseDecorator::HEADER_VALUE,
            $runAfterResult->getHeader(StoreModeSeoResponseDecorator::HEADER_NAME),
        );

        $fpcResponse = Response::html('<html>cached</html>');
        $fpcHit = new Event(['response' => $fpcResponse]);
        $fpcHit->setName('Weline_Framework_Fpc::cache_hit_response');
        $observer->execute($fpcHit);
        self::assertSame(
            StoreModeSeoResponseDecorator::HEADER_VALUE,
            $fpcResponse->getHeader(StoreModeSeoResponseDecorator::HEADER_NAME),
        );
    }

    public function testFpmUsesResponseReadyAndWlsSkipsIt(): void
    {
        $observer = new StoreModeSeoResponseObserver($this->decorator);
        $fpmResponse = Response::html('<html>fpm</html>');
        $responseReady = new Event(['response' => $fpmResponse]);
        $responseReady->setName('Weline_Framework_Http::response_ready');

        Runtime::setMode(Runtime::FPM);
        $observer->execute($responseReady);
        self::assertSame(
            StoreModeSeoResponseDecorator::HEADER_VALUE,
            $fpmResponse->getHeader(StoreModeSeoResponseDecorator::HEADER_NAME),
        );

        $wlsResponse = Response::html('<html>wls</html>');
        $wlsReady = new Event(['response' => $wlsResponse]);
        $wlsReady->setName('Weline_Framework_Http::response_ready');
        Runtime::setMode(Runtime::WLS);
        $observer->execute($wlsReady);
        self::assertNull($wlsResponse->getHeader(StoreModeSeoResponseDecorator::HEADER_NAME));
    }

    private function enterRequest(string $mode): void
    {
        HeaderCollector::reset();
        WelineEnv::getInstance()->reset();
        Runtime::resetModeCache();
        if (Context::hasCurrent()) {
            Context::leave();
        }
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'wls']]));
        RequestContext::setId('seo-store-mode-response-test-' . $mode);
        RequestContext::setWelineArea(RequestContext::AREA_FRONTEND);
        RequestContext::installScopeIdentity(ScopeIdentity::channel(
            0,
            'default',
            'store-' . $mode,
            'web',
            $mode,
        ));
        Context::current()->set('route.is_static', false);
        Context::current()->set('route.is_media', false);
        WelineEnv::set('request.method', 'GET', 'unit-test');
    }
}
