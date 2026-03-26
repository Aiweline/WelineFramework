<?php

declare(strict_types=1);

namespace Weline\Server\Test\Session;

use PHPUnit\Framework\TestCase;
use Weline\Server\Session\Client\SessionClient;
use Weline\Server\Session\Server\SessionProtocol;
use Weline\Server\Shared\Client\SharedStateClient;

/**
 * Session Server integration test.
 *
 * Runs a real SessionServer subprocess so the blocking client/request flow
 * matches production usage instead of relying on same-thread manual ticking.
 */
class SessionServerIntegrationTest extends TestCase
{
    private ?SessionClient $client = null;

    /** @var resource|null */
    private $serverProcess = null;

    /** @var array<int, resource> */
    private array $serverPipes = [];

    private int $testPort = 0;
    private string $testPersistPath = '';
    private string $tokenFileName = '';
    private string $runnerScriptPath = '';
    private string $stdoutPath = '';
    private string $stderrPath = '';

    protected function setUp(): void
    {
        if (!\function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is required for SessionServer integration test');
        }

        $suffix = \bin2hex(\random_bytes(6));
        $this->testPersistPath = \sys_get_temp_dir() . '/wls_session_integration_' . \getmypid() . '_' . $suffix . '/';
        if (!\is_dir($this->testPersistPath)) {
            \mkdir($this->testPersistPath, 0755, true);
        }

        $this->testPort = $this->reservePort();
        $this->tokenFileName = 'session_server.integration.' . $suffix . '.token';
        $this->runnerScriptPath = $this->testPersistPath . 'session_server_runner.php';
        $this->stdoutPath = $this->testPersistPath . 'session_server.stdout.log';
        $this->stderrPath = $this->testPersistPath . 'session_server.stderr.log';

        $this->writeRunnerScript();
        $this->startServerProcess();

        $this->client = new SessionClient('127.0.0.1', $this->testPort, [
            'connect_timeout' => 0.5,
            'timeout' => 1.0,
            'token_file_name' => $this->tokenFileName,
            'log_connect_fail' => false,
        ]);

        if (!$this->waitUntilServerReady()) {
            $this->stopServerProcess();
            $this->markTestSkipped('Cannot start Session Server on port ' . $this->testPort . ': ' . $this->getServerOutput());
        }
    }

    protected function tearDown(): void
    {
        if ($this->client !== null) {
            $this->client->disconnect();
            $this->client = null;
        }

        $this->stopServerProcess();

        $tokenPath = BP . 'var/session/' . $this->tokenFileName;
        if (\is_file($tokenPath)) {
            @\unlink($tokenPath);
        }

        $this->removePath($this->testPersistPath);
    }

    public function testPing(): void
    {
        $this->assertTrue($this->client->ping());
    }

    public function testSetAndGet(): void
    {
        $sessionId = 'integration_test_session_1';

        $this->assertTrue($this->client->set($sessionId, 'user_id', 12345));
        $this->assertEquals(12345, $this->client->get($sessionId, 'user_id'));
    }

    public function testGetAll(): void
    {
        $sessionId = 'integration_test_session_2';

        $this->assertTrue($this->client->set($sessionId, 'key1', 'value1'));
        $this->assertTrue($this->client->set($sessionId, 'key2', 'value2'));

        $all = $this->client->getAll($sessionId);

        $this->assertIsArray($all);
        $this->assertEquals('value1', $all['key1'] ?? null);
        $this->assertEquals('value2', $all['key2'] ?? null);
    }

    public function testSetAll(): void
    {
        $sessionId = 'integration_test_session_3';
        $data = ['name' => 'test', 'role' => 'admin', 'active' => true];

        $result = $this->client->setAll($sessionId, $data);

        $this->assertTrue($result);
        $this->assertEquals($data, $this->client->getAll($sessionId));
    }

