<?php
declare(strict_types=1);

namespace Weline\Server\Test\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Provider\DispatcherProvider;
use Weline\Server\Service\Provider\HttpRedirectProvider;
use Weline\Server\Service\Provider\SessionServerProvider;
use Weline\Server\Service\Provider\WorkerProvider;

class ProviderTest extends TestCase
{
    private ServiceContext $context;

    protected function setUp(): void
    {
        $this->context = new ServiceContext(
            instanceName: 'test-instance',
            epoch: 1,
            controlPort: 19000,
            masterPid: 12345,
            host: '0.0.0.0',
            mainPort: 443,
            sslEnabled: true,
            sslCert: '/path/to/cert.pem',
            sslKey: '/path/to/key.pem',
            mode: 'multi',
            daemon: false,
            debug: true,
            frontend: false,
            envConfig: [
                'wls' => [
                    'worker_count' => 4,
                    'worker_base_port' => 10443,
                    'dispatcher_port' => 18080,
                    'session' => [
                        'port' => 18888,
                    ],
                ],
            ],
        );
    }

    public function testWorkerProviderBasic(): void
    {
        $provider = new WorkerProvider();

        $this->assertEquals('worker', $provider->getRole());
        $this->assertEquals('HTTP Worker', $provider->getDisplayName());
        $this->assertTrue($provider->isEnabled($this->context));
        $this->assertEquals(4, $provider->getInstanceCount($this->context));
        $this->assertEquals(20, $provider->getPriority());
        $this->assertEquals('graceful', $provider->getReloadStrategy());
    }

    public function testWorkerProviderBuildCommand(): void
    {
        $provider = new WorkerProvider();
        $command = $provider->buildCommand(1, $this->context);

        $this->assertStringContainsString('worker_ssl.php', $command->script);
        $this->assertContains('--port=10444', $command->arguments);
        $this->assertContains('--instance=test-instance', $command->arguments);
        $this->assertEquals('weline-wls-worker-test-instance-1', $command->getProcessName());
    }

    public function testWorkerProviderPort(): void
    {
        $provider = new WorkerProvider();

        $this->assertEquals(10443, $provider->getPort(0, $this->context));
        $this->assertEquals(10444, $provider->getPort(1, $this->context));
        $this->assertEquals(10445, $provider->getPort(2, $this->context));
    }

    public function testDispatcherProviderBasic(): void
    {
        $provider = new DispatcherProvider();

        $this->assertEquals('dispatcher', $provider->getRole());
        $this->assertEquals('Dispatcher', $provider->getDisplayName());
        $this->assertTrue($provider->isEnabled($this->context));
        $this->assertEquals(1, $provider->getInstanceCount($this->context));
        $this->assertEquals(30, $provider->getPriority());
    }

    public function testDispatcherProviderBuildCommand(): void
    {
        $provider = new DispatcherProvider();
        $command = $provider->buildCommand(0, $this->context);

        $this->assertStringContainsString('dispatcher.php', $command->script);
        $this->assertContains('--port=443', $command->arguments);
        $this->assertContains('--ssl=1', $command->arguments);
        $this->assertContains('--instance=test-instance', $command->arguments);
    }

    public function testDispatcherProviderPort(): void
    {
        $provider = new DispatcherProvider();
        $this->assertEquals(443, $provider->getPort(0, $this->context));
    }

    public function testSessionServerProviderBasic(): void
    {
        $provider = new SessionServerProvider();

        $this->assertEquals('session_server', $provider->getRole());
        $this->assertEquals('Session Server', $provider->getDisplayName());
        $this->assertTrue($provider->isEnabled($this->context));
        $this->assertEquals(1, $provider->getInstanceCount($this->context));
        $this->assertEquals(10, $provider->getPriority());
    }

    public function testSessionServerProviderBuildCommand(): void
    {
        $provider = new SessionServerProvider();
        $command = $provider->buildCommand(0, $this->context);

        $this->assertStringContainsString('session_server.php', $command->script);
        $this->assertContains('18888', $command->arguments);
    }

