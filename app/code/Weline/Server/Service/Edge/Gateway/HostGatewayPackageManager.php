<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

use Weline\Server\Service\Edge\Nginx\Runtime\NginxRuntimeArtifact;

/**
 * Verifies and installs one self-contained WLS 2.0 host-gateway release.
 *
 * Production releases are externally signed. Test packages are accepted only
 * when GatewayPaths has already proved the isolated test-root/high-port
 * contract; a test package can never report release_ready.
 */
final class HostGatewayPackageManager
{
    public const MANIFEST_SCHEMA = 2;
    public const MAX_PACKAGE_BYTES = 536_870_912;

    private const REQUIRED_CAPABILITIES = [
        'broker_sideband_actions',
        'dual_control_channels',
        'native_peer_identity',
        'neutral_default_certificate',
        'no_follow_snapshot',
        'privilege_separation',
        'self_contained_nginx',
        'self_contained_php',
        'singleton_fencing',
    ];

    public function __construct(
        private readonly GatewayPaths $paths = new GatewayPaths(),
        private readonly NginxRuntimeArtifact $artifact = new NginxRuntimeArtifact(),
        private readonly ?string $trustedKeysFile = null,
    ) {
    }

    /**
     * @return array{
     *   slot:string,
     *   slot_dir:string,
     *   runtime_generation:string,
     *   package_digest:string,
     *   release_ready:bool,
     *   test_mode:bool,
     *   profile:string,
     *   previous_active_slot:string
     * }
     */
    public function stage(string $packageDirectory, string $profile): array
    {
        $profile = \strtolower(\trim($profile));
        if (!\in_array($profile, ['default', 'ipv4-only'], true)) {
            throw new \InvalidArgumentException('Gateway profile must be default or ipv4-only.');
        }

        return $this->withInstallLock(function () use ($packageDirectory, $profile): array {
            $verified = $this->verifyPackage($packageDirectory, $profile);
            $this->paths->ensureDirectories();
            $activeFile = $this->paths->activeSlotFile();
            $previousActive = \is_file($activeFile)
                ? \strtoupper(\trim((string)@\file_get_contents($activeFile)))
                : '';
            if (!\in_array($previousActive, ['A', 'B'], true)) {
                $previousActive = '';
            }
            $slot = $this->paths->inactiveSlot();
            $slotDirectory = $this->paths->slotDir($slot);
            if (\file_exists($slotDirectory) || \is_link($slotDirectory)) {
                if (!$this->inactiveSlotMayBeReplaced($slot)) {
                    throw new \RuntimeException(
                        'Inactive gateway slot is still retained for rollback and cannot be replaced.'
                    );
                }
                $this->removeTree($slotDirectory);
                if (\file_exists($slotDirectory) || \is_link($slotDirectory)) {
                    throw new \RuntimeException(
                        'Expired inactive gateway slot could not be safely removed.'
                    );
                }
            }

            $components = [];
            foreach ($verified['manifest']['components'] as $relative => $definition) {
                $components[(string)$relative] = [
                    'source' => $verified['package_dir'] . DIRECTORY_SEPARATOR
                        . \str_replace('/', DIRECTORY_SEPARATOR, (string)$relative),
                    'mode' => (int)$definition['mode'],
                ];
            }
            $components['release/manifest.json'] = [
                'source' => $verified['manifest_file'],
                'mode' => 0600,
            ];
            if ($verified['signature_file'] !== '') {
                $components['release/manifest.sig'] = [
                    'source' => $verified['signature_file'],
                    'mode' => 0600,
                ];
            }

            $hostId = $this->hostId();
            $artifactManifest = $this->artifact->install(
                $slotDirectory,
                'host_gateway',
                $components,
                [
                    'package_digest' => $verified['package_digest'],
                    'package_version' => (string)$verified['manifest']['version'],
                    'protocol_min' => (int)$verified['manifest']['protocol_min'],
                    'protocol_max' => (int)$verified['manifest']['protocol_max'],
                    'security_profile' => (string)$verified['manifest']['security_profile'],
                    'implementation_level' => (string)$verified['manifest']['implementation_level'],
                    'capabilities' => $verified['manifest']['capabilities'],
                    'host_id' => $hostId,
                    'slot' => $slot,
                    'listen_profile' => $profile,
                    'test_mode' => $this->paths->isTestMode(),
                    'release_ready' => (bool)$verified['manifest']['release_ready'],
                ],
            );

            try {
                $this->ensureAdministratorCredential();
                $this->runSlotSelfTests($slotDirectory);
                $launcherComponent = $this->componentPath('wls-gateway-launcher');
                $this->installStableLauncher(
                    $verified['package_dir'] . DIRECTORY_SEPARATOR
                        . \str_replace('/', DIRECTORY_SEPARATOR, $launcherComponent),
                    (string)$verified['manifest']['components'][$launcherComponent]['sha256'],
                );
            } catch (\Throwable $throwable) {
                $this->removeTree($slotDirectory);
                throw $throwable;
            }

            return [
                'slot' => $slot,
                'slot_dir' => $slotDirectory,
                'runtime_generation' => (string)$artifactManifest['runtime_generation'],
                'package_digest' => $verified['package_digest'],
                'release_ready' => (bool)$verified['manifest']['release_ready'],
                'test_mode' => $this->paths->isTestMode(),
                'profile' => $profile,
                'previous_active_slot' => $previousActive,
            ];
        });
    }