    public function testDelete(): void
    {
        $sessionId = 'integration_test_session_4';

        $this->assertTrue($this->client->set($sessionId, 'key1', 'value1'));
        $this->assertTrue($this->client->set($sessionId, 'key2', 'value2'));
        $this->assertTrue($this->client->delete($sessionId, 'key1'));

        $this->assertNull($this->client->get($sessionId, 'key1'));
        $this->assertEquals('value2', $this->client->get($sessionId, 'key2'));
    }

    public function testDestroy(): void
    {
        $sessionId = 'integration_test_session_5';

        $this->assertTrue($this->client->set($sessionId, 'key', 'value'));
        $this->assertTrue($this->client->exists($sessionId));
        $this->assertTrue($this->client->destroy($sessionId));
        $this->assertFalse($this->client->exists($sessionId));
    }

    public function testExists(): void
    {
        $sessionId = 'integration_test_session_6';

        $this->assertFalse($this->client->exists($sessionId));
        $this->assertTrue($this->client->set($sessionId, 'key', 'value'));
        $this->assertTrue($this->client->exists($sessionId));
    }

    public function testStats(): void
    {
        $this->assertTrue($this->client->set('stats_test_1', 'key', 'value'));
        $this->assertTrue($this->client->set('stats_test_2', 'key', 'value'));

        $stats = $this->client->getStats();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('session_count', $stats);
        $this->assertGreaterThanOrEqual(2, $stats['session_count']);
    }

    public function testPersist(): void
    {
        $this->assertTrue($this->client->set('persist_test', 'key', 'value'));
        $this->assertTrue($this->client->persist());
        $this->assertFileExists($this->testPersistPath . 'wls_session_store.dat');
    }

    public function testTouch(): void
    {
        $sessionId = 'integration_test_session_7';

        $this->assertTrue($this->client->set($sessionId, 'key', 'value'));
        $this->assertTrue($this->client->touch($sessionId, 7200));
    }

    public function testSlidingExpirationOnGetAll(): void
    {
        $sessionId = 'integration_test_session_sliding';

        if (!$this->client->set($sessionId, 'key', 'value', 1)) {
            $this->markTestSkipped('Session server write is unavailable in current environment');
        }

        \usleep(700000);
        $all = $this->client->getAll($sessionId);
        $this->assertSame('value', $all['key'] ?? null);

        \usleep(700000);
        $allAfterSliding = $this->client->getAll($sessionId);
        $this->assertSame('value', $allAfterSliding['key'] ?? null);
    }

    public function testComplexData(): void
    {
        $sessionId = 'integration_test_session_8';
        $complexData = [
            'user' => [
                'id' => 123,
                'name' => 'Test User',
                'roles' => ['admin', 'editor'],
            ],
            'preferences' => [
                'theme' => 'dark',
                'language' => 'zh_CN',
            ],
            'metadata' => [
                'login_time' => \time(),
                'ip' => '192.168.1.1',
            ],
        ];

        $this->assertTrue($this->client->setAll($sessionId, $complexData));
        $this->assertEquals($complexData, $this->client->getAll($sessionId));
    }

    private function writeRunnerScript(): void
    {
        $bp = \str_replace('\\', '\\\\', BP);
        $persistPath = \str_replace('\\', '\\\\', $this->testPersistPath);
        $tokenFileName = \str_replace('\\', '\\\\', $this->tokenFileName);
        $port = $this->testPort;

        $script = <<<PHP
<?php
declare(strict_types=1);

if (!defined('BP')) {
    define('BP', '{$bp}');
}
if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}

require BP . 'app' . DIRECTORY_SEPARATOR . 'autoload.php';

\$server = new \\Weline\\Server\\Session\\Server\\SessionServer([
    'port' => {$port},
    'max_sessions' => 1000,
    'session_ttl' => 3600,
    'persist_path' => '{$persistPath}',
    'token_file_name' => '{$tokenFileName}',
]);

if (!\$server->start('127.0.0.1', {$port})) {
    fwrite(STDERR, (string) (\$server->getLastBindError() ?? 'start failed'));
    exit(1);
}

\$server->run();
exit(0);
PHP;

