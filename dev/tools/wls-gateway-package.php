<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/code/Weline/Server/Service/Edge/Gateway/GatewayProjectStateFilesystem.php';
require_once __DIR__ . '/../../app/code/Weline/Server/Service/Edge/Gateway/GatewayBoundedTreeWalker.php';
require_once __DIR__ . '/../../app/code/Weline/Server/Service/Edge/Gateway/GatewayBoundedCommandRunner.php';

/**
 * One bounded process primitive for both assembly and isolated signing.
 *
 * The release tool must not reintroduce blocking pipe reads or an unbounded
 * proc_close around attacker-controlled package inputs. On Windows the
 * candidate's native helper is configured before any component is executed;
 * on POSIX the shared runner creates and later verifies a dedicated process
 * group for every command.
 */
final class WlsGatewayPackageCommandRunner
{
    private const WINDOWS_HELPER_MAX_BYTES = 16_777_216;

    /** @var array{path:string,size:int,sha256:string,source:string}|null */
    private static ?array $windowsHelperProof = null;

    public static function configureWindowsHelper(string $path): void
    {
        if (\PHP_OS_FAMILY !== 'Windows') {
            return;
        }
        $canonical = @\realpath($path);
        if (!\is_string($canonical)) {
            throw new \RuntimeException(
                'The Windows package candidate lacks a verifiable bounded-command helper.'
            );
        }
        $bytes = \Weline\Server\Service\Edge\Gateway\GatewayProjectStateFilesystem::read(
            $canonical,
            self::WINDOWS_HELPER_MAX_BYTES,
            'Windows package bounded-command helper',
        );
        $size = \strlen($bytes);
        $digest = \hash('sha256', $bytes);
        self::$windowsHelperProof = [
            'path' => $canonical,
            'size' => $size,
            'sha256' => $digest,
            'source' => 'package-candidate',
        ];
    }

    /**
     * @param list<string> $command
     * @return array{code:int,output:string}
     */
    public static function run(array $command): array
    {
        $result = \Weline\Server\Service\Edge\Gateway\GatewayBoundedCommandRunner::run(
            $command,
            120.0,
            null,
            true,
            self::$windowsHelperProof,
        );

        return [
            'code' => (int)$result['code'],
            'output' => (string)$result['output'],
        ];
    }
}

/**
 * Static dependency audit for binaries that claim to be self-contained.
 *
 * Base operating-system ABI libraries are allowed. TLS, compression, parser
 * and other third-party runtimes must be linked statically; the current
 * package schema has no library directory/RPATH contract, so merely declaring
 * self_contained=true cannot make a host dependency trustworthy.
 */
final class WlsGatewayDependencyAuditor
{
    private const MAX_AUDITOR_TOOL_BYTES = 536_870_912;

    /** @var array<string,array{path:string,sha256:string,size:int,version_exit_code:int,version_output:string}> */
    private static array $toolProofs = [];

    public static function beginAudit(): void
    {
        self::$toolProofs = [];
    }

    /** @return list<array{path:string,sha256:string,size:int,version_exit_code:int,version_output:string}> */
    public static function toolProofs(): array
    {
        $proofs = \array_values(self::$toolProofs);
        \usort(
            $proofs,
            static fn (array $left, array $right): int =>
                \strcmp($left['path'], $right['path']),
        );
        return $proofs;
    }

    public static function assertBaseSystemOnly(
        string $component,
        string $binary,
        string $platform,
    ): void {
        $dependencies = match ($platform) {
            'Linux' => self::linuxDependencies($binary),
            'Darwin' => self::darwinDependencies($binary),
            'Windows' => self::windowsDependencies($binary),
            default => throw new \RuntimeException(
                'Unsupported dependency audit platform: ' . $platform
            ),
        };
        foreach ($dependencies as $dependency) {
            $allowed = match ($platform) {
                'Linux' => self::linuxSystemLibrary($dependency),
                'Darwin' => \str_starts_with($dependency, '/usr/lib/')
                    || \str_starts_with($dependency, '/System/Library/'),
                'Windows' => self::windowsSystemLibrary($dependency),
            };
            if (!$allowed) {
                throw new \RuntimeException(
                    'Production component ' . $component
                        . ' has forbidden host runtime dependency: ' . $dependency
                );
            }
        }
    }

    private static function linuxSystemLibrary(string $dependency): bool
    {
        if (\in_array($dependency, [
            'libc.so.6',
            'libm.so.6',
            'libpthread.so.0',
            'libdl.so.2',
            'librt.so.1',
            'libresolv.so.2',
            'libutil.so.1',
            'libgcc_s.so.1',
            'libstdc++.so.6',
        ], true)) {
            return true;
        }
        return \preg_match(
            '/\A(?:ld-linux(?:-[a-z0-9_]+)?\.so\.[0-9]+|ld-linux-[a-z0-9_-]+\.so\.[0-9]+|ld64\.so\.[0-9]+)\z/',
            $dependency,
        ) === 1;
    }

    /** @return list<string> */
    private static function linuxDependencies(string $binary): array
    {
        $tool = self::optionalTool(['readelf']);
        if ($tool !== '') {
            $result = self::run([$tool, '-dW', $binary]);
            if ($result['code'] !== 0) {
                throw new \RuntimeException(
                    'Linux self-contained dependency audit failed: ' . $result['output']
                );
            }
            \preg_match_all(
                '/Shared library:\s*\[([^\]]+)\]/',
                $result['output'],
                $matches,
            );
            return \array_values(\array_unique(\array_map(
                'strtolower',
                $matches[1] ?? [],
            )));
        }
        return self::llvmNeededLibraries($binary, 'Linux');
    }

    /** @return list<string> */
    private static function darwinDependencies(string $binary): array
    {
        $tool = self::optionalTool(['otool']);
        if ($tool !== '') {
            $result = self::run([$tool, '-L', $binary]);
            if ($result['code'] !== 0) {
                throw new \RuntimeException(
                    'macOS self-contained dependency audit failed: ' . $result['output']
                );
            }
            $dependencies = [];
            foreach (\array_slice(
                \preg_split('/\R/', $result['output']) ?: [],
                1,
            ) as $line) {
                $parts = \preg_split('/\s+/', \trim($line)) ?: [];
                $dependency = \trim((string)($parts[0] ?? ''));
                if ($dependency !== '') {
                    $dependencies[] = $dependency;
                }
            }
            return \array_values(\array_unique($dependencies));
        }
        return self::llvmNeededLibraries($binary, 'macOS');
    }

    /** @return list<string> */
    private static function windowsDependencies(string $binary): array
    {
        $llvm = self::optionalTool([
            'llvm-readobj.exe',
            'llvm-readobj',
            'llvm-readobj-21',
            'llvm-readobj-20',
            'llvm-readobj-19',
            'llvm-readobj-18',
            'llvm-readobj-17',
        ]);
        if ($llvm !== '') {
            return \array_map(
                'strtolower',
                self::llvmNeededLibraries($binary, 'Windows', $llvm),
            );
        }
        $dumpbin = self::optionalTool(['dumpbin.exe', 'dumpbin']);
        if ($dumpbin !== '') {
            $result = self::run([$dumpbin, '/DEPENDENTS', $binary]);
            if ($result['code'] !== 0) {
                throw new \RuntimeException(
                    'Windows self-contained dependency audit failed: ' . $result['output']
                );
            }
            \preg_match_all(
                '/^\s*([A-Za-z0-9._-]+\.dll)\s*$/im',
                $result['output'],
                $matches,
            );
            return \array_values(\array_unique(\array_map(
                'strtolower',
                $matches[1] ?? [],
            )));
        }
        $objdump = self::tool([
            'x86_64-w64-mingw32-objdump',
            'i686-w64-mingw32-objdump',
            'llvm-objdump.exe',
            'llvm-objdump',
            'objdump.exe',
            'objdump',
        ]);
        $result = self::run([$objdump, '-p', $binary]);
        if ($result['code'] !== 0) {
            throw new \RuntimeException(
                'Windows self-contained dependency audit failed: ' . $result['output']
            );
        }
        return self::gnuObjdumpNeededLibraries($result['output']);
    }

    /** @return list<string> */
    private static function gnuObjdumpNeededLibraries(string $output): array
    {
        if (!\preg_match('/\bfile format pei-[A-Za-z0-9._-]+\b/i', $output)
            || !\str_contains($output, 'The Import Tables')
        ) {
            throw new \RuntimeException(
                'Windows dependency audit output is not recognized as a PE import table.'
            );
        }
        \preg_match_all(
            '/^\s*DLL Name:\s*([A-Za-z0-9._-]+\.dll)\s*$/im',
            $output,
            $matches,
        );
        return \array_values(\array_unique(\array_map(
            'strtolower',
            $matches[1] ?? [],
        )));
    }

    /**
     * LLVM can inspect ELF, Mach-O and PE/COFF without executing the target,
     * so the isolated Linux signing job can independently audit every target.
     *
     * @return list<string>
     */
    private static function llvmNeededLibraries(
        string $binary,
        string $target,
        string $tool = '',
    ): array {
        $tool = $tool !== '' ? $tool : self::tool([
            'llvm-readobj.exe',
            'llvm-readobj',
            'llvm-readobj-21',
            'llvm-readobj-20',
            'llvm-readobj-19',
            'llvm-readobj-18',
            'llvm-readobj-17',
        ]);
        $result = self::run([$tool, '--needed-libs', $binary]);
        if ($result['code'] !== 0) {
            throw new \RuntimeException(
                $target . ' self-contained dependency audit failed: ' . $result['output']
            );
        }
        $dependencies = [];
        $inside = false;
        foreach (\preg_split('/\R/', $result['output']) ?: [] as $line) {
            $line = \trim($line);
            if ($line === 'NeededLibraries [') {
                $inside = true;
                continue;
            }
            if ($inside && $line === ']') {
                $inside = false;
                continue;
            }
            if ($inside && $line !== '') {
                $dependencies[] = $line;
            }
        }
        if ($dependencies === []
            && !\str_contains($result['output'], 'NeededLibraries [')
        ) {
            throw new \RuntimeException(
                $target . ' dependency audit output is not recognized.'
            );
        }
        return \array_values(\array_unique($dependencies));
    }

    private static function windowsSystemLibrary(string $dependency): bool
    {
        $dependency = \strtolower($dependency);
        if (\str_starts_with($dependency, 'api-ms-win-')
            || \str_starts_with($dependency, 'ext-ms-win-')
        ) {
            return true;
        }
        return \in_array($dependency, [
            'advapi32.dll',
            'bcrypt.dll',
            'crypt32.dll',
            'dbghelp.dll',
            'dnsapi.dll',
            'gdi32.dll',
            'iphlpapi.dll',
            'kernel32.dll',
            'msvcrt.dll',
            'ncrypt.dll',
            'normaliz.dll',
            'ntdll.dll',
            'ole32.dll',
            'oleaut32.dll',
            'psapi.dll',
            'rpcrt4.dll',
            'secur32.dll',
            'shell32.dll',
            'shlwapi.dll',
            'user32.dll',
            'userenv.dll',
            'version.dll',
            'winhttp.dll',
            'winmm.dll',
            'ws2_32.dll',
        ], true);
    }

    /** @param list<string> $names */
    private static function tool(array $names): string
    {
        $tool = self::optionalTool($names);
        if ($tool === '') {
            throw new \RuntimeException(
                'Self-contained dependency audit tool is unavailable: ' . \implode(' or ', $names)
            );
        }
        return $tool;
    }

    /** @param list<string> $names */
    private static function optionalTool(array $names): string
    {
        $directories = \array_filter(\explode(
            PATH_SEPARATOR,
            (string)(\getenv('PATH') ?: ''),
        ));
        foreach ($names as $name) {
            $candidates = \str_contains($name, DIRECTORY_SEPARATOR)
                ? [$name]
                : \array_map(
                    static fn (string $directory): string => \rtrim(
                        $directory,
                        '/\\',
                    ) . DIRECTORY_SEPARATOR . $name,
                    $directories,
                );
            foreach ($candidates as $candidate) {
                $resolved = \realpath($candidate);
                if ($resolved !== false
                    && \is_file($resolved)
                    && \is_executable($resolved)
                ) {
                    self::recordToolProof($resolved);
                    return $resolved;
                }
            }
        }
        return '';
    }

