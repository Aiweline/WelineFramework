<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\FiberOutputBuffer;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\RuntimeInterface;
use Weline\Framework\View\TemplateEnvView;
use Weline\Framework\View\TemplateRequestView;
use Weline\Framework\View\Template;

final class TemplateRequestRefreshTest extends TestCase
{
    private Request $originalRequest;

    protected function setUp(): void
    {
        parent::setUp();
        Template::resetInstance();
        $this->originalRequest = ObjectManager::getInstance(Request::class);
    }

    protected function tearDown(): void
    {
        ObjectManager::setInstance(Request::class, $this->originalRequest);
        Template::resetInstance();
        FiberOutputBuffer::uninstall();
        Runtime::resetModeCache();
        Context::leave();
        parent::tearDown();
    }

    public function testObFileRefreshesLegacyTemplateRequestReference(): void
    {
        $initialRequest = $this->createRequestStub('http://stale.test/stale');
        ObjectManager::setInstance(Request::class, $initialRequest);

        $template = Template::getInstance();

        $currentRequest = $this->createRequestStub('http://fresh.test/fresh');
        ObjectManager::setInstance(Request::class, $currentRequest);

        $tempFile = tempnam(sys_get_temp_dir(), 'weline-template-request-');
        if ($tempFile === false) {
            self::fail('Failed to allocate temp template file.');
        }

        file_put_contents($tempFile, '<?= $this->request->getBaseUrl() ?>');

        try {
            $rendered = $template->ob_file($tempFile, []);
            self::assertSame('http://fresh.test/fresh', trim($rendered));
        } finally {
            @unlink($tempFile);
        }
    }

    public function testInitTreatsCliRequestContextAsRequestRuntime(): void
    {
        Context::enter(new Context([
            'meta' => [
                'type' => 'request',
                'mode' => 'wls',
            ],
        ]));

        $request = $this->createRequestStub('http://fresh.test/fresh');
        ObjectManager::setInstance(Request::class, $request);

        $template = Template::getInstance();

        self::assertSame('Weline_Framework', $template->getData('title'));
        self::assertSame('http://fresh.test/fresh', $template->getData('req')['url'] ?? null);
    }

    public function testInitUsesRequestModulePathFallbackWhenRouterModulePathIsMissing(): void
    {
        Context::enter(new Context([
            'meta' => [
                'type' => 'request',
                'mode' => 'wls',
            ],
        ]));

        $modulePath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'weline-template-module-path-fallback';
        ObjectManager::setInstance(Request::class, $this->createRequestStubWithoutRouterModulePath('http://fallback.test/page', $modulePath));

        $template = Template::getInstance();
        $viewDir = (new \ReflectionClass($template))->getProperty('view_dir')->getValue($template);

        self::assertSame($modulePath . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR, $viewDir);
    }

    public function testLazyRequestProxyTracksCurrentRequestWithoutReinitializingTemplate(): void
    {
        Context::enter(new Context([
            'meta' => [
                'type' => 'request',
                'mode' => 'wls',
            ],
        ]));

        ObjectManager::setInstance(Request::class, $this->createRequestStub('http://bootstrap.test/init'));
        $template = Template::getInstance();
        $reqView = $template->getData('req');

        self::assertInstanceOf(TemplateRequestView::class, $reqView);

        ObjectManager::setInstance(Request::class, $this->createRequestStub('http://first.test/one'));
        self::assertSame('http://first.test/one', $reqView['url'] ?? null);

        ObjectManager::setInstance(Request::class, $this->createRequestStub('http://second.test/two'));
        self::assertSame('http://second.test/two', $reqView['url'] ?? null);
    }

    public function testObFileRestoresTemporaryTemplateDataWhenFiberCaptureOverflows(): void
    {
        Runtime::setMode(RuntimeInterface::MODE_WLS);
        FiberOutputBuffer::install();

        ObjectManager::setInstance(Request::class, $this->createRequestStub('http://overflow.test/workspace'));
        $template = Template::getInstance();
        $template->setData('existing_marker', 'stable');

        $tempFile = tempnam(sys_get_temp_dir(), 'weline-template-overflow-');
        if ($tempFile === false) {
            self::fail('Failed to allocate temp template file.');
        }

        file_put_contents(
            $tempFile,
            '<?php for ($i = 0; $i < 18; $i++) { echo str_repeat("x", 1024 * 1024); }'
        );

        try {
            $template->ob_file($tempFile, [
                'leak_marker' => 'temporary',
                'existing_marker' => 'temporary',
            ]);
            self::fail('Expected oversized template capture to throw.');
        } catch (\OverflowException $exception) {
            self::assertStringContainsString('reason=capture_limit', $exception->getMessage());
        } finally {
            @unlink($tempFile);
        }

        self::assertFalse($template->hasData('leak_marker'));
        self::assertSame('stable', $template->getData('existing_marker'));
    }

    public function testInitUsesLazyEnvProxy(): void
    {
        Context::enter(new Context([
            'meta' => [
                'type' => 'request',
                'mode' => 'wls',
            ],
        ]));

        ObjectManager::setInstance(Request::class, $this->createRequestStub('http://bootstrap.test/init'));
        $template = Template::getInstance();
        $envView = $template->getData('env');

        self::assertInstanceOf(TemplateEnvView::class, $envView);
        self::assertTrue(isset($envView['router']) || isset($envView['system']) || isset($envView['cache']));
    }

    private function createRequestStub(string $baseUrl): Request
    {
        $modulePath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $urlBuilder = new class($baseUrl) extends Url {
            public function __construct(private readonly string $currentUrl)
            {
            }

            public function getCurrentUrl(array $params = [], bool $merge_url_params = true): string
            {
                return $this->currentUrl;
            }
        };

        return new class($baseUrl, $modulePath, $urlBuilder) extends Request {
            public function __construct(
                private readonly string $baseUrl,
                private readonly string $modulePath,
                private readonly Url $urlBuilder
            ) {
            }

            public function getBaseUrl(): string
            {
                return $this->baseUrl;
            }

            public function getRouterData(string $key): mixed
            {
                return $key === 'module_path' ? $this->modulePath : null;
            }

            public function getModuleName(): string
            {
                return 'Weline_Framework';
            }

            public function getParams()
            {
                return [];
            }

            public function getQuery(string $key = '', mixed $default = null)
            {
                return $key === '' ? [] : $default;
            }

            public function getUrlBuilder(): Url
            {
                return $this->urlBuilder;
            }
        };
    }

    private function createRequestStubWithoutRouterModulePath(string $baseUrl, string $modulePath): Request
    {
        $urlBuilder = new class($baseUrl) extends Url {
            public function __construct(private readonly string $currentUrl)
            {
            }

            public function getCurrentUrl(array $params = [], bool $merge_url_params = true): string
            {
                return $this->currentUrl;
            }
        };

        return new class($baseUrl, $modulePath, $urlBuilder) extends Request {
            public function __construct(
                private readonly string $baseUrl,
                private readonly string $modulePath,
                private readonly Url $urlBuilder
            ) {
            }

            public function getBaseUrl(): string
            {
                return $this->baseUrl;
            }

            public function getRouterData(string $key): mixed
            {
                return null;
            }

            public function getModulePath(): string
            {
                return rtrim($this->modulePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            }

            public function getModuleName(): string
            {
                return 'Weline_Framework';
            }

            public function getParams()
            {
                return [];
            }

            public function getQuery(string $key = '', mixed $default = null)
            {
                return $key === '' ? [] : $default;
            }

            public function getUrlBuilder(): Url
            {
                return $this->urlBuilder;
            }
        };
    }
}
