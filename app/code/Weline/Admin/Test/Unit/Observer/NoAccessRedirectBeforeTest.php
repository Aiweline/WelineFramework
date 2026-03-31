<?php

declare(strict_types=1);

namespace Weline\Admin\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Admin\Observer\NoAccessRedirectBefore;
use Weline\Framework\Event\Event;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Response;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;

final class NoAccessRedirectBeforeTest extends TestCase
{
    private Request $originalRequest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalRequest = ObjectManager::getInstance(Request::class);
    }

    protected function tearDown(): void
    {
        ObjectManager::setInstance(Request::class, $this->originalRequest);
        parent::tearDown();
    }

    public function testExecuteUsesCurrentRequestFromObjectManagerUnderWls(): void
    {
        $staleCapture = (object)['url' => null, 'code' => null];
        $freshCapture = (object)['url' => null, 'code' => null];

        $staleRequest = $this->createRequestStub(false, 'stale.example', $staleCapture);
        $observer = new NoAccessRedirectBefore($staleRequest);

        $currentRequest = $this->createRequestStub(true, 'fresh.example', $freshCapture);
        ObjectManager::setInstance(Request::class, $currentRequest);

        $event = new Event(['data' => ['reason' => 'not_logged_in']]);

        try {
            $observer->execute($event);
            self::fail('Expected redirect to be captured.');
        } catch (RedirectCapturedException) {
            self::assertNull($staleCapture->url);
            self::assertSame(302, $freshCapture->code);
            self::assertIsString($freshCapture->url);
            self::assertStringContainsString('fresh.example', $freshCapture->url);
            self::assertStringContainsString('admin/login', $freshCapture->url);
            self::assertStringContainsString('no_access_reason=not_logged_in', $freshCapture->url);
        }
    }

    private function createRequestStub(bool $isBackend, string $host, object $capture): Request
    {
        $response = new class($capture) extends Response {
            public function __construct(private readonly object $capture)
            {
            }

            public function redirect(string $url, int $code = 302): never
            {
                $this->capture->url = $url;
                $this->capture->code = $code;
                throw new RedirectCapturedException();
            }
        };

        $urlBuilder = new class() extends Url {
            public function __construct()
            {
            }

            public function getBackendUrlPath(string $path = '', array $params = [], bool $merge_url_params = false): string
            {
                return '/' . ltrim($path, '/');
            }
        };

        return new class($isBackend, $host, $response, $urlBuilder) extends Request {
            public function __construct(
                private readonly bool $isBackendValue,
                private readonly string $host,
                private readonly Response $response,
                private readonly Url $urlBuilder
            ) {
            }

            public function isBackend(): bool
            {
                return $this->isBackendValue;
            }

            public function isSecure(): bool
            {
                return false;
            }

            public function getServer(string $key = ''): string|array
            {
                $server = [
                    'HTTP_HOST' => $this->host,
                    'SERVER_NAME' => $this->host,
                    'WELINE_AREA_ROUTE' => '',
                    'WELINE_USER_CURRENCY' => 'CNY',
                    'WELINE_USER_LANG' => 'zh_Hans_CN',
                ];

                if ($key === '') {
                    return $server;
                }

                return $server[$key] ?? '';
            }

            public function getResponse(): Response
            {
                return $this->response;
            }

            public function getUrlBuilder(): Url
            {
                return $this->urlBuilder;
            }
        };
    }
}

final class RedirectCapturedException extends \RuntimeException
{
}
