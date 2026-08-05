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
        if (!\is_array($before)
            || \is_link($tool)
            || ((((int)($before['mode'] ?? 0)) & 0170000) !== 0100000)
            || (int)($before['nlink'] ?? 0) !== 1
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
            ? $this->loadSecretKey($this->requiredOption($options, 'signing-key-file'))
            : $this->loadSecretKeyEnvironment(
                $this->requiredOption($options, 'signing-key-environment'),
            );
        $bootstrapManifest = '';
        $bootstrapSignature = '';
        try {
            $this->verifyTrustedSigningKey(
                $secretKey,
                $keyId,
                $this->requiredOption($options, 'trusted-keys'),
            );
            $manifest['release_ready'] = true;
            $manifest['signing_key_id'] = $keyId;
            $signedManifest = \json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . PHP_EOL;
            $signature = \sodium_crypto_sign_detached($signedManifest, $secretKey);
            if ($bootstrapOutput !== null) {
                $helperDefinition = $manifest['components']['bin/wls-bounded-command.exe'];
                $helperSize = $helperDefinition['size'] ?? null;
                $helperDigest = \strtolower((string)($helperDefinition['sha256'] ?? ''));
                if (!\is_int($helperSize)
                    || $helperSize < 1
                    || $helperSize > self::WINDOWS_BOOTSTRAP_MAX_HELPER_BYTES
                    || \preg_match('/\A[a-f0-9]{64}\z/D', $helperDigest) !== 1
                ) {
                    throw new \RuntimeException(
                        'The Windows bounded-command bootstrap component is outside its signed bounds.'
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
                $bootstrapSignature = \base64_encode(
                    \sodium_crypto_sign_detached($bootstrapManifest, $secretKey),
                ) . PHP_EOL;
            }
        } finally {
            \sodium_memzero($secretKey);
        }

        $nonce = \bin2hex(\random_bytes(8));
        $bootstrapCandidate = '';
        $bootstrapPublished = false;
        if ($bootstrapOutput !== null) {
            $bootstrapCandidate = $this->stageWindowsBootstrapBundle(
                $bootstrapOutput . '.candidate.' . $nonce,
                $package . DIRECTORY_SEPARATOR . 'bin'
                    . DIRECTORY_SEPARATOR . 'wls-bounded-command.exe',
                $bootstrapManifest,
                $bootstrapSignature,
                $manifest['components']['bin/wls-bounded-command.exe'],
            );
        }
        $manifestCandidate = $manifestFile . '.candidate.' . $nonce;
        $signatureFile = $package . DIRECTORY_SEPARATOR . 'manifest.sig';
        $signatureCandidate = $signatureFile . '.candidate.' . $nonce;
        try {
            $this->writeFile($manifestCandidate, $signedManifest, 0644);
            if ($bootstrapOutput !== null) {
                if (!@\rename($bootstrapCandidate, $bootstrapOutput)) {
                    throw new \RuntimeException(
                        'Unable to atomically publish the Windows bounded-command bootstrap bundle.'
                    );
                }
                $bootstrapPublished = true;
                $bootstrapCandidate = '';
            }
            $this->writeFile(
                $signatureCandidate,
                \base64_encode($signature) . PHP_EOL,
                0600,
            );
            if (!@\rename($signatureCandidate, $signatureFile)) {
                throw new \RuntimeException(
                    'Unable to publish the detached package signature.'
                );
            }
            if (!@\rename($manifestCandidate, $manifestFile)) {
                @\unlink($signatureFile);
                throw new \RuntimeException(
                    'Unable to publish the signed package manifest.'
                );
            }
            @\chmod($manifestFile, 0644);
            @\chmod($signatureFile, 0600);
        } catch (\Throwable $throwable) {
            @\unlink($manifestCandidate);
            @\unlink($signatureCandidate);
            if ($bootstrapCandidate !== '') {
                $this->removeWindowsBootstrapBundle($bootstrapCandidate);
            }
            if ($bootstrapPublished && $bootstrapOutput !== null) {
                $this->removeWindowsBootstrapBundle($bootstrapOutput);
            }
            throw $throwable;
        }

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
    ): array {
        $manifestFile = $package . DIRECTORY_SEPARATOR . 'manifest.json';
        $manifestBytes = $this->readStableFile(
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
        if (\file_exists($package . DIRECTORY_SEPARATOR . 'manifest.sig')
            || \is_link($package . DIRECTORY_SEPARATOR . 'manifest.sig')
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

    private function outputTarget(string $output): string
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
        if (\file_exists($target) || \is_link($target)) {
            throw new \RuntimeException('Bootstrap output must not already exist.');
        }

        return $target;
    }

    /**
     * @param array<string,mixed> $definition
     */
    private function stageWindowsBootstrapBundle(
        string $candidate,
        string $helperFile,
        string $manifest,
        string $signature,
        array $definition,
    ): string {
        if (!@\mkdir($candidate, 0700)) {
            throw new \RuntimeException(
                'Unable to create the Windows bounded-command bootstrap candidate.'
            );
        }
        try {
            $helper = $this->readStableFile(
                $helperFile,
                self::WINDOWS_BOOTSTRAP_MAX_HELPER_BYTES,
                'Windows bounded-command bootstrap helper',
            );
            if (\strlen($helper) !== (int)($definition['size'] ?? -1)
                || !\hash_equals(
                    \strtolower((string)($definition['sha256'] ?? '')),
                    \hash('sha256', $helper),
                )
            ) {
                throw new \RuntimeException(
                    'Windows bounded-command helper changed while staging its bootstrap bundle.'
                );
            }
            $this->writeFile(
                $candidate . DIRECTORY_SEPARATOR . self::WINDOWS_BOOTSTRAP_HELPER,
                $helper,
                0755,
            );
            $this->writeFile(
                $candidate . DIRECTORY_SEPARATOR . self::WINDOWS_BOOTSTRAP_MANIFEST,
                $manifest,
                0644,
            );
            $this->writeFile(
                $candidate . DIRECTORY_SEPARATOR . self::WINDOWS_BOOTSTRAP_SIGNATURE,
                $signature,
                0644,
            );
        } catch (\Throwable $throwable) {
            $this->removeWindowsBootstrapBundle($candidate);
            throw $throwable;
        }

        return $candidate;
    }

    private function removeWindowsBootstrapBundle(string $directory): void
    {
        if (!\file_exists($directory) && !\is_link($directory)) {
            return;
        }
        if (!\is_dir($directory) || \is_link($directory)) {
            throw new \RuntimeException(
                'Windows bootstrap cleanup root is linked or special.'
            );
        }
        $records = \Weline\Server\Service\Edge\Gateway\GatewayBoundedTreeWalker::collect(
            $directory,
            true,
            true,
            4,
            1,
        );
        foreach ($records as $record) {
            \Weline\Server\Service\Edge\Gateway\GatewayBoundedTreeWalker::revalidate($record);
            $removed = ($record['directory'] ?? false) === true
                ? @\rmdir((string)$record['path'])
                : @\unlink((string)$record['path']);
            if (!$removed) {
                throw new \RuntimeException(
                    'Unable to remove Windows bootstrap release artifact.'
                );
            }
        }
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
                || $mode < 0400
                || $mode > 0755
            ) {
                throw new \RuntimeException(
                    'Package component declaration is outside release bounds: ' . $relative
                );
            }
            $file = $package . DIRECTORY_SEPARATOR
                . \str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $real = $this->safeFile($file);
            if (!$this->pathIsWithin($real, $package)) {
                throw new \RuntimeException('Package component escaped its candidate root.');
            }
            $inspected = $this->digestStableFile(
                $real,
                self::MAX_COMPONENT_BYTES,
                'package component ' . $relative,
            );
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

    private function loadSecretKey(string $file): string
    {
        if (!\function_exists('sodium_crypto_sign_detached')) {
            throw new \RuntimeException('Libsodium is required to sign a production package.');
        }
        $encoded = \trim($this->readStableFile(
            $this->safeFile($file),
            self::MAX_SECRET_KEY_FILE_BYTES,
            'release signing key',
        ));
        $key = \base64_decode($encoded, true);
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
            if (!@\fflush($handle)
                || (\function_exists('fsync') && !@\fsync($handle))
            ) {
                throw new \RuntimeException('Unable to persist release signature state.');
            }
        } catch (\Throwable $throwable) {
            $failure = $throwable;
        } finally {
            @\fclose($handle);
        }
        if ($failure instanceof \Throwable || !@\chmod($file, $mode)) {
            $cleanup = !\file_exists($file) || @\unlink($file);
            if (!$cleanup) {
                throw new \RuntimeException(
                    'Release signature-state failure left a partial file: ' . $file,
                    0,
                    $failure,
                );
            }
            throw $failure ?? new \RuntimeException(
                'Unable to seal release signature-state permissions.'
            );
        }
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
