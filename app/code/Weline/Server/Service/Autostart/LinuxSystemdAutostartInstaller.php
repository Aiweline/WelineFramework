<?php

declare(strict_types=1);

namespace Weline\Server\Service\Autostart;

use Weline\Server\Service\AdministratorAuthorizationSession;
use Weline\Server\Service\MasterProcess;

/**
 * Ensures a Linux systemd WLS autostart unit exists for the current project/instance.
 */
final class LinuxSystemdAutostartInstaller
{
    public function __construct(
        private readonly LinuxSystemdAutostartPlanner $planner = new LinuxSystemdAutostartPlanner(),
        private readonly ?AdministratorAuthorizationSession $authorizationSession = null,
        private readonly ?\Closure $systemctlRunner = null,
        private readonly ?\Closure $fileReader = null,
        private readonly ?\Closure $privilegedRunner = null,
        private readonly string $osFamily = PHP_OS_FAMILY,
    ) {
    }

    /**
     * @param array<string,mixed> $config Start config snapshot
     * @return array{status:string,message:string,unit?:string,fingerprint?:string}
     */
    public function ensure(array $config, string $instanceName, bool $autoInstall = true): array
    {
        if ($this->osFamily !== 'Linux') {
            return [
                'status' => 'skipped',
                'message' => 'Linux systemd autostart is only managed on Linux.',
            ];
        }
        if (!$autoInstall) {
            return [
                'status' => 'skipped',
                'message' => 'Autostart auto-install disabled.',
            ];
        }

        $spec = $this->buildSpec($config, $instanceName);
        $plan = $this->planner->plan($spec);
        $existing = [
            $plan['service_unit'] => $this->readUnitBody($plan['service_unit']),
            $plan['health_service_unit'] => $this->readUnitBody($plan['health_service_unit']),
            $plan['health_timer_unit'] => $this->readUnitBody($plan['health_timer_unit']),
        ];
        $existing = \array_filter($existing, static fn (?string $body): bool => \is_string($body) && $body !== '');

        if ($this->planner->isInstallSatisfied(
            $existing,
            $plan['fingerprint'],
            $this->isEnabled($plan['service_unit']),
            $this->isEnabled($plan['health_timer_unit']),
        )) {
            return [
                'status' => 'already_installed',
                'message' => 'WLS systemd autostart already installed.',
                'unit' => $plan['service_unit'],
                'fingerprint' => $plan['fingerprint'],
            ];
        }

        $requestPath = $this->writeInstallRequest($plan, $spec);
        $ok = $this->runPrivilegedInstaller($requestPath);
        if (!$ok) {
            return [
                'status' => 'needs_admin',
                'message' => 'Failed to install WLS systemd autostart (administrator authorization required or unavailable).',
                'unit' => $plan['service_unit'],
                'fingerprint' => $plan['fingerprint'],
            ];
        }

        return [
            'status' => 'installed',
            'message' => 'WLS systemd autostart installed.',
            'unit' => $plan['service_unit'],
            'fingerprint' => $plan['fingerprint'],
        ];
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private function buildSpec(array $config, string $instanceName): array
    {
        $projectRoot = \rtrim(\str_replace('\\', '/', (string)BP), '/');
        $phpBinary = \realpath(PHP_BINARY);
        if (!\is_string($phpBinary) || $phpBinary === '') {
            $phpBinary = PHP_BINARY;
        }
        $runUser = \trim((string)(\Weline\Framework\App\Env::get('user') ?: ''));
        if ($runUser === '' && \function_exists('posix_geteuid') && \function_exists('posix_getpwuid')) {
            $info = @\posix_getpwuid((int)\posix_geteuid());
            $runUser = \is_array($info) ? (string)($info['name'] ?? '') : '';
        }
        if ($runUser === '') {
            $runUser = 'www';
        }

        $workerCount = (int)($config['worker_count'] ?? $config['worker_count_requested'] ?? 2);
        if ($workerCount < 1) {
            $workerCount = 2;
        }
        $workerMemoryLimit = (string)($config['worker_memory_limit'] ?? '256M');
        $topology = \strtolower((string)($config['runtime']['topology'] ?? $config['topology'] ?? ''));
        $useDispatcher = !empty($config['dispatcher'])
            || $topology === 'dispatcher'
            || (($config['_cli_dispatcher'] ?? false) === true);

        return [
            'project_root' => $projectRoot,
            'php_binary' => $phpBinary,
            'run_user' => $runUser,
            'instance' => $instanceName !== '' ? $instanceName : 'default',
            'project_hash' => MasterProcess::getProjectIdentityHash(),
            'worker_count' => $workerCount,
            'worker_memory_limit' => $workerMemoryLimit,
            'php_memory_limit' => '1024M',
            'use_dispatcher' => $useDispatcher,
            'cli_memory_flag' => '1024M',
        ];
    }

    private function readUnitBody(string $unitName): ?string
    {
        $path = '/etc/systemd/system/' . $unitName;
        if ($this->fileReader !== null) {
            return ($this->fileReader)($path);
        }
        if (!\is_file($path)) {
            return null;
        }
        $body = @\file_get_contents($path);

        return \is_string($body) ? $body : null;
    }

    private function isEnabled(string $unitName): bool
    {
        $runner = $this->systemctlRunner ?? static function (array $cmd): int {
            $line = '';
            foreach ($cmd as $part) {
                $line .= ($line === '' ? '' : ' ') . \escapeshellarg($part);
            }
            $output = [];
            $code = 0;
            @\exec($line . ' 2>/dev/null', $output, $code);

            return (int)$code;
        };

        return $runner(['systemctl', 'is-enabled', '--', $unitName]) === 0;
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $spec
     */
    private function writeInstallRequest(array $plan, array $spec): string
    {
        $dir = \rtrim((string)BP, '/\\') . '/var/server/autostart';
        if (!\is_dir($dir) && !@\mkdir($dir, 0750, true) && !\is_dir($dir)) {
            throw new \RuntimeException('Unable to create autostart request directory.');
        }
        $path = $dir . '/install-request-' . $plan['unit_basename'] . '.json';
        $payload = [
            'schema' => 1,
            'created_at' => \gmdate('c'),
            'spec' => $spec,
            'plan' => [
                'unit_basename' => $plan['unit_basename'],
                'service_unit' => $plan['service_unit'],
                'health_service_unit' => $plan['health_service_unit'],
                'health_timer_unit' => $plan['health_timer_unit'],
                'script_path' => $plan['script_path'],
                'health_script_path' => $plan['health_script_path'],
                'fingerprint' => $plan['fingerprint'],
                'service_body' => $plan['service_body'],
                'health_service_body' => $plan['health_service_body'],
                'health_timer_body' => $plan['health_timer_body'],
                'script_body' => $plan['script_body'],
                'health_script_body' => $plan['health_script_body'],
            ],
        ];
        $json = \json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!\is_string($json) || $json === '') {
            throw new \RuntimeException('Unable to encode autostart install request.');
        }
        $tmp = $path . '.tmp.' . \bin2hex(\random_bytes(4));
        if (@\file_put_contents($tmp, $json) === false) {
            throw new \RuntimeException('Unable to write autostart install request.');
        }
        @\chmod($tmp, 0640);
        if (!@\rename($tmp, $path)) {
            @\unlink($tmp);
            throw new \RuntimeException('Unable to publish autostart install request.');
        }

        return $path;
    }

    private function runPrivilegedInstaller(string $requestPath): bool
    {
        if ($this->privilegedRunner !== null) {
            return (bool)($this->privilegedRunner)($requestPath);
        }

        $phpBinary = @\realpath(PHP_BINARY);
        $installerCandidate = \dirname(__DIR__, 2)
            . DIRECTORY_SEPARATOR
            . 'bin'
            . DIRECTORY_SEPARATOR
            . 'wls_autostart_privileged_installer.php';
        $installer = @\realpath($installerCandidate);
        if (!\is_string($phpBinary)
            || $phpBinary === ''
            || !\is_file($phpBinary)
            || !\is_executable($phpBinary)
            || !\is_string($installer)
            || $installer === ''
            || !\is_file($installer)
            || \is_link($installerCandidate)
        ) {
            return false;
        }

        $session = $this->authorizationSession ?? new AdministratorAuthorizationSession();

        return $session->runPrivileged([
            $phpBinary,
            '-n',
            $installer,
            '--request=' . $requestPath,
        ]);
    }
}