    private static function recordToolProof(string $tool): void
    {
        if (isset(self::$toolProofs[$tool])) {
            return;
        }
        $before = @\lstat($tool);
        // Apple ships many /usr/bin auditors as multi-link Mach-O leaves under
        // SIP. Content hashing still pins the exact inode bytes; rejecting
        // nlink>1 falsely fails Darwin package dependency audits on otool.
        $nlink = (int)($before['nlink'] ?? 0);
        $darwinSystemAuditor = \PHP_OS_FAMILY === 'Darwin'
            && $nlink >= 1
            && (
                \str_starts_with($tool, '/usr/bin/')
                || \str_starts_with($tool, '/bin/')
                || \str_starts_with($tool, '/usr/sbin/')
            );
        if (!\is_array($before)
            || \is_link($tool)
            || ((((int)($before['mode'] ?? 0)) & 0170000) !== 0100000)
            || ($nlink !== 1 && !$darwinSystemAuditor)
            || (int)($before['size'] ?? -1) < 1
            || (int)$before['size'] > self::MAX_AUDITOR_TOOL_BYTES
        ) {
            throw new \RuntimeException('Dependency-audit tool identity is unsafe: ' . $tool);
        }
        $handle = @\fopen($tool, 'rb');
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Dependency-audit tool cannot be opened: ' . $tool);
        }
        try {
            $opened = @\fstat($handle);
            if (!\is_array($opened) || !self::sameFileState($before, $opened)) {
                throw new \RuntimeException('Dependency-audit tool changed before hashing: ' . $tool);
            }
            $hash = \hash_init('sha256');
            $consumed = 0;
            while ($consumed < (int)$opened['size']) {
                $chunk = @\fread($handle, \min(1_048_576, (int)$opened['size'] - $consumed));
                if (!\is_string($chunk) || $chunk === '') {
                    throw new \RuntimeException('Dependency-audit tool ended while hashing: ' . $tool);
                }
                $consumed += \strlen($chunk);
                \hash_update($hash, $chunk);
            }
            $extra = @\fread($handle, 1);
            $after = @\fstat($handle);
            $pathAfter = @\lstat($tool);
            if ($extra !== ''
                || !\is_array($after)
                || !\is_array($pathAfter)
                || !self::sameFileState($opened, $after)
                || !self::sameFileState($after, $pathAfter)
            ) {
                throw new \RuntimeException('Dependency-audit tool changed while hashing: ' . $tool);
            }
            $digest = \hash_final($hash);
        } finally {
            @\fclose($handle);
        }
        $version = self::run([$tool, '--version']);
        $versionOutput = \trim((string)$version['output']);
        if (\strlen($versionOutput) > 4096) {
            $versionOutput = \substr($versionOutput, 0, 4096);
        }
        self::$toolProofs[$tool] = [
            'path' => $tool,
            'sha256' => $digest,
            'size' => (int)$before['size'],
            'version_exit_code' => (int)$version['code'],
            'version_output' => $versionOutput,
        ];
    }

    /** @param array<string|int,mixed> $left @param array<string|int,mixed> $right */
    private static function sameFileState(array $left, array $right): bool
    {
        foreach (['dev', 'ino', 'mode', 'nlink', 'size', 'mtime', 'ctime'] as $field) {
            if (!\array_key_exists($field, $left)
                || !\array_key_exists($field, $right)
                || (int)$left[$field] !== (int)$right[$field]
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param list<string> $command
     * @return array{code:int,output:string}
     */
    private static function run(array $command): array
    {
        return WlsGatewayPackageCommandRunner::run($command);
    }
}

/**
 * Deterministic WLS 2.0 host-gateway package assembler.
 *
 * Production assembly requires provenance for every executable but never
 * receives a signing key. WlsGatewayPackageSigner finalizes that candidate in
 * a separate job which verifies hashes and does not execute package content.
 */
final class WlsGatewayPackageBuilder
{
    private const MAX_CONTROLLER_BYTES = 16_777_216;
    private const MAX_METADATA_BYTES = 33_554_432;
    private const MAX_COMPONENT_BYTES = 536_870_912;
    private const MAX_PACKAGE_BYTES = 536_870_912;
    private const REQUIRED_EXECUTABLES = [
        'php',
        'nginx',
        'wls-gateway-broker',
        'wls-gateway-launcher',
    ];

    /**
     * @param array<string,string> $options
     * @return array<string,mixed>
     */
    public function build(array $options): array
    {
        $profile = \strtolower($this->requiredOption($options, 'profile'));
        if (!\in_array($profile, ['test', 'production'], true)) {
            throw new \InvalidArgumentException('Package profile must be test or production.');
        }
        $production = $profile === 'production';
        $platform = $options['platform'] ?? \PHP_OS_FAMILY;
        $arch = $this->normalizeArch($options['arch'] ?? (string)\php_uname('m'));
        if (!\hash_equals(\PHP_OS_FAMILY, $platform)
            || !\hash_equals($this->normalizeArch((string)\php_uname('m')), $arch)
        ) {
            throw new \RuntimeException(
                'Package assembly must run on the declared target OS and architecture.'
            );
        }
        if (!\in_array($platform, ['Darwin', 'Linux', 'Windows'], true)
            || !\in_array($arch, ['arm64', 'x86_64'], true)
        ) {
            throw new \RuntimeException('Unsupported WLS Gateway package target.');
        }

        $version = \trim($this->requiredOption($options, 'version'));
        if (\preg_match('/\A[0-9A-Za-z][0-9A-Za-z._+-]{0,63}\z/D', $version) !== 1) {
            throw new \InvalidArgumentException('Gateway package version is invalid.');
        }
        $finalOutput = $this->outputTarget($this->requiredOption($options, 'output'));
        $controller = $this->safeInput(
            $this->requiredOption($options, 'controller'),
            self::MAX_CONTROLLER_BYTES,
        );
        $licenses = $this->safeInput(
            $this->requiredOption($options, 'licenses'),
            self::MAX_METADATA_BYTES,
        );
        $executables = [];
        $requiredExecutables = self::REQUIRED_EXECUTABLES;
        if ($platform === 'Windows') {
            $requiredExecutables[] = 'wls-bounded-command';
        }
        foreach ($requiredExecutables as $name) {
            $executables[$name] = $this->safeInput(
                $this->requiredOption($options, $name),
                self::MAX_COMPONENT_BYTES,
            );
        }
        $inputBytes = $this->stableFileSize($controller, self::MAX_CONTROLLER_BYTES)
            + $this->stableFileSize($licenses, self::MAX_METADATA_BYTES);
        foreach ($executables as $executable) {
            $inputBytes += $this->stableFileSize(
                $executable,
                self::MAX_COMPONENT_BYTES,
            );
            if ($inputBytes > self::MAX_PACKAGE_BYTES) {
                throw new \RuntimeException(
                    'Gateway package inputs exceed the fixed total size limit.'
                );
            }
        }
        if ($platform === 'Windows') {
            WlsGatewayPackageCommandRunner::configureWindowsHelper(
                $executables['wls-bounded-command'],
            );
        }

        $provenance = $this->loadProvenance(
            $options['provenance'] ?? '',
            $platform,
            $arch,
            $controller,
            $executables,
            $production,
        );
        if ($production) {
            foreach ($executables as $name => $binary) {
                WlsGatewayDependencyAuditor::assertBaseSystemOnly(
                    $name,
                    $binary,
                    $platform,
                );
            }
        }
        $this->runComponentSelfTests($controller, $executables, $platform);

        $output = '';
        try {
        $output = $this->prepareOutput(
            $finalOutput . '.candidate.' . \bin2hex(\random_bytes(8)),
        );
        $components = [];
        $this->copyComponent($controller, $output . '/app/controller.php', 0644, $components, $output);
        foreach ($executables as $name => $source) {
            $target = 'bin/' . $name . ($platform === 'Windows' ? '.exe' : '');
            $this->copyComponent($source, $output . '/' . $target, 0755, $components, $output);
        }
        $this->copyComponent($licenses, $output . '/LICENSES.txt', 0644, $components, $output);

        $provenanceFile = $output . '/provenance.json';
        $this->writeFile(
            $provenanceFile,
            \json_encode(
                $provenance,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . PHP_EOL,
            0644,
        );
        $components['provenance.json'] = $this->componentDefinition(
            $provenanceFile,
            0644,
        );

        $sbomFile = $output . '/sbom.cdx.json';
        $this->writeFile(
            $sbomFile,
            \json_encode(
                $this->buildSbom($version, $platform, $arch, $provenance, $components),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . PHP_EOL,
            0644,
        );
        $components['sbom.cdx.json'] = $this->componentDefinition($sbomFile, 0644);
        \ksort($components, SORT_STRING);

        $capabilities = [
            'broker_sideband_actions' => true,
            'dual_control_channels' => true,
            'native_peer_identity' => true,
            'neutral_default_certificate' => true,
            'no_follow_snapshot' => true,
            'privilege_separation' => true,
            'self_contained_nginx' => $this->isSelfContained($provenance, 'nginx'),
            'self_contained_php' => $this->isSelfContained($provenance, 'php'),
            'singleton_fencing' => true,
        ];
        if ($platform === 'Windows') {
            // Assembly already executed the locked PHP binary with this exact
            // kernel32 cdef probe. The signer and installer require the
            // resulting declaration; a generic `php --version` is not enough.
            $capabilities['windows_kernel32_ffi_atomic_write'] = true;
        }
        if ($production && \in_array(false, $capabilities, true)) {
            throw new \RuntimeException(
                'Production package provenance does not prove every required capability.'
            );
        }

        $manifest = [
            'schema_version' => 2,
            'version' => $version,
            'platform' => $platform,
            'arch' => $arch,
            'protocol_min' => 2,
            'protocol_max' => 2,
            'security_profile' => 'native-broker-v1',
            'implementation_level' => 'wls-2.0',
            'package_profile' => $profile,
            'release_ready' => false,
            'signing_key_id' => '',
            'listen_profiles' => ['default', 'ipv4-only'],
            'capabilities' => $capabilities,
            'components' => $components,
        ];
        $manifestBytes = \json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;
        $manifestFile = $output . '/manifest.json';
        $this->writeFile($manifestFile, $manifestBytes, 0644);

        if (!@\rename($output, $finalOutput)) {
            throw new \RuntimeException('Unable to atomically publish the gateway package.');
        }
        $output = $finalOutput;
        return [
            'ok' => true,
            'output' => $output,
            'profile' => $profile,
            'release_ready' => false,
            'production_candidate' => $production,
            'version' => $version,
            'platform' => $platform,
            'arch' => $arch,
            'manifest_sha256' => \hash('sha256', $manifestBytes),
            'component_count' => \count($components),
        ];
        } catch (\Throwable $throwable) {
            if ($output !== '') {
                $this->removeTree($output);
            }
            throw $throwable;
        }
    }

    /**
     * @param array<string,string> $options
     */
    private function requiredOption(array $options, string $name): string
    {
        $value = \trim($options[$name] ?? '');
        if ($value === '') {
            throw new \InvalidArgumentException('Missing required option --' . $name . '.');
        }
        return $value;
    }

    private function outputTarget(string $output): string
    {
        if (\str_contains($output, "\0")) {
            throw new \RuntimeException('Package output path is invalid.');
        }
        $parent = \realpath(\dirname($output));
        if (!\is_string($parent) || !\is_dir($parent) || \is_link(\dirname($output))) {
            throw new \RuntimeException('Package output parent is missing or unsafe.');
        }
        $output = $parent . DIRECTORY_SEPARATOR . \basename($output);
        if (\file_exists($output) || \is_link($output)) {
            throw new \RuntimeException('Package output must not already exist.');
        }
        return $output;
    }

    private function prepareOutput(string $output): string
    {
        $output = $this->outputTarget($output);
        if (!@\mkdir($output, 0700) || !@\mkdir($output . '/app', 0700) || !@\mkdir($output . '/bin', 0700)) {
            throw new \RuntimeException('Unable to create package output directories.');
        }
        return $output;
    }

    private function safeInput(string $path, int $maximumBytes): string
    {
        $before = @\lstat($path);
        if (\str_contains($path, "\0")
            || !\is_array($before)
            || \is_link($path)
            || ((((int)($before['mode'] ?? 0)) & 0170000) !== 0100000)
            || (int)($before['nlink'] ?? 0) !== 1
            || (int)($before['size'] ?? -1) < 1
            || (int)$before['size'] > $maximumBytes
        ) {
            throw new \RuntimeException('Package input is missing or unsafe: ' . $path);
        }
        $real = \realpath($path);
        $after = \is_string($real) ? @\lstat($real) : false;
        if (!\is_string($real)
            || !\is_array($after)
            || !\is_file($real)
            || \is_link($real)
            || !$this->sameFileState($before, $after)
        ) {
            throw new \RuntimeException('Package input is missing or unsafe: ' . $path);
        }
        return $real;
    }

    /**
     * @param array<string,string> $executables
     * @return array<string,mixed>
     */
    private function loadProvenance(
        string $file,
        string $platform,
        string $arch,
        string $controller,
        array $executables,
        bool $production,
    ): array {
        if ($file === '') {
            if ($production) {
                throw new \RuntimeException('Production package requires component provenance.');
            }
            $components = [
                'controller' => $this->testProvenanceComponent($controller),
            ];
            foreach ($executables as $name => $path) {
                $components[$name] = $this->testProvenanceComponent($path);
            }
            return [
                'schema_version' => 1,
                'target' => ['platform' => $platform, 'arch' => $arch],
                'components' => $components,
            ];
        }

        $file = $this->safeInput($file, self::MAX_METADATA_BYTES);
        $decoded = \json_decode($this->readStableFile(
            $file,
            self::MAX_METADATA_BYTES,
            'gateway component provenance',
        ), true);
        if (!\is_array($decoded)
            || (int)($decoded['schema_version'] ?? 0) !== 1
            || !\hash_equals($platform, (string)($decoded['target']['platform'] ?? ''))
            || !\hash_equals($arch, $this->normalizeArch((string)($decoded['target']['arch'] ?? '')))
            || !\is_array($decoded['components'] ?? null)
        ) {
            throw new \RuntimeException('Gateway component provenance target is invalid.');
        }
        $sources = ['controller' => $controller] + $executables;
        foreach (\array_keys($sources) as $name) {
            $definition = $decoded['components'][$name] ?? null;
            if (!\is_array($definition)
                || \trim((string)($definition['version'] ?? '')) === ''
                || \trim((string)($definition['source_url'] ?? '')) === ''
                || \preg_match(
                    '/\A[a-f0-9]{64}\z/D',
                    \strtolower((string)($definition['source_sha256'] ?? '')),
                ) !== 1
                || \trim((string)($definition['license'] ?? '')) === ''
                || \preg_match(
                    '/\A[a-f0-9]{64}\z/D',
                    \strtolower((string)($definition['binary_sha256'] ?? '')),
                ) !== 1
                || !\hash_equals(
                    \strtolower((string)$definition['binary_sha256']),
                    $this->digestStableFile(
                        $sources[$name],
                        self::MAX_COMPONENT_BYTES,
                        'gateway provenance source ' . $name,
                    )['sha256'],
                )
            ) {
                throw new \RuntimeException(
                    'Gateway component provenance is incomplete or mismatched: ' . $name
                );
            }
            if ($production
                && $name !== 'controller'
                && ($definition['self_contained'] ?? false) !== true
            ) {
                throw new \RuntimeException(
                    'Production component is not proven self-contained: ' . $name
                );
            }
        }
        return $decoded;
    }

    /** @return array<string,mixed> */
    private function testProvenanceComponent(string $path): array
    {
        $digest = $this->digestStableFile(
            $path,
            self::MAX_COMPONENT_BYTES,
            'test provenance component',
        )['sha256'];
        return [
            'version' => 'local-test',
            'source_url' => 'test://local-component',
            'source_sha256' => $digest,
            'binary_sha256' => $digest,
            'license' => 'test-only',
            'self_contained' => false,
        ];
    }

    /** @param array<string,string> $executables */
    private function runComponentSelfTests(
        string $controller,
        array $executables,
        string $platform,
    ): void
    {
        $commands = [
            [$executables['php'], '-l', $controller],
            [$executables['php'], $controller, '--self-test'],
            [$executables['php'], '--version'],
            [$executables['nginx'], '-V'],
            [$executables['wls-gateway-broker'], '--self-test'],
            [$executables['wls-gateway-launcher'], '--self-test'],
        ];
        if ($platform === 'Windows') {
            $commands[] = [$executables['wls-bounded-command'], '--self-test'];
            \array_splice($commands, 3, 0, [[
                $executables['php'],
                '-r',
                $this->windowsAtomicFfiProbeScript(),
            ]]);
        }
        foreach ($commands as $command) {
            $result = $this->run($command);
            if ($result['code'] !== 0) {
                throw new \RuntimeException(
                    'Gateway package component self-test failed: '
                    . \basename($command[0]) . ': ' . $result['output']
                );
            }
        }
    }

    private function windowsAtomicFfiProbeScript(): string
    {
        return <<<'PHP'
$mode = strtolower(trim((string)ini_get('ffi.enable')));
if (!extension_loaded('FFI')
    || !class_exists('FFI')
    || in_array($mode, ['', '0', 'off', 'false'], true)
) {
    fwrite(STDERR, "FFI extension or ffi.enable is unavailable.\n");
    exit(70);
}
try {
    $ffi = FFI::cdef(
        'typedef unsigned long DWORD; DWORD GetLastError(void);',
        'kernel32.dll',
    );
    $ffi->GetLastError();
} catch (Throwable $throwable) {
    fwrite(STDERR, "kernel32 FFI::cdef failed.\n");
    exit(71);
}
PHP;
    }

    /**
     * @param array<string,array{sha256:string,size:int,mode:int}> $components
     */
    private function copyComponent(
        string $source,
        string $target,
        int $mode,
        array &$components,
        string $output,
    ): void {
        $before = $this->digestStableFile(
            $source,
            self::MAX_COMPONENT_BYTES,
            'package source component',
        );
        try {
            if (!@\copy($source, $target)) {
                throw new \RuntimeException('Unable to copy package component: ' . $source);
            }
            @\chmod($target, $mode);
            $after = $this->digestStableFile(
                $source,
                self::MAX_COMPONENT_BYTES,
                'package source component',
            );
            $copied = $this->digestStableFile(
                $target,
                self::MAX_COMPONENT_BYTES,
                'copied package component',
            );
            if ($before !== $after || $before !== $copied) {
                throw new \RuntimeException(
                    'Package source component changed while it was copied: ' . $source
                );
            }
            $relative = \str_replace('\\', '/', \substr($target, \strlen($output) + 1));
            $components[$relative] = [
                'sha256' => $copied['sha256'],
                'size' => $copied['size'],
                'mode' => $mode,
            ];
        } catch (\Throwable $throwable) {
            if ((\file_exists($target) || \is_link($target)) && !@\unlink($target)) {
                throw new \RuntimeException(
                    'Unable to remove a failed package component copy.',
                    0,
                    $throwable,
                );
            }
            throw $throwable;
        }
    }

    /** @return array{sha256:string,size:int,mode:int} */
    private function componentDefinition(string $file, int $mode): array
    {
        $inspected = $this->digestStableFile(
            $file,
            self::MAX_COMPONENT_BYTES,
            'package component',
        );
        return [
            'sha256' => $inspected['sha256'],
            'size' => $inspected['size'],
            'mode' => $mode,
        ];
    }

    /**
     * @param array<string,mixed> $provenance
     * @param array<string,array{sha256:string,size:int,mode:int}> $components
     * @return array<string,mixed>
     */
    private function buildSbom(
        string $version,
        string $platform,
        string $arch,
        array $provenance,
        array $components,
    ): array {
        $sbomComponents = [];
        foreach ((array)$provenance['components'] as $name => $definition) {
            if (!\is_array($definition)) {
                continue;
            }
            $sbomComponents[] = [
                'type' => match ((string)$name) {
                    'controller' => 'application',
                    'php' => 'framework',
                    default => 'file',
                },
                'name' => (string)$name,
                'version' => (string)($definition['version'] ?? 'unknown'),
                'licenses' => [[
                    'license' => ['name' => (string)($definition['license'] ?? 'unknown')],
                ]],
                'hashes' => [[
                    'alg' => 'SHA-256',
                    'content' => (string)($definition['binary_sha256'] ?? ''),
                ]],
                'externalReferences' => [[
                    'type' => 'distribution',
                    'url' => (string)($definition['source_url'] ?? 'about:blank'),
                ]],
            ];
        }
        return [
            'bomFormat' => 'CycloneDX',
            'specVersion' => '1.5',
            'serialNumber' => 'urn:uuid:' . $this->uuidV4(),
            'version' => 1,
            'metadata' => [
                'component' => [
                    'type' => 'application',
                    'name' => 'wls-gateway',
                    'version' => $version,
                    'properties' => [
                        ['name' => 'wls:platform', 'value' => $platform],
                        ['name' => 'wls:arch', 'value' => $arch],
                        ['name' => 'wls:component-count', 'value' => (string)\count($components)],
                    ],
                ],
            ],
            'components' => $sbomComponents,
        ];
    }

    /** @param array<string,mixed> $provenance */
    private function isSelfContained(array $provenance, string $component): bool
    {
        return ($provenance['components'][$component]['self_contained'] ?? false) === true;
    }

    private function stableFileSize(string $file, int $maximumBytes): int
    {
        return $this->digestStableFile(
            $file,
            $maximumBytes,
            'gateway package input',
        )['size'];
    }

    /** @return array{sha256:string,size:int} */
    private function digestStableFile(
        string $file,
        int $maximumBytes,
        string $label,
    ): array {
        $before = @\lstat($file);
        if (!\is_array($before)
            || \is_link($file)
            || ((((int)($before['mode'] ?? 0)) & 0170000) !== 0100000)
            || (int)($before['nlink'] ?? 0) !== 1
            || (int)($before['size'] ?? -1) < 1
            || (int)$before['size'] > $maximumBytes
        ) {
            throw new \RuntimeException($label . ' is missing, linked, or outside bounds.');
        }
        $handle = @\fopen($file, 'rb');
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to open ' . $label . '.');
        }
        try {
            $opened = @\fstat($handle);
            if (!\is_array($opened) || !$this->sameFileState($before, $opened)) {
                throw new \RuntimeException($label . ' changed before hashing.');
            }
            $context = \hash_init('sha256');
            $consumed = 0;
            while ($consumed < (int)$opened['size']) {
                $chunk = @\fread(
                    $handle,
                    \min(1_048_576, (int)$opened['size'] - $consumed),
                );
                if (!\is_string($chunk) || $chunk === '') {
                    throw new \RuntimeException($label . ' ended before its declared size.');
                }
                $consumed += \strlen($chunk);
                \hash_update($context, $chunk);
            }
            $extra = @\fread($handle, 1);
            $after = @\fstat($handle);
            $pathAfter = @\lstat($file);
            if ($extra !== ''
                || !\is_array($after)
                || !\is_array($pathAfter)
                || $consumed !== (int)$opened['size']
                || !$this->sameFileState($opened, $after)
                || !$this->sameFileState($after, $pathAfter)
            ) {
                throw new \RuntimeException($label . ' changed while being hashed.');
            }
            return [
                'sha256' => \hash_final($context),
                'size' => $consumed,
            ];
        } finally {
            @\fclose($handle);
        }
    }

    private function readStableFile(string $file, int $maximumBytes, string $label): string
    {
        $before = @\lstat($file);
        if (!\is_array($before)
            || \is_link($file)
            || ((((int)($before['mode'] ?? 0)) & 0170000) !== 0100000)
            || (int)($before['nlink'] ?? 0) !== 1
            || (int)($before['size'] ?? -1) < 1
            || (int)$before['size'] > $maximumBytes
        ) {
            throw new \RuntimeException($label . ' is missing, linked, or outside bounds.');
        }
        $handle = @\fopen($file, 'rb');
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to open ' . $label . '.');
        }
        try {
            $opened = @\fstat($handle);
            $bytes = @\stream_get_contents($handle, $maximumBytes + 1);
            $after = @\fstat($handle);
            $pathAfter = @\lstat($file);
            if (!\is_array($opened)
                || !\is_string($bytes)
                || !\is_array($after)
                || !\is_array($pathAfter)
                || \strlen($bytes) !== (int)($opened['size'] ?? -1)
                || !$this->sameFileState($before, $opened)
                || !$this->sameFileState($opened, $after)
                || !$this->sameFileState($after, $pathAfter)
            ) {
                throw new \RuntimeException($label . ' changed while being read.');
            }
            return $bytes;
        } finally {
            @\fclose($handle);
        }
    }

    /** @param array<string|int,mixed> $left @param array<string|int,mixed> $right */
    private function sameFileState(array $left, array $right): bool
    {
        foreach (['dev', 'ino', 'mode', 'nlink', 'size', 'mtime', 'ctime'] as $field) {
            if (!\array_key_exists($field, $left)
                || !\array_key_exists($field, $right)
                || (int)$left[$field] !== (int)$right[$field]
            ) {
                return false;
            }
        }
        return true;
    }

    private function writeFile(string $file, string $contents, int $mode): void
    {
        $handle = @\fopen($file, 'xb');
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to create package file: ' . $file);
        }
        try {
            if (@\fwrite($handle, $contents) !== \strlen($contents)) {
                throw new \RuntimeException('Unable to write package file: ' . $file);
            }
            @\fflush($handle);
            \function_exists('fsync') && @\fsync($handle);
        } finally {
            @\fclose($handle);
        }
        @\chmod($file, $mode);
    }

    private function removeTree(string $directory): void
    {
        if (!\file_exists($directory) && !\is_link($directory)) {
            return;
        }
        if (!\is_dir($directory) || \is_link($directory)) {
            throw new \RuntimeException(
                'Package cleanup root is linked or special: ' . $directory
            );
        }
        $records = \Weline\Server\Service\Edge\Gateway\GatewayBoundedTreeWalker::collect(
            $directory,
            true,
            true,
        );
        foreach ($records as $record) {
            \Weline\Server\Service\Edge\Gateway\GatewayBoundedTreeWalker::revalidate($record);
            $removed = ($record['directory'] ?? false) === true
                ? @\rmdir((string)$record['path'])
                : @\unlink((string)$record['path']);
            if (!$removed) {
                throw new \RuntimeException(
                    'Unable to remove package cleanup artifact: '
                        . (string)$record['path']
                );
            }
        }
    }

    /**
     * @param list<string> $command
     * @return array{code:int,output:string}
     */
    private function run(array $command): array
    {
        return WlsGatewayPackageCommandRunner::run($command);
    }

    private function normalizeArch(string $arch): string
    {
        return match (\strtolower(\trim($arch))) {
            'amd64', 'x86_64' => 'x86_64',
            'aarch64', 'arm64' => 'arm64',
            default => \strtolower(\trim($arch)),
        };
    }

    private function uuidV4(): string
    {
        $bytes = \random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3f) | 0x80);
        $hex = \bin2hex($bytes);
        return \substr($hex, 0, 8) . '-' . \substr($hex, 8, 4) . '-'
            . \substr($hex, 12, 4) . '-' . \substr($hex, 16, 4) . '-'
            . \substr($hex, 20);
    }
}

/**
 * Finalizes a production candidate without executing any package component.
 *
 * This class is intentionally separate from the assembler so CI can place it
 * in a clean, approval-protected signing job that receives only files and
 * digests from the unprivileged assembly job.
 */
final class WlsGatewayPackageSigner
{
    private const AUDIT_RECEIPT_SCHEMA = 'wls-gateway-dependency-audit/2';
    private const MAX_MANIFEST_BYTES = 8_388_608;
    private const MAX_METADATA_BYTES = 33_554_432;
    private const MAX_COMPONENT_BYTES = 536_870_912;
    private const MAX_PACKAGE_BYTES = 536_870_912;
    private const MAX_SECRET_KEY_FILE_BYTES = 4096;
    private const MAX_TRUSTED_KEYS_BYTES = 1_048_576;
    private const MAX_AUDIT_RECEIPT_BYTES = 65_536;
    private const WINDOWS_BOOTSTRAP_SCHEMA = 'wls-bounded-command-bootstrap/1';
    private const WINDOWS_BOOTSTRAP_HELPER = 'wls-bounded-command.exe';
    private const WINDOWS_BOOTSTRAP_MANIFEST = 'wls-bounded-command.manifest.json';
    private const WINDOWS_BOOTSTRAP_SIGNATURE = 'wls-bounded-command.manifest.sig';
    private const WINDOWS_BOOTSTRAP_MAX_HELPER_BYTES = 16_777_216;
    private const SIGNING_TRANSACTION_SCHEMA = 'wls-gateway-signing-transaction/3';
    private const SIGNING_TRANSACTION_RECORD = 'record.json';
    private const SIGNING_TRANSACTION_ROLLBACK = 'unsigned-manifest.json';
    private const SIGNING_TRANSACTION_MANIFEST = 'signed-manifest.json';
    private const SIGNING_TRANSACTION_SIGNATURE = 'manifest.sig';
    private const SIGNING_TRANSACTION_QUARANTINE = 'quarantine';
    private const SIGNING_TRANSACTION_ORIGINAL_MANIFEST = 'original-unsigned-manifest.json';
    private const SIGNING_TRANSACTION_PUBLISHED_MANIFEST = 'published-signed-manifest.json';
    private const SIGNING_TRANSACTION_PUBLISHED_SIGNATURE = 'published-manifest.sig';
    private const SIGNING_LOCK_SCHEMA = 'wls-gateway-signing-lock/3';
    private const SIGNING_LOCK_ROOT = 'wls-gateway-signing-locks-v3';
    private const MAX_ABANDONED_SIGNING_STAGES = 8;
    private const MAX_SIGNING_TRANSACTION_BYTES = 16_777_216;

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