        \file_put_contents($this->runnerScriptPath, $script);
    }

    private function startServerProcess(): void
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['file', $this->stdoutPath, 'a'],
            2 => ['file', $this->stderrPath, 'a'],
        ];

        $process = \proc_open(
            [PHP_BINARY, $this->runnerScriptPath],
            $descriptorSpec,
            $pipes,
            BP,
        );

        if (!\is_resource($process)) {
            $this->fail('Failed to start SessionServer subprocess.');
        }

        if (isset($pipes[0]) && \is_resource($pipes[0])) {
            @\fclose($pipes[0]);
            unset($pipes[0]);
        }

        $this->serverProcess = $process;
        $this->serverPipes = $pipes;
    }

    private function waitUntilServerReady(): bool
    {
        $tokenPath = BP . 'var/session/' . $this->tokenFileName;
        $deadline = \microtime(true) + 8.0;

        while (\microtime(true) < $deadline) {
            if (!$this->isServerProcessRunning()) {
                return false;
            }

            if (\is_file($tokenPath) && \trim((string) @\file_get_contents($tokenPath)) !== '') {
                if ($this->client !== null && $this->client->ping()) {
                    return true;
                }
            }

            \usleep(100000);
        }

        return false;
    }

    private function stopServerProcess(): void
    {
        if (!\is_resource($this->serverProcess)) {
            return;
        }

        try {
            $controlClient = new SharedStateClient('127.0.0.1', $this->testPort, [
                'token_file_name' => $this->tokenFileName,
                'connect_timeout' => 0.3,
                'timeout' => 0.8,
                'acquire_timeout' => 0.1,
                'log_connect_fail' => false,
            ]);
            $controlClient->request(SessionProtocol::CMD_PERSIST);
            $controlClient->request(SessionProtocol::CMD_SHUTDOWN);
            $controlClient->disconnect();
        } catch (\Throwable) {
            // Best effort: fall through to process termination.
        }

        $deadline = \microtime(true) + 5.0;
        while ($this->isServerProcessRunning() && \microtime(true) < $deadline) {
            \usleep(100000);
        }

        if ($this->isServerProcessRunning()) {
            @\proc_terminate($this->serverProcess);
            \usleep(200000);
        }

        foreach ($this->serverPipes as $pipe) {
            if (\is_resource($pipe)) {
                @\fclose($pipe);
            }
        }
        $this->serverPipes = [];

        @\proc_close($this->serverProcess);
        $this->serverProcess = null;
    }

    private function isServerProcessRunning(): bool
    {
        if (!\is_resource($this->serverProcess)) {
            return false;
        }

        $status = \proc_get_status($this->serverProcess);

        return (bool) ($status['running'] ?? false);
    }

    private function reservePort(): int
    {
        $socket = \stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($socket, $errstr);

        $name = \stream_socket_get_name($socket, false);
        @\fclose($socket);

        self::assertIsString($name);
        $parts = \explode(':', $name);
        $port = (int) \end($parts);
        self::assertGreaterThan(0, $port);

        return $port;
    }

    private function getServerOutput(): string
    {
        $stdout = \is_file($this->stdoutPath) ? \trim((string) @\file_get_contents($this->stdoutPath)) : '';
        $stderr = \is_file($this->stderrPath) ? \trim((string) @\file_get_contents($this->stderrPath)) : '';

        return \trim($stderr . "\n" . $stdout);
    }

    private function removePath(string $path): void
    {
        if ($path === '') {
            return;
        }
        if (\is_file($path) || \is_link($path)) {
            @\unlink($path);
            return;
        }
        if (!\is_dir($path)) {
            return;
        }

        foreach ((array) \glob($path . '*') as $childPath) {
            $this->removePath($childPath);
        }
        foreach ((array) \glob($path . '/*') as $childPath) {
            $this->removePath($childPath);
        }
        @\rmdir($path);
    }
}