    public function testSessionServerProviderSupportsLegacyNestedWlsServerPort(): void
    {
        $provider = new SessionServerProvider();
        $context = new ServiceContext(
            instanceName: 'test-instance',
            epoch: 1,
            controlPort: 19000,
            masterPid: 12345,
            host: '0.0.0.0',
            mainPort: 443,
            sslEnabled: true,
            sslCert: '/path/to/cert.pem',
            sslKey: '/path/to/key.pem',
            mode: 'multi',
            daemon: false,
            debug: true,
            frontend: false,
            envConfig: [
                'wls' => [
                    'session' => [
                        'wls_server' => [
                            'port' => 18889,
                        ],
                    ],
                ],
            ],
        );

        $this->assertEquals(18889, $provider->getPort(0, $context));
    }

    public function testSessionServerProviderEnabledForSingleWorkerByDefault(): void
    {
        $provider = new SessionServerProvider();
        $context = new ServiceContext(
            instanceName: 'single-worker',
            epoch: 1,
            controlPort: 19000,
            masterPid: 12345,
            host: '127.0.0.1',
            mainPort: 9981,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            mode: 'multi',
            daemon: true,
            debug: false,
            frontend: false,
            envConfig: [
                'wls' => [
                    'worker_count' => 1,
                ],
            ],
        );

        $this->assertTrue($provider->isEnabled($context));
    }

    public function testSessionServerProviderHonorsExplicitDisable(): void
    {
        $provider = new SessionServerProvider();
        $context = new ServiceContext(
            instanceName: 'single-worker',
            epoch: 1,
            controlPort: 19000,
            masterPid: 12345,
            host: '127.0.0.1',
            mainPort: 9981,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            mode: 'multi',
            daemon: true,
            debug: false,
            frontend: false,
            envConfig: [
                'wls' => [
                    'worker_count' => 1,
                    'session_server' => [
                        'enabled' => false,
                    ],
                ],
            ],
        );

        $this->assertFalse($provider->isEnabled($context));
    }

    public function testHttpRedirectProviderBasic(): void
    {
        $provider = new HttpRedirectProvider();

        $this->assertEquals('redirect', $provider->getRole());
        $this->assertEquals('HTTP Redirect', $provider->getDisplayName());
        $this->assertEquals(1, $provider->getInstanceCount($this->context));
        $this->assertEquals(40, $provider->getPriority());
        $this->assertEquals('immediate', $provider->getReloadStrategy());
    }

    public function testHttpRedirectProviderBuildCommand(): void
    {
        $provider = new HttpRedirectProvider();
        $command = $provider->buildCommand(0, $this->context);

        $this->assertStringContainsString('http_redirect_worker.php', $command->script);
        $this->assertContains('80', $command->arguments);
        $this->assertContains('443', $command->arguments);
    }

    public function testHttpRedirectDisabledWhenHttpsNotStandardPort(): void
    {
        $provider = new HttpRedirectProvider();
        $ctx = new ServiceContext(
            instanceName: 't',
            epoch: 1,
            controlPort: 19000,
            masterPid: 1,
            host: '127.0.0.1',
            mainPort: 9981,
            sslEnabled: true,
            sslCert: '/c',
            sslKey: '/k',
            mode: 'multi',
            daemon: false,
            debug: false,
            frontend: false,
            envConfig: [],
            httpRedirectPort: 0,
        );
        $this->assertFalse($provider->isEnabled($ctx));
    }

    public function testHttpRedirectEnabledWhenMasterPassesPort(): void
    {
        $provider = new HttpRedirectProvider();
        $ctx = new ServiceContext(
            instanceName: 't',
            epoch: 1,
            controlPort: 19000,
            masterPid: 1,
            host: '127.0.0.1',
            mainPort: 9981,
            sslEnabled: true,
            sslCert: '/c',
            sslKey: '/k',
            mode: 'multi',
            daemon: false,
            debug: false,
            frontend: false,
            envConfig: [],
            httpRedirectPort: 9080,
        );
        $this->assertTrue($provider->isEnabled($ctx));
        $this->assertSame(9080, $provider->getPort(0, $ctx));
    }

    public function testHttpRedirectProviderIsEnabledOnlyWithSSL(): void
    {
        $provider = new HttpRedirectProvider();

        $this->assertTrue($provider->isEnabled($this->context));

        $nonSslContext = new ServiceContext(
            instanceName: 'test',
            epoch: 1,
            controlPort: 19000,
            masterPid: 12345,
            host: '0.0.0.0',
            mainPort: 8080,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            mode: 'multi',
            daemon: false,
            debug: false,
            frontend: false,
            envConfig: [],
        );

        $this->assertFalse($provider->isEnabled($nonSslContext));
    }
}
