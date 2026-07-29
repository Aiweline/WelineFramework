<?php

declare(strict_types=1);

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
                    return $resolved;
                }
            }
        }
        return '';
    }

    /**
     * @param list<string> $command
     * @return array{code:int,output:string}
     */
    private static function run(array $command): array
    {
        $process = @\proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true],
        );
        if (!\is_resource($process)) {
            return ['code' => 127, 'output' => 'process start failed'];
        }
        $output = (string)\stream_get_contents($pipes[1])
            . "\n" . (string)\stream_get_contents($pipes[2]);
        @\fclose($pipes[1]);
        @\fclose($pipes[2]);
        return ['code' => \proc_close($process), 'output' => \trim($output)];
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
    private const REQUIRED_EXECUTABLES = [
        'php',
        'nginx',
        'wls-gateway-broker',
        'wls-gateway-launcher',
    ];

    private const REQUIRED_PROVENANCE_COMPONENTS = [
        'controller',
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
        $controller = $this->safeInput($this->requiredOption($options, 'controller'));
        $licenses = $this->safeInput($this->requiredOption($options, 'licenses'));
        $executables = [];
        foreach (self::REQUIRED_EXECUTABLES as $name) {
            $executables[$name] = $this->safeInput(
                $this->requiredOption($options, $name),
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
        $this->runComponentSelfTests($controller, $executables);

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

    private function safeInput(string $path): string
    {
        if (\str_contains($path, "\0") || \is_link($path)) {
            throw new \RuntimeException('Package input is missing or unsafe: ' . $path);
        }
        $real = \realpath($path);
        if (!\is_string($real) || !\is_file($real) || \is_link($real)) {
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

        $file = $this->safeInput($file);
        $decoded = \json_decode((string)@\file_get_contents($file), true);
        if (!\is_array($decoded)
            || (int)($decoded['schema_version'] ?? 0) !== 1
            || !\hash_equals($platform, (string)($decoded['target']['platform'] ?? ''))
            || !\hash_equals($arch, $this->normalizeArch((string)($decoded['target']['arch'] ?? '')))
            || !\is_array($decoded['components'] ?? null)
        ) {
            throw new \RuntimeException('Gateway component provenance target is invalid.');
        }
        $sources = ['controller' => $controller] + $executables;
        foreach (self::REQUIRED_PROVENANCE_COMPONENTS as $name) {
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
                    (string)\hash_file('sha256', $sources[$name]),
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
        return [
            'version' => 'local-test',
            'source_url' => 'test://local-component',
            'source_sha256' => (string)\hash_file('sha256', $path),
            'binary_sha256' => (string)\hash_file('sha256', $path),
            'license' => 'test-only',
            'self_contained' => false,
        ];
    }

    /** @param array<string,string> $executables */
    private function runComponentSelfTests(string $controller, array $executables): void
    {
        $commands = [
            [$executables['php'], '-l', $controller],
            [$executables['php'], $controller, '--self-test'],
            [$executables['php'], '--version'],
            [$executables['nginx'], '-V'],
            [$executables['wls-gateway-broker'], '--self-test'],
            [$executables['wls-gateway-launcher'], '--self-test'],
        ];
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
        if (!@\copy($source, $target)) {
            throw new \RuntimeException('Unable to copy package component: ' . $source);
        }
        @\chmod($target, $mode);
        $relative = \str_replace('\\', '/', \substr($target, \strlen($output) + 1));
        $components[$relative] = $this->componentDefinition($target, $mode);
    }

    /** @return array{sha256:string,size:int,mode:int} */
    private function componentDefinition(string $file, int $mode): array
    {
        $digest = @\hash_file('sha256', $file);
        $size = @\filesize($file);
        if (!\is_string($digest) || !\is_int($size) || $size < 1) {
            throw new \RuntimeException('Unable to hash package component: ' . $file);
        }
        return ['sha256' => $digest, 'size' => $size, 'mode' => $mode];
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
        if (!\is_dir($directory) || \is_link($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $directory,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $item->isDir() && !$item->isLink()
                ? @\rmdir($path)
                : @\unlink($path);
        }
        @\rmdir($directory);
    }

    /**
     * @param list<string> $command
     * @return array{code:int,output:string}
     */
    private function run(array $command): array
    {
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @\proc_open($command, $descriptors, $pipes, null, null, [
            'bypass_shell' => true,
        ]);
        if (!\is_resource($process)) {
            return ['code' => 127, 'output' => 'process start failed'];
        }
        $stdout = (string)\stream_get_contents($pipes[1]);
        $stderr = (string)\stream_get_contents($pipes[2]);
        @\fclose($pipes[1]);
        @\fclose($pipes[2]);
        return [
            'code' => \proc_close($process),
            'output' => \trim($stdout . "\n" . $stderr),
        ];
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
    public function sign(array $options): array
    {
        $package = $this->packageDirectory($this->requiredOption($options, 'package'));
        $manifestFile = $package . DIRECTORY_SEPARATOR . 'manifest.json';
        $manifestBytes = (string)@\file_get_contents($this->safeFile($manifestFile));
        $manifest = \json_decode($manifestBytes, true);
        if (!\is_array($manifest)
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
            || (int)($manifest['protocol_min'] ?? 0) > 2
            || (int)($manifest['protocol_max'] ?? 0) < 2
            || !\is_array($manifest['components'] ?? null)
            || !\is_array($manifest['capabilities'] ?? null)
        ) {
            throw new \RuntimeException(
                'WLS Gateway signing accepts only an unsigned production candidate.'
            );
        }
        foreach (self::REQUIRED_CAPABILITIES as $capability) {
            if (($manifest['capabilities'][$capability] ?? false) !== true) {
                throw new \RuntimeException(
                    'Unsigned production candidate lacks capability: ' . $capability
                );
            }
        }
        $suffix = (string)$manifest['platform'] === 'Windows' ? '.exe' : '';
        foreach ([
            'app/controller.php',
            'bin/php' . $suffix,
            'bin/nginx' . $suffix,
            'bin/wls-gateway-broker' . $suffix,
            'bin/wls-gateway-launcher' . $suffix,
            'LICENSES.txt',
            'provenance.json',
            'sbom.cdx.json',
        ] as $required) {
            if (!\is_array($manifest['components'][$required] ?? null)) {
                throw new \RuntimeException(
                    'Unsigned production candidate lacks component: ' . $required
                );
            }
        }
        if (\file_exists($package . DIRECTORY_SEPARATOR . 'manifest.sig')
            || \is_link($package . DIRECTORY_SEPARATOR . 'manifest.sig')
        ) {
            throw new \RuntimeException('Production candidate is already signed or unsafe.');
        }
        $this->verifyComponents($package, $manifest);
        $this->verifyProvenance($package, $manifest);

        $keyId = \trim($this->requiredOption($options, 'signing-key-id'));
        $secretKey = $this->loadSecretKey(
            $this->requiredOption($options, 'signing-key-file'),
        );
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
        } finally {
            \sodium_memzero($secretKey);
        }

        $nonce = \bin2hex(\random_bytes(8));
        $manifestCandidate = $manifestFile . '.candidate.' . $nonce;
        $signatureFile = $package . DIRECTORY_SEPARATOR . 'manifest.sig';
        $signatureCandidate = $signatureFile . '.candidate.' . $nonce;
        $this->writeFile($manifestCandidate, $signedManifest, 0644);
        try {
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
        ];
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
        foreach ($manifest['components'] as $relative => $definition) {
            $relative = $this->relativePath((string)$relative);
            if (!\is_array($definition)) {
                throw new \RuntimeException('Package component definition is invalid.');
            }
            $file = $package . DIRECTORY_SEPARATOR
                . \str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $real = $this->safeFile($file);
            if (!$this->pathIsWithin($real, $package)) {
                throw new \RuntimeException('Package component escaped its candidate root.');
            }
            $digest = @\hash_file('sha256', $real);
            $size = @\filesize($real);
            if (!\is_string($digest)
                || !\is_int($size)
                || !\hash_equals(
                    \strtolower((string)($definition['sha256'] ?? '')),
                    $digest,
                )
                || (int)($definition['size'] ?? -1) !== $size
                || (int)($definition['mode'] ?? 0) < 0400
                || (int)($definition['mode'] ?? 0) > 0755
            ) {
                throw new \RuntimeException(
                    'Package component changed before signing: ' . $relative
                );
            }
        }
    }

    /** @param array<string,mixed> $manifest */
    private function verifyProvenance(string $package, array $manifest): void
    {
        $provenance = \json_decode(
            (string)@\file_get_contents($this->safeFile(
                $package . DIRECTORY_SEPARATOR . 'provenance.json',
            )),
            true,
        );
        if (!\is_array($provenance)
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
        $verified = [];
        foreach ($files as $name => $relative) {
            $definition = $provenance['components'][$name] ?? null;
            $file = $package . DIRECTORY_SEPARATOR
                . \str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!\is_array($definition)
                || \preg_match(
                    '/\A[a-f0-9]{64}\z/D',
                    \strtolower((string)($definition['source_sha256'] ?? '')),
                ) !== 1
                || \trim((string)($definition['source_url'] ?? '')) === ''
                || \trim((string)($definition['version'] ?? '')) === ''
                || \trim((string)($definition['license'] ?? '')) === ''
                || !\hash_equals(
                    \strtolower((string)($definition['binary_sha256'] ?? '')),
                    (string)@\hash_file('sha256', $file),
                )
                || ($name !== 'controller'
                    && ($definition['self_contained'] ?? false) !== true)
            ) {
                throw new \RuntimeException(
                    'Production provenance changed or is incomplete: ' . $name
                );
            }
            if ($name !== 'controller') {
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
        $licenses = \trim((string)@\file_get_contents($this->safeFile(
            $package . DIRECTORY_SEPARATOR . 'LICENSES.txt',
        )));
        $sbom = \json_decode((string)@\file_get_contents($this->safeFile(
            $package . DIRECTORY_SEPARATOR . 'sbom.cdx.json',
        )), true);
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
                $sbomComponents[(string)$component['name']] = $component;
            }
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
        $encoded = \trim((string)@\file_get_contents($this->safeFile($file)));
        $key = \base64_decode($encoded, true);
        if (!\is_string($key) || \strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \RuntimeException('Gateway release signing key file is invalid.');
        }
        return $key;
    }

    private function verifyTrustedSigningKey(
        string $secretKey,
        string $keyId,
        string $trustedKeysFile,
    ): void {
        $decoded = \json_decode(
            (string)@\file_get_contents($this->safeFile($trustedKeysFile)),
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
        try {
            if (@\fwrite($handle, $contents) !== \strlen($contents)) {
                throw new \RuntimeException('Unable to write release signature state.');
            }
            @\fflush($handle);
            \function_exists('fsync') && @\fsync($handle);
        } finally {
            @\fclose($handle);
        }
        @\chmod($file, $mode);
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
            'sign' => (new WlsGatewayPackageSigner())->sign($options),
            default => throw new \InvalidArgumentException(
                'Package mode must be assemble or sign.'
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
