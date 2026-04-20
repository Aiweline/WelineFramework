<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\ServerInstanceManager;

class ServerInstanceManagerFastLookupTest extends TestCase
{
    public function testHasInstanceUsesPersistedRecordFastPath(): void
    {
        $this->ensureFrameworkBasePath();
        $manager = $this->createManager([
            'master_pid' => 999999,
            'control_port' => 19999,
            'host' => '127.0.0.1',
            'port' => 9982,
            'ssl_enabled' => false,
            'dispatcher_enabled' => false,
            'count' => 1,
            'worker_port' => 19982,
            'http_redirect_port' => 0,
            'started_at' => '2026-03-23 00:00:00',
            'started_timestamp' => 1774195200,
            'services' => [
                'worker' => [
                    'display_name' => 'HTTP Worker',
                    'instances' => [
                        [
                            'instance_id' => 1,
                            'pid' => 999998,
                            'port' => 19982,
                            'state' => ServiceInstance::STATE_READY,
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($manager->hasInstance('default'));
        $this->assertNotNull($manager->getInstanceInfo('default', false));
    }

    public function testFindRunningInstanceNameByPortUsesRedirectServicePortWhenLegacyFieldIsStale(): void
    {
        $this->ensureFrameworkBasePath();
        $manager = $this->createManager([
            'master_pid' => 999999,
            'control_port' => 19999,
            'host' => '127.0.0.1',
            'port' => 443,
            'ssl_enabled' => true,
            'dispatcher_enabled' => false,
            'count' => 1,
            'worker_port' => 19982,
            'http_redirect_port' => 0,
            'started_at' => '2026-03-23 00:00:00',
            'started_timestamp' => 1774195200,
            'services' => [
                'redirect' => [
                    'display_name' => 'HTTP Redirect',
                    'instances' => [
                        [
                            'instance_id' => 1,
                            'pid' => \getmypid(),
                            'port' => 80,
                            'state' => ServiceInstance::STATE_READY,
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame('default', $manager->findRunningInstanceNameByPort(80));
    }

    public function testGetInstanceInfoDerivesRedirectPortFromHttpsLegacyConfig(): void
    {
        $this->ensureFrameworkBasePath();
        $manager = $this->createManager([
            'master_pid' => \getmypid(),
            'control_port' => 19999,
            'host' => '127.0.0.1',
            'port' => 443,
            'ssl_enabled' => true,
            'dispatcher_enabled' => false,
            'count' => 1,
            'worker_port' => 19982,
            'http_redirect_port' => 0,
            'redirect_pid' => \getmypid(),
            'started_at' => '2026-03-23 00:00:00',
            'started_timestamp' => 1774195200,
        ]);

        $info = $manager->getInstanceInfo('default', false);

        $this->assertNotNull($info);
        $this->assertSame(80, $info->httpRedirectPort);
        $this->assertNotNull($info->getRedirect());
        $this->assertSame(80, $info->getRedirect()?->port);
    }

    private function createManager(array $rawData): ServerInstanceManager
    {
        return new class($rawData) extends ServerInstanceManager {
            public function __construct(private readonly array $rawData)
            {
            }

            public function getAllInstanceInfo(bool $validateStale = true): array
            {
                unset($validateStale);

                $info = $this->getInstanceInfo('default', false);
                return $info === null ? [] : ['default' => $info];
            }

            public function getRawInstanceData(string $name): ?array
            {
                return $name === 'default' ? $this->rawData : null;
            }
        };
    }

    private function ensureFrameworkBasePath(): void
    {
        if (!\defined('BP')) {
            \define('BP', \getcwd() . DIRECTORY_SEPARATOR);
        }
        if (!\defined('DS')) {
            \define('DS', DIRECTORY_SEPARATOR);
        }
        if (!\defined('APP_PATH')) {
            \define('APP_PATH', BP . 'app' . DS);
        }
        if (!\defined('APP_CODE_PATH')) {
            \define('APP_CODE_PATH', APP_PATH . 'code' . DS);
        }
        if (!\defined('APP_ETC_PATH')) {
            \define('APP_ETC_PATH', APP_PATH . 'etc' . DS);
        }
        if (!\defined('DEV_PATH')) {
            \define('DEV_PATH', BP . 'dev' . DS);
        }
        if (!\defined('PUB')) {
            \define('PUB', BP . 'pub' . DS);
        }
    }
}