    public function activate(string $slot): void
    {
        $this->withInstallLock(function () use ($slot): null {
            $slot = \strtoupper(\trim($slot));
            $verification = $this->artifact->verify($this->paths->slotDir($slot), 'host_gateway');
            if (!($verification['ok'] ?? false)) {
                throw new \RuntimeException(
                    'Gateway slot cannot be activated: ' . (string)($verification['reason'] ?? 'invalid')
                );
            }
            $manifest = $this->installedManifest($slot);
            if (!$this->paths->isTestMode() && !($manifest['release_ready'] ?? false)) {
                throw new \RuntimeException('A non-release-ready gateway slot cannot become active.');
            }
            if ($this->paths->isTestMode() && !($manifest['test_mode'] ?? false)) {
                throw new \RuntimeException('Test gateway cannot activate a production slot.');
            }
            $previous = $this->paths->activeSlot();
            $this->atomicWrite(
                $this->paths->previousSlotFile(),
                $previous . PHP_EOL,
                0640,
            );
            $this->atomicWrite($this->paths->activeSlotFile(), $slot . PHP_EOL, 0640);
            return null;
        });
    }

    /**
     * Persist a signed five-minute observation intent before switching the
     * root-owned active-slot pointer. A crash between those two writes leaves
     * the old slot active; a crash after the pointer write is reconciled by
     * the stable launcher.
     *
     * @param array<string,mixed> $staged
     * @return array<string,mixed>
     */
    public function beginUpgradeActivation(array $staged): array
    {
        return $this->withInstallLock(function () use ($staged): array {
            $to = \strtoupper(\trim((string)($staged['slot'] ?? '')));
            $from = \strtoupper(\trim((string)(
                $staged['previous_active_slot'] ?? ''
            )));
            $runtimeGeneration = \strtolower(\trim((string)(
                $staged['runtime_generation'] ?? ''
            )));
            if (!\in_array($from, ['A', 'B'], true)
                || !\in_array($to, ['A', 'B'], true)
                || $from === $to
                || !\hash_equals($from, $this->paths->activeSlot())
                || \preg_match('/\A[a-f0-9]{64}\z/D', $runtimeGeneration) !== 1
            ) {
                throw new \RuntimeException(
                    'Gateway upgrade activation fence does not match the staged A/B slot.'
                );
            }
            $verification = $this->artifact->verify(
                $this->paths->slotDir($to),
                'host_gateway',
            );
            if (!($verification['ok'] ?? false)
                || !\hash_equals(
                    $runtimeGeneration,
                    (string)($verification['runtime_generation'] ?? ''),
                )
            ) {
                throw new \RuntimeException(
                    'Gateway staged slot changed before upgrade activation.'
                );
            }
            $secret = \strtolower(\trim((string)@\file_get_contents(
                $this->paths->adminTokenFile(),
            )));
            $key = \preg_match('/\A[a-f0-9]{64}\z/D', $secret) === 1
                ? \hex2bin($secret)
                : false;
            if (!\is_string($key) || \strlen($key) !== 32) {
                throw new \RuntimeException(
                    'Gateway administrator credential cannot sign the upgrade intent.'
                );
            }
            $preparedAt = \time();
            $payload = "WLS-UPGRADE/1\n"
                . 'host_id=' . $this->hostId() . "\n"
                . 'from=' . $from . "\n"
                . 'to=' . $to . "\n"
                . 'prepared_at=' . $preparedAt . "\n"
                . 'deadline=' . ($preparedAt + 300) . "\n"
                . 'runtime_generation=' . $runtimeGeneration . "\n"
                . 'nonce=' . \bin2hex(\random_bytes(16)) . "\n";
            $signature = \hash_hmac('sha256', $payload, $key);
            \sodium_memzero($key);
            $intent = $payload . 'signature=' . $signature . "\n";
            $this->atomicWrite($this->paths->upgradeIntentFile(), $intent, 0600);
            try {
                $this->atomicWrite(
                    $this->paths->previousSlotFile(),
                    $from . PHP_EOL,
                    0640,
                );
                $this->atomicWrite(
                    $this->paths->activeSlotFile(),
                    $to . PHP_EOL,
                    0640,
                );
            } catch (\Throwable $throwable) {
                try {
                    $this->atomicWrite(
                        $this->paths->activeSlotFile(),
                        $from . PHP_EOL,
                        0640,
                    );
                } catch (\Throwable) {
                }
                @\unlink($this->paths->upgradeIntentFile());
                throw $throwable;
            }
            return [
                'from' => $from,
                'to' => $to,
                'runtime_generation' => $runtimeGeneration,
                'prepared_at' => $preparedAt,
                'deadline' => $preparedAt + 300,
                'observation_seconds' => 300,
            ];
        });
    }

