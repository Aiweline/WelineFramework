<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

use Weline\Framework\System\Process\Processer;
use Weline\Server\Service\Edge\Nginx\ManagedNginxPaths;

/**
 * Installs and controls the project-independent host gateway runtime.
 */
final class GatewayHostManager
{
    public function __construct(
        private readonly GatewayPaths $paths = new GatewayPaths(),
        private readonly GatewayClient $client = new GatewayClient(),
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function status(): array
    {
        try {
            $response = $this->client->status();
            if (!($response['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'ready' => false,
                    'reason' => (string)($response['error']['message'] ?? 'Gateway status rejected.'),
                ];
            }
            $payload = \is_array($response['payload'] ?? null) ? $response['payload'] : [];
            return ['ok' => true] + $payload;
        } catch (\Throwable $throwable) {
            return [
                'ok' => false,
                'ready' => false,
                'reason' => $throwable->getMessage(),
                'home' => $this->paths->home(),
            ];
        }
    }

    /**
     * Establish or adopt a trusted WLS 2.0 gateway.
     *
     * @return array<string,mixed>
     */
    public function prepare(): array
    {
        $status = $this->status();
        if (($status['ok'] ?? false)
            && ($status['protocol'] ?? '') === GatewayPaths::PROTOCOL
            && ($status['ready'] ?? false)
            && ($status['supervisor_ready'] ?? false)
        ) {
            return $status + ['established' => false];
        }

        $ownedDataPlane = $this->hasOwnedDataPlane();
        $portCheck = $ownedDataPlane
            ? ['ok' => true, 'reason' => 'verified gateway data plane will be adopted']
            : $this->publicPortsAvailable();
        if (!($portCheck['ok'] ?? false)) {
            return [
                'ok' => false,
                'ready' => false,
                'state' => 'PORT_TAKEN',
                'reason' => (string)$portCheck['reason'],
                'owner' => 'unknown',
            ];
        }

        try {
            if ($ownedDataPlane) {
                $activeSlot = $this->paths->activeSlot();
                $controller = $this->paths->slotDir($activeSlot) . DIRECTORY_SEPARATOR . 'controller.php';
                if (!\is_file($controller)) {
                    throw new \RuntimeException('Owned gateway data plane has no matching controller slot.');
                }
                $installed = ['controller' => $controller];
            } else {
                $installed = $this->installInactiveSlot();
            }
            $supervisor = $this->installPlatformSupervisor((string)$installed['controller']);
            if (!($supervisor['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'ready' => false,
                    'state' => 'SUPERVISOR_UNAVAILABLE',
                    'reason' => (string)($supervisor['message'] ?? 'Platform supervisor installation failed.'),
                ];
            }
            $this->startPlatformSupervisor((string)$installed['controller'], (string)$supervisor['kind']);
        } catch (\Throwable $throwable) {
            return [
                'ok' => false,
                'ready' => false,
                'state' => 'INSTALL_FAILED',
                'reason' => $throwable->getMessage(),
            ];
        }

        $deadline = \microtime(true) + 15.0;
        do {
            \usleep(100000);
            $status = $this->status();
            if (($status['ok'] ?? false)
                && ($status['ready'] ?? false)
                && ($status['supervisor_ready'] ?? false)
            ) {
                return $status + ['established' => true];
            }
        } while (\microtime(true) < $deadline);

        return [
            'ok' => false,
            'ready' => false,
            'state' => 'START_TIMEOUT',
            'reason' => (string)($status['reason'] ?? 'Gateway did not become ready within 15 seconds.'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function register(string $instanceName): array
    {
        $builder = new GatewayRegistrationBuilder();
        $registration = $builder->build($instanceName);
        $status = $this->status();
        if (!($status['ok'] ?? false) || !($status['ready'] ?? false)) {
            throw new \RuntimeException('WLS Gateway is not ready for project registration.');
        }
        $registration['gateway_epoch'] = (string)($status['epoch'] ?? '');
        $enrollment = $this->client->request('enroll', [
            'project_uuid' => (string)$registration['project_uuid'],
            'project_root' => (string)$registration['project_root'],
            'certificate_roots' => (array)$registration['certificate_roots'],
        ]);
        if (!($enrollment['ok'] ?? false)) {
            throw new \RuntimeException(
                (string)($enrollment['error']['message'] ?? 'Gateway enrollment failed.')
            );
        }
        $response = $this->client->request('register', $registration);
        if (!($response['ok'] ?? false)) {
            throw new \RuntimeException(
                (string)($response['error']['message'] ?? 'Gateway registration failed.')
            );
        }
        return (array)($response['payload'] ?? []);
    }

    /**
     * @return array<string,mixed>
     */
    public function renew(string $instanceName): array
    {
        $builder = new GatewayRegistrationBuilder();
        $registration = $builder->build($instanceName);
        $routesResponse = $this->client->request('routes');
        $expected = [];
        foreach ((array)($routesResponse['payload']['routes'] ?? []) as $route) {
            if (\is_array($route)
                && (string)($route['project_uuid'] ?? '') === (string)$registration['project_uuid']
            ) {
                $expected[(string)$route['route_id']] = (int)($route['route_generation'] ?? 0);
            }
        }
        $status = $this->status();
        $registration['gateway_epoch'] = (string)($status['epoch'] ?? '');
        $registration['expected_route_generations'] = $expected;
        $response = $this->client->request('renew', $registration);
        if (!($response['ok'] ?? false)) {
            throw new \RuntimeException(
                (string)($response['error']['message'] ?? 'Gateway certificate renew failed.')
            );
        }
        return (array)($response['payload'] ?? []);
    }

    /**
     * @return array<string,mixed>
     */
    public function heartbeat(string $instanceName): array
    {
        $builder = new GatewayRegistrationBuilder();
        $registration = $builder->build($instanceName);
        $response = $this->client->request('heartbeat', [
            'project_uuid' => (string)$registration['project_uuid'],
            'project_generation' => (int)$registration['project_generation'],
        ]);
        if (!($response['ok'] ?? false)) {
            throw new \RuntimeException(
                (string)($response['error']['message'] ?? 'Gateway heartbeat failed.')
            );
        }
        return (array)($response['payload'] ?? []);
    }

    /**
     * @return array<string,mixed>
     */
    public function drain(string $instanceName, int $seconds = 300): array
    {
        $builder = new GatewayRegistrationBuilder();
        $response = $this->client->request('drain', [
            'project_uuid' => $builder->projectUuid(),
            'instance_id' => $instanceName,
            'seconds' => \max(1, \min(300, $seconds)),
        ]);
        if (!($response['ok'] ?? false)) {
            if (\str_contains(
                \strtolower((string)($response['error']['message'] ?? '')),
                'no registered route',
            )) {
                return ['accepted' => true, 'idempotent' => true, 'already_removed' => true];
            }
            throw new \RuntimeException(
                (string)($response['error']['message'] ?? 'Gateway drain failed.')
            );
        }
        return (array)($response['payload'] ?? []);
    }

    /**
     * @return array<string,mixed>
     */
    public function unregister(string $instanceName): array
    {
        $builder = new GatewayRegistrationBuilder();
        $response = $this->client->request('unregister', [
            'project_uuid' => $builder->projectUuid(),
            'instance_id' => $instanceName,
        ]);
        if (!($response['ok'] ?? false)) {
            throw new \RuntimeException(
                (string)($response['error']['message'] ?? 'Gateway unregister failed.')
            );
        }
        return (array)($response['payload'] ?? []);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function request(string $operation, array $payload = []): array
    {
        return $this->client->request($operation, $payload);
    }

    /**
     * Install a tested controller and pinned Nginx binary into the inactive
     * immutable slot, then atomically make it active.
     *
     * @return array{slot:string,controller:string,binary:string}
     */
    public function installInactiveSlot(): array
    {
        $this->paths->ensureDirectories();
        $managed = new ManagedNginxPaths();
        if (!$managed->isInstalled()) {
            throw new \RuntimeException(
                'WLS 2.0 gateway seed binary is missing; run php bin/w server:nginx:install first.'
            );
        }
        $sourceController = \dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'bin'
            . DIRECTORY_SEPARATOR . 'wls_gateway_controller.php';
        if (!\is_file($sourceController)) {
            throw new \RuntimeException('Standalone WLS Gateway controller source is missing.');
        }
        $slot = $this->paths->inactiveSlot();
        $slotDir = $this->paths->slotDir($slot);
        $controller = $slotDir . DIRECTORY_SEPARATOR . 'controller.php';
        $binary = $slotDir . DIRECTORY_SEPARATOR
            . (\PHP_OS_FAMILY === 'Windows' ? 'nginx.exe' : 'nginx');
        $mimeTypes = $slotDir . DIRECTORY_SEPARATOR . 'mime.types';

        $this->copyVerified($sourceController, $controller, 0700);
        $this->copyVerified($managed->binary(), $binary, 0700);
        $sourceMime = $managed->installRoot() . DIRECTORY_SEPARATOR . 'conf'
            . DIRECTORY_SEPARATOR . 'mime.types';
        if (\is_file($sourceMime)) {
            $this->copyVerified($sourceMime, $mimeTypes, 0644);
        } else {
            $this->atomicWrite($mimeTypes, "types {\n  text/html html htm;\n  application/json json;\n}\n", 0644);
        }

        $selfTest = $this->runCommand([\PHP_BINARY, $controller, '--self-test']);
        if (($selfTest['code'] ?? 1) !== 0) {
            throw new \RuntimeException('Gateway controller self-test failed: ' . (string)$selfTest['output']);
        }
        $binaryTest = $this->runCommand([$binary, '-V']);
        if (($binaryTest['code'] ?? 1) !== 0) {
            throw new \RuntimeException('Gateway Nginx binary self-test failed: ' . (string)$binaryTest['output']);
        }

        $manifest = [
            'protocol' => GatewayPaths::PROTOCOL,
            'slot' => $slot,
            'controller_sha256' => (string)\hash_file('sha256', $controller),
            'binary_sha256' => (string)\hash_file('sha256', $binary),
            'seed_project_identity' => \Weline\Server\Service\MasterProcess::getProjectIdentityHash(),
            'installed_at' => \gmdate(DATE_ATOM),
            'php' => \PHP_VERSION,
            'platform' => \PHP_OS_FAMILY,
            'arch' => \php_uname('m'),
        ];
        $this->atomicWrite(
            $slotDir . DIRECTORY_SEPARATOR . 'manifest.json',
            (string)\json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            0600,
        );
        $previous = $this->paths->activeSlot();
        $this->atomicWrite(
            $this->paths->stateDir() . DIRECTORY_SEPARATOR . 'previous-slot',
            $previous . "\n",
            0600,
        );
        $this->atomicWrite($this->paths->activeSlotFile(), $slot . "\n", 0600);
        $this->ensureToken();
        return ['slot' => $slot, 'controller' => $controller, 'binary' => $binary];
    }

    /**
     * @return array{ok:bool,kind:string,message:string}
     */
    private function installPlatformSupervisor(string $controller): array
    {
        $this->paths->ensureDirectories();
        if ((string)\getenv('WLS_GATEWAY_TEST_MODE') === '1') {
            $this->atomicWrite(
                $this->paths->stateDir() . DIRECTORY_SEPARATOR . 'platform-supervisor.ready',
                "test-session\n",
                0600,
            );
            return ['ok' => true, 'kind' => 'test-session', 'message' => 'test session supervisor'];
        }

        if (\PHP_OS_FAMILY === 'Darwin') {
            $userHome = (string)\getenv('HOME');
            if ($userHome === '') {
                return ['ok' => false, 'kind' => 'launchd', 'message' => 'HOME is unavailable for LaunchAgent.'];
            }
            $directory = $userHome . DIRECTORY_SEPARATOR . 'Library' . DIRECTORY_SEPARATOR . 'LaunchAgents';
            if (!\is_dir($directory) && !@\mkdir($directory, 0700, true) && !\is_dir($directory)) {
                return ['ok' => false, 'kind' => 'launchd', 'message' => 'Unable to create LaunchAgents directory.'];
            }
            $plist = $directory . DIRECTORY_SEPARATOR . 'com.weline.wls-gateway-v2.plist';
            $environment = $this->supervisorEnvironmentXml();
            $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                . '<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" '
                . '"http://www.apple.com/DTDs/PropertyList-1.0.dtd">' . "\n"
                . '<plist version="1.0"><dict>'
                . '<key>Label</key><string>com.weline.wls-gateway-v2</string>'
                . '<key>ProgramArguments</key><array><string>' . $this->xml(\PHP_BINARY) . '</string>'
                . '<string>' . $this->xml($controller) . '</string>'
                . '<string>--home=' . $this->xml($this->paths->home()) . '</string></array>'
                . '<key>RunAtLoad</key><true/><key>KeepAlive</key><true/>'
                . '<key>ThrottleInterval</key><integer>5</integer>'
                . '<key>StandardOutPath</key><string>' . $this->xml($this->paths->controllerLogFile()) . '</string>'
                . '<key>StandardErrorPath</key><string>' . $this->xml($this->paths->controllerLogFile()) . '</string>'
                . $environment
                . '</dict></plist>';
            $this->atomicWrite($plist, $xml, 0600);
            $this->atomicWrite(
                $this->paths->stateDir() . DIRECTORY_SEPARATOR . 'platform-supervisor.ready',
                "launchd\n",
                0600,
            );
            $this->atomicWrite(
                $this->paths->stateDir() . DIRECTORY_SEPARATOR . 'platform-supervisor.path',
                $plist . "\n",
                0600,
            );
            return ['ok' => true, 'kind' => 'launchd', 'message' => $plist];
        }

        if (\PHP_OS_FAMILY === 'Linux') {
            $configHome = (string)(\getenv('XDG_CONFIG_HOME') ?: '');
            if ($configHome === '') {
                $userHome = (string)\getenv('HOME');
                if ($userHome === '') {
                    return ['ok' => false, 'kind' => 'systemd-user', 'message' => 'HOME is unavailable for systemd user service.'];
                }
                $configHome = $userHome . DIRECTORY_SEPARATOR . '.config';
            }
            $directory = $configHome . DIRECTORY_SEPARATOR . 'systemd' . DIRECTORY_SEPARATOR . 'user';
            if (!\is_dir($directory) && !@\mkdir($directory, 0700, true) && !\is_dir($directory)) {
                return ['ok' => false, 'kind' => 'systemd-user', 'message' => 'Unable to create systemd user directory.'];
            }
            $service = $directory . DIRECTORY_SEPARATOR . 'weline-wls-gateway-v2.service';
            $unit = "[Unit]\nDescription=Weline WLS 2.0 Host Gateway\nAfter=network.target\n\n"
                . "[Service]\nType=simple\nRestart=always\nRestartSec=5\n"
                . 'ExecStart=' . $this->systemdQuote(\PHP_BINARY) . ' '
                . $this->systemdQuote($controller) . ' '
                . $this->systemdQuote('--home=' . $this->paths->home()) . "\n"
                . 'Environment=WLS_GATEWAY_HOME=' . $this->systemdQuote($this->paths->home()) . "\n"
                . "[Install]\nWantedBy=default.target\n";
            $this->atomicWrite($service, $unit, 0600);
            $this->atomicWrite(
                $this->paths->stateDir() . DIRECTORY_SEPARATOR . 'platform-supervisor.ready',
                "systemd-user\n",
                0600,
            );
            return ['ok' => true, 'kind' => 'systemd-user', 'message' => $service];
        }

        return [
            'ok' => false,
            'kind' => 'windows-service',
            'message' => 'Windows requires an administrator-installed service wrapper; gateway remains not ready.',
        ];
    }

    private function startPlatformSupervisor(string $controller, string $kind): void
    {
        if ($kind === 'test-session') {
            Processer::createDetachedPhpArgv(
                [\PHP_BINARY, $controller, '--home=' . $this->paths->home()],
                (string)BP,
                'weline-wls-gateway-controller --name=wls-gateway-v2',
                true,
                $this->paths->controllerLogFile(),
                $this->paths->controllerLogFile(),
            );
            return;
        }
        if ($kind === 'launchd') {
            $uid = \function_exists('posix_geteuid') ? \posix_geteuid() : (int)\getmyuid();
            $domain = 'gui/' . $uid;
            $plist = \trim((string)@\file_get_contents(
                $this->paths->stateDir() . DIRECTORY_SEPARATOR . 'platform-supervisor.path'
            ));
            if ($plist === '') {
                throw new \RuntimeException('LaunchAgent path is missing.');
            }
            $this->runCommand(['launchctl', 'bootout', $domain . '/com.weline.wls-gateway-v2']);
            $bootstrap = $this->runCommand(['launchctl', 'bootstrap', $domain, $plist]);
            if (($bootstrap['code'] ?? 1) !== 0) {
                throw new \RuntimeException('launchd bootstrap failed: ' . (string)$bootstrap['output']);
            }
            $this->runCommand(['launchctl', 'kickstart', '-k', $domain . '/com.weline.wls-gateway-v2']);
            return;
        }
        if ($kind === 'systemd-user') {
            $reload = $this->runCommand(['systemctl', '--user', 'daemon-reload']);
            $enable = $this->runCommand(['systemctl', '--user', 'enable', '--now', 'weline-wls-gateway-v2.service']);
            if (($reload['code'] ?? 1) !== 0 || ($enable['code'] ?? 1) !== 0) {
                throw new \RuntimeException('systemd user service activation failed: ' . (string)$enable['output']);
            }
            return;
        }
        throw new \RuntimeException('Unsupported platform supervisor: ' . $kind);
    }

    /**
     * @return array{ok:bool,reason:string}
     */
    private function publicPortsAvailable(): array
    {
        $sockets = [];
        try {
            foreach ([
                'tcp://0.0.0.0:' . $this->paths->publicHttpPort(),
                'tcp://0.0.0.0:' . $this->paths->publicHttpsPort(),
            ] as $address) {
                $socket = @\stream_socket_server(
                    $address,
                    $errno,
                    $error,
                    \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
                );
                if (!\is_resource($socket)) {
                    return ['ok' => false, 'reason' => $address . ' is unavailable: ' . $error];
                }
                $sockets[] = $socket;
            }
            return ['ok' => true, 'reason' => 'public IPv4 ports are available'];
        } finally {
            foreach ($sockets as $socket) {
                @\fclose($socket);
            }
        }
    }

    private function hasOwnedDataPlane(): bool
    {
        $pidFile = $this->paths->runDir() . DIRECTORY_SEPARATOR . 'nginx.pid';
        $rawPid = \trim((string)@\file_get_contents($pidFile));
        if ($rawPid === '' || !\ctype_digit($rawPid)) {
            return false;
        }
        $pid = (int)$rawPid;
        if ($pid < 1) {
            return false;
        }
        if (\PHP_OS_FAMILY !== 'Windows' && \function_exists('posix_kill') && !@\posix_kill($pid, 0)) {
            return false;
        }
        $output = [];
        if (\PHP_OS_FAMILY === 'Windows') {
            @\exec('wmic process where processid=' . $pid . ' get CommandLine /value 2>NUL', $output);
        } else {
            @\exec('ps -p ' . $pid . ' -o command= 2>/dev/null', $output);
        }
        $command = \implode("\n", $output);
        foreach (['A', 'B'] as $slot) {
            $slotDir = $this->paths->slotDir($slot);
            $binary = $slotDir . DIRECTORY_SEPARATOR
                . (\PHP_OS_FAMILY === 'Windows' ? 'nginx.exe' : 'nginx');
            $manifestRaw = @\file_get_contents($slotDir . DIRECTORY_SEPARATOR . 'manifest.json');
            $manifest = \is_string($manifestRaw) ? \json_decode($manifestRaw, true) : null;
            $expected = \is_array($manifest) ? (string)($manifest['binary_sha256'] ?? '') : '';
            $actual = @\hash_file('sha256', $binary);
            if ($expected !== ''
                && \is_string($actual)
                && \hash_equals($expected, $actual)
                && \str_contains($command, $binary)
            ) {
                if ($this->paths->activeSlot() !== $slot) {
                    $this->atomicWrite($this->paths->activeSlotFile(), $slot . "\n", 0600);
                }
                return true;
            }
        }
        return false;
    }

    private function ensureToken(): void
    {
        $token = \trim((string)@\file_get_contents($this->paths->tokenFile()));
        if (\preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return;
        }
        $this->atomicWrite($this->paths->tokenFile(), \bin2hex(\random_bytes(32)) . "\n", 0600);
    }

    private function copyVerified(string $source, string $target, int $mode): void
    {
        $sourceHash = @\hash_file('sha256', $source);
        if (!\is_string($sourceHash)) {
            throw new \RuntimeException('Unable to hash gateway seed file: ' . $source);
        }
        $temporary = $target . '.tmp-' . \bin2hex(\random_bytes(6));
        if (!@\copy($source, $temporary)) {
            throw new \RuntimeException('Unable to copy gateway seed file: ' . $source);
        }
        @\chmod($temporary, $mode);
        $targetHash = @\hash_file('sha256', $temporary);
        if (!\is_string($targetHash) || !\hash_equals($sourceHash, $targetHash) || !@\rename($temporary, $target)) {
            @\unlink($temporary);
            throw new \RuntimeException('Gateway seed copy verification failed: ' . $source);
        }
        @\chmod($target, $mode);
    }

    private function atomicWrite(string $path, string $contents, int $mode): void
    {
        $temporary = $path . '.tmp-' . \bin2hex(\random_bytes(6));
        if (@\file_put_contents($temporary, $contents, LOCK_EX) !== \strlen($contents)) {
            @\unlink($temporary);
            throw new \RuntimeException('Unable to write gateway host file: ' . $path);
        }
        @\chmod($temporary, $mode);
        if (!@\rename($temporary, $path)) {
            @\unlink($temporary);
            throw new \RuntimeException('Unable to publish gateway host file: ' . $path);
        }
        @\chmod($path, $mode);
    }

    /**
     * @param list<string> $command
     * @return array{code:int,output:string}
     */
    private function runCommand(array $command): array
    {
        $parts = \array_map(static fn (string $part): string => \escapeshellarg($part), $command);
        $output = [];
        $code = 0;
        @\exec(\implode(' ', $parts) . ' 2>&1', $output, $code);
        return ['code' => $code, 'output' => \implode("\n", $output)];
    }

    private function supervisorEnvironmentXml(): string
    {
        $values = ['WLS_GATEWAY_HOME' => $this->paths->home()];
        foreach ([
            'WLS_GATEWAY_LISTEN_HTTP',
            'WLS_GATEWAY_LISTEN_HTTPS',
            'WLS_GATEWAY_HEALTH_PORT',
            'WLS_GATEWAY_CONTROL_PORT',
        ] as $name) {
            $value = \getenv($name);
            if ($value !== false && \trim((string)$value) !== '') {
                $values[$name] = \trim((string)$value);
            }
        }
        $xml = '<key>EnvironmentVariables</key><dict>';
        foreach ($values as $name => $value) {
            $xml .= '<key>' . $this->xml($name) . '</key><string>' . $this->xml($value) . '</string>';
        }
        return $xml . '</dict>';
    }

    private function xml(string $value): string
    {
        return \htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function systemdQuote(string $value): string
    {
        return '"' . \str_replace(['\\', '"', '%'], ['\\\\', '\\"', '%%'], $value) . '"';
    }
}
