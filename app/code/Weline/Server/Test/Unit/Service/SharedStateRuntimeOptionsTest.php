<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Server\Service\SharedStateRuntimeOptions;

class SharedStateRuntimeOptionsTest extends TestCase
{
    private string $instanceFile = '';

    protected function tearDown(): void
    {
        if ($this->instanceFile !== '' && \is_file($this->instanceFile)) {
            @\unlink($this->instanceFile);
        }
    }

    public function testCliArgsOverrideInstanceAndEnvRuntime(): void
    {
        $this->writeInstanceFile('shared-state-cli', [
            'shared_state' => [
                'session' => [
                    'host' => '127.0.0.10',
                    'port' => 21001,
                    'token_file_name' => 'session.instance.token',
                ],
                'memory' => [
                    'host' => '127.0.0.11',
                    'port' => 21002,
                    'token_file_name' => 'memory.instance.token',
                ],
            ],
        ]);

        $options = SharedStateRuntimeOptions::fromCliArgs(
            [
                'worker.php',
                '127.0.0.1',
                '9981',
                '1',
                'shared-state-cli',
                '--session-host=127.0.0.2',
                '--session-port=29970',
                '--session-token-file-name=session.cli.token',
                '--memory-host=127.0.0.3',
                '--memory-port=29971',
                '--memory-token-file-name=memory.cli.token',
            ],
            'shared-state-cli',
            [
                'session' => ['server_host' => '127.0.0.4', 'server_port' => 19970],
                'wls' => [
                    'session' => [
                        'host' => '127.0.0.5',
                        'port' => 19971,
                        'token_file_name' => 'session.env.token',
                    ],
                    'memory_service' => [
                        'host' => '127.0.0.6',
                        'port' => 19972,
                        'token_file_name' => 'memory.env.token',
                    ],
                ],
            ]
        );

        self::assertSame(
            ['host' => '127.0.0.2', 'port' => 29970, 'token_file_name' => 'session.cli.token'],
            $options->getSession()
        );
        self::assertSame(
            ['host' => '127.0.0.3', 'port' => 29971, 'token_file_name' => 'memory.cli.token'],
            $options->getMemory()
        );
    }

    public function testInstanceRuntimeFallsBackWhenCliArgsMissing(): void
    {
        $this->writeInstanceFile('shared-state-instance', [
            'shared_state' => [
                'session' => [
                    'host' => '127.0.0.20',
                    'port' => 22001,
                    'token_file_name' => 'session.instance.token',
                ],
                'memory' => [
                    'host' => '127.0.0.21',
                    'port' => 22002,
                    'token_file_name' => 'memory.instance.token',
                ],
            ],
        ]);

        $options = SharedStateRuntimeOptions::fromCliArgs(
            ['worker.php', '127.0.0.1', '9981', '1', 'shared-state-instance'],
            'shared-state-instance',
            [
                'session' => ['server_host' => '127.0.0.4', 'server_port' => 19970],
                'wls' => [
                    'session' => [
                        'host' => '127.0.0.5',
                        'port' => 19971,
                        'token_file_name' => 'session.env.token',
                    ],
                    'memory_service' => [
                        'host' => '127.0.0.6',
                        'port' => 19972,
                        'token_file_name' => 'memory.env.token',
                    ],
                ],
            ]
        );

        self::assertSame(
            ['host' => '127.0.0.20', 'port' => 22001, 'token_file_name' => 'session.instance.token'],
            $options->getSession()
        );
        self::assertSame(
            ['host' => '127.0.0.21', 'port' => 22002, 'token_file_name' => 'memory.instance.token'],
            $options->getMemory()
        );
    }

    public function testToEnvOverridesExposeInstanceLocalEndpoints(): void
    {
        $options = new SharedStateRuntimeOptions(
            ['host' => '127.0.0.7', 'port' => 23001, 'token_file_name' => 'session.runtime.token'],
            ['host' => '127.0.0.8', 'port' => 23002, 'token_file_name' => 'memory.runtime.token'],
        );

        self::assertSame(
            [
                'session' => [
                    'server_host' => '127.0.0.7',
                    'server_port' => 23001,
                ],
                'wls' => [
                    'session' => [
                        'host' => '127.0.0.7',
                        'port' => 23001,
                        'token_file_name' => 'session.runtime.token',
                        'wls_server' => [
                            'host' => '127.0.0.7',
                            'port' => 23001,
                            'token_file_name' => 'session.runtime.token',
                        ],
                    ],
                    'memory_service' => [
                        'host' => '127.0.0.8',
                        'port' => 23002,
                        'token_file_name' => 'memory.runtime.token',
                    ],
                ],
            ],
            $options->toEnvOverrides()
        );
    }

    public function testSessionEndpointDoesNotFallbackToMainWlsPortBeforeSessionRuntimePort(): void
    {
        $options = SharedStateRuntimeOptions::fromCliArgs(
            ['worker.php', '127.0.0.1', '9981', '1', 'shared-state-main-port-regression'],
            'shared-state-main-port-regression',
            [
                'session' => ['server_host' => '127.0.0.20', 'server_port' => 19970],
                'wls' => [
                    // 这是主站监听端口，不应作为 Session 服务端口。
                    'session' => [
                        'host' => '127.0.0.1',
                        'port' => 9522,
                        'token_file_name' => 'session.main-port.token',
                        'wls_server' => [
                            'host' => '127.0.0.1',
                            'port' => 9522,
                            'token_file_name' => 'session.main-port.token',
                        ],
                    ],
                    'shared_state' => [
                        'runtime' => [
                            'session' => [
                                'host' => '127.0.0.30',
                                'port' => 29970,
                                'token_file_name' => 'session.runtime.token',
                            ],
                        ],
                    ],
                ],
            ]
        );

        self::assertSame(
            ['host' => '127.0.0.30', 'port' => 29970, 'token_file_name' => 'session.runtime.token'],
            $options->getSession()
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeInstanceFile(string $instanceName, array $data): void
    {
        $dir = Env::VAR_DIR . 'server' . DIRECTORY_SEPARATOR . 'instances' . DIRECTORY_SEPARATOR;
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0777, true);
        }

        $this->instanceFile = $dir . $instanceName . '.json';
        \file_put_contents($this->instanceFile, \json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