    public function rollbackUpgradeActivation(string $failedSlot, string $previousSlot): void
    {
        $this->withInstallLock(function () use ($failedSlot, $previousSlot): null {
            $failedSlot = \strtoupper(\trim($failedSlot));
            $previousSlot = \strtoupper(\trim($previousSlot));
            if (!\in_array($failedSlot, ['A', 'B'], true)
                || !\in_array($previousSlot, ['A', 'B'], true)
                || $failedSlot === $previousSlot
                || !\hash_equals($failedSlot, $this->paths->activeSlot())
            ) {
                throw new \RuntimeException(
                    'Gateway upgrade rollback fence no longer matches the active slot.'
                );
            }
            $verification = $this->artifact->verify(
                $this->paths->slotDir($previousSlot),
                'host_gateway',
            );
            if (!($verification['ok'] ?? false)) {
                throw new \RuntimeException(
                    'Gateway previous slot is not valid for upgrade rollback.'
                );
            }
            $this->atomicWrite(
                $this->paths->activeSlotFile(),
                $previousSlot . PHP_EOL,
                0640,
            );
            $this->atomicWrite(
                $this->paths->previousSlotFile(),
                $failedSlot . PHP_EOL,
                0640,
            );
            @\rename(
                $this->paths->upgradeIntentFile(),
                $this->paths->upgradeIntentFile() . '.rolled-back-' . \gmdate('YmdHis'),
            );
            return null;
        });
    }

    public function discardStaged(string $slot): void
    {
        $this->withInstallLock(function () use ($slot): null {
            $slot = \strtoupper(\trim($slot));
            if (!\in_array($slot, ['A', 'B'], true)) {
                throw new \InvalidArgumentException('Gateway slot must be A or B.');
            }
            $activeFile = $this->paths->activeSlotFile();
            $active = \is_file($activeFile)
                ? \strtoupper(\trim((string)@\file_get_contents($activeFile)))
                : '';
            if ($active === $slot) {
                throw new \RuntimeException('Refusing to discard the active gateway slot.');
            }
            $this->removeTree($this->paths->slotDir($slot));
            return null;
        });
    }

    public function rollbackActivation(string $failedSlot, string $previousSlot): void
    {
        $this->withInstallLock(function () use ($failedSlot, $previousSlot): null {
            $failedSlot = \strtoupper(\trim($failedSlot));
            $previousSlot = \strtoupper(\trim($previousSlot));
            $initialActivation = !\in_array($previousSlot, ['A', 'B'], true);
            $activeFile = $this->paths->activeSlotFile();
            $active = \is_file($activeFile)
                ? \strtoupper(\trim((string)@\file_get_contents($activeFile)))
                : '';
            if ($active !== $failedSlot) {
                throw new \RuntimeException('Gateway active slot changed during installation rollback.');
            }
            if (\in_array($previousSlot, ['A', 'B'], true)) {
                $verification = $this->artifact->verify(
                    $this->paths->slotDir($previousSlot),
                    'host_gateway',
                );
                if (!($verification['ok'] ?? false)) {
                    throw new \RuntimeException('Previous gateway slot is not valid for rollback.');
                }
                $this->atomicWrite($activeFile, $previousSlot . PHP_EOL, 0640);
            } elseif (!@\unlink($activeFile) && \is_file($activeFile)) {
                throw new \RuntimeException('Unable to clear failed initial gateway activation.');
            }
            if ($initialActivation) {
                $this->removeFailedInitialBootstrap($failedSlot);
                @\unlink($this->paths->previousSlotFile());
            }
            $this->removeTree($this->paths->slotDir($failedSlot));
            return null;
        });
    }

