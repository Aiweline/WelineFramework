<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Session;

if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}
if (!defined('BP')) {
    define('BP', dirname(__DIR__, 7) . DS);
}
if (!defined('APP_PATH')) {
    define('APP_PATH', BP . 'app' . DS);
}
if (!defined('APP_CODE_PATH')) {
    define('APP_CODE_PATH', APP_PATH . 'code' . DS);
}
if (!defined('APP_ETC_PATH')) {
    define('APP_ETC_PATH', APP_PATH . 'etc' . DS);
}
if (!defined('DEV_PATH')) {
    define('DEV_PATH', BP . 'dev' . DS);
}
if (!defined('PUB')) {
    define('PUB', BP . 'pub' . DS);
}

use PHPUnit\Framework\TestCase;
use Weline\Server\Session\Server\SessionProtocol;
use Weline\Server\Session\Server\SessionServer;

/**
 * 协议探活必须显式 purpose=protocol_probe 才标记；普通 AUTH / 池化连接不得误标。
 */
final class SessionServerProtocolProbePurposeContractTest extends TestCase
{
    public function testProtocolBuildersDeclareProbePurposeAndNormalAuthDoesNot(): void
    {
        $probeAuth = SessionProtocol::extractMessages(SessionProtocol::buildProtocolProbeAuth('secret-token'));
        self::assertCount(1, $probeAuth);
        self::assertSame(SessionProtocol::CMD_AUTH, $probeAuth[0]['cmd'] ?? null);
        self::assertTrue(SessionProtocol::isProtocolProbePurpose($probeAuth[0]['purpose'] ?? null));

        $normalAuth = SessionProtocol::extractMessages(SessionProtocol::buildAuth('secret-token'));
        self::assertCount(1, $normalAuth);
        self::assertArrayNotHasKey('purpose', $normalAuth[0]);
        self::assertFalse(SessionProtocol::isProtocolProbePurpose($normalAuth[0]['purpose'] ?? null));

        $probePing = SessionProtocol::extractMessages(SessionProtocol::buildProtocolProbePing());
        self::assertCount(1, $probePing);
        self::assertTrue(SessionProtocol::isProtocolProbePurpose($probePing[0]['purpose'] ?? null));

        self::assertFalse(SessionProtocol::isProtocolProbePurpose('health'));
        self::assertFalse(SessionProtocol::isProtocolProbePurpose('protocol_probe '));
        self::assertFalse(SessionProtocol::isProtocolProbePurpose(null));
    }

    public function testProbeAuthMarksClientPurposeWhileNormalAuthDoesNot(): void
    {
        $tokenFileName = 'session-server-probe-purpose-' . \str_replace('.', '-', (string) \microtime(true)) . '.token';
        $persistPath = \sys_get_temp_dir() . '/wls_session_probe_purpose_' . \getmypid() . '/';
        if (!\is_dir($persistPath)) {
            self::assertTrue(@\mkdir($persistPath, 0755, true) || \is_dir($persistPath));
        }

        $server = new SessionServer([
            'port' => 0,
            'persist_path' => $persistPath,
            'token_file_name' => $tokenFileName,
            'persist_enabled' => false,
        ]);

        try {
            self::assertTrue($server->start('127.0.0.1', 0));
            $port = $server->getPort();
            $token = (string) $server->getAuthToken();
            self::assertNotSame('', $token);

            $probeSocket = \stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 3);
            self::assertNotFalse($probeSocket, $errstr);
            \stream_set_blocking($probeSocket, false);
            \fwrite($probeSocket, SessionProtocol::buildProtocolProbeAuth($token));
            $server->tick(50000);
            $server->tick(50000);

            $clients = (new \ReflectionProperty($server, 'clients'))->getValue($server);
            self::assertIsArray($clients);
            self::assertCount(1, $clients);
            $probeClient = \array_values($clients)[0];
            self::assertTrue((bool) ($probeClient['authenticated'] ?? false));
            self::assertSame(
                SessionProtocol::PURPOSE_PROTOCOL_PROBE,
                (string) ($probeClient['connection_purpose'] ?? ''),
                'Probe AUTH must stamp connection_purpose=protocol_probe.',
            );

            $businessSocket = \stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 3);
            self::assertNotFalse($businessSocket, $errstr);
            \stream_set_blocking($businessSocket, false);
            \fwrite($businessSocket, SessionProtocol::buildAuth($token));
            $server->tick(50000);
            $server->tick(50000);

            $clients = (new \ReflectionProperty($server, 'clients'))->getValue($server);
            self::assertIsArray($clients);
            self::assertCount(2, $clients);
            $purposes = [];
            foreach ($clients as $client) {
                self::assertTrue((bool) ($client['authenticated'] ?? false));
                $purposes[] = (string) ($client['connection_purpose'] ?? '');
            }
            \sort($purposes);
            self::assertSame(
                ['', SessionProtocol::PURPOSE_PROTOCOL_PROBE],
                $purposes,
                'Exactly one probe-marked client and one unmarked business client.',
            );

            $serverSource = (string) \file_get_contents(
                \dirname(__DIR__, 3) . '/Session/Server/SessionServer.php'
            );
            self::assertStringContainsString(
                '[探活] Client disconnected:',
                $serverSource,
                'Probe disconnect must log INFO with Chinese [探活] tag.',
            );
            self::assertStringContainsString(
                '[探活] Client authenticated:',
                $serverSource,
                'Probe auth debug must also use [探活] tag.',
            );

            @\fclose($probeSocket);
            @\fclose($businessSocket);
            $server->tick(50000);
            $server->tick(50000);
        } finally {
            $server->stop();
            $tokenPath = \Weline\Server\Service\SharedStateRuntimeScope::tokenFilePath($tokenFileName);
            if (\is_file($tokenPath)) {
                @\unlink($tokenPath);
            }
            if (\is_dir($persistPath)) {
                @\rmdir($persistPath);
            }
        }
    }
}