    /** @param array<string,string> $options */
    public function audit(array $options): array
    {
        $package = $this->packageDirectory($this->requiredOption($options, 'package'));
        WlsGatewayDependencyAuditor::beginAudit();
        $verified = $this->verifyUnsignedCandidate($package, true);
        $manifest = $verified['manifest'];
        $receiptFile = $this->outputFileTarget(
            $this->requiredOption($options, 'receipt-output'),
        );
        $scriptProof = $this->digestStableFile(
            $this->safeFile(__FILE__),
            self::MAX_METADATA_BYTES,
            'dependency-auditor implementation',
        );
        $auditor = [
            'implementation' => 'bounded-static-import-v2',
            'script_sha256' => $scriptProof['sha256'],
            'tools' => WlsGatewayDependencyAuditor::toolProofs(),
        ];
        if ($auditor['tools'] === []) {
            throw new \RuntimeException(
                'Dependency audit did not bind an external object-format inspection tool.'
            );
        }
        $environmentDigest = $this->auditEnvironmentDigest($auditor);
        $expectedEnvironment = \strtolower(\trim(
            (string)($options['expected-audit-environment-sha256'] ?? ''),
        ));
        if ($expectedEnvironment !== ''
            && (\preg_match('/\A[a-f0-9]{64}\z/D', $expectedEnvironment) !== 1
                || !\hash_equals($expectedEnvironment, $environmentDigest))
        ) {
            throw new \RuntimeException(
                'Dependency-audit environment does not match the protected release allowlist.'
            );
        }
        $receipt = [
            'schema' => self::AUDIT_RECEIPT_SCHEMA,
            'manifest_sha256' => \hash('sha256', $verified['manifest_bytes']),
            'version' => (string)$manifest['version'],
            'platform' => (string)$manifest['platform'],
            'arch' => (string)$manifest['arch'],
            'component_count' => \count($manifest['components']),
            'component_set_sha256' => $this->componentSetDigest($manifest),
            'audit_environment_sha256' => $environmentDigest,
            'auditor' => $auditor,
        ];
        $receiptBytes = \json_encode(
            $receipt,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;
        $this->writeFile($receiptFile, $receiptBytes, 0644);

        return [
            'ok' => true,
            'output' => $receiptFile,
            'manifest_sha256' => $receipt['manifest_sha256'],
            'component_set_sha256' => $receipt['component_set_sha256'],
            'component_count' => $receipt['component_count'],
            'platform' => $receipt['platform'],
            'arch' => $receipt['arch'],
            'audit_environment_sha256' => $environmentDigest,
        ];
    }

    /** @param array<string,string> $options */
    public function sign(array $options): array
    {
        $package = $this->packageDirectory($this->requiredOption($options, 'package'));
        $signingLock = $this->acquireSigningLock($package);
        try {
            return $this->signLocked($options, $package, $signingLock);
        } finally {
            $this->releaseSigningLock($signingLock);
        }
    }

    /** @param array<string,string> $options @param array<string,mixed> $signingLock */
    private function signLocked(array $options, string $package, array $signingLock): array
    {
        $this->assertSigningLockHeld($signingLock, $package);
        $keyId = \trim($this->requiredOption($options, 'signing-key-id'));
        $hasKeyFile = isset($options['signing-key-file'])
            && \trim((string)$options['signing-key-file']) !== '';
        $hasKeyEnvironment = isset($options['signing-key-environment'])
            && \trim((string)$options['signing-key-environment']) !== '';
        if ($hasKeyFile === $hasKeyEnvironment) {
            throw new \InvalidArgumentException(
                'Exactly one of --signing-key-file or --signing-key-environment is required.'
            );
        }
        $secretKey = $hasKeyFile
            ? $this->loadSecretKey(
                $this->requiredOption($options, 'signing-key-file'),
                $signingLock,
            )
            : $this->loadSecretKeyEnvironment(
                $this->requiredOption($options, 'signing-key-environment'),
            );
        $transactionActive = false;
        try {
            $this->verifyTrustedSigningKey(
                $secretKey,
                $keyId,
                $this->requiredOption($options, 'trusted-keys'),
            );
            $reconciled = $this->reconcileSigningTransaction(
                $package,
                $options,
                $keyId,
                $secretKey,
                $signingLock,
            );
            if ($reconciled !== null) {
                return $reconciled;
            }
            $this->recoverAbandonedSigningStages(
                $package,
                $options,
                $keyId,
                $secretKey,
                $signingLock,
            );

            $verified = $this->verifyUnsignedCandidate($package, false);
            $manifest = $verified['manifest'];
            $manifestBytes = $verified['manifest_bytes'];
            $manifestFile = $verified['manifest_file'];
            $this->verifyAuditReceipt(
                $this->requiredOption($options, 'audit-receipt'),
                $manifest,
                $manifestBytes,
                \strtolower($this->requiredOption(
                    $options,
                    'expected-audit-environment-sha256',
                )),
            );
            $bootstrapOutput = null;
            if ((string)$manifest['platform'] === 'Windows') {
                $bootstrapOutput = $this->outputTarget(
                    $this->requiredOption($options, 'bootstrap-output'),
                );
                $this->assertBootstrapOutputBoundary($bootstrapOutput, $package);
            } elseif (isset($options['bootstrap-output'])) {
                throw new \InvalidArgumentException(
                    '--bootstrap-output is valid only for a Windows production package.'
                );
            }
            if (\file_exists($package . DIRECTORY_SEPARATOR . 'manifest.sig')
                || \is_link($package . DIRECTORY_SEPARATOR . 'manifest.sig')
            ) {
                throw new \RuntimeException('Production candidate is already signed or unsafe.');
            }

            $manifest['release_ready'] = true;
            $manifest['signing_key_id'] = $keyId;
            $signedManifest = \json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . PHP_EOL;
            $signatureBytes = \base64_encode(
                \sodium_crypto_sign_detached($signedManifest, $secretKey),
            ) . PHP_EOL;
            $bootstrapFiles = $bootstrapOutput === null
                ? []
                : $this->windowsBootstrapFiles(
                    $package,
                    $manifest,
                    $keyId,
                    $secretKey,
                );
            $transaction = $this->prepareSigningTransaction(
                $package,
                $bootstrapOutput,
                $manifestBytes,
                $signedManifest,
                $signatureBytes,
                $bootstrapFiles,
                $manifest,
                $keyId,
                $secretKey,
                $signingLock,
            );
            $transactionActive = true;
            $active = $transaction['active'];
            $paths = $this->signingTransactionPaths($package);
            if ($bootstrapOutput !== null) {
                $this->assertSigningLockHeld($signingLock, $package);
                $this->assertRecordedIdentity(
                    $transaction['bootstrap_stage'],
                    true,
                    0700,
                    $transaction['expected']['identities']['bootstrap_stage'],
                    'staged Windows bootstrap',
                );
                $this->verifyWindowsBootstrapBundle(
                    $transaction['bootstrap_stage'],
                    $bootstrapFiles,
                    $transaction['expected']['identities'],
                );
                if (!$this->atomicRename(
                    $transaction['bootstrap_stage'],
                    $bootstrapOutput,
                    true,
                )) {
                    throw new \RuntimeException(
                        'Unable to atomically publish the Windows bounded-command bootstrap bundle.'
                    );
                }
                $this->verifyWindowsBootstrapBundle(
                    $bootstrapOutput,
                    $bootstrapFiles,
                    $transaction['expected']['identities'],
                );
                $this->assertRecordedIdentity(
                    $bootstrapOutput,
                    true,
                    0700,
                    $transaction['expected']['identities']['bootstrap_stage'],
                    'published Windows bootstrap',
                );
            }
            $this->assertSigningLockHeld($signingLock, $package);
            $this->assertRecordedIdentity(
                $active . DIRECTORY_SEPARATOR . self::SIGNING_TRANSACTION_SIGNATURE,
                false,
                0600,
                $transaction['expected']['identities']['signature'],
                'staged package signature',
            );
            $this->assertExactFile(
                $active . DIRECTORY_SEPARATOR . self::SIGNING_TRANSACTION_SIGNATURE,
                $signatureBytes,
                4096,
                'staged package signature',
            );
            if (!$this->atomicRename(
                $active . DIRECTORY_SEPARATOR . self::SIGNING_TRANSACTION_SIGNATURE,
                $package . DIRECTORY_SEPARATOR . 'manifest.sig',
                true,
            )) {
                throw new \RuntimeException(
                    'Unable to publish the detached package signature.'
                );
            }
            $this->assertRecordedIdentity(
                $package . DIRECTORY_SEPARATOR . 'manifest.sig',
                false,
                0600,
                $transaction['expected']['identities']['signature'],
                'published package signature',
            );
            $quarantineDirectory = $active . DIRECTORY_SEPARATOR
                . self::SIGNING_TRANSACTION_QUARANTINE;
            $this->quarantineExactFile(
                $manifestFile,
                $quarantineDirectory . DIRECTORY_SEPARATOR
                    . self::SIGNING_TRANSACTION_ORIGINAL_MANIFEST,
                $manifestBytes,
                $transaction['expected']['identities']['original_manifest'],
                $transaction['expected']['identities']['quarantine'],
                0644,
                'unsigned package manifest',
            );
            $this->assertSigningLockHeld($signingLock, $package);
            $this->assertRecordedIdentity(
                $active . DIRECTORY_SEPARATOR . self::SIGNING_TRANSACTION_MANIFEST,
                false,
                0644,
                $transaction['expected']['identities']['signed_manifest'],
                'staged signed manifest',
            );
            $this->assertExactFile(
                $active . DIRECTORY_SEPARATOR . self::SIGNING_TRANSACTION_MANIFEST,
                $signedManifest,
                self::MAX_MANIFEST_BYTES,
                'staged signed manifest',
            );
            if (!$this->atomicRename(
                $active . DIRECTORY_SEPARATOR . self::SIGNING_TRANSACTION_MANIFEST,
                $manifestFile,
                true,
            )) {
                throw new \RuntimeException(
                    'Unable to publish the signed package manifest.'
                );
            }
            $this->assertExactFile(
                $manifestFile,
                $signedManifest,
                self::MAX_MANIFEST_BYTES,
                'published signed manifest',
            );
            $this->assertRecordedIdentity(
                $manifestFile,
                false,
                0644,
                $transaction['expected']['identities']['signed_manifest'],
                'published signed manifest',
            );
            $this->assertSigningLockHeld($signingLock, $package);
            $this->verifySigningTransactionDirectory(
                $active,
                'active',
                $transaction['expected'],
            );
            if (!$this->atomicRename($active, $paths['complete'], true)) {
                throw new \RuntimeException(
                    'Unable to seal the completed release-signing transaction.'
                );
            }
            $this->assertRecordedIdentity(
                $paths['complete'],
                true,
                0700,
                $transaction['expected']['identities']['stage'],
                'completed signing transaction',
            );
            $this->verifySigningTransactionDirectory(
                $paths['complete'],
                'complete',
                $transaction['expected'],
            );
            $this->assertRecordedIdentity(
                $manifestFile,
                false,
                0644,
                $transaction['expected']['identities']['signed_manifest'],
                'committed signed manifest',
            );
            $this->assertExactFile(
                $manifestFile,
                $signedManifest,
                self::MAX_MANIFEST_BYTES,
                'committed signed manifest',
            );
            $this->assertRecordedIdentity(
                $package . DIRECTORY_SEPARATOR . 'manifest.sig',
                false,
                0600,
                $transaction['expected']['identities']['signature'],
                'committed package signature',
            );
            $this->assertExactFile(
                $package . DIRECTORY_SEPARATOR . 'manifest.sig',
                $signatureBytes,
                4096,
                'committed package signature',
            );
            $transactionActive = false;

            return $this->signingResult(
                $package,
                $manifest,
                $keyId,
                $signedManifest,
                $bootstrapOutput,
            );
        } catch (\Throwable $throwable) {
            if ($transactionActive
                && (\file_exists($this->signingTransactionPaths($package)['active'])
                    || \is_link($this->signingTransactionPaths($package)['active']))
            ) {
                try {
                    $this->rollbackSigningTransaction(
                        $package,
                        $options,
                        $keyId,
                        $secretKey,
                        $this->signingTransactionPaths($package)['active'],
                        null,
                        'active',
                        $signingLock,
                    );
                } catch (\Throwable $rollbackFailure) {
                    throw new \RuntimeException(
                        'Release signing failed and its transaction could not be safely rolled back: '
                            . $rollbackFailure->getMessage(),
                        0,
                        $throwable,
                    );
                }
            }
            throw $throwable;
        } finally {
            \sodium_memzero($secretKey);
        }
    }

    /** @return array<string,mixed> */
    private function acquireSigningLock(string $package): array
    {
        $root = $this->signingLockRoot();
        $rootIdentity = $this->securePathIdentity($root, true, 0700);
        $path = $root . DIRECTORY_SEPARATOR . \hash('sha256', $package) . '.lock';
        $packageIdentity = $this->securePathIdentity($package, true);
        $handle = null;
        if (!\file_exists($path) && !\is_link($path)) {
            $handle = @\fopen($path, 'x+b');
            if (\is_resource($handle)) {
                $this->sealPrivatePath($path, false, 0600);
            }
        }
        if (!\is_resource($handle)) {
            $before = $this->securePathIdentity($path, false, 0600);
            $handle = @\fopen($path, 'c+b');
            if (!\is_resource($handle)) {
                throw new \RuntimeException('Unable to open the package-bound signing lock.');
            }
            $opened = @\fstat($handle);
            if (!\is_array($opened)
                || !$this->statMatchesSecureIdentity($opened, $before)
            ) {
                @\fclose($handle);
                throw new \RuntimeException('Package signing lock identity changed while opening.');
            }
        }
        if (!@\flock($handle, LOCK_EX | LOCK_NB)) {
            @\fclose($handle);
            throw new \RuntimeException('The production package is already locked for signing.');
        }
        $identity = $this->securePathIdentity($path, false, 0600);
        $opened = @\fstat($handle);
        if (!\is_array($opened) || !$this->statMatchesSecureIdentity($opened, $identity)) {
            @\flock($handle, LOCK_UN);
            @\fclose($handle);
            throw new \RuntimeException('Package signing lock identity changed after acquisition.');
        }
        $record = \json_encode([
            'schema' => self::SIGNING_LOCK_SCHEMA,
            'lock_root_identity' => $rootIdentity,
            'package_path_sha256' => \hash('sha256', $package),
            'package_identity' => $packageIdentity,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        if (!@\ftruncate($handle, 0) || !@\rewind($handle)) {
            @\flock($handle, LOCK_UN);
            @\fclose($handle);
            throw new \RuntimeException('Unable to reset the package-bound signing lock.');
        }
        $written = @\fwrite($handle, $record);
        if ($written !== \strlen($record)
            || !@\fflush($handle)
            || (\function_exists('fsync') && !@\fsync($handle))
        ) {
            @\flock($handle, LOCK_UN);
            @\fclose($handle);
            throw new \RuntimeException('Unable to persist the package-bound signing lock.');
        }
        $this->sealPrivatePath($path, false, 0600);
        $identity = $this->securePathIdentity($path, false, 0600);

        return [
            'handle' => $handle,
            'path' => $path,
            'identity' => $identity,
            'record' => $record,
            'root' => $root,
            'root_identity' => $rootIdentity,
            'package_identity' => $packageIdentity,
            'package_path_sha256' => \hash('sha256', $package),
        ];
    }

    /** @param array<string,mixed> $lock */
    private function assertSigningLockHeld(array $lock, string $package): void
    {
        $handle = $lock['handle'] ?? null;
        if (!\is_resource($handle)
            || !\hash_equals(
                (string)($lock['package_path_sha256'] ?? ''),
                \hash('sha256', $package),
            )
        ) {
            throw new \RuntimeException('Package-bound signing lock is not held.');
        }
        $opened = @\fstat($handle);
        $root = (string)($lock['root'] ?? '');
        $currentRoot = $this->securePathIdentity($root, true, 0700);
        $current = $this->securePathIdentity((string)$lock['path'], false, 0600);
        $currentPackage = $this->securePathIdentity($package, true);
        $expectedRecord = (string)($lock['record'] ?? '');
        $record = @\rewind($handle)
            ? @\stream_get_contents($handle, \strlen($expectedRecord) + 1)
            : false;
        if (!\is_array($opened)
            || !\is_string($record)
            || !\hash_equals($expectedRecord, $record)
            || !\hash_equals(
                $root . DIRECTORY_SEPARATOR . \hash('sha256', $package) . '.lock',
                (string)($lock['path'] ?? ''),
            )
            || !$this->sameSecureIdentity(
                (array)($lock['root_identity'] ?? []),
                $currentRoot,
            )
            || !$this->statMatchesSecureIdentity($opened, $current)
            || !$this->sameSecureIdentity((array)$lock['identity'], $current)
            || !$this->sameSecureIdentity(
                (array)($lock['package_identity'] ?? []),
                $currentPackage,
            )
        ) {
            throw new \RuntimeException('Package-bound signing lock identity changed.');
        }
    }

    /** @param array<string,mixed> $lock */
    private function releaseSigningLock(array $lock): void
    {
        $handle = $lock['handle'] ?? null;
        if (!\is_resource($handle)) {
            return;
        }
        @\flock($handle, LOCK_UN);
        @\fclose($handle);
    }

    private function signingLockRoot(): string
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $baseInput = \trim((string)\getenv('LOCALAPPDATA'));
            if ($baseInput === '') {
                $baseInput = \sys_get_temp_dir();
            }
            $principal = \substr($this->windowsCurrentUserSidSha256(), 0, 24);
        } else {
            if (!\function_exists('posix_geteuid')) {
                throw new \RuntimeException(
                    'Protected signing locks require the POSIX identity extension.'
                );
            }
            $baseInput = \sys_get_temp_dir();
            $principal = (string)\posix_geteuid();
        }
        if ($baseInput === '' || \str_contains($baseInput, "\0")) {
            throw new \RuntimeException('Protected signing-lock base is unavailable.');
        }
        $base = @\realpath($baseInput);
        $status = \is_string($base) ? @\lstat($base) : false;
        if (!\is_string($base)
            || !\is_array($status)
            || \is_link($baseInput)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException('Protected signing-lock base is unsafe.');
        }
        if (\PHP_OS_FAMILY !== 'Windows') {
            $mode = ((int)$status['mode']) & 07777;
            if (($mode & 0022) !== 0 && ($mode & 01000) === 0) {
                throw new \RuntimeException(
                    'Protected signing-lock base is replaceable by another identity.'
                );
            }
        } else {
            $this->securePathIdentity($base, true);
        }
        $root = $base . DIRECTORY_SEPARATOR . self::SIGNING_LOCK_ROOT . '-' . $principal;
        $rootStatus = @\lstat($root);
        if (!\is_array($rootStatus)) {
            if (!@\mkdir($root, 0700) && !\is_dir($root)) {
                throw new \RuntimeException('Unable to create the protected signing-lock root.');
            }
        }
        $this->sealPrivatePath($root, true, 0700);
        $this->securePathIdentity($root, true, 0700);

        return $root;
    }

    /** @return array<string,int|string> */
    private function securePathIdentity(
        string $path,
        bool $directory,
        ?int $expectedMode = null,
    ): array {
        $stat = @\lstat($path);
        $expectedType = $directory ? 0040000 : 0100000;
        if (!\is_array($stat)
            || \is_link($path)
            || ((((int)($stat['mode'] ?? 0)) & 0170000) !== $expectedType)
            || (!$directory && (int)($stat['nlink'] ?? 0) !== 1)
        ) {
            throw new \RuntimeException('Secure signing path is missing, linked, or special.');
        }
        $mode = ((int)$stat['mode']) & 0777;
        if (\PHP_OS_FAMILY !== 'Windows'
            && $expectedMode !== null
            && $mode !== $expectedMode
        ) {
            throw new \RuntimeException('Secure signing path mode is outside policy.');
        }
        if (\PHP_OS_FAMILY !== 'Windows') {
            if (!\function_exists('posix_geteuid')
                || (int)($stat['uid'] ?? -1) !== (int)\posix_geteuid()
            ) {
                throw new \RuntimeException('Secure signing path owner is outside policy.');
            }
            if ($expectedMode !== null
                && ((((int)($stat['mode'] ?? 0)) & 07000) !== 0)
            ) {
                throw new \RuntimeException('Secure signing path mode is outside policy.');
            }
        }
        $windows = \PHP_OS_FAMILY === 'Windows'
            ? $this->windowsPathIdentity($path, $directory)
            : [
                'windows_file_id' => '',
                'windows_dacl_sha256' => '',
                'windows_owner_sid_sha256' => '',
            ];
        if (\PHP_OS_FAMILY === 'Windows'
            && !\hash_equals(
                $this->windowsCurrentUserSidSha256(),
                (string)$windows['windows_owner_sid_sha256'],
            )
        ) {
            throw new \RuntimeException('Secure signing path owner is outside policy.');
        }

        return [
            'device' => (int)($stat['dev'] ?? 0),
            'inode' => (int)($stat['ino'] ?? 0),
            'uid' => (int)($stat['uid'] ?? 0),
            'gid' => (int)($stat['gid'] ?? 0),
            'mode' => $mode,
            'type' => $directory ? 'directory' : 'file',
            'windows_file_id' => (string)$windows['windows_file_id'],
            'windows_dacl_sha256' => (string)$windows['windows_dacl_sha256'],
            'windows_owner_sid_sha256' => (string)$windows['windows_owner_sid_sha256'],
        ];
    }

    /** @param array<string|int,mixed> $stat @param array<string,mixed> $identity */
    private function statMatchesSecureIdentity(array $stat, array $identity): bool
    {
        return (int)($stat['dev'] ?? -1) === (int)($identity['device'] ?? -2)
            && (int)($stat['ino'] ?? -1) === (int)($identity['inode'] ?? -2)
            && ((((int)($stat['mode'] ?? 0)) & 0777) === (int)($identity['mode'] ?? -1));
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private function sameSecureIdentity(array $left, array $right): bool
    {
        foreach ([
            'device',
            'inode',
            'uid',
            'gid',
            'mode',
            'type',
            'windows_file_id',
            'windows_dacl_sha256',
            'windows_owner_sid_sha256',
        ] as $field) {
            if (!\array_key_exists($field, $left)
                || !\array_key_exists($field, $right)
                || (string)$left[$field] !== (string)$right[$field]
            ) {
                return false;
            }
        }
        return true;
    }

    private function sealPrivatePath(string $path, bool $directory, int $mode): void
    {
        $before = $this->securePathIdentity($path, $directory);
        if (!@\chmod($path, $mode)) {
            throw new \RuntimeException('Unable to seal private signing-path permissions.');
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            $this->sealWindowsPrivateDacl($path, $directory);
        }
        $after = $this->securePathIdentity($path, $directory, $mode);
        if (!$this->sameSigningObject($before, $after)) {
            throw new \RuntimeException('Private signing path identity changed while sealing.');
        }
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private function sameSigningObject(array $left, array $right): bool
    {
        foreach ([
            'device',
            'inode',
            'uid',
            'gid',
            'type',
            'windows_file_id',
            'windows_owner_sid_sha256',
        ] as $field) {
            if (!\array_key_exists($field, $left)
                || !\array_key_exists($field, $right)
                || (string)$left[$field] !== (string)$right[$field]
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array{
     *   windows_file_id:string,
     *   windows_dacl_sha256:string,
     *   windows_owner_sid_sha256:string
     * }
     */
    private function windowsPathIdentity(string $path, bool $directory): array
    {
        if (!\class_exists('FFI')) {
            throw new \RuntimeException('Windows signing identity requires PHP FFI.');
        }
        $kernel = $this->windowsKernelFfi();
        $security = $this->windowsSecurityFfi();
        $wide = $this->windowsWideString($kernel, $path);
        $handle = $kernel->CreateFileW(
            $wide,
            0x00020080,
            0x00000007,
            null,
            3,
            0x00200000 | ($directory ? 0x02000000 : 0),
            null,
        );
        if ((int)\FFI::cast('long long', $handle)->cdata === -1) {
            throw new \RuntimeException('Unable to open Windows signing path without following.');
        }
        try {
            $information = $kernel->new('BY_HANDLE_FILE_INFORMATION');
            if ($kernel->GetFileInformationByHandle($handle, \FFI::addr($information)) === 0
                || (((int)$information->dwFileAttributes) & 0x00000400) !== 0
            ) {
                throw new \RuntimeException('Windows signing path is reparse-backed or unreadable.');
            }
            $ownerSid = $security->new('void *');
            $dacl = $security->new('void *');
            $descriptor = $security->new('void *');
            $status = $security->GetSecurityInfo(
                $handle,
                1,
                0x00000005,
                \FFI::addr($ownerSid),
                null,
                \FFI::addr($dacl),
                null,
                \FFI::addr($descriptor),
            );
            if ($status !== 0
                || \FFI::isNull($ownerSid)
                || \FFI::isNull($dacl)
                || \FFI::isNull($descriptor)
                || $security->IsValidSid($ownerSid) === 0
            ) {
                throw new \RuntimeException('Unable to inspect Windows signing-path DACL.');
            }
            try {
                $length = (int)$security->GetSecurityDescriptorLength($descriptor);
                if ($length < 1 || $length > 65_536) {
                    throw new \RuntimeException('Windows signing-path DACL is outside bounds.');
                }
                $ownerLength = (int)$security->GetLengthSid($ownerSid);
                $acl = \FFI::cast('ACL *', $dacl);
                $aclLength = (int)$acl->AclSize;
                if ($ownerLength < 8
                    || $ownerLength > 1024
                    || $aclLength < 8
                    || $aclLength > 65_536
                ) {
                    throw new \RuntimeException(
                        'Windows signing-path owner or DACL is outside bounds.'
                    );
                }
                $ownerDigest = \hash('sha256', \FFI::string($ownerSid, $ownerLength));
                $daclDigest = \hash('sha256', \FFI::string($dacl, $aclLength));
            } finally {
                $kernel->LocalFree($descriptor);
            }
            return [
                'windows_file_id' => \sprintf(
                    '%08x:%08x:%08x',
                    (int)$information->dwVolumeSerialNumber,
                    (int)$information->nFileIndexHigh,
                    (int)$information->nFileIndexLow,
                ),
                'windows_dacl_sha256' => $daclDigest,
                'windows_owner_sid_sha256' => $ownerDigest,
            ];
        } finally {
            $kernel->CloseHandle($handle);
        }
    }

    private function windowsCurrentUserSidSha256(): string
    {
        static $digest = null;
        if (\is_string($digest)) {
            return $digest;
        }
        if (!\class_exists('FFI')) {
            throw new \RuntimeException('Windows signing identity requires PHP FFI.');
        }
        $security = $this->windowsSecurityFfi();
        $kernel = $this->windowsKernelFfi();
        $token = $security->new('void *');
        if ($security->OpenProcessToken(
            $kernel->GetCurrentProcess(),
            0x0008,
            \FFI::addr($token),
        ) === 0 || \FFI::isNull($token)) {
            throw new \RuntimeException('Unable to open the Windows signing identity token.');
        }
        try {
            $required = $security->new('DWORD');
            $security->GetTokenInformation(
                $token,
                1,
                null,
                0,
                \FFI::addr($required),
            );
            $length = (int)$required->cdata;
            if ($length < 16 || $length > 65_536) {
                throw new \RuntimeException('Windows signing identity token is outside bounds.');
            }
            $buffer = $security->new('unsigned char[' . $length . ']');
            if ($security->GetTokenInformation(
                $token,
                1,
                \FFI::addr($buffer[0]),
                $length,
                \FFI::addr($required),
            ) === 0) {
                throw new \RuntimeException('Unable to read the Windows signing identity token.');
            }
            $tokenUser = \FFI::cast('TOKEN_USER *', \FFI::addr($buffer[0]));
            $sid = $tokenUser->User->Sid;
            if (\FFI::isNull($sid) || $security->IsValidSid($sid) === 0) {
                throw new \RuntimeException('Windows signing identity SID is invalid.');
            }
            $sidLength = (int)$security->GetLengthSid($sid);
            if ($sidLength < 8 || $sidLength > 1024) {
                throw new \RuntimeException('Windows signing identity SID is outside bounds.');
            }
            $digest = \hash('sha256', \FFI::string($sid, $sidLength));
            return $digest;
        } finally {
            $kernel->CloseHandle($token);
        }
    }

    private function sealWindowsPrivateDacl(string $path, bool $directory): void
    {
        $security = $this->windowsSecurityFfi();
        $kernel = $this->windowsKernelFfi();
        $sddl = $this->windowsWideString(
            $security,
            'D:P(A;;FA;;;OW)(A;;FA;;;SY)',
        );
        $descriptor = $security->new('void *');
        if ($security->ConvertStringSecurityDescriptorToSecurityDescriptorW(
            $sddl,
            1,
            \FFI::addr($descriptor),
            null,
        ) === 0) {
            throw new \RuntimeException('Unable to build the private Windows signing DACL.');
        }
        try {
            $present = $security->new('BOOL');
            $defaulted = $security->new('BOOL');
            $dacl = $security->new('void *');
            if ($security->GetSecurityDescriptorDacl(
                $descriptor,
                \FFI::addr($present),
                \FFI::addr($dacl),
                \FFI::addr($defaulted),
            ) === 0 || $present->cdata === 0 || \FFI::isNull($dacl)) {
                throw new \RuntimeException('Unable to extract the private Windows DACL.');
            }
            $pathWide = $this->windowsWideString($kernel, $path);
            $handle = $kernel->CreateFileW(
                $pathWide,
                0x00060080,
                0x00000007,
                null,
                3,
                0x00200000 | ($directory ? 0x02000000 : 0),
                null,
            );
            if ((int)\FFI::cast('long long', $handle)->cdata === -1) {
                throw new \RuntimeException(
                    'Unable to open the Windows signing path for DACL sealing.'
                );
            }
            try {
                $information = $kernel->new('BY_HANDLE_FILE_INFORMATION');
                $expectedDirectory = $directory ? 0x00000010 : 0;
                if ($kernel->GetFileInformationByHandle(
                    $handle,
                    \FFI::addr($information),
                ) === 0
                    || (((int)$information->dwFileAttributes) & 0x00000400) !== 0
                    || ((((int)$information->dwFileAttributes) & 0x00000010)
                        !== $expectedDirectory)
                ) {
                    throw new \RuntimeException(
                        'Windows signing path changed before DACL sealing.'
                    );
                }
                if ($security->SetSecurityInfo(
                    $handle,
                    1,
                    0x80000004,
                    null,
                    null,
                    $dacl,
                    null,
                ) !== 0) {
                    throw new \RuntimeException(
                        'Unable to apply the private Windows signing DACL.'
                    );
                }
            } finally {
                $kernel->CloseHandle($handle);
            }
        } finally {
            $kernel->LocalFree($descriptor);
        }
    }

    private function windowsSecurityFfi(): \FFI
    {
        static $ffi = null;
        if ($ffi instanceof \FFI) {
            return $ffi;
        }
        $ffi = \FFI::cdef(<<<'CDEF'
typedef unsigned short WCHAR;
typedef unsigned long DWORD;
typedef int BOOL;
typedef void *HANDLE;
typedef struct {
  unsigned char AclRevision;
  unsigned char Sbz1;
  unsigned short AclSize;
  unsigned short AceCount;
  unsigned short Sbz2;
} ACL;
typedef struct { void *Sid; DWORD Attributes; } SID_AND_ATTRIBUTES;
typedef struct { SID_AND_ATTRIBUTES User; } TOKEN_USER;
DWORD GetSecurityInfo(HANDLE, int, DWORD, void**, void**, void**, void**, void**);
DWORD GetSecurityDescriptorLength(void*);
DWORD GetLengthSid(void*);
BOOL IsValidSid(void*);
BOOL OpenProcessToken(HANDLE, DWORD, HANDLE*);
BOOL GetTokenInformation(HANDLE, int, void*, DWORD, DWORD*);
BOOL ConvertStringSecurityDescriptorToSecurityDescriptorW(const WCHAR*, DWORD, void**, DWORD*);
BOOL GetSecurityDescriptorDacl(void*, BOOL*, void**, BOOL*);
DWORD SetSecurityInfo(HANDLE, int, DWORD, void*, void*, void*, void*);
CDEF, 'advapi32.dll');
        return $ffi;
    }

    private function windowsKernelFfi(): \FFI
    {
        static $ffi = null;
        if ($ffi instanceof \FFI) {
            return $ffi;
        }
        $ffi = \FFI::cdef(<<<'CDEF'
typedef long long intptr_t;
typedef unsigned short WCHAR;
typedef unsigned long DWORD;
typedef int BOOL;
typedef void *HANDLE;
typedef struct {
  DWORD dwFileAttributes;
  DWORD ftCreationTimeLow; DWORD ftCreationTimeHigh;
  DWORD ftLastAccessTimeLow; DWORD ftLastAccessTimeHigh;
  DWORD ftLastWriteTimeLow; DWORD ftLastWriteTimeHigh;
  DWORD dwVolumeSerialNumber;
  DWORD nFileSizeHigh; DWORD nFileSizeLow;
  DWORD nNumberOfLinks;
  DWORD nFileIndexHigh; DWORD nFileIndexLow;
} BY_HANDLE_FILE_INFORMATION;
HANDLE CreateFileW(const WCHAR*, DWORD, DWORD, void*, DWORD, DWORD, HANDLE);
HANDLE GetCurrentProcess(void);
BOOL GetFileInformationByHandle(HANDLE, BY_HANDLE_FILE_INFORMATION*);
BOOL CloseHandle(HANDLE);
BOOL MoveFileExW(const WCHAR*, const WCHAR*, DWORD);
void *LocalFree(void*);
CDEF, 'kernel32.dll');
        return $ffi;
    }

    private function windowsWideString(\FFI $ffi, string $value): \FFI\CData
    {
        $bytes = \iconv('UTF-8', 'UTF-16LE', $value . "\0");
        if (!\is_string($bytes)) {
            throw new \RuntimeException('Unable to encode a Windows signing path.');
        }
        $units = \unpack('v*', $bytes);
        if (!\is_array($units) || $units === []) {
            throw new \RuntimeException('Unable to encode a Windows signing path.');
        }
        $wide = $ffi->new('WCHAR[' . \count($units) . ']');
        foreach (\array_values($units) as $index => $unit) {
            $wide[$index] = $unit;
        }
        return $wide;
    }

    /** @return array{active:string,complete:string,quarantine:string} */
    private function signingTransactionPaths(string $package): array
    {
        return [
            'active' => $package . '.signing-transaction',
            'complete' => $package . '.signing-complete',
            'quarantine' => $package . '.signing-quarantine',
        ];
    }

    private function signingStagingPath(string $package, string $transactionId): string
    {
        return $package . '.signing-stage-' . $transactionId;
    }

    /**
     * Recover only a fully authenticated pre-activation transaction. A path
     * without the signed record and exact inode/content topology is retained
     * for diagnosis; the bounded scanner prevents such unproved state from
     * growing without eventually stopping publication.
     *
     * @param array<string,string> $options
     * @param array<string,mixed> $signingLock
     */
    private function recoverAbandonedSigningStages(
        string $package,
        array $options,
        string $keyId,
        string $secretKey,
        array $signingLock,
    ): void {
        foreach ($this->abandonedSigningStages($package) as $stage) {
            try {
                $expected = $this->verifySigningTransactionRecord(
                    $package,
                    $options,
                    $keyId,
                    $secretKey,
                    $stage,
                );
                if (!\hash_equals(
                    $this->signingStagingPath(
                        $package,
                        (string)$expected['transaction_id'],
                    ),
                    $stage,
                )) {
                    throw new \RuntimeException(
                        'Abandoned signing stage is not bound to its transaction id.'
                    );
                }
                $this->verifySigningTransactionDirectory($stage, 'stage', $expected);
            } catch (\Throwable) {
                // Unauthenticated or incomplete staging is evidence, not owned
                // garbage. Never rename or delete it automatically.
                continue;
            }

            $manifest = $package . DIRECTORY_SEPARATOR . 'manifest.json';
            $signature = $package . DIRECTORY_SEPARATOR . 'manifest.sig';
            $this->assertRecordedIdentity(
                $manifest,
                false,
                0644,
                $expected['identities']['original_manifest'],
                'unsigned manifest for abandoned signing recovery',
            );
            $this->assertExactFile(
                $manifest,
                $expected['unsigned_manifest'],
                self::MAX_MANIFEST_BYTES,
                'unsigned manifest for abandoned signing recovery',
            );
            $this->assertRecordedIdentity(
                $manifest,
                false,
                0644,
                $expected['identities']['original_manifest'],
                'unsigned manifest for abandoned signing recovery',
            );
            if (\file_exists($signature) || \is_link($signature)) {
                throw new \RuntimeException(
                    'Abandoned signing recovery found a published package signature.'
                );
            }
            $reserved = $this->signingTransactionPaths($package);
            foreach ($reserved as $path) {
                if (\file_exists($path) || \is_link($path)) {
                    throw new \RuntimeException(
                        'Abandoned signing recovery conflicts with reserved transaction state.'
                    );
                }
            }
            $this->assertSigningLockHeld($signingLock, $package);
            if (!$this->atomicRename($stage, $reserved['active'], true)) {
                throw new \RuntimeException(
                    'Unable to activate the proof-bound abandoned signing stage.'
                );
            }
            $this->rollbackSigningTransaction(
                $package,
                $options,
                $keyId,
                $secretKey,
                $reserved['active'],
                $expected,
                'active',
                $signingLock,
            );
        }
    }

    /** @return list<string> */
    private function abandonedSigningStages(string $package): array
    {
        $parent = \dirname($package);
        $pattern = '/\A' . \preg_quote(\basename($package), '/')
            . '\.signing-stage-[a-f0-9]{32}\z/D';
        $handle = @\opendir($parent);
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to inspect abandoned signing staging.');
        }
        $stages = [];
        try {
            while (($entry = \readdir($handle)) !== false) {
                if (\preg_match($pattern, $entry) !== 1) {
                    continue;
                }
                $stages[] = $parent . DIRECTORY_SEPARATOR . $entry;
                if (\count($stages) > self::MAX_ABANDONED_SIGNING_STAGES) {
                    throw new \RuntimeException(
                        'The abandoned signing-stage bound was exceeded; retain state for diagnosis.'
                    );
                }
            }
        } finally {
            @\closedir($handle);
        }
        \sort($stages, SORT_STRING);
        return $stages;
    }

    /** @return array{stage:string,quarantine:string} */
    private function bootstrapTransactionPaths(string $output, string $transactionId): array
    {
        $parent = \dirname($output);
        $leaf = \basename($output);
        return [
            'stage' => $parent . DIRECTORY_SEPARATOR . '.' . $leaf
                . '.signing-stage-' . $transactionId,
            'quarantine' => $parent . DIRECTORY_SEPARATOR . '.' . $leaf
                . '.signing-quarantine-' . $transactionId,
        ];
    }

    /**
     * @param array<string,string> $options
     * @return array<string,mixed>|null
     */
    private function reconcileSigningTransaction(
        string $package,
        array $options,
        string $keyId,
        string $secretKey,
        array $signingLock,
    ): ?array {
        $this->assertSigningLockHeld($signingLock, $package);
        $present = [];
        foreach ($this->signingTransactionPaths($package) as $state => $directory) {
            $stat = @\lstat($directory);
            if (!\is_array($stat)) {
                continue;
            }
            if (\is_link($directory)
                || ((((int)($stat['mode'] ?? 0)) & 0170000) !== 0040000)
            ) {
                throw new \RuntimeException(
                    'Release-signing transaction state is linked or special.'
                );
            }
            $present[$state] = $directory;
        }
        if ($present === []) {
            return null;
        }
        if (\count($present) !== 1) {
            throw new \RuntimeException(
                'Multiple reserved release-signing transaction states are present.'
            );
        }

        $state = (string)\array_key_first($present);
        $directory = $present[$state];
        $expected = $this->verifySigningTransactionRecord(
            $package,
            $options,
            $keyId,
            $secretKey,
            $directory,
        );
        try {
            $this->verifySigningTransactionDirectory($directory, $state, $expected);
        } catch (\Throwable $mismatch) {
            if ($state === 'active') {
                $this->quarantineTransactionState(
                    $directory,
                    $this->signingTransactionPaths($package)['quarantine'],
                    $expected,
                );
                throw new \RuntimeException(
                    'Active release-signing artifacts mismatched proof and were quarantined.',
                    0,
                    $mismatch,
                );
            }
            throw $mismatch;
        }
        if ($state === 'quarantine') {
            $this->cleanupSigningTransactionQuarantine(
                $package,
                $directory,
                $expected,
                $signingLock,
            );
            return null;
        }
        if ($state !== 'complete') {
            $this->rollbackSigningTransaction(
                $package,
                $options,
                $keyId,
                $secretKey,
                $directory,
                $expected,
                $state,
                $signingLock,
            );
            return null;
        }

        $manifestFile = $package . DIRECTORY_SEPARATOR . 'manifest.json';
        $signatureFile = $package . DIRECTORY_SEPARATOR . 'manifest.sig';
        $this->assertRecordedIdentity(
            $manifestFile,
            false,
            0644,
            $expected['identities']['signed_manifest'],
            'completed signed manifest',
        );
        $this->assertExactFile(
            $manifestFile,
            $expected['signed_manifest'],
            self::MAX_MANIFEST_BYTES,
            'completed signed manifest',
        );
        $this->assertRecordedIdentity(
            $signatureFile,
            false,
            0600,
            $expected['identities']['signature'],
            'completed detached signature',
        );
        $this->assertExactFile(
            $signatureFile,
            $expected['signature_bytes'],
            4096,
            'completed detached signature',
        );
        $signature = \base64_decode(\trim($expected['signature_bytes']), true);
        $publicKey = \sodium_crypto_sign_publickey_from_secretkey($secretKey);
        if (!\is_string($signature)
            || \strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || !\sodium_crypto_sign_verify_detached(
                $signature,
                $expected['signed_manifest'],
                $publicKey,
            )
        ) {
            throw new \RuntimeException(
                'Completed release-signing transaction signature verification failed.'
            );
        }
        if ($expected['bootstrap_output'] !== null) {
            $this->verifyWindowsBootstrapBundle(
                $expected['bootstrap_output'],
                $expected['bootstrap_files'],
                $expected['identities'],
            );
        }
        $this->verifyAuditReceipt(
            $this->requiredOption($options, 'audit-receipt'),
            $expected['manifest'],
            $expected['unsigned_manifest'],
            \strtolower($this->requiredOption(
                $options,
                'expected-audit-environment-sha256',
            )),
        );

        return $this->signingResult(
            $package,
            $expected['signed_manifest_value'],
            $keyId,
            $expected['signed_manifest'],
            $expected['bootstrap_output'],
        );
    }

    /**
     * @param array<string,mixed> $manifest
     */
    private function signingTransactionRecord(
        string $package,
        string $transactionId,
        string $unsignedManifest,
        string $signedManifest,
        string $signatureBytes,
        ?string $bootstrapOutput,
        ?string $bootstrapStage,
        ?string $bootstrapQuarantine,
        array $identities,
        array $manifest,
        string $keyId,
        string $secretKey,
    ): string {
        $unsignedDigest = \hash('sha256', $unsignedManifest);
        $signedDigest = \hash('sha256', $signedManifest);
        $payload = [
            'arch' => (string)$manifest['arch'],
            'bootstrap_output' => $bootstrapOutput ?? '',
            'bootstrap_quarantine' => $bootstrapQuarantine ?? '',
            'bootstrap_stage' => $bootstrapStage ?? '',
            'identities' => $identities,
            'package_binding_sha256' => $this->signingPackageBinding(
                $package,
                $unsignedDigest,
                $signedDigest,
                $bootstrapOutput,
            ),
            'platform' => (string)$manifest['platform'],
            'schema' => self::SIGNING_TRANSACTION_SCHEMA,
            'signature_file_sha256' => \hash('sha256', $signatureBytes),
            'signed_manifest_sha256' => $signedDigest,
            'signing_key_id' => $keyId,
            'transaction_id' => $transactionId,
            'unsigned_manifest_base64' => \base64_encode($unsignedManifest),
            'unsigned_manifest_sha256' => $unsignedDigest,
            'version' => (string)$manifest['version'],
        ];
        $payloadBytes = $this->signingTransactionJson($payload);
        $record = $payload + [
            'proof_signature_base64' => \base64_encode(
                \sodium_crypto_sign_detached($payloadBytes, $secretKey),
            ),
        ];
        $recordBytes = $this->signingTransactionJson($record);
        if (\strlen($recordBytes) > self::MAX_SIGNING_TRANSACTION_BYTES) {
            throw new \RuntimeException('Release-signing transaction proof exceeds its bound.');
        }
        return $recordBytes;
    }

    private function signingPackageBinding(
        string $package,
        string $unsignedDigest,
        string $signedDigest,
        ?string $bootstrapOutput,
    ): string {
        return \hash(
            'sha256',
            $package . "\0" . $unsignedDigest . "\0" . $signedDigest . "\0"
                . ($bootstrapOutput ?? ''),
        );
    }

    private function assertBootstrapOutputBoundary(string $output, string $package): void
    {
        $unsafe = $this->pathIsWithin($output, $package);
        foreach ($this->signingTransactionPaths($package) as $reserved) {
            $unsafe = $unsafe || $this->pathIsWithin($output, $reserved);
        }
        if ($unsafe) {
            throw new \RuntimeException(
                'Bootstrap output overlaps the package or its reserved signing state.'
            );
        }
    }

    /** @param array<string,mixed> $value */
    private function signingTransactionJson(array $value): string
    {
        return \json_encode(
            $this->canonicalValue($value),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;
    }

    /** @return array<string,array<string,int|string>> */
    private function validatedRecordedIdentities(mixed $value): array
    {
        $expectedNames = [
            'bootstrap_helper',
            'bootstrap_manifest',
            'bootstrap_parent',
            'bootstrap_signature',
            'bootstrap_stage',
            'original_manifest',
            'quarantine',
            'rollback',
            'signature',
            'signed_manifest',
            'stage',
        ];
        $names = \is_array($value) ? \array_keys($value) : [];
        \sort($names, SORT_STRING);
        if (!\is_array($value) || $names !== $expectedNames) {
            throw new \RuntimeException('Release-signing identity proof topology is invalid.');
        }
        $identityKeys = [
            'device',
            'gid',
            'inode',
            'mode',
            'type',
            'uid',
            'windows_dacl_sha256',
            'windows_file_id',
            'windows_owner_sid_sha256',
        ];
        foreach ($value as $name => $identity) {
            if ($identity === [] && \in_array($name, [
                'bootstrap_helper',
                'bootstrap_manifest',
                'bootstrap_parent',
                'bootstrap_signature',
                'bootstrap_stage',
            ], true)) {
                continue;
            }
            $keys = \is_array($identity) ? \array_keys($identity) : [];
            \sort($keys, SORT_STRING);
            if (!\is_array($identity)
                || $keys !== $identityKeys
                || !\in_array((string)$identity['type'], ['file', 'directory'], true)
                || !\is_int($identity['device'])
                || !\is_int($identity['inode'])
                || !\is_int($identity['uid'])
                || !\is_int($identity['gid'])
                || !\is_int($identity['mode'])
                || !\is_string($identity['windows_file_id'])
                || !\is_string($identity['windows_dacl_sha256'])
                || !\is_string($identity['windows_owner_sid_sha256'])
            ) {
                throw new \RuntimeException('Release-signing identity proof is invalid.');
            }
        }
        return $value;
    }

    /**
     * @param array<string,string> $options
     * @return array<string,mixed>
     */
    private function verifySigningTransactionRecord(
        string $package,
        array $options,
        string $keyId,
        string $secretKey,
        string $directory,
    ): array {
        $recordFile = $directory . DIRECTORY_SEPARATOR . self::SIGNING_TRANSACTION_RECORD;
        if (!\file_exists($recordFile) || \is_link($recordFile)) {
            throw new \RuntimeException(
                'Reserved release-signing state lacks a valid transaction proof.'
            );
        }
        $recordBytes = $this->readStableFile(
            $recordFile,
            self::MAX_SIGNING_TRANSACTION_BYTES,
            'release-signing transaction proof',
        );
        $record = \json_decode($recordBytes, true);
        $keys = \is_array($record) ? \array_keys($record) : [];
        \sort($keys, SORT_STRING);
        if (!\is_array($record)
            || $keys !== [
                'arch',
                'bootstrap_output',
                'bootstrap_quarantine',
                'bootstrap_stage',
                'identities',
                'package_binding_sha256',
                'platform',
                'proof_signature_base64',
                'schema',
                'signature_file_sha256',
                'signed_manifest_sha256',
                'signing_key_id',
                'transaction_id',
                'unsigned_manifest_base64',
                'unsigned_manifest_sha256',
                'version',
            ]
            || !\hash_equals(self::SIGNING_TRANSACTION_SCHEMA, (string)($record['schema'] ?? ''))
            || !\hash_equals($keyId, (string)($record['signing_key_id'] ?? ''))
            || \preg_match('/\A[a-f0-9]{32}\z/D', (string)($record['transaction_id'] ?? '')) !== 1
        ) {
            throw new \RuntimeException('Release-signing transaction proof is invalid.');
        }
        $proof = \base64_decode((string)$record['proof_signature_base64'], true);
        $unsignedManifest = \base64_decode(
            (string)$record['unsigned_manifest_base64'],
            true,
        );
        $payload = $record;
        unset($payload['proof_signature_base64']);
        $publicKey = \sodium_crypto_sign_publickey_from_secretkey($secretKey);
        if (!\is_string($proof)
            || \strlen($proof) !== SODIUM_CRYPTO_SIGN_BYTES
            || !\sodium_crypto_sign_verify_detached(
                $proof,
                $this->signingTransactionJson($payload),
                $publicKey,
            )
            || !\hash_equals($this->signingTransactionJson($record), $recordBytes)
            || !\is_string($unsignedManifest)
            || \strlen($unsignedManifest) < 1
            || \strlen($unsignedManifest) > self::MAX_MANIFEST_BYTES
            || !\hash_equals(
                \strtolower((string)$record['unsigned_manifest_sha256']),
                \hash('sha256', $unsignedManifest),
            )
        ) {
            throw new \RuntimeException('Release-signing transaction proof is invalid.');
        }
        $verified = $this->verifyUnsignedCandidate(
            $package,
            false,
            $unsignedManifest,
            $package . DIRECTORY_SEPARATOR . 'manifest.json',
            true,
        );
        $manifest = $verified['manifest'];
        if (!\hash_equals((string)$manifest['version'], (string)$record['version'])
            || !\hash_equals((string)$manifest['platform'], (string)$record['platform'])
            || !\hash_equals((string)$manifest['arch'], (string)$record['arch'])
        ) {
            throw new \RuntimeException(
                'Release-signing transaction proof does not match its package manifest.'
            );
        }
        $bootstrapOutput = null;
        $bootstrapStage = null;
        $bootstrapQuarantine = null;
        $bootstrapFiles = [];
        $identities = $this->validatedRecordedIdentities($record['identities'] ?? null);
        $bootstrapIdentityNames = [
            'bootstrap_helper',
            'bootstrap_manifest',
            'bootstrap_parent',
            'bootstrap_signature',
            'bootstrap_stage',
        ];
        if ((string)$manifest['platform'] === 'Windows') {
            foreach ($bootstrapIdentityNames as $identityName) {
                if ($identities[$identityName] === []) {
                    throw new \RuntimeException(
                        'Release-signing bootstrap identity proof is incomplete.'
                    );
                }
            }
            $bootstrapOutput = $this->outputTarget(
                $this->requiredOption($options, 'bootstrap-output'),
                true,
            );
            $this->assertBootstrapOutputBoundary($bootstrapOutput, $package);
            if (!\hash_equals(
                $bootstrapOutput,
                (string)$record['bootstrap_output'],
            )) {
                throw new \RuntimeException(
                    'Release-signing transaction bootstrap target does not match.'
                );
            }
            $bootstrapPaths = $this->bootstrapTransactionPaths(
                $bootstrapOutput,
                (string)$record['transaction_id'],
            );
            $bootstrapStage = $bootstrapPaths['stage'];
            $bootstrapQuarantine = $bootstrapPaths['quarantine'];
            if (!\hash_equals($bootstrapStage, (string)$record['bootstrap_stage'])
                || !\hash_equals(
                    $bootstrapQuarantine,
                    (string)$record['bootstrap_quarantine'],
                )
            ) {
                throw new \RuntimeException(
                    'Release-signing bootstrap staging paths are not proof-bound.'
                );
            }
            $this->assertRecordedIdentity(
                \dirname($bootstrapOutput),
                true,
                null,
                $identities['bootstrap_parent'],
                'Windows bootstrap parent',
            );
            $bootstrapFiles = $this->windowsBootstrapFiles(
                $package,
                $manifest,
                $keyId,
                $secretKey,
            );
        } else {
            foreach ($bootstrapIdentityNames as $identityName) {
                if ($identities[$identityName] !== []) {
                    throw new \RuntimeException(
                        'Non-Windows signing proof contains bootstrap identities.'
                    );
                }
            }
            if ((string)$record['bootstrap_output'] !== ''
                || (string)$record['bootstrap_stage'] !== ''
                || (string)$record['bootstrap_quarantine'] !== ''
                || isset($options['bootstrap-output'])
            ) {
                throw new \RuntimeException(
                    'Release-signing transaction has an invalid bootstrap target.'
                );
            }
        }
        $signedManifestValue = $manifest;
        $signedManifestValue['release_ready'] = true;
        $signedManifestValue['signing_key_id'] = $keyId;
        $signedManifest = \json_encode(
            $signedManifestValue,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;
        $signatureBytes = \base64_encode(
            \sodium_crypto_sign_detached($signedManifest, $secretKey),
        ) . PHP_EOL;
        $signedDigest = \hash('sha256', $signedManifest);
        if (!\hash_equals(
                \strtolower((string)$record['signed_manifest_sha256']),
                $signedDigest,
            )
            || !\hash_equals(
                \strtolower((string)$record['signature_file_sha256']),
                \hash('sha256', $signatureBytes),
            )
            || !\hash_equals(
                \strtolower((string)$record['package_binding_sha256']),
                $this->signingPackageBinding(
                    $package,
                    \hash('sha256', $unsignedManifest),
                    $signedDigest,
                    $bootstrapOutput,
                ),
            )
        ) {
            throw new \RuntimeException(
                'Release-signing transaction proof is not package-bound.'
            );
        }

        return [
            'record_bytes' => $recordBytes,
            'manifest' => $manifest,
            'signed_manifest_value' => $signedManifestValue,
            'unsigned_manifest' => $unsignedManifest,
            'signed_manifest' => $signedManifest,
            'signature_bytes' => $signatureBytes,
            'bootstrap_output' => $bootstrapOutput,
            'bootstrap_stage' => $bootstrapStage,
            'bootstrap_quarantine' => $bootstrapQuarantine,
            'bootstrap_files' => $bootstrapFiles,
            'transaction_id' => (string)$record['transaction_id'],
            'identities' => $identities,
        ];
    }

    /** @param array<string,mixed> $expected */
    private function verifySigningTransactionDirectory(
        string $directory,
        string $state,
        array $expected,
    ): void {
        $this->assertRecordedIdentity(
            $directory,
            true,
            0700,
            $expected['identities']['stage'],
            $state . ' signing transaction',
        );
        $entries = $this->boundedDirectoryEntries($directory, 5);
        $allowed = [
            self::SIGNING_TRANSACTION_RECORD,
            self::SIGNING_TRANSACTION_ROLLBACK,
            self::SIGNING_TRANSACTION_MANIFEST,
            self::SIGNING_TRANSACTION_SIGNATURE,
            self::SIGNING_TRANSACTION_QUARANTINE,
        ];
        foreach ($entries as $entry) {
            if (!\in_array($entry, $allowed, true)) {
                throw new \RuntimeException(
                    'Release-signing transaction contains an unowned artifact.'
                );
            }
        }
        if (!\in_array(self::SIGNING_TRANSACTION_RECORD, $entries, true)) {
            throw new \RuntimeException(
                'Reserved release-signing state lacks a valid transaction proof.'
            );
        }
        $recordFile = $directory . DIRECTORY_SEPARATOR . self::SIGNING_TRANSACTION_RECORD;
        $recordIdentity = $this->securePathIdentity($recordFile, false, 0600);
        $this->assertExactFile(
            $recordFile,
            $expected['record_bytes'],
            self::MAX_SIGNING_TRANSACTION_BYTES,
            'release-signing transaction proof',
        );
        if (!$this->sameSecureIdentity(
            $recordIdentity,
            $this->securePathIdentity($recordFile, false, 0600),
        )) {
            throw new \RuntimeException(
                'Release-signing transaction proof identity changed.'
            );
        }
        $checks = [
            self::SIGNING_TRANSACTION_ROLLBACK => [
                $expected['unsigned_manifest'],
                self::MAX_MANIFEST_BYTES,
                'transaction unsigned-manifest rollback',
                $expected['identities']['rollback'],
                0600,
            ],
            self::SIGNING_TRANSACTION_MANIFEST => [
                $expected['signed_manifest'],
                self::MAX_MANIFEST_BYTES,
                'transaction signed-manifest candidate',
                $expected['identities']['signed_manifest'],
                0644,
            ],
            self::SIGNING_TRANSACTION_SIGNATURE => [
                $expected['signature_bytes'],
                4096,
                'transaction signature candidate',
                $expected['identities']['signature'],
                0600,
            ],
        ];
        foreach ($checks as $leaf => [$bytes, $maximum, $label, $identity, $mode]) {
            $file = $directory . DIRECTORY_SEPARATOR . $leaf;
            if (\file_exists($file) || \is_link($file)) {
                $this->assertExactFile($file, $bytes, $maximum, $label);
                $this->assertRecordedIdentity(
                    $file,
                    false,
                    $mode,
                    $identity,
                    $label,
                );
            }
        }
        $quarantine = $directory . DIRECTORY_SEPARATOR . self::SIGNING_TRANSACTION_QUARANTINE;
        $hasQuarantine = \in_array(
            self::SIGNING_TRANSACTION_QUARANTINE,
            $entries,
            true,
        );
        if (!$hasQuarantine && $state !== 'quarantine') {
            throw new \RuntimeException('Signing transaction lacks its private quarantine.');
        }
        if ($hasQuarantine) {
            $this->verifyTransactionQuarantine($quarantine, $expected, $state);
        }
        $this->verifyBootstrapTransactionLocations($expected, $state);
        if ($state === 'stage') {
            $required = $allowed;
            \sort($required, SORT_STRING);
            if ($entries !== $required
                || $this->boundedDirectoryEntries($quarantine, 0) !== []
            ) {
                throw new \RuntimeException('Private signing staging is incomplete.');
            }
        }
        if ($state === 'complete') {
            $required = [
                self::SIGNING_TRANSACTION_RECORD,
                self::SIGNING_TRANSACTION_ROLLBACK,
                self::SIGNING_TRANSACTION_QUARANTINE,
            ];
            \sort($entries, SORT_STRING);
            \sort($required, SORT_STRING);
            if ($entries !== $required) {
                throw new \RuntimeException(
                    'Completed release-signing transaction has incomplete publication state.'
                );
            }
            $quarantineEntries = $this->boundedDirectoryEntries($quarantine, 3);
            if ($quarantineEntries !== [self::SIGNING_TRANSACTION_ORIGINAL_MANIFEST]) {
                throw new \RuntimeException(
                    'Completed transaction lacks its exact unsigned-manifest quarantine.'
                );
            }
        }
        if ($state === 'quarantine'
            && !\in_array(self::SIGNING_TRANSACTION_RECORD, $entries, true)
        ) {
            throw new \RuntimeException(
                'Signing quarantine lacks its authenticated transaction proof.'
            );
        }
    }

    /** @param array<string,mixed> $expected */
    private function verifyTransactionQuarantine(
        string $directory,
        array $expected,
        string $state,
    ): void {
        $this->assertRecordedIdentity(
            $directory,
            true,
            0700,
            $expected['identities']['quarantine'],
            $state . ' transaction quarantine',
        );
        $entries = $this->boundedDirectoryEntries($directory, 3);
        $allowed = [
            self::SIGNING_TRANSACTION_ORIGINAL_MANIFEST => [
                $expected['unsigned_manifest'],
                $expected['identities']['original_manifest'],
                0644,
            ],
            self::SIGNING_TRANSACTION_PUBLISHED_MANIFEST => [
                $expected['signed_manifest'],
                $expected['identities']['signed_manifest'],
                0644,
            ],
            self::SIGNING_TRANSACTION_PUBLISHED_SIGNATURE => [
                $expected['signature_bytes'],
                $expected['identities']['signature'],
                0600,
            ],
        ];
        foreach ($entries as $leaf) {
            if (!isset($allowed[$leaf])) {
                throw new \RuntimeException(
                    'Transaction quarantine contains an unowned artifact.'
                );
            }
            [$bytes, $identity, $mode] = $allowed[$leaf];
            $file = $directory . DIRECTORY_SEPARATOR . $leaf;
            $this->assertExactFile(
                $file,
                $bytes,
                self::MAX_MANIFEST_BYTES,
                'transaction quarantine artifact',
            );
            $this->assertRecordedIdentity(
                $file,
                false,
                $mode,
                $identity,
                'transaction quarantine artifact',
            );
        }
    }

    /** @param array<string,mixed> $expected */
    private function verifyBootstrapTransactionLocations(array $expected, string $state): void
    {
        if ($expected['bootstrap_output'] === null) {
            return;
        }
        $this->assertRecordedIdentity(
            \dirname($expected['bootstrap_output']),
            true,
            null,
            $expected['identities']['bootstrap_parent'],
            'Windows bootstrap parent',
        );
        $locations = [
            'stage' => $expected['bootstrap_stage'],
            'published' => $expected['bootstrap_output'],
            'quarantine' => $expected['bootstrap_quarantine'],
        ];
        $present = [];
        foreach ($locations as $name => $path) {
            if (\file_exists($path) || \is_link($path)) {
                $this->assertRecordedIdentity(
                    $path,
                    true,
                    0700,
                    $expected['identities']['bootstrap_stage'],
                    $name . ' Windows bootstrap',
                );
                $this->verifyWindowsBootstrapBundle(
                    $path,
                    $expected['bootstrap_files'],
                    $expected['identities'],
                    $state === 'quarantine' && $name === 'quarantine',
                );
                $present[] = $name;
            }
        }
        $required = match ($state) {
            'stage' => ['stage'],
            'complete' => ['published'],
            'quarantine' => null,
            default => null,
        };
        $invalid = $required !== null
            ? $present !== $required
            : ($state === 'quarantine'
                ? \array_diff($present, ['quarantine']) !== []
                : \count($present) !== 1);
        if ($invalid) {
            throw new \RuntimeException(
                'Windows bootstrap transaction location is incomplete or ambiguous.'
            );
        }
    }

    /** @return list<string> */
    private function boundedDirectoryEntries(string $directory, int $maximum): array
    {
        $stat = @\lstat($directory);
        if (!\is_array($stat)
            || \is_link($directory)
            || ((((int)($stat['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException('Release-signing state directory is unsafe.');
        }
        $entries = @\scandir($directory);
        if (!\is_array($entries)) {
            throw new \RuntimeException('Unable to inspect release-signing state.');
        }
        $entries = \array_values(\array_diff($entries, ['.', '..']));
        if (\count($entries) > $maximum) {
            throw new \RuntimeException('Release-signing state exceeds its entry bound.');
        }
        \sort($entries, SORT_STRING);
        return $entries;
    }

    private function assertExactFile(
        string $file,
        string $expected,
        int $maximumBytes,
        string $label,
    ): void {
        $actual = $this->readStableFile($file, $maximumBytes, $label);
        if (!\hash_equals($expected, $actual)) {
            throw new \RuntimeException($label . ' does not match transaction ownership proof.');
        }
    }

    /** @param array<string,mixed> $expected */
    private function assertRecordedIdentity(
        string $path,
        bool $directory,
        ?int $mode,
        array $expected,
        string $label,
    ): array {
        $current = $this->securePathIdentity($path, $directory, $mode);
        if (!$this->sameSecureIdentity($expected, $current)) {
            throw new \RuntimeException($label . ' identity changed.');
        }
        return $current;
    }

    /**
     * @param array<string,mixed> $sourceIdentity
     * @param array<string,mixed> $quarantineIdentity
     */
    private function quarantineExactFile(
        string $source,
        string $destination,
        string $expectedBytes,
        array $sourceIdentity,
        array $quarantineIdentity,
        int $mode,
        string $label,
    ): void {
        if (\file_exists($destination) || \is_link($destination)) {
            throw new \RuntimeException($label . ' quarantine target already exists.');
        }
        $quarantine = \dirname($destination);
        $this->assertRecordedIdentity(
            $quarantine,
            true,
            0700,
            $quarantineIdentity,
            $label . ' quarantine directory',
        );
        $this->assertRecordedIdentity(
            $source,
            false,
            $mode,
            $sourceIdentity,
            $label,
        );
        $this->assertExactFile(
            $source,
            $expectedBytes,
            \max(self::MAX_MANIFEST_BYTES, 4096),
            $label,
        );
        $this->assertRecordedIdentity(
            $quarantine,
            true,
            0700,
            $quarantineIdentity,
            $label . ' quarantine directory',
        );
        $this->assertRecordedIdentity(
            $source,
            false,
            $mode,
            $sourceIdentity,
            $label,
        );
        if (!$this->atomicRename($source, $destination, true)) {
            throw new \RuntimeException('Unable to move ' . $label . ' into quarantine.');
        }
        $this->assertRecordedIdentity(
            $quarantine,
            true,
            0700,
            $quarantineIdentity,
            $label . ' quarantine directory',
        );
        $this->assertRecordedIdentity(
            $destination,
            false,
            $mode,
            $sourceIdentity,
            'quarantined ' . $label,
        );
        $this->assertExactFile(
            $destination,
            $expectedBytes,
            \max(self::MAX_MANIFEST_BYTES, 4096),
            'quarantined ' . $label,
        );
    }

    /** @param array<string,mixed> $expected */
    private function quarantineTransactionState(
        string $active,
        string $quarantine,
        array $expected,
    ): void {
        if (\file_exists($quarantine) || \is_link($quarantine)) {
            throw new \RuntimeException('Signing transaction quarantine already exists.');
        }
        $this->assertRecordedIdentity(
            $active,
            true,
            0700,
            $expected['identities']['stage'],
            'active signing transaction',
        );
        if (!$this->atomicRename($active, $quarantine, true)) {
            throw new \RuntimeException('Unable to quarantine the active signing transaction.');
        }
        $this->assertRecordedIdentity(
            $quarantine,
            true,
            0700,
            $expected['identities']['stage'],
            'quarantined signing transaction',
        );
    }

    private function persistDirectory(string $directory): void
    {
        $this->securePathIdentity($directory, true);
        if (!\function_exists('fsync') || \PHP_OS_FAMILY === 'Windows') {
            return;
        }
        $handle = @\fopen($directory, 'rb');
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to open signing directory for persistence.');
        }
        try {
            if (!@\fsync($handle)) {
                throw new \RuntimeException('Unable to persist signing-directory metadata.');
            }
        } finally {
            @\fclose($handle);
        }
    }

    private function atomicRename(string $source, string $destination, bool $writeThrough): bool
    {
        if (\file_exists($destination) || \is_link($destination)) {
            return false;
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            $kernel = $this->windowsKernelFfi();
            $from = $this->windowsWideString($kernel, $source);
            $to = $this->windowsWideString($kernel, $destination);
            return $kernel->MoveFileExW($from, $to, $writeThrough ? 0x00000008 : 0) !== 0;
        }
        if (!\class_exists('FFI')) {
            throw new \RuntimeException(
                'No-replace signing renames require PHP FFI on this platform.'
            );
        }
        $renamed = match (\PHP_OS_FAMILY) {
            'Linux' => $this->unixRenameFfi()->renameat2(
                -100,
                $source,
                -100,
                $destination,
                1,
            ) === 0,
            'Darwin' => $this->unixRenameFfi()->renamex_np(
                $source,
                $destination,
                0x00000004 | 0x00000010,
            ) === 0,
            default => throw new \RuntimeException(
                'No-replace signing rename is unsupported on this platform.'
            ),
        };
        if (!$renamed) {
            return false;
        }
        $this->persistDirectory(\dirname($source));
        if (!\hash_equals(\dirname($source), \dirname($destination))) {
            $this->persistDirectory(\dirname($destination));
        }
        return true;
    }

    private function unixRenameFfi(): \FFI
    {
        static $ffi = [];
        $family = \PHP_OS_FAMILY;
        if (($ffi[$family] ?? null) instanceof \FFI) {
            return $ffi[$family];
        }
        $definition = match ($family) {
            'Linux' => 'int renameat2(int, const char *, int, const char *, unsigned int);',
            'Darwin' => 'int renamex_np(const char *, const char *, unsigned int);',
            default => throw new \RuntimeException(
                'No-replace signing rename is unsupported on this platform.'
            ),
        };
        $ffi[$family] = \FFI::cdef($definition);
        return $ffi[$family];
    }

    /**
     * @param array<string,array{bytes:string,mode:int}> $bootstrapFiles
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $signingLock
     * @return array<string,mixed>
     */
    private function prepareSigningTransaction(
        string $package,
        ?string $bootstrapOutput,
        string $unsignedManifest,
        string $signedManifest,
        string $signatureBytes,
        array $bootstrapFiles,
        array $manifest,
        string $keyId,
        string $secretKey,
        array $signingLock,
    ): array {
        $this->assertSigningLockHeld($signingLock, $package);
        $paths = $this->signingTransactionPaths($package);
        foreach ($paths as $directory) {
            if (\file_exists($directory) || \is_link($directory)) {
                throw new \RuntimeException(
                    'Reserved release-signing transaction state already exists.'
                );
            }
        }
        $transactionId = \bin2hex(\random_bytes(16));
        $stage = $this->signingStagingPath($package, $transactionId);
        $bootstrapPaths = $bootstrapOutput === null
            ? ['stage' => null, 'quarantine' => null]
            : $this->bootstrapTransactionPaths($bootstrapOutput, $transactionId);
        foreach ([$stage, $bootstrapPaths['stage'], $bootstrapPaths['quarantine']] as $private) {
            if ($private !== null && (\file_exists($private) || \is_link($private))) {
                throw new \RuntimeException('Private signing staging path already exists.');
            }
        }
        if (!@\mkdir($stage, 0700)) {
            throw new \RuntimeException('Unable to create private signing staging.');
        }
        $this->sealPrivatePath($stage, true, 0700);
        $quarantine = $stage . DIRECTORY_SEPARATOR . self::SIGNING_TRANSACTION_QUARANTINE;
        if (!@\mkdir($quarantine, 0700)) {
            throw new \RuntimeException('Unable to create private signing quarantine.');
        }
        $this->sealPrivatePath($quarantine, true, 0700);
        $rollbackFile = $stage . DIRECTORY_SEPARATOR . self::SIGNING_TRANSACTION_ROLLBACK;
        $signedFile = $stage . DIRECTORY_SEPARATOR . self::SIGNING_TRANSACTION_MANIFEST;
        $signatureFile = $stage . DIRECTORY_SEPARATOR . self::SIGNING_TRANSACTION_SIGNATURE;
        $this->writeFile($rollbackFile, $unsignedManifest, 0600);
        $this->writeFile($signedFile, $signedManifest, 0644);
        $this->writeFile($signatureFile, $signatureBytes, 0600);
        if ($bootstrapPaths['stage'] !== null) {
            $this->stageWindowsBootstrapBundle($bootstrapPaths['stage'], $bootstrapFiles);
        }
        $identities = [
            'stage' => $this->securePathIdentity($stage, true, 0700),
            'quarantine' => $this->securePathIdentity($quarantine, true, 0700),
            'rollback' => $this->securePathIdentity($rollbackFile, false, 0600),
            'signed_manifest' => $this->securePathIdentity($signedFile, false, 0644),
            'signature' => $this->securePathIdentity($signatureFile, false, 0600),
            'original_manifest' => $this->securePathIdentity(
                $package . DIRECTORY_SEPARATOR . 'manifest.json',
                false,
                0644,
            ),
            'bootstrap_parent' => $bootstrapOutput === null
                ? []
                : $this->securePathIdentity(\dirname($bootstrapOutput), true),
            'bootstrap_stage' => $bootstrapPaths['stage'] === null
                ? []
                : $this->securePathIdentity($bootstrapPaths['stage'], true, 0700),
            'bootstrap_helper' => $bootstrapPaths['stage'] === null
                ? []
                : $this->securePathIdentity(
                    $bootstrapPaths['stage'] . DIRECTORY_SEPARATOR
                        . self::WINDOWS_BOOTSTRAP_HELPER,
                    false,
                    0755,
                ),
            'bootstrap_manifest' => $bootstrapPaths['stage'] === null
                ? []
                : $this->securePathIdentity(
                    $bootstrapPaths['stage'] . DIRECTORY_SEPARATOR
                        . self::WINDOWS_BOOTSTRAP_MANIFEST,
                    false,
                    0644,
                ),
            'bootstrap_signature' => $bootstrapPaths['stage'] === null
                ? []
                : $this->securePathIdentity(
                    $bootstrapPaths['stage'] . DIRECTORY_SEPARATOR
                        . self::WINDOWS_BOOTSTRAP_SIGNATURE,
                    false,
                    0644,
                ),
        ];
        $recordBytes = $this->signingTransactionRecord(
            $package,
            $transactionId,
            $unsignedManifest,
            $signedManifest,
            $signatureBytes,
            $bootstrapOutput,
            $bootstrapPaths['stage'],
            $bootstrapPaths['quarantine'],
            $identities,
            $manifest,
            $keyId,
            $secretKey,
        );
        $recordFile = $stage . DIRECTORY_SEPARATOR . self::SIGNING_TRANSACTION_RECORD;
        $this->writeFile($recordFile, $recordBytes, 0600);
        $unsignedManifestValue = $manifest;
        $unsignedManifestValue['release_ready'] = false;
        $unsignedManifestValue['signing_key_id'] = '';
        $expected = [
            'record_bytes' => $recordBytes,
            'manifest' => $unsignedManifestValue,
            'signed_manifest_value' => $manifest,
            'unsigned_manifest' => $unsignedManifest,
            'signed_manifest' => $signedManifest,
            'signature_bytes' => $signatureBytes,
            'bootstrap_output' => $bootstrapOutput,
            'bootstrap_stage' => $bootstrapPaths['stage'],
            'bootstrap_quarantine' => $bootstrapPaths['quarantine'],
            'bootstrap_files' => $bootstrapFiles,
            'transaction_id' => $transactionId,
            'identities' => $identities,
        ];
        $this->verifySigningTransactionDirectory($stage, 'stage', $expected);
        if ($bootstrapPaths['stage'] !== null) {
            $this->verifyWindowsBootstrapBundle(
                $bootstrapPaths['stage'],
                $bootstrapFiles,
                $identities,
            );
        }
        $this->persistDirectory($quarantine);
        $this->persistDirectory($stage);
        if ($bootstrapPaths['stage'] !== null) {
            $this->persistDirectory($bootstrapPaths['stage']);
            $this->persistDirectory(\dirname($bootstrapPaths['stage']));
        }
        $this->assertRecordedIdentity(
            $package . DIRECTORY_SEPARATOR . 'manifest.json',
            false,
            0644,
            $identities['original_manifest'],
            'unsigned package manifest before transaction activation',
        );
        $this->assertExactFile(
            $package . DIRECTORY_SEPARATOR . 'manifest.json',
            $unsignedManifest,
            self::MAX_MANIFEST_BYTES,
            'unsigned package manifest before transaction activation',
        );
        if (\file_exists($package . DIRECTORY_SEPARATOR . 'manifest.sig')
            || \is_link($package . DIRECTORY_SEPARATOR . 'manifest.sig')
        ) {
            throw new \RuntimeException(
                'Package signature appeared before transaction activation.'
            );
        }
        $this->assertSigningLockHeld($signingLock, $package);
        foreach ($paths as $directory) {
            if (\file_exists($directory) || \is_link($directory)) {
                throw new \RuntimeException(
                    'Reserved signing state appeared before transaction activation.'
                );
            }
        }
        if (!$this->atomicRename($stage, $paths['active'], true)) {
            throw new \RuntimeException(
                'Unable to atomically activate the verified signing transaction.'
            );
        }
        $this->assertRecordedIdentity(
            $paths['active'],
            true,
            0700,
            $identities['stage'],
            'active signing transaction',
        );

        return [
            'active' => $paths['active'],
            'bootstrap_stage' => $bootstrapPaths['stage'],
            'expected' => $expected,
        ];
    }

    /**
     * @param array<string,string> $options
     * @param array<string,mixed>|null $expected
     */
    private function rollbackSigningTransaction(
        string $package,
        array $options,
        string $keyId,
        string $secretKey,
        string $directory,
        ?array $expected = null,
        string $state = 'active',
        array $signingLock = [],
    ): void {
        $this->assertSigningLockHeld($signingLock, $package);
        $expected ??= $this->verifySigningTransactionRecord(
            $package,
            $options,
            $keyId,
            $secretKey,
            $directory,
        );
        if ($state !== 'active') {
            throw new \RuntimeException('Only an active signing transaction can roll back.');
        }
        try {
            $this->verifySigningTransactionDirectory($directory, $state, $expected);
        } catch (\Throwable $mismatch) {
            $this->quarantineTransactionState(
                $directory,
                $this->signingTransactionPaths($package)['quarantine'],
                $expected,
            );
            throw new \RuntimeException(
                'Active release-signing artifacts mismatched proof and were quarantined.',
                0,
                $mismatch,
            );
        }
        $manifestFile = $package . DIRECTORY_SEPARATOR . 'manifest.json';
        $signatureFile = $package . DIRECTORY_SEPARATOR . 'manifest.sig';
        $manifestState = 'missing';
        if (\file_exists($manifestFile) || \is_link($manifestFile)) {
            try {
                $this->assertRecordedIdentity(
                    $manifestFile,
                    false,
                    0644,
                    $expected['identities']['original_manifest'],
                    'published unsigned manifest during signing rollback',
                );
                $this->assertExactFile(
                    $manifestFile,
                    $expected['unsigned_manifest'],
                    self::MAX_MANIFEST_BYTES,
                    'published unsigned manifest during signing rollback',
                );
                $manifestState = 'unsigned';
            } catch (\Throwable) {
                try {
                    $this->assertRecordedIdentity(
                        $manifestFile,
                        false,
                        0644,
                        $expected['identities']['signed_manifest'],
                        'published signed manifest during signing rollback',
                    );
                    $this->assertExactFile(
                        $manifestFile,
                        $expected['signed_manifest'],
                        self::MAX_MANIFEST_BYTES,
                        'published signed manifest during signing rollback',
                    );
                    $manifestState = 'signed';
                } catch (\Throwable $mismatch) {
                    $this->quarantineTransactionState(
                        $directory,
                        $this->signingTransactionPaths($package)['quarantine'],
                        $expected,
                    );
                    throw new \RuntimeException(
                        'Published manifest mismatched proof; the transaction was quarantined.',
                        0,
                        $mismatch,
                    );
                }
            }
        }
        if (\file_exists($signatureFile) || \is_link($signatureFile)) {
            try {
                $this->assertRecordedIdentity(
                    $signatureFile,
                    false,
                    0600,
                    $expected['identities']['signature'],
                    'published signature during signing rollback',
                );
                $this->assertExactFile(
                    $signatureFile,
                    $expected['signature_bytes'],
                    4096,
                    'published signature during signing rollback',
                );
            } catch (\Throwable $mismatch) {
                $this->quarantineTransactionState(
                    $directory,
                    $this->signingTransactionPaths($package)['quarantine'],
                    $expected,
                );
                throw new \RuntimeException(
                    'Published signature mismatched proof; the transaction was quarantined.',
                    0,
                    $mismatch,
                );
            }
        }
        $privateQuarantine = $directory . DIRECTORY_SEPARATOR
            . self::SIGNING_TRANSACTION_QUARANTINE;
        if (\file_exists($signatureFile) || \is_link($signatureFile)) {
            $this->assertSigningLockHeld($signingLock, $package);
            $this->quarantineExactFile(
                $signatureFile,
                $privateQuarantine . DIRECTORY_SEPARATOR
                    . self::SIGNING_TRANSACTION_PUBLISHED_SIGNATURE,
                $expected['signature_bytes'],
                $expected['identities']['signature'],
                $expected['identities']['quarantine'],
                0600,
                'published package signature',
            );
        }
        if ($manifestState === 'signed') {
            $this->assertSigningLockHeld($signingLock, $package);
            $this->quarantineExactFile(
                $manifestFile,
                $privateQuarantine . DIRECTORY_SEPARATOR
                    . self::SIGNING_TRANSACTION_PUBLISHED_MANIFEST,
                $expected['signed_manifest'],
                $expected['identities']['signed_manifest'],
                $expected['identities']['quarantine'],
                0644,
                'published signed manifest',
            );
        }
        $originalManifest = $privateQuarantine . DIRECTORY_SEPARATOR
            . self::SIGNING_TRANSACTION_ORIGINAL_MANIFEST;
        if ($manifestState === 'unsigned') {
            if (\file_exists($originalManifest) || \is_link($originalManifest)) {
                $this->quarantineTransactionState(
                    $directory,
                    $this->signingTransactionPaths($package)['quarantine'],
                    $expected,
                );
                throw new \RuntimeException(
                    'Unsigned manifest ownership was ambiguous; the transaction was quarantined.'
                );
            }
        } else {
            try {
                $this->assertRecordedIdentity(
                    $originalManifest,
                    false,
                    0644,
                    $expected['identities']['original_manifest'],
                    'quarantined unsigned manifest',
                );
                $this->assertExactFile(
                    $originalManifest,
                    $expected['unsigned_manifest'],
                    self::MAX_MANIFEST_BYTES,
                    'quarantined unsigned manifest',
                );
            } catch (\Throwable $mismatch) {
                $this->quarantineTransactionState(
                    $directory,
                    $this->signingTransactionPaths($package)['quarantine'],
                    $expected,
                );
                throw new \RuntimeException(
                    'Unsigned rollback artifact mismatched proof; the transaction was quarantined.',
                    0,
                    $mismatch,
                );
            }
            $this->assertSigningLockHeld($signingLock, $package);
            if (!$this->atomicRename($originalManifest, $manifestFile, true)) {
                throw new \RuntimeException('Unable to restore the unsigned package manifest.');
            }
        }
        $this->assertRecordedIdentity(
            $manifestFile,
            false,
            0644,
            $expected['identities']['original_manifest'],
            'restored unsigned package manifest',
        );
        $this->assertExactFile(
            $manifestFile,
            $expected['unsigned_manifest'],
            self::MAX_MANIFEST_BYTES,
            'restored unsigned package manifest',
        );
        if (\file_exists($signatureFile) || \is_link($signatureFile)) {
            throw new \RuntimeException('Published signature remained after quarantine.');
        }

        if ($expected['bootstrap_output'] !== null) {
            $bootstrapLocations = [
                $expected['bootstrap_stage'],
                $expected['bootstrap_output'],
                $expected['bootstrap_quarantine'],
            ];
            $present = [];
            foreach ($bootstrapLocations as $bootstrapLocation) {
                if (\file_exists($bootstrapLocation) || \is_link($bootstrapLocation)) {
                    $present[] = $bootstrapLocation;
                }
            }
            if (\count($present) !== 1) {
                throw new \RuntimeException(
                    'Windows bootstrap rollback location is incomplete or ambiguous.'
                );
            }
            $currentBootstrap = $present[0];
            if (!\hash_equals($currentBootstrap, $expected['bootstrap_quarantine'])) {
                $this->assertSigningLockHeld($signingLock, $package);
                if (!$this->atomicRename(
                    $currentBootstrap,
                    $expected['bootstrap_quarantine'],
                    true,
                )) {
                    throw new \RuntimeException(
                        'Unable to move the Windows bootstrap output into quarantine.'
                    );
                }
            }
            $this->assertRecordedIdentity(
                $expected['bootstrap_quarantine'],
                true,
                0700,
                $expected['identities']['bootstrap_stage'],
                'quarantined Windows bootstrap',
            );
            $this->verifyWindowsBootstrapBundle(
                $expected['bootstrap_quarantine'],
                $expected['bootstrap_files'],
                $expected['identities'],
            );
        }

        $transactionQuarantine = $this->signingTransactionPaths($package)['quarantine'];
        $this->assertSigningLockHeld($signingLock, $package);
        $this->quarantineTransactionState($directory, $transactionQuarantine, $expected);
        $this->cleanupSigningTransactionQuarantine(
            $package,
            $transactionQuarantine,
            $expected,
            $signingLock,
        );
    }

    /** @param array<string,mixed> $expected @param array<string,mixed> $signingLock */
    private function cleanupSigningTransactionQuarantine(
        string $package,
        string $directory,
        array $expected,
        array $signingLock,
    ): void {
        if (!\hash_equals(
            $this->signingTransactionPaths($package)['quarantine'],
            $directory,
        )) {
            throw new \RuntimeException(
                'Signing cleanup requires the package-bound quarantine path.'
            );
        }
        $this->assertSigningLockHeld($signingLock, $package);
        $this->verifySigningTransactionDirectory($directory, 'quarantine', $expected);
        $manifestFile = $package . DIRECTORY_SEPARATOR . 'manifest.json';
        $signatureFile = $package . DIRECTORY_SEPARATOR . 'manifest.sig';
        $this->assertRecordedIdentity(
            $manifestFile,
            false,
            0644,
            $expected['identities']['original_manifest'],
            'rollback-restored unsigned manifest',
        );
        $this->assertExactFile(
            $manifestFile,
            $expected['unsigned_manifest'],
            self::MAX_MANIFEST_BYTES,
            'rollback-restored unsigned manifest',
        );
        if (\file_exists($signatureFile) || \is_link($signatureFile)) {
            throw new \RuntimeException(
                'Signing quarantine cleanup found a published package signature.'
            );
        }
        if ($expected['bootstrap_output'] !== null) {
            foreach ([$expected['bootstrap_stage'], $expected['bootstrap_output']] as $unsafe) {
                if (\file_exists($unsafe) || \is_link($unsafe)) {
                    throw new \RuntimeException(
                        'Signing quarantine cleanup found a non-quarantined bootstrap output.'
                    );
                }
            }
            if (\file_exists($expected['bootstrap_quarantine'])
                || \is_link($expected['bootstrap_quarantine'])
            ) {
                $this->removeWindowsBootstrapBundle(
                    $expected['bootstrap_quarantine'],
                    $expected['bootstrap_files'],
                    $expected['identities'],
                    true,
                );
            }
        }

        $privateQuarantine = $directory . DIRECTORY_SEPARATOR
            . self::SIGNING_TRANSACTION_QUARANTINE;
        if (\file_exists($privateQuarantine) || \is_link($privateQuarantine)) {
            $privateEntries = $this->boundedDirectoryEntries($privateQuarantine, 3);
            if (\in_array(
                self::SIGNING_TRANSACTION_ORIGINAL_MANIFEST,
                $privateEntries,
                true,
            )) {
                throw new \RuntimeException(
                    'Signing cleanup found the original manifest in two locations.'
                );
            }
            $privateArtifacts = [
                self::SIGNING_TRANSACTION_PUBLISHED_MANIFEST => [
                    $expected['signed_manifest'],
                    self::MAX_MANIFEST_BYTES,
                    $expected['identities']['signed_manifest'],
                    0644,
                ],
                self::SIGNING_TRANSACTION_PUBLISHED_SIGNATURE => [
                    $expected['signature_bytes'],
                    4096,
                    $expected['identities']['signature'],
                    0600,
                ],
            ];
            foreach ($privateEntries as $leaf) {
                if (!isset($privateArtifacts[$leaf])) {
                    throw new \RuntimeException(
                        'Signing cleanup quarantine contains an unowned artifact.'
                    );
                }
                [$bytes, $maximum, $identity, $mode] = $privateArtifacts[$leaf];
                $this->removeExactQuarantinedFile(
                    $privateQuarantine . DIRECTORY_SEPARATOR . $leaf,
                    $bytes,
                    $maximum,
                    $identity,
                    $mode,
                    $privateQuarantine,
                    $expected['identities']['quarantine'],
                    'published signing artifact',
                );
            }
            $this->assertRecordedIdentity(
                $privateQuarantine,
                true,
                0700,
                $expected['identities']['quarantine'],
                'empty private signing quarantine',
            );
            if ($this->boundedDirectoryEntries($privateQuarantine, 0) !== []
                || !@\rmdir($privateQuarantine)
            ) {
                throw new \RuntimeException('Unable to remove empty private signing quarantine.');
            }
            $this->persistDirectory($directory);
        }

        $artifacts = [
            self::SIGNING_TRANSACTION_MANIFEST => [
                $expected['signed_manifest'],
                self::MAX_MANIFEST_BYTES,
                $expected['identities']['signed_manifest'],
                0644,
            ],
            self::SIGNING_TRANSACTION_SIGNATURE => [
                $expected['signature_bytes'],
                4096,
                $expected['identities']['signature'],
                0600,
            ],
            self::SIGNING_TRANSACTION_ROLLBACK => [
                $expected['unsigned_manifest'],
                self::MAX_MANIFEST_BYTES,
                $expected['identities']['rollback'],
                0600,
            ],
        ];
        foreach ($artifacts as $leaf => [$bytes, $maximum, $identity, $mode]) {
            $file = $directory . DIRECTORY_SEPARATOR . $leaf;
            if (!\file_exists($file) && !\is_link($file)) {
                continue;
            }
            $this->removeExactQuarantinedFile(
                $file,
                $bytes,
                $maximum,
                $identity,
                $mode,
                $directory,
                $expected['identities']['stage'],
                'transaction-owned rollback artifact',
            );
        }

        $recordFile = $directory . DIRECTORY_SEPARATOR . self::SIGNING_TRANSACTION_RECORD;
        $this->assertRecordedIdentity(
            $directory,
            true,
            0700,
            $expected['identities']['stage'],
            'signing transaction quarantine',
        );
        if ($this->boundedDirectoryEntries($directory, 1)
            !== [self::SIGNING_TRANSACTION_RECORD]
        ) {
            throw new \RuntimeException('Signing quarantine cleanup is incomplete.');
        }
        $recordIdentity = $this->securePathIdentity($recordFile, false, 0600);
        $this->assertExactFile(
            $recordFile,
            $expected['record_bytes'],
            self::MAX_SIGNING_TRANSACTION_BYTES,
            'release-signing transaction proof',
        );
        $retired = $package . '.signing-cleanup-' . $expected['transaction_id'];
        $this->assertSigningLockHeld($signingLock, $package);
        if (!$this->atomicRename($directory, $retired, true)) {
            throw new \RuntimeException('Unable to retire the empty signing quarantine.');
        }
        $this->assertRecordedIdentity(
            $retired,
            true,
            0700,
            $expected['identities']['stage'],
            'retired signing quarantine',
        );
        $retiredRecord = $retired . DIRECTORY_SEPARATOR . self::SIGNING_TRANSACTION_RECORD;
        $this->assertRecordedIdentity(
            $retiredRecord,
            false,
            0600,
            $recordIdentity,
            'retired signing transaction proof',
        );
        $this->assertExactFile(
            $retiredRecord,
            $expected['record_bytes'],
            self::MAX_SIGNING_TRANSACTION_BYTES,
            'retired signing transaction proof',
        );
        if (!@\unlink($retiredRecord)) {
            throw new \RuntimeException('Unable to remove the retired signing proof.');
        }
        $this->persistDirectory($retired);
        $this->assertRecordedIdentity(
            $retired,
            true,
            0700,
            $expected['identities']['stage'],
            'empty retired signing quarantine',
        );
        if ($this->boundedDirectoryEntries($retired, 0) !== [] || !@\rmdir($retired)) {
            throw new \RuntimeException('Unable to finalize signing quarantine cleanup.');
        }
        $this->persistDirectory(\dirname($retired));
    }

    /** @param array<string,mixed> $fileIdentity @param array<string,mixed> $directoryIdentity */
    private function removeExactQuarantinedFile(
        string $file,
        string $expectedBytes,
        int $maximumBytes,
        array $fileIdentity,
        int $mode,
        string $directory,
        array $directoryIdentity,
        string $label,
    ): void {
        $this->assertRecordedIdentity(
            $directory,
            true,
            0700,
            $directoryIdentity,
            $label . ' quarantine',
        );
        $this->assertRecordedIdentity(
            $file,
            false,
            $mode,
            $fileIdentity,
            $label,
        );
        $this->assertExactFile($file, $expectedBytes, $maximumBytes, $label);
        $this->assertRecordedIdentity(
            $file,
            false,
            $mode,
            $fileIdentity,
            $label,
        );
        if (!@\unlink($file)) {
            throw new \RuntimeException('Unable to remove ' . $label . '.');
        }
        $this->persistDirectory($directory);
        $this->assertRecordedIdentity(
            $directory,
            true,
            0700,
            $directoryIdentity,
            $label . ' quarantine',
        );
    }

    /**
     * @param array<string,mixed> $manifest
     * @return array<string,mixed>
     */
    private function signingResult(
        string $package,
        array $manifest,
        string $keyId,
        string $signedManifest,
        ?string $bootstrapOutput,
    ): array {
        return [
            'ok' => true,
            'output' => $package,
            'profile' => 'production',
            'release_ready' => true,
            'version' => (string)$manifest['version'],
            'platform' => (string)$manifest['platform'],
            'arch' => (string)$manifest['arch'],
            'signing_key_id' => $keyId,
            'manifest_sha256' => \hash('sha256', $signedManifest),
            'component_count' => \count($manifest['components']),
            'bootstrap_output' => $bootstrapOutput,
        ];
    }

    /**
     * @return array{
     *   manifest:array<string,mixed>,
     *   manifest_bytes:string,
     *   manifest_file:string
     * }
     */
    private function verifyUnsignedCandidate(
        string $package,
        bool $auditDependencies,
        ?string $providedManifestBytes = null,
        ?string $providedManifestFile = null,
        bool $allowSignature = false,
    ): array {
        $manifestFile = $providedManifestFile
            ?? $package . DIRECTORY_SEPARATOR . 'manifest.json';
        $manifestBytes = $providedManifestBytes ?? $this->readStableFile(
            $this->safeFile($manifestFile),
            self::MAX_MANIFEST_BYTES,
            'unsigned production manifest',
        );
        $manifest = \json_decode($manifestBytes, true);
        $manifestKeys = \is_array($manifest) ? \array_keys($manifest) : [];
        \sort($manifestKeys, SORT_STRING);
        $expectedManifestKeys = [
            'arch',
            'capabilities',
            'components',
            'implementation_level',
            'listen_profiles',
            'package_profile',
            'platform',
            'protocol_max',
            'protocol_min',
            'release_ready',
            'schema_version',
            'security_profile',
            'signing_key_id',
            'version',
        ];
        if (!\is_array($manifest)
            || $manifestKeys !== $expectedManifestKeys
            || (int)($manifest['schema_version'] ?? 0) !== 2
            || !\hash_equals('production', (string)($manifest['package_profile'] ?? ''))
            || ($manifest['release_ready'] ?? true) !== false
            || (string)($manifest['signing_key_id'] ?? '') !== ''
            || !\hash_equals('wls-2.0', (string)($manifest['implementation_level'] ?? ''))
            || !\hash_equals('native-broker-v1', (string)($manifest['security_profile'] ?? ''))
            || !\in_array(
                (string)($manifest['platform'] ?? ''),
                ['Darwin', 'Linux', 'Windows'],
                true,
            )
            || !\in_array(
                (string)($manifest['arch'] ?? ''),
                ['arm64', 'x86_64'],
                true,
            )
            || \preg_match(
                '/\A[0-9A-Za-z][0-9A-Za-z._+-]{0,63}\z/D',
                (string)($manifest['version'] ?? ''),
            ) !== 1
            || ($manifest['protocol_min'] ?? null) !== 2
            || ($manifest['protocol_max'] ?? null) !== 2
            || !\is_array($manifest['components'] ?? null)
            || !\is_array($manifest['capabilities'] ?? null)
            || ($manifest['listen_profiles'] ?? null) !== ['default', 'ipv4-only']
        ) {
            throw new \RuntimeException(
                'WLS Gateway release processing accepts only an unsigned production candidate.'
            );
        }
        if (!$allowSignature
            && (\file_exists($package . DIRECTORY_SEPARATOR . 'manifest.sig')
            || \is_link($package . DIRECTORY_SEPARATOR . 'manifest.sig')
            )
        ) {
            throw new \RuntimeException('Production candidate is already signed or unsafe.');
        }
        foreach (self::REQUIRED_CAPABILITIES as $capability) {
            if (($manifest['capabilities'][$capability] ?? false) !== true) {
                throw new \RuntimeException(
                    'Unsigned production candidate lacks capability: ' . $capability
                );
            }
        }
        if ((string)$manifest['platform'] === 'Windows'
            && ($manifest['capabilities']['windows_kernel32_ffi_atomic_write'] ?? false)
                !== true
        ) {
            throw new \RuntimeException(
                'Unsigned Windows candidate lacks the locked kernel32 FFI atomic-write capability.'
            );
        }
        $expectedCapabilities = self::REQUIRED_CAPABILITIES;
        if ((string)$manifest['platform'] === 'Windows') {
            $expectedCapabilities[] = 'windows_kernel32_ffi_atomic_write';
        }
        $actualCapabilities = \array_keys($manifest['capabilities']);
        \sort($expectedCapabilities, SORT_STRING);
        \sort($actualCapabilities, SORT_STRING);
        if ($actualCapabilities !== $expectedCapabilities) {
            throw new \RuntimeException(
                'Unsigned production candidate capability topology is not the locked WLS 2.0 set.'
            );
        }
        $expectedComponents = $this->requiredComponents((string)$manifest['platform']);
        $actualComponents = \array_keys($manifest['components']);
        foreach ($actualComponents as $relative) {
            if (!\is_string($relative)) {
                throw new \RuntimeException(
                    'Unsigned production candidate component paths must be strings.'
                );
            }
        }
        \sort($expectedComponents, SORT_STRING);
        \sort($actualComponents, SORT_STRING);
        if ($actualComponents !== $expectedComponents) {
            throw new \RuntimeException(
                'Unsigned production candidate component topology is not the locked WLS 2.0 set.'
            );
        }

        $this->verifyComponents($package, $manifest);
        $this->verifyProvenance($package, $manifest, $auditDependencies);

        return [
            'manifest' => $manifest,
            'manifest_bytes' => $manifestBytes,
            'manifest_file' => $manifestFile,
        ];
    }

    /** @return list<string> */
    private function requiredComponents(string $platform): array
    {
        $suffix = $platform === 'Windows' ? '.exe' : '';
        $required = [
            'app/controller.php',
            'bin/php' . $suffix,
            'bin/nginx' . $suffix,
            'bin/wls-gateway-broker' . $suffix,
            'bin/wls-gateway-launcher' . $suffix,
            'LICENSES.txt',
            'provenance.json',
            'sbom.cdx.json',
        ];
        if ($platform === 'Windows') {
            $required[] = 'bin/wls-bounded-command.exe';
        }
        return $required;
    }

    private function expectedComponentMode(string $relative, string $platform): int
    {
        if (\in_array($relative, [
            'app/controller.php',
            'LICENSES.txt',
            'provenance.json',
            'sbom.cdx.json',
        ], true)) {
            return 0644;
        }

        $suffix = $platform === 'Windows' ? '.exe' : '';
        $executables = [
            'bin/php' . $suffix,
            'bin/nginx' . $suffix,
            'bin/wls-gateway-broker' . $suffix,
            'bin/wls-gateway-launcher' . $suffix,
        ];
        if ($platform === 'Windows') {
            $executables[] = 'bin/wls-bounded-command.exe';
        }
        if (\in_array($relative, $executables, true)) {
            return 0755;
        }

        throw new \RuntimeException(
            'Package component path is outside the locked WLS 2.0 release set: ' . $relative
        );
    }

    /** @param array<string,mixed> $manifest */
    private function componentSetDigest(array $manifest): string
    {
        $components = $manifest['components'];
        \ksort($components, SORT_STRING);
        $records = [];
        foreach ($components as $relative => $definition) {
            if (!\is_string($relative) || !\is_array($definition)) {
                throw new \RuntimeException('Package component definition is invalid.');
            }
            $records[] = [
                'path' => $relative,
                'sha256' => \strtolower((string)($definition['sha256'] ?? '')),
                'size' => (int)($definition['size'] ?? -1),
                'mode' => (int)($definition['mode'] ?? 0),
            ];
        }
        return \hash('sha256', \json_encode(
            $records,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    /** @param array<string,mixed> $manifest */
    private function verifyAuditReceipt(
        string $file,
        array $manifest,
        string $manifestBytes,
        string $expectedEnvironmentDigest,
    ): void {
        $receiptBytes = $this->readStableFile(
            $this->safeFile($file),
            self::MAX_AUDIT_RECEIPT_BYTES,
            'dependency-audit receipt',
        );
        $receipt = \json_decode($receiptBytes, true);
        $receiptKeys = \is_array($receipt) ? \array_keys($receipt) : [];
        \sort($receiptKeys, SORT_STRING);
        $auditor = \is_array($receipt['auditor'] ?? null) ? $receipt['auditor'] : [];
        $auditorKeys = \array_keys($auditor);
        \sort($auditorKeys, SORT_STRING);
        $tools = \is_array($auditor['tools'] ?? null) ? $auditor['tools'] : [];
        $toolsValid = $tools !== [] && \array_is_list($tools);
        foreach ($tools as $tool) {
            $keys = \is_array($tool) ? \array_keys($tool) : [];
            \sort($keys, SORT_STRING);
            if (!\is_array($tool)
                || $keys !== [
                    'path',
                    'sha256',
                    'size',
                    'version_exit_code',
                    'version_output',
                ]
                || !\is_string($tool['path'])
                || $tool['path'] === ''
                || \strlen($tool['path']) > 32768
                || \str_contains($tool['path'], "\0")
                || \preg_match(
                    '/\A[a-f0-9]{64}\z/D',
                    \strtolower((string)$tool['sha256']),
                ) !== 1
                || !\is_int($tool['size'])
                || $tool['size'] < 1
                || $tool['size'] > self::MAX_COMPONENT_BYTES
                || !\is_int($tool['version_exit_code'])
                || $tool['version_exit_code'] < 0
                || $tool['version_exit_code'] > 255
                || !\is_string($tool['version_output'])
                || \strlen($tool['version_output']) > 4096
            ) {
                $toolsValid = false;
                break;
            }
        }
        $currentScript = $this->digestStableFile(
            $this->safeFile(__FILE__),
            self::MAX_METADATA_BYTES,
            'signer/auditor implementation',
        );
        $environmentDigest = $this->auditEnvironmentDigest($auditor);
        if (!\is_array($receipt)
            || $receiptKeys !== [
                'arch',
                'audit_environment_sha256',
                'auditor',
                'component_count',
                'component_set_sha256',
                'manifest_sha256',
                'platform',
                'schema',
                'version',
            ]
            || !\hash_equals(self::AUDIT_RECEIPT_SCHEMA, (string)($receipt['schema'] ?? ''))
            || !\hash_equals(
                \hash('sha256', $manifestBytes),
                \strtolower((string)($receipt['manifest_sha256'] ?? '')),
            )
            || !\hash_equals(
                $this->componentSetDigest($manifest),
                \strtolower((string)($receipt['component_set_sha256'] ?? '')),
            )
            || !\hash_equals((string)$manifest['version'], (string)($receipt['version'] ?? ''))
            || !\hash_equals((string)$manifest['platform'], (string)($receipt['platform'] ?? ''))
            || !\hash_equals((string)$manifest['arch'], (string)($receipt['arch'] ?? ''))
            || (int)($receipt['component_count'] ?? -1) !== \count($manifest['components'])
            || $auditorKeys !== ['implementation', 'script_sha256', 'tools']
            || !\hash_equals(
                'bounded-static-import-v2',
                (string)($auditor['implementation'] ?? ''),
            )
            || !\hash_equals(
                $currentScript['sha256'],
                \strtolower((string)($auditor['script_sha256'] ?? '')),
            )
            || !$toolsValid
            || \preg_match('/\A[a-f0-9]{64}\z/D', $expectedEnvironmentDigest) !== 1
            || !\hash_equals($expectedEnvironmentDigest, $environmentDigest)
            || !\hash_equals(
                $environmentDigest,
                \strtolower((string)($receipt['audit_environment_sha256'] ?? '')),
            )
        ) {
            throw new \RuntimeException(
                'Dependency-audit receipt does not match the immutable production candidate.'
            );
        }
    }

    /** @param array<string,mixed> $auditor */
    private function auditEnvironmentDigest(array $auditor): string
    {
        return \hash('sha256', \json_encode(
            $this->canonicalValue($auditor),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    private function canonicalValue(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $child) {
            $value[$key] = $this->canonicalValue($child);
        }
        if (!\array_is_list($value)) {
            \ksort($value, SORT_STRING);
        }
        return $value;
    }

    private function outputFileTarget(string $output): string
    {
        $absolute = \str_starts_with($output, DIRECTORY_SEPARATOR)
            || \preg_match('/\A[A-Za-z]:[\\\\\/]/D', $output) === 1;
        if ($output === '' || \str_contains($output, "\0") || !$absolute) {
            throw new \RuntimeException(
                'Dependency-audit receipt output must be one absolute local file.'
            );
        }
        $leaf = \basename($output);
        $parentInput = \dirname($output);
        $parent = @\realpath($parentInput);
        if (!\is_string($parent)
            || !\is_dir($parent)
            || \is_link($parentInput)
            || \preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D', $leaf) !== 1
        ) {
            throw new \RuntimeException(
                'Dependency-audit receipt output parent or leaf is unsafe.'
            );
        }
        $target = $parent . DIRECTORY_SEPARATOR . $leaf;
        if (\file_exists($target) || \is_link($target)) {
            throw new \RuntimeException(
                'Dependency-audit receipt output must not already exist.'
            );
        }
        return $target;
    }

    private function outputTarget(string $output, bool $allowExisting = false): string
    {
        $absolute = \str_starts_with($output, DIRECTORY_SEPARATOR)
            || \preg_match('/\A[A-Za-z]:[\\\\\/]/D', $output) === 1;
        if ($output === ''
            || \str_contains($output, "\0")
            || !$absolute
        ) {
            throw new \RuntimeException('Bootstrap output must be one absolute local directory.');
        }
        $leaf = \basename($output);
        $parentInput = \dirname($output);
        $parent = @\realpath($parentInput);
        if (!\is_string($parent)
            || !\is_dir($parent)
            || \is_link($parentInput)
            || \preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D', $leaf) !== 1
        ) {
            throw new \RuntimeException('Bootstrap output parent or leaf is unsafe.');
        }
        $target = $parent . DIRECTORY_SEPARATOR . $leaf;
        if (!$allowExisting && \is_link($target)) {
            throw new \RuntimeException('Bootstrap output must not be linked.');
        }
        if (!$allowExisting && \file_exists($target)) {
            throw new \RuntimeException('Bootstrap output must not already exist.');
        }

        return $target;
    }

    /**
     * @param array<string,mixed> $manifest
     * @return array<string,array{bytes:string,mode:int}>
     */
    private function windowsBootstrapFiles(
        string $package,
        array $manifest,
        string $keyId,
        string $secretKey,
    ): array {
        $definition = $manifest['components']['bin/wls-bounded-command.exe'] ?? null;
        $helperSize = \is_array($definition) ? ($definition['size'] ?? null) : null;
        $helperDigest = \is_array($definition)
            ? \strtolower((string)($definition['sha256'] ?? ''))
            : '';
        if (!\is_int($helperSize)
            || $helperSize < 1
            || $helperSize > self::WINDOWS_BOOTSTRAP_MAX_HELPER_BYTES
            || \preg_match('/\A[a-f0-9]{64}\z/D', $helperDigest) !== 1
        ) {
            throw new \RuntimeException(
                'The Windows bounded-command bootstrap component is outside its signed bounds.'
            );
        }
        $helper = $this->readStableFile(
            $package . DIRECTORY_SEPARATOR . 'bin'
                . DIRECTORY_SEPARATOR . 'wls-bounded-command.exe',
            self::WINDOWS_BOOTSTRAP_MAX_HELPER_BYTES,
            'Windows bounded-command bootstrap helper',
        );
        if (\strlen($helper) !== $helperSize
            || !\hash_equals($helperDigest, \hash('sha256', $helper))
        ) {
            throw new \RuntimeException(
                'Windows bounded-command helper changed while preparing its bootstrap bundle.'
            );
        }
        $bootstrapManifest = \json_encode([
            'schema' => self::WINDOWS_BOOTSTRAP_SCHEMA,
            'platform' => 'Windows',
            'arch' => (string)$manifest['arch'],
            'version' => (string)$manifest['version'],
            'signing_key_id' => $keyId,
            'component' => [
                'path' => self::WINDOWS_BOOTSTRAP_HELPER,
                'size' => $helperSize,
                'sha256' => $helperDigest,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            . PHP_EOL;

        return [
            self::WINDOWS_BOOTSTRAP_HELPER => ['bytes' => $helper, 'mode' => 0755],
            self::WINDOWS_BOOTSTRAP_MANIFEST => [
                'bytes' => $bootstrapManifest,
                'mode' => 0644,
            ],
            self::WINDOWS_BOOTSTRAP_SIGNATURE => [
                'bytes' => \base64_encode(
                    \sodium_crypto_sign_detached($bootstrapManifest, $secretKey),
                ) . PHP_EOL,
                'mode' => 0644,
            ],
        ];
    }

    /** @param array<string,array{bytes:string,mode:int}> $files */
    private function stageWindowsBootstrapBundle(string $candidate, array $files): string
    {
        if (!@\mkdir($candidate, 0700)) {
            throw new \RuntimeException(
                'Unable to create the Windows bounded-command bootstrap candidate.'
            );
        }
        $this->sealPrivatePath($candidate, true, 0700);
        foreach ($files as $leaf => $definition) {
            if (!\in_array($leaf, [
                self::WINDOWS_BOOTSTRAP_HELPER,
                self::WINDOWS_BOOTSTRAP_MANIFEST,
                self::WINDOWS_BOOTSTRAP_SIGNATURE,
            ], true)) {
                throw new \RuntimeException(
                    'Windows bootstrap transaction contains an unowned artifact.'
                );
            }
            $this->writeFile(
                $candidate . DIRECTORY_SEPARATOR . $leaf,
                $definition['bytes'],
                $definition['mode'],
            );
        }
        $this->verifyWindowsBootstrapBundle($candidate, $files);

        return $candidate;
    }

    /**
     * @param array<string,array{bytes:string,mode:int}> $expected
     * @param array<string,array<string,int|string>>|null $identities
     */
    private function verifyWindowsBootstrapBundle(
        string $directory,
        array $expected,
        ?array $identities = null,
        bool $allowPartial = false,
    ): void
    {
        $stat = @\lstat($directory);
        if (!\is_array($stat)
            || \is_link($directory)
            || ((((int)($stat['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException(
                'Windows bootstrap ownership proof root is linked or special.'
            );
        }
        $entries = $this->boundedDirectoryEntries($directory, \count($expected));
        $leaves = \array_keys($expected);
        \sort($leaves, SORT_STRING);
        if ((!$allowPartial && $entries !== $leaves)
            || \array_diff($entries, $leaves) !== []
        ) {
            throw new \RuntimeException(
                'Windows bootstrap bundle does not match transaction ownership proof.'
            );
        }
        foreach ($entries as $leaf) {
            $definition = $expected[$leaf];
            $file = $directory . DIRECTORY_SEPARATOR . $leaf;
            $identityKey = $this->windowsBootstrapIdentityKey($leaf);
            if ($identities !== null) {
                $this->assertRecordedIdentity(
                    $file,
                    false,
                    $definition['mode'],
                    $identities[$identityKey],
                    'Windows bootstrap transaction artifact',
                );
            }
            $this->assertExactFile(
                $file,
                $definition['bytes'],
                self::WINDOWS_BOOTSTRAP_MAX_HELPER_BYTES,
                'Windows bootstrap transaction artifact',
            );
            $fileStat = @\lstat($file);
            if (!\is_array($fileStat)
                || (((int)($fileStat['mode'] ?? 0)) & 0777) !== $definition['mode']
            ) {
                throw new \RuntimeException(
                    'Windows bootstrap artifact mode does not match ownership proof.'
                );
            }
            if ($identities !== null) {
                $this->assertRecordedIdentity(
                    $file,
                    false,
                    $definition['mode'],
                    $identities[$identityKey],
                    'Windows bootstrap transaction artifact',
                );
            }
        }
    }

    private function windowsBootstrapIdentityKey(string $leaf): string
    {
        return match ($leaf) {
            self::WINDOWS_BOOTSTRAP_HELPER => 'bootstrap_helper',
            self::WINDOWS_BOOTSTRAP_MANIFEST => 'bootstrap_manifest',
            self::WINDOWS_BOOTSTRAP_SIGNATURE => 'bootstrap_signature',
            default => throw new \RuntimeException(
                'Windows bootstrap proof contains an unexpected artifact.'
            ),
        };
    }

    /**
     * @param array<string,array{bytes:string,mode:int}> $expected
     * @param array<string,array<string,int|string>> $identities
     */
    private function removeWindowsBootstrapBundle(
        string $directory,
        array $expected,
        array $identities,
        bool $allowPartial = false,
    ): void
    {
        if (\preg_match(
            '/\A\.[A-Za-z0-9][A-Za-z0-9._-]{0,127}'
                . '\.signing-quarantine-[a-f0-9]{32}\z/D',
            \basename($directory),
        ) !== 1) {
            throw new \RuntimeException(
                'Windows bootstrap deletion requires a proof-bound quarantine path.'
            );
        }
        $this->verifyWindowsBootstrapBundle(
            $directory,
            $expected,
            $identities,
            $allowPartial,
        );
        $directoryIdentity = $identities['bootstrap_stage'];
        $parentIdentity = $identities['bootstrap_parent'];
        $this->assertRecordedIdentity(
            \dirname($directory),
            true,
            null,
            $parentIdentity,
            'Windows bootstrap quarantine parent',
        );
        foreach ($this->boundedDirectoryEntries($directory, \count($expected)) as $leaf) {
            $file = $directory . DIRECTORY_SEPARATOR . $leaf;
            $this->assertRecordedIdentity(
                $directory,
                true,
                0700,
                $directoryIdentity,
                'quarantined Windows bootstrap',
            );
            $this->assertRecordedIdentity(
                $file,
                false,
                $expected[$leaf]['mode'],
                $identities[$this->windowsBootstrapIdentityKey($leaf)],
                'quarantined Windows bootstrap artifact',
            );
            $this->assertExactFile(
                $file,
                $expected[$leaf]['bytes'],
                self::WINDOWS_BOOTSTRAP_MAX_HELPER_BYTES,
                'Windows bootstrap transaction artifact',
            );
            if (!@\unlink($file)) {
                throw new \RuntimeException(
                    'Unable to remove transaction-owned Windows bootstrap artifact.'
                );
            }
            $this->persistDirectory($directory);
            $this->assertRecordedIdentity(
                $directory,
                true,
                0700,
                $directoryIdentity,
                'quarantined Windows bootstrap',
            );
        }
        $this->assertRecordedIdentity(
            $directory,
            true,
            0700,
            $directoryIdentity,
            'empty quarantined Windows bootstrap',
        );
        if ($this->boundedDirectoryEntries($directory, 0) !== [] || !@\rmdir($directory)) {
            throw new \RuntimeException(
                'Unable to remove transaction-owned Windows bootstrap directory.'
            );
        }
        $this->persistDirectory(\dirname($directory));
        $this->assertRecordedIdentity(
            \dirname($directory),
            true,
            null,
            $parentIdentity,
            'Windows bootstrap quarantine parent',
        );
    }

    /** @return array{sha256:string,size:int} */
    private function digestStableFile(
        string $file,
        int $maximumBytes,
        string $label,
    ): array {
        $before = @\lstat($file);
        if (!\is_array($before)
            || \is_link($file)
            || ((((int)($before['mode'] ?? 0)) & 0170000) !== 0100000)
            || (int)($before['nlink'] ?? 0) !== 1
            || (int)($before['size'] ?? -1) < 1
            || (int)$before['size'] > $maximumBytes
        ) {
            throw new \RuntimeException($label . ' is missing, linked, or outside bounds.');
        }
        $handle = @\fopen($file, 'rb');
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to open ' . $label . '.');
        }
        try {
            $opened = @\fstat($handle);
            if (!\is_array($opened) || !$this->sameFileState($before, $opened)) {
                throw new \RuntimeException($label . ' changed before hashing.');
            }
            $context = \hash_init('sha256');
            $consumed = 0;
            while ($consumed < (int)$opened['size']) {
                $chunk = @\fread(
                    $handle,
                    \min(1_048_576, (int)$opened['size'] - $consumed),
                );
                if (!\is_string($chunk) || $chunk === '') {
                    throw new \RuntimeException($label . ' ended before its declared size.');
                }
                $consumed += \strlen($chunk);
                \hash_update($context, $chunk);
            }
            $extra = @\fread($handle, 1);
            $after = @\fstat($handle);
            $pathAfter = @\lstat($file);
            if ($extra !== ''
                || !\is_array($after)
                || !\is_array($pathAfter)
                || $consumed !== (int)$opened['size']
                || !$this->sameFileState($opened, $after)
                || !$this->sameFileState($after, $pathAfter)
            ) {
                throw new \RuntimeException($label . ' changed while being hashed.');
            }
            return [
                'sha256' => \hash_final($context),
                'size' => $consumed,
            ];
        } finally {
            @\fclose($handle);
        }
    }

    private function readStableFile(string $file, int $maximumBytes, string $label): string
    {
        $before = @\lstat($file);
        if (!\is_array($before)
            || \is_link($file)
            || ((((int)($before['mode'] ?? 0)) & 0170000) !== 0100000)
            || (int)($before['nlink'] ?? 0) !== 1
            || (int)($before['size'] ?? -1) < 1
            || (int)$before['size'] > $maximumBytes
        ) {
            throw new \RuntimeException($label . ' is missing, linked, or outside bounds.');
        }
        $handle = @\fopen($file, 'rb');
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to open ' . $label . '.');
        }
        try {
            $opened = @\fstat($handle);
            $bytes = @\stream_get_contents($handle, $maximumBytes + 1);
            $after = @\fstat($handle);
            $pathAfter = @\lstat($file);
            if (!\is_array($opened)
                || !\is_string($bytes)
                || !\is_array($after)
                || !\is_array($pathAfter)
                || \strlen($bytes) !== (int)($opened['size'] ?? -1)
                || !$this->sameFileState($before, $opened)
                || !$this->sameFileState($opened, $after)
                || !$this->sameFileState($after, $pathAfter)
            ) {
                throw new \RuntimeException($label . ' changed while being read.');
            }
            return $bytes;
        } finally {
            @\fclose($handle);
        }
    }

    /** @param array<string|int,mixed> $left @param array<string|int,mixed> $right */
    private function sameFileState(array $left, array $right): bool
    {
        foreach (['dev', 'ino', 'mode', 'nlink', 'size', 'mtime', 'ctime'] as $field) {
            if (!\array_key_exists($field, $left)
                || !\array_key_exists($field, $right)
                || (int)$left[$field] !== (int)$right[$field]
            ) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,string> $options */
    private function requiredOption(array $options, string $name): string
    {
        $value = \trim($options[$name] ?? '');
        if ($value === '') {
            throw new \InvalidArgumentException('Missing required option --' . $name . '.');
        }
        return $value;
    }

    private function packageDirectory(string $directory): string
    {
        if (\str_contains($directory, "\0") || \is_link($directory)) {
            throw new \RuntimeException('Production package directory is unsafe.');
        }
        $real = \realpath($directory);
        if (!\is_string($real) || !\is_dir($real) || \is_link($real)) {
            throw new \RuntimeException('Production package directory is missing or unsafe.');
        }
        return $real;
    }

    private function safeFile(string $file): string
    {
        if (\str_contains($file, "\0") || \is_link($file)) {
            throw new \RuntimeException('Release signing input is unsafe: ' . $file);
        }
        $real = \realpath($file);
        if (!\is_string($real) || !\is_file($real) || \is_link($real)) {
            throw new \RuntimeException('Release signing input is missing or unsafe: ' . $file);
        }
        return $real;
    }

    /** @param array<string,mixed> $manifest */
    private function verifyComponents(string $package, array $manifest): void
    {
        $totalBytes = 0;
        $packageIdentity = $this->securePathIdentity($package, true);
        foreach ($manifest['components'] as $relative => $definition) {
            if (!\is_string($relative)
                || !\hash_equals($relative, $this->relativePath($relative))
                || !\is_array($definition)
            ) {
                throw new \RuntimeException('Package component definition is invalid.');
            }
            $definitionKeys = \array_keys($definition);
            \sort($definitionKeys, SORT_STRING);
            if ($definitionKeys !== ['mode', 'sha256', 'size']) {
                throw new \RuntimeException(
                    'Package component declaration contains unknown fields: ' . $relative
                );
            }
            $expectedDigest = \strtolower((string)($definition['sha256'] ?? ''));
            $expectedSize = $definition['size'] ?? null;
            $mode = $definition['mode'] ?? null;
            if (\preg_match('/\A[a-f0-9]{64}\z/D', $expectedDigest) !== 1
                || !\is_int($expectedSize)
                || $expectedSize < 1
                || $expectedSize > self::MAX_COMPONENT_BYTES
                || !\is_int($mode)
                || $mode !== $this->expectedComponentMode(
                    $relative,
                    (string)$manifest['platform'],
                )
            ) {
                throw new \RuntimeException(
                    'Package component declaration violates the locked release mode: ' . $relative
                );
            }
            $file = $package . DIRECTORY_SEPARATOR
                . \str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $real = $this->safeFile($file);
            if (!$this->pathIsWithin($real, $package)) {
                throw new \RuntimeException('Package component escaped its candidate root.');
            }
            try {
                $componentIdentity = $this->securePathIdentity(
                    $real,
                    false,
                    \PHP_OS_FAMILY === 'Windows' ? null : $mode,
                );
                if ((\PHP_OS_FAMILY === 'Windows'
                        && !\hash_equals(
                            (string)$packageIdentity['windows_owner_sid_sha256'],
                            (string)$componentIdentity['windows_owner_sid_sha256'],
                        ))
                    || (\PHP_OS_FAMILY !== 'Windows'
                        && (int)$packageIdentity['uid'] !== (int)$componentIdentity['uid'])
                ) {
                    throw new \RuntimeException('Package component owner differs from its root.');
                }
                $inspected = $this->digestStableFile(
                    $real,
                    self::MAX_COMPONENT_BYTES,
                    'package component ' . $relative,
                );
                if (!$this->sameSecureIdentity(
                    $componentIdentity,
                    $this->securePathIdentity(
                        $real,
                        false,
                        \PHP_OS_FAMILY === 'Windows' ? null : $mode,
                    ),
                )) {
                    throw new \RuntimeException(
                        'Package component identity changed while it was verified.'
                    );
                }
            } catch (\Throwable $throwable) {
                throw new \RuntimeException(
                    'Package component mode or owner is outside release policy: ' . $relative,
                    0,
                    $throwable,
                );
            }
            if (!\hash_equals($expectedDigest, $inspected['sha256'])
                || $expectedSize !== $inspected['size']
            ) {
                throw new \RuntimeException(
                    'Package component changed before signing: ' . $relative
                );
            }
            $totalBytes += $inspected['size'];
            if ($totalBytes > self::MAX_PACKAGE_BYTES) {
                throw new \RuntimeException(
                    'Production package exceeds its fixed total size bound.'
                );
            }
        }
    }

    /** @param array<string,mixed> $manifest */
    private function verifyProvenance(
        string $package,
        array $manifest,
        bool $auditDependencies,
    ): void {
        $provenance = \json_decode(
            $this->readStableFile(
                $this->safeFile($package . DIRECTORY_SEPARATOR . 'provenance.json'),
                self::MAX_METADATA_BYTES,
                'production provenance',
            ),
            true,
        );
        $provenanceKeys = \is_array($provenance) ? \array_keys($provenance) : [];
        \sort($provenanceKeys, SORT_STRING);
        $targetKeys = \is_array($provenance['target'] ?? null)
            ? \array_keys($provenance['target'])
            : [];
        \sort($targetKeys, SORT_STRING);
        if (!\is_array($provenance)
            || $provenanceKeys !== ['components', 'schema_version', 'target']
            || $targetKeys !== ['arch', 'platform']
            || (int)($provenance['schema_version'] ?? 0) !== 1
            || !\hash_equals(
                (string)$manifest['platform'],
                (string)($provenance['target']['platform'] ?? ''),
            )
            || !\hash_equals(
                (string)$manifest['arch'],
                (string)($provenance['target']['arch'] ?? ''),
            )
            || !\is_array($provenance['components'] ?? null)
        ) {
            throw new \RuntimeException('Production package provenance is invalid.');
        }
        $suffix = (string)$manifest['platform'] === 'Windows' ? '.exe' : '';
        $files = [
            'controller' => 'app/controller.php',
            'php' => 'bin/php' . $suffix,
            'nginx' => 'bin/nginx' . $suffix,
            'wls-gateway-broker' => 'bin/wls-gateway-broker' . $suffix,
            'wls-gateway-launcher' => 'bin/wls-gateway-launcher' . $suffix,
        ];
        if ((string)$manifest['platform'] === 'Windows') {
            $files['wls-bounded-command'] = 'bin/wls-bounded-command.exe';
        }
        $provenanceNames = \array_keys($provenance['components']);
        foreach ($provenanceNames as $name) {
            if (!\is_string($name)) {
                throw new \RuntimeException(
                    'Production provenance component names must be strings.'
                );
            }
        }
        $expectedNames = \array_keys($files);
        \sort($provenanceNames, SORT_STRING);
        \sort($expectedNames, SORT_STRING);
        if ($provenanceNames !== $expectedNames) {
            throw new \RuntimeException(
                'Production provenance component topology is not the locked WLS 2.0 set.'
            );
        }
        $verified = [];
        foreach ($files as $name => $relative) {
            $definition = $provenance['components'][$name] ?? null;
            $file = $package . DIRECTORY_SEPARATOR
                . \str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $binary = $this->digestStableFile(
                $this->safeFile($file),
                self::MAX_COMPONENT_BYTES,
                'provenance component ' . $name,
            );
            $definitionKeys = \is_array($definition) ? \array_keys($definition) : [];
            \sort($definitionKeys, SORT_STRING);
            if (!\is_array($definition)
                || $definitionKeys !== [
                    'binary_sha256',
                    'license',
                    'self_contained',
                    'source_sha256',
                    'source_url',
                    'version',
                ]
                || \preg_match(
                    '/\A[a-f0-9]{64}\z/D',
                    \strtolower((string)($definition['source_sha256'] ?? '')),
                ) !== 1
                || \strlen(\trim((string)($definition['source_url'] ?? ''))) < 1
                || \strlen((string)$definition['source_url']) > 4096
                || \strlen(\trim((string)($definition['version'] ?? ''))) < 1
                || \strlen((string)$definition['version']) > 256
                || \strlen(\trim((string)($definition['license'] ?? ''))) < 1
                || \strlen((string)$definition['license']) > 1024
                || !\hash_equals(
                    \strtolower((string)($definition['binary_sha256'] ?? '')),
                    $binary['sha256'],
                )
                || ($name !== 'controller'
                    && ($definition['self_contained'] ?? false) !== true)
            ) {
                throw new \RuntimeException(
                    'Production provenance changed or is incomplete: ' . $name
                );
            }
            if ($auditDependencies && $name !== 'controller') {
                WlsGatewayDependencyAuditor::assertBaseSystemOnly(
                    $name,
                    $file,
                    (string)$manifest['platform'],
                );
            }
            $definition['binary_sha256'] = \strtolower(
                (string)$definition['binary_sha256'],
            );
            $verified[$name] = $definition;
        }
        $licenses = \trim($this->readStableFile(
            $this->safeFile($package . DIRECTORY_SEPARATOR . 'LICENSES.txt'),
            self::MAX_METADATA_BYTES,
            'production license inventory',
        ));
        $sbom = \json_decode($this->readStableFile(
            $this->safeFile($package . DIRECTORY_SEPARATOR . 'sbom.cdx.json'),
            self::MAX_METADATA_BYTES,
            'production CycloneDX SBOM',
        ), true);
        if ($licenses === ''
            || !\is_array($sbom)
            || !\hash_equals('CycloneDX', (string)($sbom['bomFormat'] ?? ''))
            || !\is_array($sbom['components'] ?? null)
            || $sbom['components'] === []
        ) {
            throw new \RuntimeException(
                'Production license inventory or CycloneDX SBOM is invalid.'
            );
        }
        $sbomComponents = [];
        foreach ($sbom['components'] as $component) {
            if (\is_array($component)
                && \is_string($component['name'] ?? null)
                && (string)$component['name'] !== ''
            ) {
                if (isset($sbomComponents[(string)$component['name']])) {
                    throw new \RuntimeException(
                        'Production CycloneDX SBOM contains duplicate component names.'
                    );
                }
                $sbomComponents[(string)$component['name']] = $component;
            }
        }
        $sbomNames = \array_keys($sbomComponents);
        $verifiedNames = \array_keys($verified);
        \sort($sbomNames, SORT_STRING);
        \sort($verifiedNames, SORT_STRING);
        if ($sbomNames !== $verifiedNames) {
            throw new \RuntimeException(
                'Production CycloneDX SBOM component topology does not match provenance.'
            );
        }
        foreach ($verified as $name => $definition) {
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
                    'Production CycloneDX SBOM does not match provenance: ' . $name
                );
            }
        }
    }

    /** @param array<string,mixed> $signingLock */
    private function loadSecretKey(string $file, array $signingLock): string
    {
        if (!\function_exists('sodium_crypto_sign_detached')) {
            throw new \RuntimeException('Libsodium is required to sign a production package.');
        }
        try {
            $safe = $this->safeFile($file);
            $before = $this->securePathIdentity(
                $safe,
                false,
                \PHP_OS_FAMILY === 'Windows' ? null : 0600,
            );
            $rootIdentity = (array)($signingLock['root_identity'] ?? []);
            $currentRoot = $this->securePathIdentity(
                (string)($signingLock['root'] ?? ''),
                true,
                0700,
            );
            if (!$this->sameSecureIdentity($rootIdentity, $currentRoot)
                || (\PHP_OS_FAMILY === 'Windows'
                    && (!\hash_equals(
                        (string)($currentRoot['windows_owner_sid_sha256'] ?? ''),
                        (string)($before['windows_owner_sid_sha256'] ?? ''),
                    )
                        || !\hash_equals(
                            (string)($currentRoot['windows_dacl_sha256'] ?? ''),
                            (string)($before['windows_dacl_sha256'] ?? ''),
                        )))
            ) {
                throw new \RuntimeException('Release signing key trust boundary changed.');
            }
            $encoded = \trim($this->readStableFile(
                $safe,
                self::MAX_SECRET_KEY_FILE_BYTES,
                'release signing key',
            ));
            $after = $this->securePathIdentity(
                $safe,
                false,
                \PHP_OS_FAMILY === 'Windows' ? null : 0600,
            );
            if (!$this->sameSecureIdentity($before, $after)) {
                throw new \RuntimeException('Release signing key identity changed while reading.');
            }
        } catch (\Throwable $throwable) {
            throw new \RuntimeException(
                'Release signing key permissions, owner, or DACL are unsafe.',
                0,
                $throwable,
            );
        }
        $key = \base64_decode($encoded, true);
        \sodium_memzero($encoded);
        if (!\is_string($key) || \strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \RuntimeException('Gateway release signing key file is invalid.');
        }
        return $key;
    }

    private function loadSecretKeyEnvironment(string $name): string
    {
        if (!\function_exists('sodium_crypto_sign_detached')) {
            throw new \RuntimeException('Libsodium is required to sign a production package.');
        }
        if (\preg_match('/\AWLS_GATEWAY_[A-Z0-9_]{1,96}\z/D', $name) !== 1) {
            throw new \RuntimeException('Release signing-key environment name is invalid.');
        }
        $encoded = \getenv($name);
        \putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
        if (!\is_string($encoded)
            || $encoded === ''
            || \strlen($encoded) > self::MAX_SECRET_KEY_FILE_BYTES
        ) {
            throw new \RuntimeException('Gateway release signing-key environment is missing or invalid.');
        }
        $key = \base64_decode(\trim($encoded), true);
        \sodium_memzero($encoded);
        if (!\is_string($key) || \strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \RuntimeException('Gateway release signing-key environment is invalid.');
        }
        return $key;
    }

    private function verifyTrustedSigningKey(
        string $secretKey,
        string $keyId,
        string $trustedKeysFile,
    ): void {
        $decoded = \json_decode(
            $this->readStableFile(
                $this->safeFile($trustedKeysFile),
                self::MAX_TRUSTED_KEYS_BYTES,
                'trusted release-key inventory',
            ),
            true,
        );
        $trusted = null;
        foreach ((array)($decoded['keys'] ?? []) as $candidate) {
            if (\is_array($candidate)
                && ($candidate['enabled'] ?? false) === true
                && \hash_equals($keyId, (string)($candidate['id'] ?? ''))
                && \hash_equals('ed25519', (string)($candidate['algorithm'] ?? ''))
            ) {
                $trusted = \base64_decode(
                    (string)($candidate['public_key_base64'] ?? ''),
                    true,
                );
                break;
            }
        }
        $derived = \sodium_crypto_sign_publickey_from_secretkey($secretKey);
        if (!\is_string($trusted)
            || \strlen($trusted) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || !\hash_equals($trusted, $derived)
        ) {
            throw new \RuntimeException(
                'Release signing key does not match an enabled trusted key id.'
            );
        }
    }

    private function relativePath(string $relative): string
    {
        $relative = \str_replace('\\', '/', \trim($relative));
        if ($relative === ''
            || \str_starts_with($relative, '/')
            || \preg_match('/\A[A-Za-z]:/', $relative) === 1
            || \in_array('..', \explode('/', $relative), true)
            || \str_contains($relative, "\0")
        ) {
            throw new \RuntimeException('Package component path is unsafe.');
        }
        return $relative;
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

    private function writeFile(string $file, string $contents, int $mode): void
    {
        $handle = @\fopen($file, 'xb');
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to stage release signature state.');
        }
        $failure = null;
        try {
            $offset = 0;
            while ($offset < \strlen($contents)) {
                $written = @\fwrite($handle, \substr($contents, $offset));
                if (!\is_int($written) || $written < 1) {
                    throw new \RuntimeException('Unable to write release signature state.');
                }
                $offset += $written;
            }
            if (!@\chmod($file, $mode)
                || !@\fflush($handle)
                || (\function_exists('fsync') && !@\fsync($handle))
            ) {
                throw new \RuntimeException('Unable to persist release signature state.');
            }
        } catch (\Throwable $throwable) {
            $failure = $throwable;
        } finally {
            @\fclose($handle);
        }
        if ($failure instanceof \Throwable) {
            if (\file_exists($file) || \is_link($file)) {
                throw new \RuntimeException(
                    'Release signature-state failure left a partial file: ' . $file,
                    0,
                    $failure,
                );
            }
            throw $failure;
        }
        $this->sealPrivatePath($file, false, $mode);
    }
}

if (\realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        $options = [];
        foreach (\array_slice($argv, 1) as $argument) {
            if (!\str_starts_with((string)$argument, '--')
                || !\str_contains((string)$argument, '=')
            ) {
                throw new \InvalidArgumentException(
                    'Arguments must use --name=value form.'
                );
            }
            [$name, $value] = \explode('=', \substr((string)$argument, 2), 2);
            if ($name === '' || isset($options[$name])) {
                throw new \InvalidArgumentException('Duplicate or invalid option: ' . $name);
            }
            $options[$name] = $value;
        }
        $mode = \strtolower(\trim($options['mode'] ?? 'assemble'));
        unset($options['mode']);
        $result = match ($mode) {
            'assemble' => (new WlsGatewayPackageBuilder())->build($options),
            'audit' => (new WlsGatewayPackageSigner())->audit($options),
            'sign' => (new WlsGatewayPackageSigner())->sign($options),
            default => throw new \InvalidArgumentException(
                'Package mode must be assemble, audit, or sign.'
            ),
        };
        echo \json_encode(
            $result,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;
        exit(0);
    } catch (\Throwable $throwable) {
        \fwrite(STDERR, \json_encode([
            'ok' => false,
            'error' => [
                'type' => $throwable::class,
                'message' => $throwable->getMessage(),
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
        exit(1);
    }
}
