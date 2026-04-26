<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\EventRegistryInterface;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Event\Config\XmlReader;
use Weline\Framework\Http\FpmResponseEmitter;
use Weline\Framework\Http\HeaderCollector;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Response;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Http\WlsResponseEmitter;
use Weline\Framework\Manager\ObjectManager;

final class ResponseTestMutationObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $payload = $event->getData('data');
        if (!\is_array($payload)) {
            return;
        }

        $result = (string)($payload['result'] ?? '');
        $event->setData('result', $result . '<!--telemetry-mutation-->');
    }
}

final class ResponseTest extends TestCase
{
    protected function setUp(): void
    {
        HeaderCollector::reset();
    }

    protected function tearDown(): void
    {
        HeaderCollector::reset();
    }

    public function testNormalizeArrayCreatesJsonResponse(): void
    {
        $response = Response::normalize(['ok' => true, 'message' => 'done']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->getHeader('Content-Type'));
        self::assertSame(['ok' => true, 'message' => 'done'], \json_decode($response->getBody(), true));
    }

    public function testNormalizeStringCreatesHtmlResponse(): void
    {
        $response = Response::normalize('<h1>hello</h1>');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeader('Content-Type'));
        self::assertSame('<h1>hello</h1>', $response->getBody());
    }

    public function testResponseTerminateExceptionCanCarryFrameworkResponse(): void
    {
        $response = Response::json(['ok' => true], 202);
        $exception = new ResponseTerminateException($response);

        self::assertSame(202, $exception->getStatusCode());
        self::assertSame($response->getBody(), $exception->getBody());
        self::assertSame($response->getHeader('Content-Type'), $exception->getHeaders()['Content-Type'] ?? null);
        self::assertStringContainsString('HTTP/1.1 202', $exception->toHttpString());
    }

    public function testResponseTerminateExceptionDoesNotDuplicateExplicitContentLength(): void
    {
        $response = Response::text('abc', 200, 'application/json; charset=utf-8');
        $response->setHeader('Content-Length', '3');

        $exception = new ResponseTerminateException($response);
        $http = $exception->toHttpString();

        self::assertSame(1, \substr_count($http, 'Content-Length:'));
        self::assertStringContainsString("Content-Length: 3\r\n", $http);
    }

    public function testHtmlTerminateResponseIsMutatedBeforeEmission(): void
    {
        $this->installTelemetryMutationObserver();

        $response = Response::html('<html><body>hello</body></html>');

        $http = (new ResponseTerminateException($response))->toHttpString();

        $expectedBody = '<html><body>hello</body></html><!--telemetry-mutation-->';
        self::assertStringContainsString($expectedBody, $http);
        self::assertStringContainsString('Content-Length: ' . \strlen($expectedBody) . "\r\n", $http);
    }

    public function testPreparedHtmlResponseSkipsRepeatedMutationDuringEmission(): void
    {
        $this->installTelemetryMutationObserver();

        $response = Response::html('<html><body>hello</body></html>')->markTelemetryPrepared();

        $http = $response->toHttpString(false);

        self::assertStringNotContainsString('<!--telemetry-mutation-->', $http);
        self::assertStringContainsString(
            'Content-Length: ' . \strlen('<html><body>hello</body></html>') . "\r\n",
            $http
        );
    }

    public function testStandaloneResponseDoesNotPolluteGlobalHeaderCollector(): void
    {
        $globalCollector = HeaderCollector::getInstance();
        $globalCollector->setHeader('X-Global', 'keep');

        $response = Response::json(['ok' => true], 200);
        $response->setHeader('X-Detached', 'yes');

        self::assertSame('keep', $globalCollector->getHeader('X-Global'));
        self::assertNull($globalCollector->getHeader('X-Detached'));
        self::assertSame('yes', $response->getHeader('X-Detached'));
    }

    public function testWlsEmitterDoesNotDuplicateExplicitContentLength(): void
    {
        $collector = HeaderCollector::createDetached();
        $collector->setHeader('Content-Type', 'application/json; charset=utf-8');
        $collector->setHeader('Content-Length', '3');

        $http = (new WlsResponseEmitter())->toHttpString($collector, 'abc');

        self::assertSame(1, \substr_count($http, 'Content-Length:'));
        self::assertStringContainsString("Content-Length: 3\r\n", $http);
    }

    public function testFpmEmitterDoesNotDuplicateExplicitContentLength(): void
    {
        $collector = HeaderCollector::createDetached();
        $collector->setHeader('Content-Type', 'application/json; charset=utf-8');
        $collector->setHeader('Content-Length', '3');

        $http = (new FpmResponseEmitter())->toHttpString($collector, 'abc');

        self::assertSame(1, \substr_count($http, 'Content-Length:'));
        self::assertStringContainsString("Content-Length: 3\r\n", $http);
    }

    private function installTelemetryMutationObserver(): void
    {
        $registry = $this->createMock(EventRegistryInterface::class);
        $registry->method('hasObservers')
            ->willReturnCallback(static fn(string $eventName): bool => $eventName === 'Weline_Framework::telemetry::request_collected');
        $registry->method('getRegistry')->willReturn([
            'events' => [
                'Weline_Framework::telemetry::request_collected' => [
                    'observers' => [[
                        'instance' => ResponseTestMutationObserver::class,
                        'disabled' => 'false',
                        'sort' => 0,
                    ]],
                ],
            ],
        ]);
        $registry->method('matchPattern')->willReturn(false);

        $reader = $this->createMock(XmlReader::class);
        $eventsManager = new EventsManager($reader, $registry);
        ObjectManager::setInstance(EventsManager::class, $eventsManager);

        $observer = new ResponseTestMutationObserver();
        ObjectManager::setInstance(ResponseTestMutationObserver::class, $observer);

        $request = $this->createMock(Request::class);
        $request->method('getUri')->willReturn('/unit/test');
        $request->method('getMethod')->willReturn('GET');
        $request->method('isBackend')->willReturn(false);
        $request->method('isApiBackend')->willReturn(false);
        $request->method('isApiFrontend')->willReturn(false);
        $request->method('isAjax')->willReturn(false);
        $request->method('isIframe')->willReturn(false);
        ObjectManager::setInstance(Request::class, $request);
    }
}