    private function removeFailedInitialBootstrap(string $failedSlot): void
    {
        $slotDirectory = $this->paths->slotDir($failedSlot);
        $releaseManifestFile = $slotDirectory . DIRECTORY_SEPARATOR
            . 'release' . DIRECTORY_SEPARATOR . 'manifest.json';
        $releaseManifest = !\is_link($releaseManifestFile) && \is_file($releaseManifestFile)
            ? \json_decode((string)@\file_get_contents($releaseManifestFile), true)
            : null;
        $launcherComponent = $this->componentPath('wls-gateway-launcher');
        $expected = \is_array($releaseManifest)
            ? \strtolower((string)($releaseManifest['components'][$launcherComponent]['sha256'] ?? ''))
            : '';
        $launcher = $this->paths->launcherFile();
        $identity = $this->paths->trustDir() . DIRECTORY_SEPARATOR
            . 'stable-launcher.sha256';
        $actual = !\is_link($launcher) && \is_file($launcher)
            ? \strtolower((string)@\hash_file('sha256', $launcher))
            : '';
        $trusted = !\is_link($identity) && \is_file($identity)
            ? \strtolower(\trim((string)@\file_get_contents($identity)))
            : '';
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $expected) !== 1
            || !\hash_equals($expected, $actual)
            || !\hash_equals($expected, $trusted)
        ) {
            throw new \RuntimeException(
                'Failed initial gateway bootstrap identity cannot be safely removed.'
            );
        }
        $nonce = \bin2hex(\random_bytes(8));
        $launcherRollback = $launcher . '.failed-initial.' . $nonce;
        $identityRollback = $identity . '.failed-initial.' . $nonce;
        if (!@\rename($launcher, $launcherRollback)) {
            throw new \RuntimeException(
                'Unable to isolate the failed initial gateway launcher.'
            );
        }
        if (!@\rename($identity, $identityRollback)) {
            @\rename($launcherRollback, $launcher);
            throw new \RuntimeException(
                'Unable to isolate the failed initial gateway launcher identity.'
            );
        }
        @\unlink($launcherRollback);
        @\unlink($identityRollback);
    }

    /** @return array<string,mixed> */
    public function installedManifest(string $slot): array
    {
        $file = $this->paths->slotDir($slot) . DIRECTORY_SEPARATOR . 'manifest.json';
        if (!\is_file($file) || \is_link($file)) {
            throw new \RuntimeException('Installed gateway slot manifest is missing or unsafe.');
        }
        $decoded = \json_decode((string)@\file_get_contents($file), true);
        if (!\is_array($decoded)) {
            throw new \RuntimeException('Installed gateway slot manifest is invalid.');
        }
        return $decoded;
    }

    /**
     * @return array{
     *   package_dir:string,
     *   manifest_file:string,
     *   signature_file:string,
     *   package_digest:string,
     *   manifest:array<string,mixed>
     * }
     */
    public function verifyPackage(string $packageDirectory, string $profile): array
    {
        $realPackage = \realpath($packageDirectory);
        if (!\is_string($realPackage)
            || !\is_dir($realPackage)
            || \is_link($packageDirectory)
            || \str_contains($packageDirectory, "\0")
        ) {
            throw new \RuntimeException('Gateway package directory is missing or unsafe.');
        }
        $manifestFile = $realPackage . DIRECTORY_SEPARATOR . 'manifest.json';
        if (!\is_file($manifestFile) || \is_link($manifestFile)) {
            throw new \RuntimeException('Gateway release manifest is missing or unsafe.');
        }
        $manifestBytes = @\file_get_contents($manifestFile);
        $manifest = \is_string($manifestBytes) ? \json_decode($manifestBytes, true) : null;
        if (!\is_array($manifest)
            || (int)($manifest['schema_version'] ?? 0) !== self::MANIFEST_SCHEMA
            || (int)($manifest['protocol_min'] ?? 0) > 2
            || (int)($manifest['protocol_max'] ?? 0) < 2
            || !\hash_equals(GatewayPaths::SECURITY_PROFILE, (string)($manifest['security_profile'] ?? ''))
            || !\hash_equals(GatewayPaths::IMPLEMENTATION_LEVEL, (string)($manifest['implementation_level'] ?? ''))
            || !\hash_equals(\PHP_OS_FAMILY, (string)($manifest['platform'] ?? ''))
            || !\hash_equals($this->normalizedArch(), $this->normalizeArch((string)($manifest['arch'] ?? '')))
            || \trim((string)($manifest['version'] ?? '')) === ''
            || !\is_array($manifest['components'] ?? null)
            || !\is_array($manifest['capabilities'] ?? null)
        ) {
            throw new \RuntimeException('Gateway release manifest contract or target does not match this host.');
        }
        $declaredProfiles = \array_values(\array_map('strval', (array)($manifest['listen_profiles'] ?? [])));
        if (!\in_array($profile, $declaredProfiles, true)) {
            throw new \RuntimeException('Gateway package does not support the requested listen profile.');
        }
        foreach (self::REQUIRED_CAPABILITIES as $capability) {
            if (($manifest['capabilities'][$capability] ?? false) !== true) {
                throw new \RuntimeException('Gateway package capability is missing: ' . $capability);
            }
        }

        $testPackage = (string)($manifest['package_profile'] ?? '') === 'test';
        if ($this->paths->isTestMode()) {
            if (!$testPackage || ($manifest['release_ready'] ?? true) !== false) {
                throw new \RuntimeException('Test mode only accepts non-release-ready test packages.');
            }
        } elseif ($testPackage || ($manifest['release_ready'] ?? false) !== true) {
            throw new \RuntimeException('Production install requires a release-ready production package.');
        }

        $totalBytes = \strlen($manifestBytes);
        foreach ($this->requiredComponents() as $required) {
            if (!\is_array($manifest['components'][$required] ?? null)) {
                throw new \RuntimeException('Gateway package component is missing: ' . $required);
            }
        }
        foreach ($manifest['components'] as $relative => $definition) {
            $relative = $this->validateRelativePath((string)$relative);
            if (!\is_array($definition)) {
                throw new \RuntimeException('Gateway package component definition is invalid.');
            }
            $source = $realPackage . DIRECTORY_SEPARATOR
                . \str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!\is_file($source) || \is_link($source)) {
                throw new \RuntimeException('Gateway package component is missing or is a link: ' . $relative);
            }
            $realSource = \realpath($source);
            if (!\is_string($realSource) || !$this->pathIsWithin($realSource, $realPackage)) {
                throw new \RuntimeException('Gateway package component escaped its package root: ' . $relative);
            }
            $expectedDigest = \strtolower(\trim((string)($definition['sha256'] ?? '')));
            $actualDigest = @\hash_file('sha256', $source);
            $size = (int)\filesize($source);
            $mode = (int)($definition['mode'] ?? 0);
            if (\preg_match('/\A[a-f0-9]{64}\z/D', $expectedDigest) !== 1
                || !\is_string($actualDigest)
                || !\hash_equals($expectedDigest, $actualDigest)
                || (int)($definition['size'] ?? -1) !== $size
                || $mode < 0400
                || $mode > 0755
            ) {
                throw new \RuntimeException('Gateway package component verification failed: ' . $relative);
            }
            $totalBytes += $size;
            if ($totalBytes > self::MAX_PACKAGE_BYTES) {
                throw new \RuntimeException('Gateway package exceeds the fixed size limit.');
            }
        }
        $provenance = $testPackage
            ? null
            : $this->verifyProductionProvenance($realPackage, $manifest);
        $this->verifySbom(
            $realPackage . DIRECTORY_SEPARATOR . 'sbom.cdx.json',
            $provenance,
        );
        if (\trim((string)@\file_get_contents($realPackage . DIRECTORY_SEPARATOR . 'LICENSES.txt')) === '') {
            throw new \RuntimeException('Gateway package license inventory is empty.');
        }

        $signatureFile = $realPackage . DIRECTORY_SEPARATOR . 'manifest.sig';
        if (!$this->paths->isTestMode()) {
            $this->verifyReleaseSignature($manifestBytes, $signatureFile, (string)($manifest['signing_key_id'] ?? ''));
        } elseif (\is_link($signatureFile)) {
            throw new \RuntimeException('Test package signature path cannot be a symbolic link.');
        }

        return [
            'package_dir' => $realPackage,
            'manifest_file' => $manifestFile,
            'signature_file' => \is_file($signatureFile) ? $signatureFile : '',
            'package_digest' => \hash('sha256', $manifestBytes),
            'manifest' => $manifest,
        ];
    }

    private function runSlotSelfTests(string $slotDirectory): void
    {
        $php = $slotDirectory . DIRECTORY_SEPARATOR
            . \str_replace('/', DIRECTORY_SEPARATOR, $this->componentPath('php'));
        $controller = $slotDirectory . DIRECTORY_SEPARATOR . 'app'
            . DIRECTORY_SEPARATOR . 'controller.php';
        $commands = [
            [$php, $controller, '--self-test'],
            [$php, '--version'],
            [$slotDirectory . DIRECTORY_SEPARATOR
                . \str_replace('/', DIRECTORY_SEPARATOR, $this->componentPath('nginx')), '-V'],
            [$slotDirectory . DIRECTORY_SEPARATOR
                . \str_replace('/', DIRECTORY_SEPARATOR, $this->componentPath('wls-gateway-broker')), '--self-test'],
            [$slotDirectory . DIRECTORY_SEPARATOR
                . \str_replace('/', DIRECTORY_SEPARATOR, $this->componentPath('wls-gateway-launcher')), '--self-test'],
        ];
        foreach ($commands as $command) {
            $result = $this->runCommand($command);
            if ($result['code'] !== 0) {
                throw new \RuntimeException(
                    'Gateway package component self-test failed: '
                    . \basename($command[0]) . ': ' . $result['output']
                );
            }
        }
    }

    private function installStableLauncher(string $source, string $expectedDigest): void
    {
        $target = $this->paths->launcherFile();
        $identityFile = $this->paths->trustDir() . DIRECTORY_SEPARATOR
            . 'stable-launcher.sha256';
        if (\is_file($target) && !\is_link($target)) {
            $actual = \strtolower((string)@\hash_file('sha256', $target));
            if (\preg_match('/\A[a-f0-9]{64}\z/D', $actual) !== 1) {
                throw new \RuntimeException(
                    'Stable gateway launcher identity verification failed.'
                );
            }
            if (\is_file($identityFile) && !\is_link($identityFile)) {
                $trusted = \strtolower(\trim((string)@\file_get_contents($identityFile)));
                if (\preg_match('/\A[a-f0-9]{64}\z/D', $trusted) === 1
                    && \hash_equals($trusted, $actual)
                ) {
                    return;
                }
                throw new \RuntimeException(
                    'Stable gateway launcher identity verification failed.'
                );
            }
            if (\file_exists($identityFile) || \is_link($identityFile)) {
                throw new \RuntimeException(
                    'Stable gateway launcher identity path is unsafe.'
                );
            }

            $activeFile = $this->paths->activeSlotFile();
            $activeSlot = \strtoupper(\trim((string)@\file_get_contents($activeFile)));
            if (!\is_file($activeFile)
                || \is_link($activeFile)
                || !\in_array($activeSlot, ['A', 'B'], true)
            ) {
                throw new \RuntimeException(
                    'Stable gateway launcher cannot establish a trusted legacy identity.'
                );
            }
            $slotDirectory = $this->paths->slotDir($activeSlot);
            $verification = $this->artifact->verify($slotDirectory, 'host_gateway');
            if (!($verification['ok'] ?? false)) {
                throw new \RuntimeException(
                    'Stable gateway launcher active-slot identity is invalid.'
                );
            }
            $releaseManifestFile = $slotDirectory . DIRECTORY_SEPARATOR
                . 'release' . DIRECTORY_SEPARATOR . 'manifest.json';
            $releaseManifest = !\is_link($releaseManifestFile) && \is_file($releaseManifestFile)
                ? \json_decode((string)@\file_get_contents($releaseManifestFile), true)
                : null;
            $launcherComponent = $this->componentPath('wls-gateway-launcher');
            $legacyDigest = \is_array($releaseManifest)
                ? \strtolower((string)($releaseManifest['components'][$launcherComponent]['sha256'] ?? ''))
                : '';
            if (\preg_match('/\A[a-f0-9]{64}\z/D', $legacyDigest) !== 1
                || !\hash_equals($legacyDigest, $actual)
            ) {
                throw new \RuntimeException(
                    'Stable gateway launcher cannot establish a trusted legacy identity.'
                );
            }
            $this->atomicWrite($identityFile, $actual . PHP_EOL, 0600);
            return;
        }
        if (\file_exists($target) || \is_link($target)) {
            throw new \RuntimeException('Stable gateway launcher target is unsafe.');
        }
        if (\file_exists($identityFile) || \is_link($identityFile)) {
            throw new \RuntimeException(
                'Stable gateway launcher identity exists without its launcher.'
            );
        }
        $temporary = $target . '.candidate.' . \bin2hex(\random_bytes(8));
        if (!@\copy($source, $temporary)) {
            throw new \RuntimeException('Unable to stage the stable gateway launcher.');
        }
        @\chmod($temporary, 0755);
        $actual = @\hash_file('sha256', $temporary);
        if (!\is_string($actual)
            || !\hash_equals($expectedDigest, $actual)
            || !@\rename($temporary, $target)
        ) {
            @\unlink($temporary);
            throw new \RuntimeException('Stable gateway launcher verification or activation failed.');
        }
        @\chmod($target, 0755);
        try {
            $this->atomicWrite($identityFile, $expectedDigest . PHP_EOL, 0600);
        } catch (\Throwable $throwable) {
            @\unlink($target);
            if (\file_exists($target) || \is_link($target)) {
                throw new \RuntimeException(
                    'Stable gateway launcher identity publication failed and launcher rollback was incomplete.',
                    0,
                    $throwable,
                );
            }
            throw $throwable;
        }
    }

    private function hostId(): string
    {
        $file = $this->paths->hostIdFile();
        $existing = \trim((string)@\file_get_contents($file));
        if (\preg_match('/\A[a-f0-9]{32}\z/D', $existing) === 1) {
            return $existing;
        }
        $hostId = \bin2hex(\random_bytes(16));
        $this->atomicWrite($file, $hostId . PHP_EOL, 0600);
        return $hostId;
    }

    private function ensureAdministratorCredential(): void
    {
        $file = $this->paths->adminTokenFile();
        $existing = \strtolower(\trim((string)@\file_get_contents($file)));
        if (\is_file($file)
            && !\is_link($file)
            && \preg_match('/\A[a-f0-9]{64}\z/D', $existing) === 1
        ) {
            return;
        }
        if (\file_exists($file) || \is_link($file)) {
            throw new \RuntimeException('Gateway administrator credential path is unsafe.');
        }
        $this->atomicWrite($file, \bin2hex(\random_bytes(32)) . PHP_EOL, 0600);
    }

    private function verifyReleaseSignature(string $manifest, string $signatureFile, string $keyId): void
    {
        if (!\function_exists('sodium_crypto_sign_verify_detached')
            || !\is_file($signatureFile)
            || \is_link($signatureFile)
            || $keyId === ''
        ) {
            throw new \RuntimeException('Gateway release signature prerequisites are unavailable.');
        }
        $keysFile = $this->trustedKeysFile
            ?? \dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'env'
                . DIRECTORY_SEPARATOR . 'gateway' . DIRECTORY_SEPARATOR . 'trusted-release-keys.json';
        $keys = \json_decode((string)@\file_get_contents($keysFile), true);
        $key = null;
        foreach ((array)($keys['keys'] ?? []) as $candidate) {
            if (\is_array($candidate)
                && ($candidate['enabled'] ?? false) === true
                && \hash_equals($keyId, (string)($candidate['id'] ?? ''))
                && \hash_equals('ed25519', (string)($candidate['algorithm'] ?? ''))
            ) {
                $key = \base64_decode((string)($candidate['public_key_base64'] ?? ''), true);
                break;
            }
        }
        $signature = \base64_decode(\trim((string)@\file_get_contents($signatureFile)), true);
        if (!\is_string($key)
            || \strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || !\is_string($signature)
            || \strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || !\sodium_crypto_sign_verify_detached($signature, $manifest, $key)
        ) {
            throw new \RuntimeException('Gateway release signature is not trusted.');
        }
    }

    /**
     * @param array<string,array<string,mixed>>|null $provenance
     */
    private function verifySbom(string $file, ?array $provenance = null): void
    {
        $decoded = \json_decode((string)@\file_get_contents($file), true);
        if (!\is_array($decoded)
            || !\hash_equals('CycloneDX', (string)($decoded['bomFormat'] ?? ''))
            || !\is_array($decoded['components'] ?? null)
            || $decoded['components'] === []
        ) {
            throw new \RuntimeException('Gateway CycloneDX SBOM is missing or invalid.');
        }
        if ($provenance === null) {
            return;
        }
        $sbomComponents = [];
        foreach ($decoded['components'] as $component) {
            if (\is_array($component)
                && \is_string($component['name'] ?? null)
                && (string)$component['name'] !== ''
            ) {
                $sbomComponents[(string)$component['name']] = $component;
            }
        }
        foreach ($provenance as $name => $definition) {
            $component = $sbomComponents[$name] ?? null;
            $hashMatched = false;
            foreach ((array)($component['hashes'] ?? []) as $hash) {
                if (\is_array($hash)
                    && \hash_equals('SHA-256', (string)($hash['alg'] ?? ''))
                    && \hash_equals(
                        (string)$definition['binary_sha256'],
                        \strtolower((string)($hash['content'] ?? '')),
                    )
                ) {
                    $hashMatched = true;
                    break;
                }
            }
            $licenseMatched = false;
            foreach ((array)($component['licenses'] ?? []) as $license) {
                if (\is_array($license)
                    && \hash_equals(
                        (string)$definition['license'],
                        (string)($license['license']['name'] ?? ''),
                    )
                ) {
                    $licenseMatched = true;
                    break;
                }
            }
            if (!\is_array($component)
                || !\hash_equals(
                    (string)$definition['version'],
                    (string)($component['version'] ?? ''),
                )
                || !$hashMatched
                || !$licenseMatched
            ) {
                throw new \RuntimeException(
                    'Gateway CycloneDX SBOM does not match provenance: ' . $name
                );
            }
        }
    }

    /**
     * @param array<string,mixed> $manifest
     * @return array<string,array<string,mixed>>
     */
    private function verifyProductionProvenance(
        string $package,
        array $manifest,
    ): array {
        $file = $package . DIRECTORY_SEPARATOR . 'provenance.json';
        $decoded = \json_decode((string)@\file_get_contents($file), true);
        if (!\is_array($decoded)
            || (int)($decoded['schema_version'] ?? 0) !== 1
            || !\hash_equals(
                (string)$manifest['platform'],
                (string)($decoded['target']['platform'] ?? ''),
            )
            || !\hash_equals(
                (string)$manifest['arch'],
                $this->normalizeArch((string)($decoded['target']['arch'] ?? '')),
            )
            || !\is_array($decoded['components'] ?? null)
        ) {
            throw new \RuntimeException('Gateway production provenance is missing or invalid.');
        }
        $suffix = \PHP_OS_FAMILY === 'Windows' ? '.exe' : '';
        $files = [
            'controller' => 'app/controller.php',
            'php' => 'bin/php' . $suffix,
            'nginx' => 'bin/nginx' . $suffix,
            'wls-gateway-broker' => 'bin/wls-gateway-broker' . $suffix,
            'wls-gateway-launcher' => 'bin/wls-gateway-launcher' . $suffix,
        ];
        $verified = [];
        foreach ($files as $name => $relative) {
            $definition = $decoded['components'][$name] ?? null;
            $binary = $package . DIRECTORY_SEPARATOR
                . \str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!\is_array($definition)
                || \trim((string)($definition['version'] ?? '')) === ''
                || \trim((string)($definition['source_url'] ?? '')) === ''
                || \preg_match(
                    '/\A[a-f0-9]{64}\z/D',
                    \strtolower((string)($definition['source_sha256'] ?? '')),
                ) !== 1
                || \trim((string)($definition['license'] ?? '')) === ''
                || !\hash_equals(
                    \strtolower((string)($definition['binary_sha256'] ?? '')),
                    (string)@\hash_file('sha256', $binary),
                )
                || ($name !== 'controller'
                    && ($definition['self_contained'] ?? false) !== true)
            ) {
                throw new \RuntimeException(
                    'Gateway production provenance does not match component: ' . $name
                );
            }
            $definition['binary_sha256'] = \strtolower(
                (string)$definition['binary_sha256'],
            );
            $verified[$name] = $definition;
        }
        return $verified;
    }

    private function validateRelativePath(string $relative): string
    {
        $relative = \str_replace('\\', '/', \trim($relative));
        if ($relative === ''
            || \str_starts_with($relative, '/')
            || \preg_match('/\A[A-Za-z]:/', $relative) === 1
            || \in_array('..', \explode('/', $relative), true)
            || \str_contains($relative, "\0")
        ) {
            throw new \RuntimeException('Gateway package paths must be relative and contained.');
        }
        return $relative;
    }

    private function normalizedArch(): string
    {
        return $this->normalizeArch((string)\php_uname('m'));
    }

    /** @return list<string> */
    private function requiredComponents(): array
    {
        return [
            'app/controller.php',
            $this->componentPath('nginx'),
            $this->componentPath('php'),
            $this->componentPath('wls-gateway-broker'),
            $this->componentPath('wls-gateway-launcher'),
            'LICENSES.txt',
            'provenance.json',
            'sbom.cdx.json',
        ];
    }

    private function componentPath(string $name): string
    {
        return 'bin/' . $name . (\PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
    }

    private function normalizeArch(string $arch): string
    {
        return match (\strtolower(\trim($arch))) {
            'amd64', 'x86_64' => 'x86_64',
            'aarch64', 'arm64' => 'arm64',
            default => \strtolower(\trim($arch)),
        };
    }

    private function pathIsWithin(string $path, string $root): bool
    {
        $path = \rtrim(\str_replace('\\', '/', $path), '/');
        $root = \rtrim(\str_replace('\\', '/', $root), '/');
        if (\PHP_OS_FAMILY === 'Windows') {
            $path = \strtolower($path);
            $root = \strtolower($root);
        }
        return \str_starts_with($path . '/', $root . '/');
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function withInstallLock(callable $callback): mixed
    {
        $this->paths->ensureDirectories();
        $lockFile = $this->paths->stateDir() . DIRECTORY_SEPARATOR . 'install.lock';
        $handle = @\fopen($lockFile, 'c+b');
        if (!\is_resource($handle) || !@\flock($handle, LOCK_EX)) {
            \is_resource($handle) && @\fclose($handle);
            throw new \RuntimeException('Unable to acquire the host-gateway installation lock.');
        }
        try {
            return $callback();
        } finally {
            @\flock($handle, LOCK_UN);
            @\fclose($handle);
        }
    }

    private function inactiveSlotMayBeReplaced(string $slot): bool
    {
        $rolledBack = $this->paths->trustDir() . DIRECTORY_SEPARATOR
            . 'upgrade-rolled-back';
        if (\is_file($rolledBack) && !\is_link($rolledBack)) {
            $contents = (string)@\file_get_contents($rolledBack);
            if (\preg_match(
                '/\AWLS-UPGRADE-ROLLED-BACK\\/1\\nslot=([AB])\\nat=[0-9]+\\n\z/D',
                $contents,
                $matches,
            ) === 1 && \hash_equals($slot, (string)$matches[1])) {
                @\unlink($rolledBack);
                return true;
            }
        }
        $retention = $this->paths->trustDir() . DIRECTORY_SEPARATOR
            . 'slot-retention';
        if (!\is_file($retention) || \is_link($retention)) {
            return false;
        }
        $contents = (string)@\file_get_contents($retention);
        if (\preg_match(
            '/\AWLS-SLOT-RETENTION\\/1\\nslot=([AB])\\nretain_until=([0-9]+)\\n\z/D',
            $contents,
            $matches,
        ) !== 1
            || !\hash_equals($slot, (string)$matches[1])
            || (int)$matches[2] > \time()
        ) {
            return false;
        }
        @\unlink($retention);
        return true;
    }

    private function atomicWrite(string $path, string $contents, int $mode): void
    {
        $temporary = $path . '.candidate.' . \bin2hex(\random_bytes(8));
        $handle = @\fopen($temporary, 'xb');
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to stage a gateway host state file.');
        }
        $failure = null;
        try {
            if (@\fwrite($handle, $contents) !== \strlen($contents)) {
                throw new \RuntimeException('Unable to write a gateway host state file.');
            }
            $parent = @\stat(\dirname($path));
            if (\PHP_OS_FAMILY !== 'Windows') {
                if (!\is_array($parent)
                    || !isset($parent['uid'], $parent['gid'])
                ) {
                    throw new \RuntimeException(
                        'Unable to resolve gateway host state parent ownership.'
                    );
                }
                $ownerApplied = \function_exists('fchown')
                    && @\fchown($handle, (int)$parent['uid']);
                if (!$ownerApplied && \function_exists('chown')) {
                    $ownerApplied = @\chown($temporary, (int)$parent['uid']);
                }
                $groupApplied = \function_exists('fchgrp')
                    && @\fchgrp($handle, (int)$parent['gid']);
                if (!$groupApplied && \function_exists('chgrp')) {
                    $groupApplied = @\chgrp($temporary, (int)$parent['gid']);
                }
                if (!$ownerApplied || !$groupApplied) {
                    throw new \RuntimeException(
                        'Unable to inherit gateway host state ownership.'
                    );
                }
            }
            @\fflush($handle);
            \function_exists('fsync') && @\fsync($handle);
        } catch (\Throwable $throwable) {
            $failure = $throwable;
        } finally {
            @\fclose($handle);
        }
        if ($failure instanceof \Throwable) {
            @\unlink($temporary);
            throw $failure;
        }
        @\chmod($temporary, $mode);
        if (!@\rename($temporary, $path)) {
            @\unlink($temporary);
            throw new \RuntimeException('Unable to publish a gateway host state file.');
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

    private function removeTree(string $directory): void
    {
        if (!\is_dir($directory) || \is_link($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $item->isDir() && !$item->isLink() ? @\rmdir($path) : @\unlink($path);
        }
        @\rmdir($directory);
    }
}
