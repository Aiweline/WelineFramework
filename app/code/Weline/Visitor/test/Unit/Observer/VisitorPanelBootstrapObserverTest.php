<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Framework\Event\Event;
use Weline\Framework\Http\Request;
use Weline\Visitor\Observer\VisitorPanelBootstrapObserver;

final class VisitorPanelBootstrapObserverTest extends TestCase
{
    public function testAppendBeforeBodyCloseInsertsBeforeClosingBody(): void
    {
        $observer = new VisitorPanelBootstrapObserver($this->createMock(Request::class));
        $method = new ReflectionMethod(VisitorPanelBootstrapObserver::class, 'appendBeforeBodyClose');
        $method->setAccessible(true);

        $result = $method->invoke(
            $observer,
            '<html><body><main>ok</main></body></html>',
            '<script data-weline-panel-visitor-bootstrap="true"></script>'
        );

        self::assertSame(
            '<html><body><main>ok</main><script data-weline-panel-visitor-bootstrap="true"></script></body></html>',
            $result
        );
    }

    public function testIsHtmlResponseAcceptsDoctypeDocument(): void
    {
        $observer = new VisitorPanelBootstrapObserver($this->createMock(Request::class));
        $method = new ReflectionMethod(VisitorPanelBootstrapObserver::class, 'isHtmlResponse');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($observer, "<!DOCTYPE html><html><body></body></html>"));
        self::assertFalse($method->invoke($observer, '{"ok":true}'));
    }

    public function testExecuteSkipsWhenMarkerAlreadyPresent(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('isAjax')->willReturn(false);
        $request->method('isApiFrontend')->willReturn(false);
        $request->method('isApiBackend')->willReturn(false);
        $request->method('isIframe')->willReturn(false);

        $observer = new VisitorPanelBootstrapObserver($request);
        $html = '<html><body><script data-weline-panel-visitor-bootstrap="true"></script></body></html>';
        $event = new Event(['result' => $html]);
        $event->setName('Weline_Framework::App::run_after');
        $observer->execute($event);

        self::assertSame($html, $event->getData('result'));
    }
}