<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Control;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Control\SharedStateAdminService;
use Weline\Server\Service\MemoryStateFacade;
use Weline\Server\Service\SessionStateFacade;
use Weline\Server\Service\SharedStateServiceManager;
use Weline\Server\Shared\Client\SharedStateClient;

final class SharedStateAdminServiceTest extends TestCase
{
    public function testOverviewUsesProbePathWithoutEnsuringFacade(): void
    {
        $sessionFacade = $this->createMock(SessionStateFacade::class);
        $sessionFacade->expects(self::never())->method('ping');
        $sessionFacade->expects(self::never())->method('getStats');

        $memoryFacade = $this->createMock(MemoryStateFacade::class);
        $memoryFacade->expects(self::never())->method('ping');
        $memoryFacade->expects(self::never())->method('getStats');

        $manager = new class extends SharedStateServiceManager {
            public array $peekCalls = [];

            public function peekRuntime(string $role, array $options = []): array
            {
                $this->peekCalls[] = $role;

                return [
                    'host' => '127.0.0.1',
                    'port' => 19970,
                    'token_file_name' => 'session_server.token',
                    'registered' => true,
                    'consumer_count' => 2,
                    'shutdown_due_at' => null,
                ];
            }
        };

        $service = new class($sessionFacade, $memoryFacade, $manager) extends SharedStateAdminService {
            public array $probeCalls = [];

            protected function resolveProbeTokenFileName(string $host, int $port, string $defaultTokenFileName): ?string
            {
                $this->probeCalls[] = ['token', $host, $port, $defaultTokenFileName];

                return 'session_server.shared.token';
            }

            protected function buildStateClient(string $host, int $port, string $tokenFileName): ?SharedStateClient
            {
                $this->probeCalls[] = ['client', $host, $port, $tokenFileName];

                return new class extends SharedStateClient {
                    public function __construct()
                    {
                    }

                    public function request(string $cmd, array $params = []): ?array
                    {
                        return [
                            'ok' => true,
                            'data' => [
                                'client_count' => 3,
                                'session_count' => 7,
                            ],
                        ];
                    }

                    public function disconnect(): void
                    {
                    }
                };
            }
        };

        $overview = $service->getSessionOverview();

        self::assertTrue($overview['connected']);
        self::assertSame('127.0.0.1', $overview['host']);
        self::assertSame(19970, $overview['port']);
        self::assertSame('session_server.shared.token', $overview['token_file_name']);
        self::assertSame(['client_count' => 3, 'session_count' => 7], $overview['stats']);
        self::assertSame(['session'], $manager->peekCalls);
        self::assertSame(
            [
                ['token', '127.0.0.1', 19970, 'session_server.token'],
                ['client', '127.0.0.1', 19970, 'session_server.shared.token'],
            ],
            $service->probeCalls
        );
    }
}
