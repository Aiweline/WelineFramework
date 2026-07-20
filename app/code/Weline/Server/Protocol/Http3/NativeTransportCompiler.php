<?php

declare(strict_types=1);

namespace Weline\Server\Protocol\Http3;

use Weline\Framework\Runtime\SchedulerSystem;

/**
 * Control-plane only native HTTP/3 dependency, build and manifest publisher.
 *
 * No request path may call this class. WLS invokes it before creating any
 * Master or Worker process and Workers consume only the immutable manifest.
 */
final class NativeTransportCompiler
{
    public const ABI_VERSION = 0x00020009;

    private const PACKAGES = [
        'libngtcp2',
        'libngtcp2_crypto_ossl',
        'libnghttp3',
        'openssl',
    ];

    private const MIN_PACKAGE_VERSIONS = [
        'libngtcp2' => '1.23.0',
        'libngtcp2_crypto_ossl' => '1.23.0',
        'libnghttp3' => '1.16.0',
        'openssl' => '3.5.0',
    ];

    private const COMMAND_TIMEOUT = 1800;
    private const LOCK_TIMEOUT = 1800.0;
    private const PUBLICATION_LOCK_TIMEOUT = 30.0;
    private const MAX_MANIFEST_BYTES = 131072;
    private const MAX_DARWIN_IMAGES = 128;
    private const MAX_DARWIN_EDGES = 512;
    private const DARWIN_LOAD_COMMANDS = [
        'LC_LOAD_DYLIB',
        'LC_LOAD_WEAK_DYLIB',
        'LC_REEXPORT_DYLIB',
        'LC_LOAD_UPWARD_DYLIB',
        'LC_LAZY_LOAD_DYLIB',
    ];
    private const DARWIN_INJECTION_ENVIRONMENT = [
        'DYLD_FRAMEWORK_PATH',
        'DYLD_FALLBACK_FRAMEWORK_PATH',
        'DYLD_LIBRARY_PATH',
        'DYLD_FALLBACK_LIBRARY_PATH',
        'DYLD_INSERT_LIBRARIES',
        'DYLD_ROOT_PATH',
        'DYLD_IMAGE_SUFFIX',
        'LD_PRELOAD',
    ];

    private ?NativeBuildProcessRunner $processRunner = null;
    private ?LinuxNativeDependencyInstaller $linuxDependencyInstaller = null;

    /**
     * @return array{status:string,ready:bool,message:string,manifest?:array<string,mixed>,output?:string}
     */
    public function ensure(bool $allowBuild = false): array
    {
        if (!\in_array(\PHP_OS_FAMILY, ['Darwin', 'Linux'], true)) {
            $message = 'HTTP/3 native transport is available only on macOS/Linux Direct Workers.';
            return ['status' => 'disabled', 'ready' => false, 'message' => $message];
        }

        $ffiFailure = $this->ffiRuntimeFailure();
        if ($ffiFailure !== null) {
            return [
                'status' => 'disabled',
                'ready' => false,
                'message' => $ffiFailure
                    . ' FFI must be preinstalled and enabled; this command will not install or inject it.',
            ];
        }

        $reusable = $this->reusableActiveManifest();
        if ($reusable !== null) {
            NativeTransportLibrary::reset();
            $runtimeVerified = NativeTransportLibrary::hasVerifiedRuntimeEvidence($reusable);
            return [
                'status' => 'ready',
                'ready' => true,
                'message' => $runtimeVerified
                    ? 'Reused the hash, ABI and runtime-verified native HTTP/3 transport.'
                    : 'Reused the hash and ABI-verified native HTTP/3 transport; the runtime self-test must run again.',
                'manifest' => $reusable,
            ];
        }

        if (!$allowBuild) {
            return [
                'status' => 'unavailable',
                'ready' => false,
                'message' => 'No reusable verified HTTP/3 component is installed for this platform. Normal server:start never downloads or compiles it.',
            ];
        }

        $lock = $this->acquireBuildLock();
        if (!\is_resource($lock)) {
            $message = 'Timed out waiting for the HTTP/3 dependency/build lock.';
            $this->recordFailure($message);
            return ['status' => 'disabled', 'ready' => false, 'message' => $message];
        }

        $installed = false;
        $installOutput = '';
        try {
            // A concurrent starter may have completed the bundle while this
            // process waited for the single control-plane lock.
            $reusable = $this->reusableActiveManifest();
            if ($reusable !== null) {
                NativeTransportLibrary::reset();
                $runtimeVerified = NativeTransportLibrary::hasVerifiedRuntimeEvidence($reusable);
                return [
                    'status' => 'ready',
                    'ready' => true,
                    'message' => $runtimeVerified
                        ? 'Reused the concurrently published runtime-verified native HTTP/3 transport.'
                        : 'Reused the concurrently published ABI-verified native HTTP/3 transport; the runtime self-test must run again.',
                    'manifest' => $reusable,
                ];
            }

            $probe = $this->probePrerequisites();
            if (!$probe['ready']) {
                $install = $this->installPrerequisites();
                $installed = $install['success'];
                $installOutput = $install['output'];
                $probe = $this->probePrerequisites();
            }
            if (!$probe['ready']) {
                $message = 'HTTP/3 prerequisites are unavailable: ' . \implode(', ', $probe['missing']);
                $this->recordFailure($message, $installOutput);
                return [
                    'status' => 'disabled',
                    'ready' => false,
                    'message' => $message,
                    'output' => $installOutput,
                ];
            }
            $manifest = $this->buildOrReuse($probe);
        } catch (\Throwable $exception) {
            $message = 'HTTP/3 native build failed: ' . $exception->getMessage();
            $this->recordFailure($message, $installOutput);
            return [
                'status' => 'disabled',
                'ready' => false,
                'message' => $message,
                'output' => $installOutput,
            ];
        } finally {
            @\flock($lock, \LOCK_UN);
            @\fclose($lock);
        }

        NativeTransportLibrary::reset();
        return [
            'status' => $installed ? 'installed' : 'ready',
            'ready' => true,
            'message' => 'Native HTTP/3 transport is compiled and ABI-verified.',
            'manifest' => $manifest,
            'output' => $installOutput,
        ];
    }

    /**
     * @return array{ready:bool,missing:list<string>,cc:?string,pkg_config:?string,versions:array<string,string>,environment:array<string,string>,dependency_manifest:array<string,mixed>}
     */
    public function probePrerequisites(): array
    {
        $missing = [];
        $dependencyManifest = \PHP_OS_FAMILY === 'Linux'
            ? $this->linuxInstaller()->readyManifest()
            : [];
        $ffiFailure = $this->ffiRuntimeFailure();
        if ($ffiFailure !== null) {
            $missing[] = \extension_loaded('FFI') && \class_exists(\FFI::class)
                ? 'ffi-runtime'
                : 'php-ffi';
        }

        $environment = $this->toolchainEnvironment();
        $cc = $this->findExecutable(['cc', 'clang', 'gcc'], $environment);
        $pkgConfig = $this->findExecutable(['pkg-config', 'pkgconf'], $environment);
        if ($cc === null) {
            $missing[] = 'c-compiler';
        }
        if ($pkgConfig === null) {
            $missing[] = 'pkg-config';
        }
        if (\PHP_OS_FAMILY === 'Linux'
            && ($this->findExecutable(['readelf'], $environment) === null
                || $this->findExecutable(['nm'], $environment) === null)
        ) {
            $missing[] = 'binutils';
        }

        $versions = [];
        if ($pkgConfig !== null) {
            $exists = $this->run([$pkgConfig, '--exists', ...self::PACKAGES], 30, null, $environment);
            if (!$exists['success']) {
                $missing[] = 'ngtcp2/nghttp3/openssl-pkg-config';
            } else {
                foreach (self::PACKAGES as $package) {
                    $version = $this->run([$pkgConfig, '--modversion', $package], 15, null, $environment);
                    $versions[$package] = $version['success'] ? \trim($version['output']) : '';
                    if ($versions[$package] === '') {
                        $missing[] = $package . '-version';
                    } elseif (isset(self::MIN_PACKAGE_VERSIONS[$package])
                        && \version_compare($versions[$package], self::MIN_PACKAGE_VERSIONS[$package], '<')
                    ) {
                        $missing[] = $package . '>=' . self::MIN_PACKAGE_VERSIONS[$package];
                    }
                }
            }
        }
        if (\PHP_OS_FAMILY === 'Linux'
            && ($dependencyManifest['linkage'] ?? '') !== 'pic-static'
        ) {
            $missing[] = 'wls-private-http3-dependencies';
        }

        return [
            'ready' => $missing === [],
            'missing' => \array_values(\array_unique($missing)),
            'cc' => $cc,
            'pkg_config' => $pkgConfig,
            'versions' => $versions,
            'environment' => $environment,
            'dependency_manifest' => $dependencyManifest,
        ];
    }

    private function ffiRuntimeFailure(): ?string
    {
        if (!\extension_loaded('FFI') || !\class_exists(\FFI::class)) {
            return 'The current PHP binary does not provide FFI.';
        }
        try {
            $probe = \FFI::cdef('int abs(int);');
            if ((int)$probe->abs(-1) !== 1) {
                return 'The current PHP FFI runtime probe returned an invalid result.';
            }
        } catch (\Throwable $exception) {
            return 'The current PHP FFI runtime is unavailable: ' . $exception->getMessage();
        }
        return null;
    }

    /**
     * @return array{success:bool,output:string}
     */
    private function installPrerequisites(): array
    {
        if (\PHP_OS_FAMILY === 'Darwin') {
            $brew = $this->findExecutable(['brew']);
            if ($brew === null) {
                return ['success' => false, 'output' => 'Homebrew is not installed.'];
            }
            return $this->run([
                $brew,
                'install',
                'openssl@3',
                'ngtcp2',
                'nghttp3',
                'pkgconf',
                'curl',
            ], self::COMMAND_TIMEOUT);
        }

        if (!\extension_loaded('FFI') || !\class_exists(\FFI::class)) {
            return [
                'success' => false,
                'output' => 'The current PHP binary has no FFI extension; a native extension cannot be injected into an already running PHP executable.',
            ];
        }

        $prefix = [];
        if (\function_exists('posix_geteuid') && \posix_geteuid() !== 0) {
            $sudo = $this->findExecutable(['sudo']);
            if ($sudo === null) {
                return ['success' => false, 'output' => 'Root or non-interactive sudo is required.'];
            }
            $sudoProbe = $this->run([$sudo, '-n', 'true'], 10);
            if (!$sudoProbe['success']) {
                return ['success' => false, 'output' => 'sudo -n is unavailable; refusing an interactive startup prompt.'];
            }
            $prefix = [$sudo, '-n'];
        }

        $apt = $this->findExecutable(['apt-get']);
        if ($apt !== null) {
            $update = $this->run([...$prefix, $apt, 'update'], self::COMMAND_TIMEOUT);
            if (!$update['success']) {
                return $update;
            }
            $tools = $this->run([
                ...$prefix,
                $apt,
                'install',
                '-y',
                '--no-install-recommends',
                'build-essential',
                'pkg-config',
                'perl',
                'ca-certificates',
                'curl',
                'tar',
                'xz-utils',
                'binutils',
            ], self::COMMAND_TIMEOUT);
            if (!$tools['success']) {
                return $tools;
            }
            return $this->installPinnedLinuxDependencies($tools['output']);
        }

        $dnf = $this->findExecutable(['dnf']);
        if ($dnf !== null) {
            $tools = $this->run([
                ...$prefix,
                $dnf,
                'install',
                '-y',
                'gcc',
                'make',
                'pkgconf-pkg-config',
                'perl-core',
                'ca-certificates',
                'curl',
                'tar',
                'xz',
                'binutils',
            ], self::COMMAND_TIMEOUT);
            if (!$tools['success']) {
                return $tools;
            }
            return $this->installPinnedLinuxDependencies($tools['output']);
        }

        $apk = $this->findExecutable(['apk']);
        if ($apk !== null) {
            $tools = $this->run([
                ...$prefix,
                $apk,
                'add',
                '--no-cache',
                'build-base',
                'pkgconf',
                'perl',
                'ca-certificates',
                'curl',
                'tar',
                'xz',
                'binutils',
            ], self::COMMAND_TIMEOUT);
            if (!$tools['success']) {
                return $tools;
            }
            return $this->installPinnedLinuxDependencies($tools['output']);
        }

        return ['success' => false, 'output' => 'No supported native package manager was found.'];
    }

    /** @return array{success:bool,output:string} */
    private function installPinnedLinuxDependencies(string $toolOutput): array
    {
        $probe = $this->probePrerequisites();
        if ($probe['ready']) {
            return ['success' => true, 'output' => $toolOutput];
        }
        $install = $this->linuxInstaller()->install();
        return [
            'success' => $install['success'],
            'output' => $toolOutput . "\n" . $install['output'],
        ];
    }

    /** @return array<string,string> */
    private function toolchainEnvironment(): array
    {
        return \PHP_OS_FAMILY === 'Linux'
            ? $this->linuxInstaller()->environment()
            : [];
    }

    private function runner(): NativeBuildProcessRunner
    {
        return $this->processRunner ??= new NativeBuildProcessRunner();
    }

    private function linuxInstaller(): LinuxNativeDependencyInstaller
    {
        return $this->linuxDependencyInstaller ??= new LinuxNativeDependencyInstaller(
            $this->nativeRoot(),
            $this->runner()
        );
    }

    /**
     * @param array{ready:bool,missing:list<string>,cc:?string,pkg_config:?string,versions:array<string,string>,environment:array<string,string>,dependency_manifest:array<string,mixed>} $probe
     * @return array<string,mixed>
     */
    private function buildOrReuse(array $probe): array
    {
        $sourceFile = __DIR__ . '/Native/wls_transport.c';
        $headerFile = __DIR__ . '/Native/wls_transport_abi.h';
        $versionScript = __DIR__ . '/Native/wls_transport.map';
        $linuxRuntimeFile = __DIR__ . '/Native/wls_linux_reuseport_runtime.c';
        $linuxBpfSourceFile = __DIR__ . '/Native/wls_linux_reuseport_bpf.c';
        $linuxBpfCodeFile = __DIR__ . '/Native/wls_linux_reuseport_bpf_code.h';
        $linuxRuntimeHeader = __DIR__ . '/Native/wls_linux_reuseport_runtime.h';
        if (!\is_file($sourceFile) || !\is_file($headerFile)
            || (\PHP_OS_FAMILY === 'Linux' && (
                !\is_file($versionScript)
                || !\is_file($linuxRuntimeFile) || !\is_file($linuxRuntimeHeader)
                || !\is_file($linuxBpfSourceFile) || !\is_file($linuxBpfCodeFile)
            ))
        ) {
            throw new \RuntimeException('native source files are missing');
        }

        $sourceDigest = $this->sourceDigest();
        $dependencyManifest = $probe['dependency_manifest'];
        if (\PHP_OS_FAMILY === 'Linux'
            && ($dependencyManifest['linkage'] ?? '') !== 'pic-static'
        ) {
            throw new \RuntimeException('Linux HTTP/3 requires the private PIC-static dependency bundle');
        }
        $pkgConfig = (string)$probe['pkg_config'];
        $cc = (string)$probe['cc'];
        $environment = $probe['environment'];
        $flags = $this->run(
            [$pkgConfig, '--cflags', ...self::PACKAGES],
            30,
            null,
            $environment
        );
        $staticDependencies = ($dependencyManifest['linkage'] ?? '') === 'pic-static';
        $libsCommand = [$pkgConfig];
        if ($staticDependencies) {
            $libsCommand[] = '--static';
        }
        $libsCommand[] = '--libs';
        $libsCommand = [...$libsCommand, ...self::PACKAGES];
        $libs = $this->run($libsCommand, 30, null, $environment);
        if (!$flags['success'] || !$libs['success']) {
            throw new \RuntimeException('pkg-config could not materialize compiler flags');
        }

        $extension = \PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
        $tmp = $this->nativeRoot() . \DIRECTORY_SEPARATOR . '.libwls_transport.' . $extension
            . '.tmp.' . \getmypid() . '.' . \bin2hex(\random_bytes(4));
        $command = [
            $cc,
            '-std=c11',
            '-O3',
            '-DNDEBUG',
            '-fPIC',
            '-fvisibility=hidden',
            '-Wall',
            '-Wextra',
            '-Werror',
            \PHP_OS_FAMILY === 'Darwin' ? '-dynamiclib' : '-shared',
            $sourceFile,
            ...(\PHP_OS_FAMILY === 'Linux' ? [$linuxRuntimeFile] : []),
            '-I' . \dirname($headerFile),
            '-o',
            $tmp,
            ...$this->splitFlags($flags['output']),
        ];
        if (\PHP_OS_FAMILY === 'Darwin') {
            $command = [...$command, ...$this->splitFlags($libs['output']), '-pthread'];
            $command[] = '-Wl,-headerpad_max_install_names';
            $command[] = '-Wl,-install_name,@rpath/libwls_transport.dylib';
            foreach ($this->libraryDirectories($libs['output']) as $libDirectory) {
                $command[] = '-Wl,-rpath,' . $libDirectory;
            }
        } else {
            if ($staticDependencies) {
                $command[] = '-Wl,--start-group';
            }
            $command = [...$command, ...$this->splitFlags($libs['output'])];
            if ($staticDependencies) {
                $command[] = '-Wl,--end-group';
            }
            $command = [...$command,
                '-pthread',
                '-Wl,--no-undefined',
                '-Wl,--exclude-libs,ALL',
                '-Wl,-Bsymbolic-functions',
                '-Wl,--version-script=' . $versionScript,
                '-Wl,-z,relro,-z,now',
            ];
        }

        $build = $this->run($command, self::COMMAND_TIMEOUT, null, $environment);
        if (!$build['success'] || !\is_file($tmp)) {
            @\unlink($tmp);
            throw new \RuntimeException(\trim($build['output']) ?: 'compiler returned a non-zero exit code');
        }
        @\chmod($tmp, 0755);

        if (\PHP_OS_FAMILY === 'Darwin') {
            return $this->buildDarwinPrivateArtifact(
                $tmp,
                $sourceDigest,
                $probe['versions'],
            );
        }

        if (\PHP_OS_FAMILY === 'Linux') {
            $binaryInspection = $this->inspectLinuxBinary($tmp, $staticDependencies);
            if (!$binaryInspection['ready']) {
                @\unlink($tmp);
                throw new \RuntimeException($binaryInspection['reason']);
            }
        }

        $inspection = $this->inspectLibrary($tmp);
        if (!$inspection['ready']) {
            @\unlink($tmp);
            throw new \RuntimeException((string)$inspection['reason']);
        }

        $dependencyFingerprint = (string)($dependencyManifest['fingerprint'] ?? '');
        $dependencyLinkage = (string)($dependencyManifest['linkage'] ?? '');
        $dependencyPrefix = (string)($dependencyManifest['prefix'] ?? '');
        if (\preg_match('/^[a-zA-Z0-9_.:-]{1,128}$/D', $dependencyFingerprint) !== 1
            || $dependencyFingerprint === 'system'
        ) {
            @\unlink($tmp);
            throw new \RuntimeException('native dependency identity is incomplete');
        }
        $fingerprintPayload = [
            'abi' => self::ABI_VERSION,
            'source' => $sourceDigest,
            'os' => \PHP_OS_FAMILY,
            'arch' => (string)\php_uname('m'),
            'versions' => $probe['versions'],
            'dependencies' => $dependencyFingerprint,
        ];
        $fingerprint = \substr(\hash('sha256', \json_encode(
            $fingerprintPayload,
            \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR
        )), 0, 32);
        $directory = $this->nativeRoot() . \DIRECTORY_SEPARATOR . $fingerprint;
        $active = $this->reusableActiveManifest();
        if ($active !== null && ($active['fingerprint'] ?? '') === $fingerprint) {
            @\unlink($tmp);
            return $active;
        }
        if (!\is_dir($directory) && !@\mkdir($directory, 0700, true) && !\is_dir($directory)) {
            @\unlink($tmp);
            throw new \RuntimeException('unable to create native cache directory');
        }
        @\chmod($directory, 0700);

        $publishSource = $tmp;
        $librarySha256 = (string)\hash_file('sha256', $publishSource);
        if (\preg_match('/^[a-f0-9]{64}$/D', $librarySha256) !== 1) {
            @\unlink($publishSource);
            throw new \RuntimeException('unable to calculate the native library artifact digest');
        }
        $artifactDirectory = $directory . \DIRECTORY_SEPARATOR . $librarySha256;
        $library = $artifactDirectory . \DIRECTORY_SEPARATOR . 'libwls_transport.' . $extension;
        $publicationLock = $this->acquirePublicationLock();
        if (!\is_resource($publicationLock)) {
            @\unlink($publishSource);
            throw new \RuntimeException('timed out waiting for the native HTTP/3 publication lock');
        }
        try {
            if (!\is_dir($artifactDirectory)
                && !@\mkdir($artifactDirectory, 0700, true)
                && !\is_dir($artifactDirectory)
            ) {
                throw new \RuntimeException('unable to create the content-addressed native artifact directory');
            }
            @\chmod($artifactDirectory, 0700);
            if (\is_file($library)) {
                $existingDigest = (string)\hash_file('sha256', $library);
                if (!\hash_equals($librarySha256, $existingDigest)) {
                    throw new \RuntimeException('existing content-addressed native artifact has a conflicting digest');
                }
                @\unlink($publishSource);
            } elseif (!@\rename($publishSource, $library)) {
                throw new \RuntimeException('unable to atomically publish native library');
            }
            @\chmod($library, 0555);

            $manifest = [
                'schema' => 1,
                'ready' => true,
                'runtime_verified' => false,
                'runtime_reason' => 'real QUIC loopback self-test has not run',
                'runtime_evidence' => [],
                'abi_version' => self::ABI_VERSION,
                'build_id' => $inspection['build_id'],
                'versions' => $inspection['versions'],
                'source_sha256' => $sourceDigest,
                'library_sha256' => $librarySha256,
                'library' => $library,
                'fingerprint' => $fingerprint,
                'platform' => \PHP_OS_FAMILY,
                'architecture' => (string)\php_uname('m'),
                'dependency_fingerprint' => $dependencyFingerprint,
                'dependency_linkage' => $dependencyLinkage,
                'dependency_prefix' => $dependencyPrefix,
                'dynamic_dependencies' => [],
                'system_dynamic_dependencies' => [],
                'os_runtime_identity' => [],
                'http3_curl' => (string)($dependencyManifest['http3_curl'] ?? ''),
                'http3_curl_sha256' => (string)($dependencyManifest['http3_curl_sha256'] ?? ''),
                'built_at' => \date(\DATE_ATOM),
            ];
            $artifactManifestPath = NativeTransportLibrary::manifestPathForFingerprint(
                $fingerprint,
                $librarySha256,
            );
            $existingManifest = $this->readJson($artifactManifestPath);
            if ($existingManifest !== []) {
                foreach ([
                    'fingerprint',
                    'library_sha256',
                    'source_sha256',
                    'build_id',
                    'dependency_fingerprint',
                    'library',
                    'platform',
                    'architecture',
                    'abi_version',
                ] as $field) {
                    if (!\hash_equals((string)($manifest[$field] ?? ''), (string)($existingManifest[$field] ?? ''))) {
                        throw new \RuntimeException('existing content-addressed native artifact manifest conflicts at ' . $field);
                    }
                }
                $manifest = $existingManifest;
            } else {
                $this->atomicJson($artifactManifestPath, $manifest);
            }
            return $manifest;
        } finally {
            if (\is_file($publishSource)) {
                @\unlink($publishSource);
            }
            @\flock($publicationLock, \LOCK_UN);
            @\fclose($publicationLock);
        }
    }

    /** @return array<string,mixed>|null */
    private function reusableActiveManifest(): ?array
    {
        $activePath = $this->activeManifestPath();
        $active = $this->readJson($activePath);
        $publishPlatformPointer = false;
        if ($active === []) {
            $legacy = $this->readJson(NativeTransportLibrary::legacyActiveManifestPath());
            if (($legacy['platform'] ?? '') === \PHP_OS_FAMILY
                && ($legacy['architecture'] ?? '') === (string)\php_uname('m')
            ) {
                $active = $legacy;
                $publishPlatformPointer = true;
            }
        }
        $manifestChanged = false;
        $library = (string)($active['library'] ?? '');
        $fingerprint = \strtolower(\trim((string)($active['fingerprint'] ?? '')));
        $librarySha256 = \strtolower(\trim((string)($active['library_sha256'] ?? '')));
        $expectedArtifactDirectory = $this->nativeRoot() . \DIRECTORY_SEPARATOR
            . $fingerprint . \DIRECTORY_SEPARATOR . $librarySha256;
        if (!($active['ready'] ?? false)
            || (int)($active['abi_version'] ?? 0) !== self::ABI_VERSION
            || ($active['source_sha256'] ?? '') !== $this->sourceDigest()
            || ($active['platform'] ?? '') !== \PHP_OS_FAMILY
            || ($active['architecture'] ?? '') !== (string)\php_uname('m')
            || \preg_match('/^[a-f0-9]{32}$/D', $fingerprint) !== 1
            || \preg_match('/^[a-f0-9]{64}$/D', $librarySha256) !== 1
            || \realpath(\dirname($library)) !== \realpath($expectedArtifactDirectory)
            || !$this->verifiedLibraryFile($library, (string)($active['library_sha256'] ?? ''))
        ) {
            return null;
        }
        if (\PHP_OS_FAMILY === 'Linux') {
            $dependencies = $this->linuxInstaller()->readyManifest();
            $dependencyCurl = (string)($dependencies['http3_curl'] ?? '');
            $dependencyCurlSha256 = (string)($dependencies['http3_curl_sha256'] ?? '');
            if (($active['dependency_linkage'] ?? '') !== 'pic-static'
                || $dependencies === []
                || !\hash_equals(
                    (string)($active['dependency_fingerprint'] ?? ''),
                    (string)($dependencies['fingerprint'] ?? '')
                )
                || ($active['dependency_prefix'] ?? '') !== ($dependencies['prefix'] ?? '')
                || ($active['http3_curl'] ?? '') !== $dependencyCurl
                || \preg_match('/^[a-f0-9]{64}$/D', $dependencyCurlSha256) !== 1
            ) {
                return null;
            }
            $activeCurlSha256 = (string)($active['http3_curl_sha256'] ?? '');
            if ($activeCurlSha256 === '') {
                return null;
            } elseif (!\hash_equals($dependencyCurlSha256, $activeCurlSha256)) {
                return null;
            }
        } elseif (\PHP_OS_FAMILY === 'Darwin'
            && !NativeTransportLibrary::hasVerifiedDarwinDependencies($active)
        ) {
            return null;
        }
        if ((bool)($active['runtime_verified'] ?? false)
            && !NativeTransportLibrary::hasVerifiedRuntimeEvidence($active)
        ) {
            // Keep the last-known-good publication intact. Production readers
            // will reject stale evidence until a successful self-test atomically
            // replaces it; failed verification only updates last-error.json.
        }
        if (!(bool)($active['runtime_verified'] ?? false)
            && \PHP_OS_FAMILY === 'Linux'
            && (!\is_file((string)($active['http3_curl'] ?? ''))
                || !\is_executable((string)($active['http3_curl'] ?? '')))
        ) {
            return null;
        }
        if (\PHP_OS_FAMILY === 'Darwin') {
            $staticBuildId = $this->darwinStaticBuildId($library);
            if ($staticBuildId === ''
                || !\hash_equals((string)($active['build_id'] ?? ''), $staticBuildId)
            ) {
                return null;
            }
        } else {
            $inspection = $this->inspectLibrary($library);
            if (!$inspection['ready']
                || !\hash_equals((string)($active['build_id'] ?? ''), $inspection['build_id'])
                || ($active['versions'] ?? []) !== $inspection['versions']
            ) {
                return null;
            }
        }
        // A legacy pointer is only eligible for direct migration when its
        // current verifier/runtime evidence is still strong. An ABI-valid but
        // unverified legacy artifact may be returned to the explicit self-test
        // as a candidate, but must not become the platform active publication
        // until markRuntimeVerified() succeeds.
        $publishPlatformPointer = $publishPlatformPointer
            && NativeTransportLibrary::hasVerifiedRuntimeEvidence($active);
        if ($manifestChanged || $publishPlatformPointer) {
            $lock = $this->acquirePublicationLock();
            if (!\is_resource($lock)) {
                return null;
            }
            try {
                $current = $this->readJson($activePath);
                if ($current !== []
                    && (($current['fingerprint'] ?? '') !== ($active['fingerprint'] ?? '')
                        || ($current['library_sha256'] ?? '') !== ($active['library_sha256'] ?? ''))
                ) {
                    return null;
                }
                $this->atomicJson($activePath, $active);
            } finally {
                @\flock($lock, \LOCK_UN);
                @\fclose($lock);
            }
        }
        return $active;
    }

    private function sourceDigest(): string
    {
        return NativeTransportLibrary::nativeSourceSha256();
    }

    /**
     * Materialize the Darwin dependency closure without loading any temporary
     * Mach-O image into the build PHP process. The returned manifest is only a
     * self-test candidate; markRuntimeVerified() remains the sole active
     * publication path.
     *
     * @param array<string,string> $packageVersions
     * @return array<string,mixed>
     */
    private function buildDarwinPrivateArtifact(
        string $temporaryLibrary,
        string $sourceDigest,
        array $packageVersions,
    ): array {
        $staging = $this->nativeRoot() . \DIRECTORY_SEPARATOR . '.darwin-private.'
            . \getmypid() . '.' . \bin2hex(\random_bytes(8));
        $published = false;
        if (!@\mkdir($staging, 0700, true) && !\is_dir($staging)) {
            @\unlink($temporaryLibrary);
            throw new \RuntimeException('unable to create the private Darwin HTTP/3 staging directory');
        }
        @\chmod($staging, 0700);

        try {
            $graph = $this->discoverDarwinDependencyGraph($temporaryLibrary);
            $rootSource = (string)$graph['root'];
            $privateNames = $this->darwinPrivateImageNames($graph['images'], $rootSource);
            $dependenciesWork = $staging . \DIRECTORY_SEPARATOR . 'dependencies.work';
            if (!@\mkdir($dependenciesWork, 0700, true) && !\is_dir($dependenciesWork)) {
                throw new \RuntimeException('unable to create the private Darwin dependency staging directory');
            }
            @\chmod($dependenciesWork, 0700);

            foreach ($privateNames as $source => $privateName) {
                $destination = $dependenciesWork . \DIRECTORY_SEPARATOR . $privateName;
                $this->copyStableDarwinImage($graph['images'][$source], $destination);
            }

            $dependencyImages = [];
            foreach ($privateNames as $source => $privateName) {
                $changes = [];
                $expectedLoads = [];
                foreach ($graph['private_edges'] as $edge) {
                    if (($edge['loader_source'] ?? '') !== $source) {
                        continue;
                    }
                    $targetSource = (string)($edge['target_source'] ?? '');
                    $targetName = (string)($privateNames[$targetSource] ?? '');
                    if ($targetName === '') {
                        throw new \RuntimeException('Darwin dependency edge targets an unsealed image');
                    }
                    $rewritten = '@loader_path/' . $targetName;
                    $sourceInstallName = (string)$edge['source_install_name'];
                    if (isset($changes[$sourceInstallName])
                        && !\hash_equals($changes[$sourceInstallName], $rewritten)
                    ) {
                        throw new \RuntimeException('Darwin dependency install name maps to multiple private images');
                    }
                    $changes[$sourceInstallName] = $rewritten;
                    $expectedLoads[] = [
                        'command' => (string)$edge['load_command'],
                        'install_name' => $rewritten,
                    ];
                }
                $expectedSystemLoads = $this->darwinExpectedSystemLoads($graph['system_edges'], $source);
                $destination = $dependenciesWork . \DIRECTORY_SEPARATOR . $privateName;
                $this->rewriteAndSignDarwinImage(
                    $destination,
                    '@loader_path/' . $privateName,
                    $changes,
                    (array)$graph['images'][$source]['raw_rpaths'],
                );
                $verified = $this->verifyRewrittenDarwinImage(
                    $destination,
                    '@loader_path/' . $privateName,
                    $expectedLoads,
                    $expectedSystemLoads,
                );
                $dependencyImages[] = [
                    'role' => 'dependency',
                    'relative_path' => $privateName,
                    'sha256' => $verified['sha256'],
                    'source_sha256' => (string)$graph['images'][$source]['sha256'],
                    'size' => $verified['size'],
                    'mode' => $verified['mode'],
                    'owner_uid' => $verified['owner_uid'],
                    'architectures' => $verified['architectures'],
                    'install_id' => $verified['install_id'],
                    'source_install_id' => (string)$graph['images'][$source]['install_id'],
                    'cdhash' => $verified['cdhash'],
                    'rpaths' => [],
                ];
            }

            $fingerprintEdges = $this->darwinPrivateEdgeDescriptors(
                $graph['private_edges'],
                $privateNames,
                $rootSource,
                null,
            );
            $fingerprintSystemEdges = $this->darwinSystemEdgeDescriptors(
                $graph['system_edges'],
                $privateNames,
                $rootSource,
            );
            $dependencyBundleSha256 = NativeTransportLibrary::darwinPrivateBundleFingerprint(
                $dependencyImages,
                $fingerprintEdges,
                $fingerprintSystemEdges,
            );
            if (\preg_match('/^[a-f0-9]{64}$/D', $dependencyBundleSha256) !== 1) {
                throw new \RuntimeException('unable to calculate the private Darwin dependency bundle identity');
            }

            $dependenciesDirectory = $staging . \DIRECTORY_SEPARATOR . 'deps';
            $sealedDependencies = $dependenciesDirectory . \DIRECTORY_SEPARATOR . $dependencyBundleSha256;
            if (!@\mkdir($dependenciesDirectory, 0700, true)
                || !@\rename($dependenciesWork, $sealedDependencies)
            ) {
                throw new \RuntimeException('unable to seal the private Darwin dependency directory');
            }
            @\chmod($dependenciesDirectory, 0700);
            @\chmod($sealedDependencies, 0700);

            $fingerprintPayload = [
                'abi' => self::ABI_VERSION,
                'source' => $sourceDigest,
                'os' => \PHP_OS_FAMILY,
                'arch' => (string)\php_uname('m'),
                'versions' => $packageVersions,
                'dependencies' => $dependencyBundleSha256,
            ];
            $fingerprint = \substr(\hash('sha256', \json_encode(
                $fingerprintPayload,
                \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
            )), 0, 32);
            $rootName = 'libwls_transport-' . $fingerprint . '.dylib';
            $stagedLibrary = $staging . \DIRECTORY_SEPARATOR . $rootName;
            $this->copyStableDarwinImage($graph['images'][$rootSource], $stagedLibrary);

            $rootChanges = [];
            $expectedRootLoads = [];
            foreach ($graph['private_edges'] as $edge) {
                if (($edge['loader_source'] ?? '') !== $rootSource) {
                    continue;
                }
                $targetName = (string)($privateNames[(string)$edge['target_source']] ?? '');
                $rewritten = '@loader_path/deps/' . $dependencyBundleSha256 . '/' . $targetName;
                $sourceInstallName = (string)$edge['source_install_name'];
                if (isset($rootChanges[$sourceInstallName])
                    && !\hash_equals($rootChanges[$sourceInstallName], $rewritten)
                ) {
                    throw new \RuntimeException('Darwin root install name maps to multiple private images');
                }
                $rootChanges[$sourceInstallName] = $rewritten;
                $expectedRootLoads[] = [
                    'command' => (string)$edge['load_command'],
                    'install_name' => $rewritten,
                ];
            }
            $expectedRootSystemLoads = $this->darwinExpectedSystemLoads(
                $graph['system_edges'],
                $rootSource,
            );
            $rootInstallId = '@loader_path/' . $rootName;
            $this->rewriteAndSignDarwinImage(
                $stagedLibrary,
                $rootInstallId,
                $rootChanges,
                (array)$graph['images'][$rootSource]['raw_rpaths'],
            );
            $rootVerification = $this->verifyRewrittenDarwinImage(
                $stagedLibrary,
                $rootInstallId,
                $expectedRootLoads,
                $expectedRootSystemLoads,
            );
            $buildId = $this->darwinStaticBuildId($stagedLibrary);
            if ($buildId === '') {
                throw new \RuntimeException('unable to read the native Darwin build identity without loading it');
            }

            $librarySha256 = (string)$rootVerification['sha256'];
            $fingerprintDirectory = $this->nativeRoot() . \DIRECTORY_SEPARATOR . $fingerprint;
            $artifactDirectory = $fingerprintDirectory . \DIRECTORY_SEPARATOR . $librarySha256;
            $library = $artifactDirectory . \DIRECTORY_SEPARATOR . $rootName;
            $privateImages = $this->finalizeDarwinImages(
                $dependencyImages,
                $rootVerification,
                $graph['images'][$rootSource],
                $artifactDirectory,
                $rootName,
                $dependencyBundleSha256,
            );
            $privateEdges = $this->finalizeDarwinEdges(
                $this->darwinPrivateEdgeDescriptors(
                    $graph['private_edges'],
                    $privateNames,
                    $rootSource,
                    $dependencyBundleSha256,
                ),
                $privateImages,
                $artifactDirectory,
            );
            $systemEdges = $this->darwinSystemEdgeDescriptors(
                $graph['system_edges'],
                $privateNames,
                $rootSource,
                $dependencyBundleSha256,
            );
            $systemDependencies = \array_values(\array_unique(\array_map(
                static fn(array $edge): string => (string)$edge['install_name'],
                $systemEdges,
            )));
            \sort($systemDependencies, \SORT_STRING);
            $manifest = [
                'schema' => 1,
                'ready' => true,
                'runtime_verified' => false,
                'runtime_reason' => 'real QUIC loopback self-test has not run',
                'runtime_evidence' => [],
                'abi_version' => self::ABI_VERSION,
                'build_id' => $buildId,
                'versions' => $packageVersions,
                'source_sha256' => $sourceDigest,
                'library_sha256' => $librarySha256,
                'library' => $library,
                'fingerprint' => $fingerprint,
                'platform' => \PHP_OS_FAMILY,
                'architecture' => (string)\php_uname('m'),
                'dependency_fingerprint' => $dependencyBundleSha256,
                'dependency_linkage' => 'private-dynamic',
                'dependency_prefix' => $artifactDirectory . \DIRECTORY_SEPARATOR . 'deps'
                    . \DIRECTORY_SEPARATOR . $dependencyBundleSha256,
                'dependency_bundle_sha256' => $dependencyBundleSha256,
                'dynamic_dependencies' => $privateEdges,
                'system_dynamic_dependencies' => $systemDependencies,
                'darwin_private_images' => $privateImages,
                'darwin_private_edges' => $privateEdges,
                'darwin_system_edges' => $systemEdges,
                'os_runtime_identity' => NativeTransportLibrary::darwinRuntimeIdentity(),
                'http3_curl' => '',
                'http3_curl_sha256' => '',
                'built_at' => \date(\DATE_ATOM),
            ];
            // Seal the complete candidate before its directory becomes visible.
            $this->atomicJson($staging . \DIRECTORY_SEPARATOR . 'manifest.json', $manifest);
            $publicationLock = $this->acquirePublicationLock();
            if (!\is_resource($publicationLock)) {
                throw new \RuntimeException('timed out waiting for the native HTTP/3 publication lock');
            }
            try {
                if (!\is_dir($fingerprintDirectory)
                    && !@\mkdir($fingerprintDirectory, 0700, true)
                    && !\is_dir($fingerprintDirectory)
                ) {
                    throw new \RuntimeException('unable to create the Darwin artifact fingerprint directory');
                }
                @\chmod($fingerprintDirectory, 0700);
                if (\file_exists($artifactDirectory)) {
                    $existing = $this->readJson($artifactDirectory . \DIRECTORY_SEPARATOR . 'manifest.json');
                    if (($existing['fingerprint'] ?? '') !== $fingerprint
                        || ($existing['library_sha256'] ?? '') !== $librarySha256
                        || ($existing['dependency_bundle_sha256'] ?? '') !== $dependencyBundleSha256
                    ) {
                        throw new \RuntimeException('existing Darwin content-addressed artifact conflicts with the candidate');
                    }
                    return $existing;
                }
                if (!@\rename($staging, $artifactDirectory)) {
                    throw new \RuntimeException('unable to atomically publish the private Darwin artifact tree');
                }
                $published = true;
                @\chmod($artifactDirectory, 0700);
                return $manifest;
            } finally {
                @\flock($publicationLock, \LOCK_UN);
                @\fclose($publicationLock);
            }
        } finally {
            @\unlink($temporaryLibrary);
            if (!$published && \is_dir($staging)) {
                $this->removeOwnedDarwinStagingTree($staging);
            }
        }
    }

    /** @return array{ready:bool,reason:string} */
    private function inspectLinuxBinary(string $library, bool $staticDependencies): array
    {
        $readelf = $this->findExecutable(['readelf']);
        $nm = $this->findExecutable(['nm']);
        if ($readelf === null || $nm === null) {
            return ['ready' => false, 'reason' => 'binutils is required to verify the Linux native library'];
        }

        $dynamic = $this->run([$readelf, '-d', $library], 30);
        if (!$dynamic['success']) {
            return ['ready' => false, 'reason' => 'readelf could not inspect the Linux native library'];
        }
        if ($staticDependencies
            && \preg_match('/NEEDED.*\[(?:libssl|libcrypto|libngtcp2|libnghttp3)[^]]*]/i', $dynamic['output']) === 1
        ) {
            return ['ready' => false, 'reason' => 'private HTTP/3 dependencies escaped PIC-static linkage'];
        }

        $symbols = $this->run([$nm, '-D', '--defined-only', $library], 30);
        if (!$symbols['success']) {
            return ['ready' => false, 'reason' => 'nm could not inspect the Linux native ABI exports'];
        }
        $expected = [
            'wls_transport_abi_version',
            'wls_transport_build_id',
            'wls_transport_last_error',
            'wls_transport_get_versions',
            'wls_tls_context_new',
            'wls_tls_context_retain',
            'wls_tls_context_release',
            'wls_tls_context_capabilities',
            'wls_tls_context_set_ticket_ring',
            'wls_tls_context_get_stats',
            'wls_h3_server_new',
            'wls_h3_server_bind',
            'wls_h3_server_bind_linux_route',
            'wls_h3_server_activate_linux_route',
            'wls_h3_server_get_linux_route_status',
            'wls_h3_server_fd',
            'wls_h3_server_dup_fd',
            'wls_h3_server_wait_fd',
            'wls_h3_server_dup_wait_fd',
            'wls_h3_server_bound_port',
            'wls_h3_server_bind_datagram_worker',
            'wls_h3_server_begin_drain',
            'wls_h3_server_poll',
            'wls_h3_server_next_request',
            'wls_h3_server_respond',
            'wls_h3_server_close_request',
            'wls_h3_server_get_stats',
            'wls_h3_server_destroy',
            'wls_h3_datagram_router_new',
            'wls_h3_datagram_router_bind',
            'wls_h3_datagram_router_publish_workers',
            'wls_h3_datagram_router_bound_port',
            'wls_h3_datagram_router_dup_fd',
            'wls_h3_datagram_router_wait_fd',
            'wls_h3_datagram_router_poll',
            'wls_h3_datagram_router_get_stats',
            'wls_h3_datagram_router_destroy',
        ];
        $actual = [];
        foreach (\preg_split('/\r?\n/', \trim($symbols['output'])) ?: [] as $line) {
            $parts = \preg_split('/\s+/', \trim($line)) ?: [];
            $symbol = (string)\end($parts);
            $symbol = \explode('@', $symbol, 2)[0];
            if ($symbol !== '' && $symbol !== 'WLS_TRANSPORT_2.9') {
                $actual[] = $symbol;
            }
        }
        $actual = \array_values(\array_unique($actual));
        \sort($actual);
        \sort($expected);
        if ($actual !== $expected) {
            return ['ready' => false, 'reason' => 'Linux native ABI export set does not match wls_transport_abi.h'];
        }
        return ['ready' => true, 'reason' => 'Linux dependency isolation and ABI exports verified'];
    }

    /** @return array{root:string,images:array<string,array<string,mixed>>,private_edges:list<array<string,string>>,system_edges:list<array<string,string>>} */
    private function discoverDarwinDependencyGraph(string $library): array
    {
        if (\PHP_OS_FAMILY !== 'Darwin') {
            throw new \RuntimeException('Darwin dependency discovery was requested on another platform');
        }
        foreach (self::DARWIN_INJECTION_ENVIRONMENT as $variable) {
            $value = \getenv($variable);
            if (\is_string($value) && \trim($value) !== '') {
                throw new \RuntimeException('refusing Darwin dependency discovery with loader injection environment');
            }
        }
        $rootLibrary = \realpath($library);
        $phpBinary = \realpath(\PHP_BINARY);
        if (!\is_string($rootLibrary) || !\is_string($phpBinary)) {
            throw new \RuntimeException('canonical Darwin library and PHP executable paths are required');
        }
        $phpInfo = $this->darwinMachOInfo($phpBinary, false);
        $queue = [[
            'binary' => $rootLibrary,
            'inherited_rpaths' => $phpInfo['expanded_rpaths'],
        ]];
        $seen = [];
        $images = [];
        $privateEdges = [];
        $systemEdges = [];
        $resolutions = [];
        while ($queue !== []) {
            $state = \array_shift($queue);
            $binary = (string)($state['binary'] ?? '');
            $inheritedRpaths = \is_array($state['inherited_rpaths'] ?? null)
                ? \array_values($state['inherited_rpaths'])
                : [];
            $stateKey = $binary . "\0" . \implode("\0", $inheritedRpaths);
            if (isset($seen[$stateKey])) {
                continue;
            }
            if (\count($seen) >= self::MAX_DARWIN_IMAGES) {
                throw new \RuntimeException('Darwin dependency closure exceeds the bounded image limit');
            }
            $seen[$stateKey] = true;
            $info = $this->darwinMachOInfo($binary, true);
            $identity = $this->darwinSourceImageIdentity($binary, $info);
            if (isset($images[$binary])) {
                foreach (['dev', 'ino', 'mode', 'uid', 'size', 'mtime', 'ctime', 'sha256'] as $field) {
                    if ((string)($images[$binary][$field] ?? '') !== (string)($identity[$field] ?? '')) {
                        throw new \RuntimeException('Darwin dependency image changed during graph discovery');
                    }
                }
            } else {
                $images[$binary] = $identity;
            }
            $rpaths = \array_values(\array_unique([
                ...$info['expanded_rpaths'],
                ...$inheritedRpaths,
            ]));
            foreach ($info['loads'] as $load) {
                if (\count($privateEdges) + \count($systemEdges) >= self::MAX_DARWIN_EDGES) {
                    throw new \RuntimeException('Darwin dependency closure exceeds the bounded edge limit');
                }
                $installName = (string)$load['install_name'];
                $command = (string)$load['command'];
                if (NativeTransportLibrary::isDarwinSystemDependency($installName)) {
                    $key = $binary . "\0" . $command . "\0" . $installName;
                    $systemEdges[$key] = [
                        'loader_source' => $binary,
                        'load_command' => $command,
                        'install_name' => $installName,
                    ];
                    continue;
                }
                $resolutionPath = $this->resolveDarwinDependency($installName, $binary, $rpaths);
                $canonical = \is_string($resolutionPath) ? \realpath($resolutionPath) : false;
                if (!\is_string($canonical)) {
                    throw new \RuntimeException('Darwin dependency could not be resolved: ' . $installName);
                }
                $resolutionKey = $binary . "\0" . $installName;
                if (isset($resolutions[$resolutionKey])
                    && !\hash_equals($resolutions[$resolutionKey], $canonical)
                ) {
                    throw new \RuntimeException('Darwin dependency resolves ambiguously across run-path chains: '
                        . $installName);
                }
                $resolutions[$resolutionKey] = $canonical;
                $key = $binary . "\0" . $command . "\0" . $installName . "\0" . $canonical;
                $privateEdges[$key] = [
                    'loader_source' => $binary,
                    'load_command' => $command,
                    'source_install_name' => $installName,
                    'target_source' => $canonical,
                ];
                $queue[] = [
                    'binary' => $canonical,
                    'inherited_rpaths' => $rpaths,
                ];
            }
        }
        if (\count($images) < 2) {
            throw new \RuntimeException('Darwin HTTP/3 dependency closure is empty');
        }
        $requiredIdentity = '';
        foreach ($images as $path => $image) {
            if (!\hash_equals($rootLibrary, $path)) {
                $requiredIdentity .= "\n" . $path . "\n" . (string)($image['install_id'] ?? '');
            }
        }
        foreach (['libngtcp2', 'libngtcp2_crypto_ossl', 'libnghttp3', 'libssl', 'libcrypto'] as $required) {
            if (!\str_contains($requiredIdentity, $required)) {
                throw new \RuntimeException('Darwin dependency closure is missing ' . $required);
            }
        }
        \ksort($images, \SORT_STRING);
        \ksort($privateEdges, \SORT_STRING);
        \ksort($systemEdges, \SORT_STRING);
        return [
            'root' => $rootLibrary,
            'images' => $images,
            'private_edges' => \array_values($privateEdges),
            'system_edges' => \array_values($systemEdges),
        ];
    }

    /** @return array{install_id:string,raw_rpaths:list<string>,expanded_rpaths:list<string>,loads:list<array{command:string,install_name:string}>,architectures:list<string>} */
    private function darwinMachOInfo(string $binary, bool $requireInstallId): array
    {
        $this->trustedDarwinTool('/usr/bin/otool');
        $this->trustedDarwinTool('/usr/bin/lipo');
        $commands = $this->runDarwinTool(['/usr/bin/otool', '-l', $binary], 30);
        if (!$commands['success']) {
            throw new \RuntimeException('otool could not inspect the Darwin image: ' . $binary);
        }
        $installId = '';
        $rawRpaths = [];
        $loads = [];
        $command = '';
        foreach (\preg_split('/\r?\n/', $commands['output']) ?: [] as $line) {
            $line = \trim($line);
            if (\preg_match('/^cmd\s+(LC_[A-Z0-9_]+)$/D', $line, $matches) === 1) {
                $command = $matches[1];
                continue;
            }
            if ($command === 'LC_RPATH'
                && \preg_match('/^path\s+(.+?)\s+\(offset\s+\d+\)$/D', $line, $matches) === 1
            ) {
                $rawRpaths[] = $matches[1];
                $command = '';
                continue;
            }
            if (($command === 'LC_ID_DYLIB' || \in_array($command, self::DARWIN_LOAD_COMMANDS, true))
                && \preg_match('/^name\s+(.+?)\s+\(offset\s+\d+\)$/D', $line, $matches) === 1
            ) {
                $name = $matches[1];
                if ($command === 'LC_ID_DYLIB') {
                    if ($installId !== '' && !\hash_equals($installId, $name)) {
                        throw new \RuntimeException('Darwin image contains multiple install ids: ' . $binary);
                    }
                    $installId = $name;
                } else {
                    $loads[] = ['command' => $command, 'install_name' => $name];
                }
                $command = '';
            }
        }
        if ($requireInstallId && $installId === '') {
            throw new \RuntimeException('Darwin dylib has no install id: ' . $binary);
        }
        $expandedRpaths = [];
        foreach ($rawRpaths as $rawRpath) {
            $expanded = $this->expandDarwinPath($rawRpath, $binary);
            if ($expanded === null) {
                throw new \RuntimeException('Darwin image has an unsupported run path: ' . $rawRpath);
            }
            $expandedRpaths[] = $expanded;
        }
        $architectures = $this->runDarwinTool(['/usr/bin/lipo', '-archs', $binary], 30);
        if (!$architectures['success']) {
            throw new \RuntimeException('lipo could not inspect the Darwin image: ' . $binary);
        }
        $architectureList = \array_values(\array_filter(
            \preg_split('/\s+/', \trim($architectures['output'])) ?: [],
            static fn(string $architecture): bool => \preg_match('/^[a-zA-Z0-9_]+$/D', $architecture) === 1,
        ));
        $architectureList = \array_values(\array_unique($architectureList));
        \sort($architectureList, \SORT_STRING);
        if (!\in_array((string)\php_uname('m'), $architectureList, true)) {
            throw new \RuntimeException('Darwin image does not contain the current architecture: ' . $binary);
        }
        return [
            'install_id' => $installId,
            'raw_rpaths' => \array_values($rawRpaths),
            'expanded_rpaths' => \array_values(\array_unique($expandedRpaths)),
            'loads' => \array_values($loads),
            'architectures' => $architectureList,
        ];
    }

    /** @param array<string,mixed> $info @return array<string,mixed> */
    private function darwinSourceImageIdentity(string $binary, array $info): array
    {
        $canonical = \realpath($binary);
        $stat = \is_string($canonical) ? @\lstat($canonical) : false;
        if (!\is_string($canonical) || !\is_array($stat)
            || (($stat['mode'] ?? 0) & 0170000) !== 0100000
            || \is_link($canonical) || !\is_file($canonical)
        ) {
            throw new \RuntimeException('Darwin dependency is not a canonical regular file: ' . $binary);
        }
        $effectiveUser = \function_exists('posix_geteuid') ? (int)\posix_geteuid() : null;
        $owner = (int)($stat['uid'] ?? -1);
        $mode = ((int)($stat['mode'] ?? 0)) & 07777;
        $sha256 = (string)\hash_file('sha256', $canonical);
        if (($effectiveUser !== null && $owner !== 0 && $owner !== $effectiveUser)
            || ($mode & 0022) !== 0
            || \preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1
        ) {
            throw new \RuntimeException('Darwin dependency leaf identity is untrusted: ' . $canonical);
        }
        return [
            'path' => $canonical,
            'dev' => (int)($stat['dev'] ?? -1),
            'ino' => (int)($stat['ino'] ?? -1),
            'mode' => (int)($stat['mode'] ?? 0),
            'uid' => $owner,
            'size' => (int)($stat['size'] ?? -1),
            'mtime' => (int)($stat['mtime'] ?? -1),
            'ctime' => (int)($stat['ctime'] ?? -1),
            'sha256' => $sha256,
            'install_id' => (string)$info['install_id'],
            'raw_rpaths' => (array)$info['raw_rpaths'],
            'architectures' => (array)$info['architectures'],
        ];
    }

    /** @param array<string,array<string,mixed>> $images @return array<string,string> */
    private function darwinPrivateImageNames(array $images, string $rootSource): array
    {
        $names = [];
        $used = [];
        foreach ($images as $source => $image) {
            if (\hash_equals($rootSource, $source)) {
                continue;
            }
            $identity = \hash('sha256', (string)$image['sha256'] . "\0" . (string)$image['install_id']);
            $privateName = 'd-' . \substr($identity, 0, 24) . '.dylib';
            if (isset($used[$privateName]) && !\hash_equals($used[$privateName], $source)) {
                throw new \RuntimeException('private Darwin dependency name collision');
            }
            $used[$privateName] = $source;
            $names[$source] = $privateName;
        }
        \ksort($names, \SORT_STRING);
        return $names;
    }

    /** @param array<string,mixed> $identity */
    private function copyStableDarwinImage(array $identity, string $destination): void
    {
        $sourcePath = (string)($identity['path'] ?? '');
        $before = @\lstat($sourcePath);
        $source = @\fopen($sourcePath, 'rb');
        $target = @\fopen($destination, 'x+b');
        if (!\is_array($before) || !\is_resource($source) || !\is_resource($target)) {
            if (\is_resource($source)) {
                @\fclose($source);
            }
            if (\is_resource($target)) {
                @\fclose($target);
            }
            @\unlink($destination);
            throw new \RuntimeException('unable to open a stable Darwin dependency copy');
        }
        $opened = @\fstat($source);
        $hash = \hash_init('sha256');
        $written = 0;
        try {
            while (!\feof($source)) {
                $chunk = @\fread($source, 1048576);
                if (!\is_string($chunk)) {
                    throw new \RuntimeException('unable to read a Darwin dependency snapshot');
                }
                if ($chunk === '') {
                    break;
                }
                \hash_update($hash, $chunk);
                $offset = 0;
                $length = \strlen($chunk);
                while ($offset < $length) {
                    $count = @\fwrite($target, \substr($chunk, $offset));
                    if (!\is_int($count) || $count < 1) {
                        throw new \RuntimeException('unable to write a Darwin dependency snapshot');
                    }
                    $offset += $count;
                    $written += $count;
                }
            }
            @\fflush($target);
            if (\function_exists('fsync')) {
                @\fsync($target);
            }
            $read = @\fstat($source);
        } finally {
            @\fclose($source);
            @\fclose($target);
        }
        $after = @\lstat($sourcePath);
        foreach (['dev', 'ino', 'mode', 'uid', 'size', 'mtime', 'ctime'] as $field) {
            $expected = (int)($identity[$field] ?? -1);
            if (!\is_array($opened) || !\is_array($read) || !\is_array($after)
                || $expected !== (int)($before[$field] ?? -2)
                || $expected !== (int)($opened[$field] ?? -3)
                || $expected !== (int)($read[$field] ?? -4)
                || $expected !== (int)($after[$field] ?? -5)
            ) {
                @\unlink($destination);
                throw new \RuntimeException('Darwin dependency changed while it was copied');
            }
        }
        $actualHash = \hash_final($hash);
        if ($written !== (int)$identity['size']
            || !\hash_equals((string)$identity['sha256'], $actualHash)
            || !\hash_equals($actualHash, (string)\hash_file('sha256', $destination))
        ) {
            @\unlink($destination);
            throw new \RuntimeException('Darwin dependency copy digest mismatch');
        }
        @\chmod($destination, 0600);
    }

    /** @param array<string,string> $changes @param list<string> $rawRpaths */
    private function rewriteAndSignDarwinImage(
        string $image,
        string $installId,
        array $changes,
        array $rawRpaths,
    ): void {
        $this->trustedDarwinTool('/usr/bin/install_name_tool');
        $this->trustedDarwinTool('/usr/bin/codesign');
        $signed = $this->runDarwinTool(['/usr/bin/codesign', '-dv', $image], 30);
        if ($signed['success']) {
            $removed = $this->runDarwinTool(['/usr/bin/codesign', '--remove-signature', $image], 30);
            if (!$removed['success']) {
                throw new \RuntimeException('unable to remove the source Darwin code signature');
            }
        }
        @\chmod($image, 0600);
        $identity = $this->runDarwinTool([
            '/usr/bin/install_name_tool',
            '-id',
            $installId,
            $image,
        ], 30);
        if (!$identity['success']) {
            throw new \RuntimeException('unable to rewrite the private Darwin install id: '
                . \trim($identity['output']));
        }
        \ksort($changes, \SORT_STRING);
        foreach ($changes as $source => $target) {
            $changed = $this->runDarwinTool([
                '/usr/bin/install_name_tool',
                '-change',
                $source,
                $target,
                $image,
            ], 30);
            if (!$changed['success']) {
                throw new \RuntimeException('unable to rewrite a private Darwin dependency edge: '
                    . \trim($changed['output']));
            }
        }
        foreach (\array_values(\array_unique($rawRpaths)) as $rawRpath) {
            $deleted = $this->runDarwinTool([
                '/usr/bin/install_name_tool',
                '-delete_rpath',
                $rawRpath,
                $image,
            ], 30);
            if (!$deleted['success']) {
                throw new \RuntimeException('unable to delete a Darwin run path: '
                    . \trim($deleted['output']));
            }
        }
        $signature = $this->runDarwinTool([
            '/usr/bin/codesign',
            '--force',
            '--sign',
            '-',
            '--timestamp=none',
            $image,
        ], 60);
        if (!$signature['success']) {
            throw new \RuntimeException('unable to ad-hoc sign a private Darwin image: '
                . \trim($signature['output']));
        }
        @\chmod($image, 0555);
        $verified = $this->runDarwinTool([
            '/usr/bin/codesign',
            '--verify',
            '--strict',
            '--verbose=2',
            $image,
        ], 30);
        if (!$verified['success']) {
            throw new \RuntimeException('private Darwin image code signature verification failed');
        }
    }

    /**
     * @param list<array{command:string,install_name:string}> $expectedPrivateLoads
     * @param list<array{command:string,install_name:string}> $expectedSystemLoads
     * @return array{sha256:string,size:int,mode:int,owner_uid:int,architectures:list<string>,install_id:string,cdhash:string}
     */
    private function verifyRewrittenDarwinImage(
        string $image,
        string $expectedInstallId,
        array $expectedPrivateLoads,
        array $expectedSystemLoads,
    ): array {
        $info = $this->darwinMachOInfo($image, true);
        if (!\hash_equals($expectedInstallId, $info['install_id']) || $info['raw_rpaths'] !== []) {
            throw new \RuntimeException('private Darwin image retains an unexpected install id or run path');
        }
        $privateLoads = [];
        $systemLoads = [];
        foreach ($info['loads'] as $load) {
            if (NativeTransportLibrary::isDarwinSystemDependency($load['install_name'])) {
                $systemLoads[] = $load;
                continue;
            }
            if (!\str_starts_with($load['install_name'], '@loader_path/')) {
                throw new \RuntimeException('private Darwin image retains an external non-system dependency');
            }
            $privateLoads[] = $load;
        }
        $sortLoads = static function (array &$loads): void {
            \usort($loads, static fn(array $left, array $right): int => \strcmp(
                (string)$left['command'] . "\0" . (string)$left['install_name'],
                (string)$right['command'] . "\0" . (string)$right['install_name'],
            ));
        };
        $sortLoads($privateLoads);
        $sortLoads($systemLoads);
        $sortLoads($expectedPrivateLoads);
        $sortLoads($expectedSystemLoads);
        if ($privateLoads !== $expectedPrivateLoads || $systemLoads !== $expectedSystemLoads) {
            throw new \RuntimeException('private Darwin image load-command closure does not match the plan');
        }
        $stat = @\lstat($image);
        $sha256 = \is_file($image) ? (string)\hash_file('sha256', $image) : '';
        if (!\is_array($stat)
            || (($stat['mode'] ?? 0) & 0170000) !== 0100000
            || (((int)($stat['mode'] ?? 0)) & 07777) !== 0555
            || \preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1
        ) {
            throw new \RuntimeException('private Darwin image file identity is incomplete');
        }
        $signature = $this->runDarwinTool([
            '/usr/bin/codesign',
            '-dv',
            '--verbose=4',
            $image,
        ], 30);
        if (!$signature['success']
            || \preg_match('/^CDHash=([a-f0-9]{40})$/m', $signature['output'], $matches) !== 1
        ) {
            throw new \RuntimeException('private Darwin image has no stable ad-hoc CDHash');
        }
        return [
            'sha256' => $sha256,
            'size' => (int)($stat['size'] ?? -1),
            'mode' => ((int)($stat['mode'] ?? 0)) & 07777,
            'owner_uid' => (int)($stat['uid'] ?? -1),
            'architectures' => $info['architectures'],
            'install_id' => $info['install_id'],
            'cdhash' => $matches[1],
        ];
    }

    /** @param list<array<string,string>> $systemEdges @return list<array{command:string,install_name:string}> */
    private function darwinExpectedSystemLoads(array $systemEdges, string $loaderSource): array
    {
        $loads = [];
        foreach ($systemEdges as $edge) {
            if (($edge['loader_source'] ?? '') === $loaderSource) {
                $loads[] = [
                    'command' => (string)$edge['load_command'],
                    'install_name' => (string)$edge['install_name'],
                ];
            }
        }
        return $loads;
    }

    /**
     * @param list<array<string,string>> $edges
     * @param array<string,string> $privateNames
     * @return list<array<string,string>>
     */
    private function darwinPrivateEdgeDescriptors(
        array $edges,
        array $privateNames,
        string $rootSource,
        ?string $bundleSha256,
    ): array {
        $descriptors = [];
        foreach ($edges as $edge) {
            $loaderSource = (string)$edge['loader_source'];
            $targetSource = (string)$edge['target_source'];
            $loaderRelative = \hash_equals($rootSource, $loaderSource)
                ? '@artifact'
                : (($bundleSha256 === null ? '' : 'deps/' . $bundleSha256 . '/')
                    . (string)($privateNames[$loaderSource] ?? ''));
            $targetName = (string)($privateNames[$targetSource] ?? '');
            if ($loaderRelative === '' || $targetName === '') {
                throw new \RuntimeException('private Darwin edge descriptor is incomplete');
            }
            $targetRelative = ($bundleSha256 === null ? '' : 'deps/' . $bundleSha256 . '/')
                . $targetName;
            $installName = $loaderRelative === '@artifact'
                ? ($bundleSha256 === null
                    ? '@loader_path/@bundle/' . $targetName
                    : '@loader_path/' . $targetRelative)
                : '@loader_path/' . $targetName;
            $descriptors[] = [
                'loader_relative_path' => $loaderRelative,
                'load_command' => (string)$edge['load_command'],
                'source_install_name' => (string)$edge['source_install_name'],
                'install_name' => $installName,
                'target_relative_path' => $targetRelative,
            ];
        }
        \usort($descriptors, static fn(array $left, array $right): int => \strcmp(
            \implode("\0", $left),
            \implode("\0", $right),
        ));
        return $descriptors;
    }

    /**
     * @param list<array<string,string>> $edges
     * @param array<string,string> $privateNames
     * @return list<array<string,string>>
     */
    private function darwinSystemEdgeDescriptors(
        array $edges,
        array $privateNames,
        string $rootSource,
        ?string $bundleSha256 = null,
    ): array {
        $descriptors = [];
        foreach ($edges as $edge) {
            $loaderSource = (string)$edge['loader_source'];
            $loaderRelative = \hash_equals($rootSource, $loaderSource)
                ? '@artifact'
                : (($bundleSha256 === null ? '' : 'deps/' . $bundleSha256 . '/')
                    . (string)($privateNames[$loaderSource] ?? ''));
            if ($loaderRelative === '') {
                throw new \RuntimeException('Darwin system edge loader is outside the private closure');
            }
            $descriptors[] = [
                'loader_relative_path' => $loaderRelative,
                'load_command' => (string)$edge['load_command'],
                'install_name' => (string)$edge['install_name'],
            ];
        }
        \usort($descriptors, static fn(array $left, array $right): int => \strcmp(
            \implode("\0", $left),
            \implode("\0", $right),
        ));
        return $descriptors;
    }

    /**
     * @param list<array<string,mixed>> $dependencyImages
     * @param array<string,mixed> $rootVerification
     * @param array<string,mixed> $rootSourceIdentity
     * @return list<array<string,mixed>>
     */
    private function finalizeDarwinImages(
        array $dependencyImages,
        array $rootVerification,
        array $rootSourceIdentity,
        string $artifactDirectory,
        string $rootName,
        string $bundleSha256,
    ): array {
        $images = [[
            'role' => 'root',
            'relative_path' => $rootName,
            'path' => $artifactDirectory . \DIRECTORY_SEPARATOR . $rootName,
            'sha256' => (string)$rootVerification['sha256'],
            'source_sha256' => (string)$rootSourceIdentity['sha256'],
            'size' => (int)$rootVerification['size'],
            'mode' => (int)$rootVerification['mode'],
            'owner_uid' => (int)$rootVerification['owner_uid'],
            'architectures' => (array)$rootVerification['architectures'],
            'install_id' => (string)$rootVerification['install_id'],
            'source_install_id' => (string)$rootSourceIdentity['install_id'],
            'cdhash' => (string)$rootVerification['cdhash'],
            'rpaths' => [],
        ]];
        foreach ($dependencyImages as $image) {
            $image['relative_path'] = 'deps/' . $bundleSha256 . '/'
                . (string)$image['relative_path'];
            $image['path'] = $artifactDirectory . \DIRECTORY_SEPARATOR
                . \str_replace('/', \DIRECTORY_SEPARATOR, (string)$image['relative_path']);
            $images[] = $image;
        }
        \usort($images, static fn(array $left, array $right): int => \strcmp(
            (string)$left['relative_path'],
            (string)$right['relative_path'],
        ));
        return $images;
    }

    /**
     * @param list<array<string,string>> $edges
     * @param list<array<string,mixed>> $images
     * @return list<array<string,mixed>>
     */
    private function finalizeDarwinEdges(array $edges, array $images, string $artifactDirectory): array
    {
        $byRelative = [];
        $rootRelative = '';
        foreach ($images as $image) {
            $relative = (string)$image['relative_path'];
            $byRelative[$relative] = $image;
            if (($image['role'] ?? '') === 'root') {
                $rootRelative = $relative;
            }
        }
        $final = [];
        foreach ($edges as $edge) {
            $loaderRelative = (string)$edge['loader_relative_path'];
            $targetRelative = (string)$edge['target_relative_path'];
            $target = $byRelative[$targetRelative] ?? null;
            $loaderPath = $loaderRelative === '@artifact'
                ? $artifactDirectory . \DIRECTORY_SEPARATOR . $rootRelative
                : $artifactDirectory . \DIRECTORY_SEPARATOR
                    . \str_replace('/', \DIRECTORY_SEPARATOR, $loaderRelative);
            if (!\is_array($target) || $rootRelative === '') {
                throw new \RuntimeException('private Darwin manifest edge targets an unknown image');
            }
            $final[] = [
                ...$edge,
                'loader' => $loaderRelative === '@artifact' ? '@artifact' : $loaderPath,
                'run_path_stack' => [],
                'resolution_path' => (string)$target['path'],
                'path' => (string)$target['path'],
                'sha256' => (string)$target['sha256'],
                'owner_uid' => (int)$target['owner_uid'],
                'mode' => (int)$target['mode'],
            ];
        }
        return $final;
    }

    private function darwinStaticBuildId(string $library): string
    {
        $this->trustedDarwinTool('/usr/bin/strings');
        $result = $this->runDarwinTool(['/usr/bin/strings', '-a', $library], 30);
        if (!$result['success']) {
            return '';
        }
        $matches = [];
        foreach (\preg_split('/\r?\n/', $result['output']) ?: [] as $line) {
            $line = \trim($line);
            if (\str_starts_with($line, 'wls-h3-abi/2.9 ngtcp2/')) {
                $matches[$line] = true;
            }
        }
        return \count($matches) === 1 ? (string)\array_key_first($matches) : '';
    }

    private function trustedDarwinTool(string $tool): void
    {
        $stat = @\lstat($tool);
        if (!\is_array($stat)
            || (($stat['mode'] ?? 0) & 0170000) !== 0100000
            || (int)($stat['uid'] ?? -1) !== 0
            || (((int)($stat['mode'] ?? 0)) & 0022) !== 0
            || !\is_executable($tool)
        ) {
            throw new \RuntimeException('untrusted Darwin build tool: ' . $tool);
        }
    }

    /** @param list<string> $command @return array{success:bool,exit_code:int,output:string} */
    private function runDarwinTool(array $command, int $timeout): array
    {
        $environment = [
            'PATH' => '/usr/bin:/bin:/usr/sbin:/sbin',
            'LANG' => 'C',
            'LC_ALL' => 'C',
        ];
        foreach (self::DARWIN_INJECTION_ENVIRONMENT as $variable) {
            $environment[$variable] = '';
        }
        return $this->runner()->run($command, $timeout, null, $environment, false);
    }

    private function removeOwnedDarwinStagingTree(string $staging): void
    {
        $expectedPrefix = $this->nativeRoot() . \DIRECTORY_SEPARATOR . '.darwin-private.';
        $stat = @\lstat($staging);
        $effectiveUser = \function_exists('posix_geteuid') ? (int)\posix_geteuid() : null;
        if (!\str_starts_with($staging, $expectedPrefix)
            || !\is_array($stat)
            || (($stat['mode'] ?? 0) & 0170000) !== 0040000
            || \is_link($staging)
            || ($effectiveUser !== null && (int)($stat['uid'] ?? -1) !== $effectiveUser)
        ) {
            return;
        }
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($staging, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                $path = $item->getPathname();
                if (!\str_starts_with($path, $staging . \DIRECTORY_SEPARATOR)) {
                    return;
                }
                if ($item->isDir() && !$item->isLink()) {
                    @\rmdir($path);
                } else {
                    @\unlink($path);
                }
            }
            @\rmdir($staging);
        } catch (\Throwable) {
        }
    }

    private function darwinInstallId(string $otool, string $binary): ?string
    {
        $result = $this->run([$otool, '-D', $binary], 30);
        if (!$result['success']) {
            return null;
        }
        $lines = \array_values(\array_filter(
            \preg_split('/\r?\n/', \trim($result['output'])) ?: [],
            static fn(string $line): bool => \trim($line) !== '',
        ));
        if (\count($lines) !== 2 || !\str_ends_with(\trim($lines[0]), ':')) {
            return null;
        }
        $installId = \trim($lines[1]);
        return $installId !== '' && \strlen($installId) <= 4096
            ? $installId
            : null;
    }

    /** @return list<string>|null */
    private function darwinRpaths(string $otool, string $binary): ?array
    {
        $result = $this->run([$otool, '-l', $binary], 30);
        if (!$result['success']) {
            return null;
        }
        $rpaths = [];
        $expectPath = false;
        foreach (\preg_split('/\r?\n/', $result['output']) ?: [] as $line) {
            $line = \trim($line);
            if ($line === 'cmd LC_RPATH') {
                if ($expectPath) {
                    return null;
                }
                $expectPath = true;
                continue;
            }
            if ($expectPath && \preg_match('/^path\s+(.+?)\s+\(offset\s+\d+\)$/D', $line, $matches) === 1) {
                $expanded = $this->expandDarwinPath($matches[1], $binary);
                if ($expanded === null) {
                    return null;
                }
                $rpaths[] = $expanded;
                $expectPath = false;
            }
        }
        if ($expectPath) {
            return null;
        }
        return \array_values(\array_unique($rpaths));
    }

    /** @param list<string> $rpaths */
    private function resolveDarwinDependency(string $installName, string $binary, array $rpaths): ?string
    {
        if (\str_starts_with($installName, '/')) {
            return $installName;
        }
        if (\str_starts_with($installName, '@loader_path/')
            || \str_starts_with($installName, '@executable_path/')
        ) {
            return $this->expandDarwinPath($installName, $binary);
        }
        if (\str_starts_with($installName, '@rpath/')) {
            $suffix = \substr($installName, \strlen('@rpath/'));
            foreach ($rpaths as $rpath) {
                $candidate = \rtrim($rpath, '/') . \DIRECTORY_SEPARATOR . $suffix;
                if (\is_file($candidate)) {
                    return $candidate;
                }
            }
            return null;
        }
        $candidate = \dirname($binary) . \DIRECTORY_SEPARATOR . $installName;
        return \is_file($candidate) ? $candidate : null;
    }

    private function expandDarwinPath(string $path, string $binary): ?string
    {
        if (\str_starts_with($path, '/')) {
            return $path;
        }
        if (\str_starts_with($path, '@loader_path/')) {
            return \dirname($binary) . \DIRECTORY_SEPARATOR
                . \substr($path, \strlen('@loader_path/'));
        }
        if (\str_starts_with($path, '@executable_path/')) {
            $executable = \realpath(\PHP_BINARY);
            if (!\is_string($executable)) {
                return null;
            }
            return \dirname($executable) . \DIRECTORY_SEPARATOR
                . \substr($path, \strlen('@executable_path/'));
        }
        return null;
    }

    /**
     * @return array{ready:bool,reason:string,build_id:string,versions:array<string,int>}
     */
    private function inspectLibrary(string $library): array
    {
        try {
            $ffi = \FFI::cdef(
                'typedef struct { uint32_t struct_size; uint32_t abi_version; uint32_t ngtcp2_compile; uint32_t ngtcp2_runtime; uint32_t nghttp3_compile; uint32_t nghttp3_runtime; uint64_t openssl_compile; uint64_t openssl_runtime; } wls_transport_versions;'
                . ' uint32_t wls_transport_abi_version(void);'
                . ' const char *wls_transport_build_id(void);'
                . ' const char *wls_transport_last_error(void);'
                . ' int wls_transport_get_versions(wls_transport_versions *versions);',
                $library
            );
            $abi = (int)$ffi->wls_transport_abi_version();
            if ($abi !== self::ABI_VERSION) {
                return ['ready' => false, 'reason' => 'native ABI mismatch', 'build_id' => '', 'versions' => []];
            }
            $versions = $ffi->new('wls_transport_versions');
            $versions->struct_size = \FFI::sizeof($versions);
            if ((int)$ffi->wls_transport_get_versions(\FFI::addr($versions)) !== 0) {
                return [
                    'ready' => false,
                    'reason' => $this->ffiString($ffi->wls_transport_last_error()),
                    'build_id' => '',
                    'versions' => [],
                ];
            }
            return [
                'ready' => true,
                'reason' => 'ABI and runtime library versions verified',
                'build_id' => $this->ffiString($ffi->wls_transport_build_id()),
                'versions' => [
                    'ngtcp2_compile' => (int)$versions->ngtcp2_compile,
                    'ngtcp2_runtime' => (int)$versions->ngtcp2_runtime,
                    'nghttp3_compile' => (int)$versions->nghttp3_compile,
                    'nghttp3_runtime' => (int)$versions->nghttp3_runtime,
                    'openssl_compile' => (int)$versions->openssl_compile,
                    'openssl_runtime' => (int)$versions->openssl_runtime,
                ],
            ];
        } catch (\Throwable $exception) {
            return ['ready' => false, 'reason' => $exception->getMessage(), 'build_id' => '', 'versions' => []];
        }
    }

    private function ffiString(mixed $value): string
    {
        return \is_string($value) ? $value : \FFI::string($value);
    }

    public function markRuntimeVerified(
        bool $verified,
        string $reason,
        ?string $expectedLibrarySha256 = null,
        array $evidence = [],
        ?string $expectedFingerprint = null,
    ): bool
    {
        $fingerprint = \strtolower(\trim((string)$expectedFingerprint));
        $librarySha256 = \strtolower(\trim((string)$expectedLibrarySha256));
        if (!$verified) {
            $this->recordRuntimeVerificationFailure($reason, $librarySha256, $fingerprint);
            return false;
        }
        if (\preg_match('/^[a-f0-9]{32}$/D', $fingerprint) !== 1
            || \preg_match('/^[a-f0-9]{64}$/D', $librarySha256) !== 1
        ) {
            $this->recordRuntimeVerificationFailure(
                'Refused to publish HTTP/3 runtime evidence without an exact artifact identity: ' . $reason,
                $librarySha256,
                $fingerprint,
            );
            return false;
        }
        $lock = $this->acquirePublicationLock();
        if (!\is_resource($lock)) {
            $this->recordRuntimeVerificationFailure(
                'Timed out waiting to publish successful HTTP/3 runtime evidence: ' . $reason,
                $librarySha256,
                $fingerprint,
            );
            return false;
        }
        try {
            try {
                $artifactManifest = NativeTransportLibrary::pinSelfTestCandidate(
                    $fingerprint,
                    $librarySha256,
                );
            } catch (\Throwable) {
                return false;
            }
            $artifactManifestPath = NativeTransportLibrary::manifestPathForFingerprint(
                $fingerprint,
                $librarySha256,
            );
            $evidenceVerifierSha256 = (string)($evidence['verifier_sha256'] ?? '');
            $currentVerifierSha256 = NativeTransportLibrary::runtimeEvidenceVerifierSha256();
            $evidenceIntegrationSha256 = (string)($evidence['integration_sha256'] ?? '');
            $currentIntegrationSha256 = NativeTransportLibrary::productionIntegrationSha256();
            if (\preg_match('/^[a-f0-9]{64}$/D', $evidenceVerifierSha256) !== 1
                || \preg_match('/^[a-f0-9]{64}$/D', $currentVerifierSha256) !== 1
                || !\hash_equals($currentVerifierSha256, $evidenceVerifierSha256)
                || \preg_match('/^[a-f0-9]{64}$/D', $evidenceIntegrationSha256) !== 1
                || \preg_match('/^[a-f0-9]{64}$/D', $currentIntegrationSha256) !== 1
                || !\hash_equals($currentIntegrationSha256, $evidenceIntegrationSha256)
            ) {
                return false;
            }
            foreach ([
                'fingerprint',
                'library_sha256',
                'source_sha256',
                'build_id',
                'dependency_fingerprint',
                'dependency_linkage',
                'platform',
                'architecture',
            ] as $field) {
                $evidence[$field] = (string)($artifactManifest[$field] ?? '');
            }
            $evidence['abi_version'] = (int)($artifactManifest['abi_version'] ?? 0);
            $encodedVersions = \json_encode(
                $artifactManifest['versions'] ?? [],
                \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
            );
            $evidence['versions_sha256'] = \hash('sha256', $encodedVersions);
            if (NativeTransportLibrary::hasVerifiedRuntimeEvidence($artifactManifest)) {
                $candidate = $artifactManifest;
            } else {
                $candidate = $artifactManifest;
                $candidate['runtime_verified'] = true;
                $candidate['runtime_reason'] = $reason;
                $candidate['runtime_evidence'] = $evidence;
                $candidate['runtime_verified_at'] = \date(\DATE_ATOM);
            }
            if (!NativeTransportLibrary::hasVerifiedRuntimeEvidence($candidate)) {
                return false;
            }
            if ($candidate !== $artifactManifest) {
                $this->atomicJson($artifactManifestPath, $candidate);
            }
            $this->atomicJson($this->activeManifestPath(), $candidate);
            return true;
        } finally {
            NativeTransportLibrary::reset();
            @\flock($lock, \LOCK_UN);
            @\fclose($lock);
        }
    }

    public function recordRuntimeVerificationFailure(
        string $reason,
        ?string $expectedLibrarySha256 = null,
        ?string $expectedFingerprint = null,
    ): void {
        $identity = \array_filter([
            'fingerprint=' . (string)$expectedFingerprint,
            'library_sha256=' . (string)$expectedLibrarySha256,
        ], static fn(string $value): bool => !\str_ends_with($value, '='));
        $this->recordFailure(
            $reason . ($identity !== [] ? ' [' . \implode(', ', $identity) . ']' : ''),
        );
    }

    private function recordFailure(string $reason, string $output = ''): void
    {
        $this->atomicJson($this->nativeRoot() . \DIRECTORY_SEPARATOR . 'last-error.json', [
            'schema' => 1,
            'ready' => false,
            'reason' => $reason,
            'output_tail' => \substr($output, -65536),
            'platform' => \PHP_OS_FAMILY,
            'architecture' => (string)\php_uname('m'),
            'updated_at' => \date(\DATE_ATOM),
        ]);
    }

    public function deactivate(string $reason): void
    {
        $lock = $this->acquirePublicationLock();
        if (!\is_resource($lock)) {
            $this->recordFailure('Timed out waiting to record HTTP/3 deactivation: ' . $reason);
            return;
        }
        try {
            $active = $this->readJson($this->activeManifestPath());
            $verifiedActive = false;
            if ($active !== []) {
                try {
                    NativeTransportLibrary::pinManifest(
                        (string)($active['fingerprint'] ?? ''),
                        (string)($active['library_sha256'] ?? ''),
                    );
                    $verifiedActive = true;
                } catch (\Throwable) {
                } finally {
                    NativeTransportLibrary::reset();
                }
            }
            if ($verifiedActive) {
                $this->recordFailure(
                    'Preserved the verified HTTP/3 active publication; deactivation was recorded only as an error: '
                    . $reason,
                );
                return;
            }
            $manifest = [
                'schema' => 1,
                'ready' => false,
                'runtime_verified' => false,
                'runtime_reason' => $reason,
                'runtime_evidence' => [],
                'platform' => \PHP_OS_FAMILY,
                'architecture' => (string)\php_uname('m'),
                'updated_at' => \date(\DATE_ATOM),
            ];
            $this->atomicJson($this->activeManifestPath(), $manifest);
            NativeTransportLibrary::reset();
        } finally {
            @\flock($lock, \LOCK_UN);
            @\fclose($lock);
        }
    }

    public function activeManifestPath(): string
    {
        return NativeTransportLibrary::activeManifestPath();
    }

    private function nativeRoot(): string
    {
        $base = \defined('BP') ? (string)\BP : \dirname(__DIR__, 6) . \DIRECTORY_SEPARATOR;
        $root = \rtrim($base, '\\/') . \DIRECTORY_SEPARATOR . 'var'
            . \DIRECTORY_SEPARATOR . 'server' . \DIRECTORY_SEPARATOR . 'native'
            . \DIRECTORY_SEPARATOR . 'http3';
        if (!\is_dir($root)) {
            @\mkdir($root, 0700, true);
        }
        @\chmod($root, 0700);
        return $root;
    }

    /** @return resource|null */
    private function acquireBuildLock(): mixed
    {
        $lock = @\fopen($this->nativeRoot() . \DIRECTORY_SEPARATOR . 'compile.lock', 'c+');
        if (!\is_resource($lock)) {
            return null;
        }
        $deadline = \microtime(true) + self::LOCK_TIMEOUT;
        do {
            if (@\flock($lock, \LOCK_EX | \LOCK_NB)) {
                return $lock;
            }
            SchedulerSystem::usleep(50000);
        } while (\microtime(true) < $deadline);
        @\fclose($lock);
        return null;
    }

    /** @return resource|null */
    private function acquirePublicationLock(): mixed
    {
        $lock = @\fopen($this->nativeRoot() . \DIRECTORY_SEPARATOR . 'publication.lock', 'c+');
        if (!\is_resource($lock)) {
            return null;
        }
        $deadline = \microtime(true) + self::PUBLICATION_LOCK_TIMEOUT;
        do {
            if (@\flock($lock, \LOCK_EX | \LOCK_NB)) {
                return $lock;
            }
            SchedulerSystem::usleep(50000);
        } while (\microtime(true) < $deadline);
        @\fclose($lock);
        return null;
    }

    /**
     * @param list<string> $command
     * @return array{success:bool,exit_code:int,output:string}
     */
    private function run(
        array $command,
        int $timeout,
        ?string $workingDirectory = null,
        array $environment = []
    ): array
    {
        return $this->runner()->run($command, $timeout, $workingDirectory, $environment);
    }

    /** @param list<string> $names @param array<string,string> $environment */
    private function findExecutable(array $names, array $environment = []): ?string
    {
        return $this->runner()->findExecutable($names, $environment);
    }

    /** @return list<string> */
    private function splitFlags(string $flags): array
    {
        $result = [];
        $current = '';
        $quote = null;
        $tokenStarted = false;
        $length = \strlen($flags);
        for ($offset = 0; $offset < $length; $offset++) {
            $character = $flags[$offset];
            if ($quote === "'") {
                if ($character === "'") {
                    $quote = null;
                } else {
                    $current .= $character;
                }
                $tokenStarted = true;
                continue;
            }
            if ($character === '\\') {
                if ($offset + 1 >= $length) {
                    throw new \RuntimeException('pkg-config flags end with an incomplete escape');
                }
                $next = $flags[++$offset];
                if ($quote === '"' && !\str_contains("$`\"\\\n", $next)) {
                    $current .= '\\';
                }
                if (!($quote === '"' && $next === "\n")) {
                    $current .= $next;
                }
                $tokenStarted = true;
                continue;
            }
            if ($character === "'" || $character === '"') {
                if ($quote === null) {
                    $quote = $character;
                    $tokenStarted = true;
                    continue;
                }
                if ($quote === $character) {
                    $quote = null;
                    continue;
                }
            }
            if ($quote === null && \ctype_space($character)) {
                if ($tokenStarted) {
                    $result[] = $current;
                    $current = '';
                    $tokenStarted = false;
                }
                continue;
            }
            $current .= $character;
            $tokenStarted = true;
        }
        if ($quote !== null) {
            throw new \RuntimeException('pkg-config flags contain an unterminated quote');
        }
        if ($tokenStarted) {
            $result[] = $current;
        }
        return $result;
    }

    /** @return list<string> */
    private function libraryDirectories(string $flags): array
    {
        $directories = [];
        foreach ($this->splitFlags($flags) as $flag) {
            if (\str_starts_with($flag, '-L') && \strlen($flag) > 2) {
                $directory = \substr($flag, 2);
                if (\is_dir($directory)) {
                    $directories[] = $directory;
                }
            }
        }
        return \array_values(\array_unique($directories));
    }

    private function verifiedLibraryFile(string $library, string $expectedHash): bool
    {
        if (!\is_file($library) || $expectedHash === '') {
            return false;
        }
        $real = \realpath($library);
        $root = \realpath($this->nativeRoot());
        if (!\is_string($real) || !\is_string($root)
            || !\str_starts_with($real, $root . \DIRECTORY_SEPARATOR)
        ) {
            return false;
        }
        if (\function_exists('posix_geteuid') && \fileowner($real) !== \posix_geteuid()) {
            return false;
        }
        return \hash_equals($expectedHash, (string)\hash_file('sha256', $real));
    }

    /** @return array<string,mixed> */
    private function readJson(string $path): array
    {
        $before = @\lstat($path);
        if (!\is_array($before)
            || (($before['mode'] ?? 0) & 0170000) !== 0100000
            || \is_link($path)
            || (int)($before['size'] ?? 0) <= 0
            || (int)($before['size'] ?? 0) > self::MAX_MANIFEST_BYTES
            || (((int)($before['mode'] ?? 0)) & 0022) !== 0
        ) {
            return [];
        }
        if (\function_exists('posix_geteuid')) {
            $owner = (int)($before['uid'] ?? -1);
            if ($owner !== 0 && $owner !== (int)\posix_geteuid()) {
                return [];
            }
        }
        $handle = @\fopen($path, 'rb');
        if (!\is_resource($handle)) {
            return [];
        }
        $opened = @\fstat($handle);
        $contents = @\stream_get_contents($handle, self::MAX_MANIFEST_BYTES + 1);
        $read = @\fstat($handle);
        @\fclose($handle);
        $after = @\lstat($path);
        if (!\is_array($opened) || !\is_array($read)
            || !\is_string($contents) || !\is_array($after)
            || \strlen($contents) !== (int)($before['size'] ?? -1)
            || \is_link($path)
        ) {
            return [];
        }
        foreach (['dev', 'ino', 'mode', 'uid', 'size', 'mtime'] as $field) {
            $expected = (int)($before[$field] ?? -1);
            if ($expected !== (int)($opened[$field] ?? -2)
                || $expected !== (int)($read[$field] ?? -3)
                || $expected !== (int)($after[$field] ?? -4)
            ) {
                return [];
            }
        }
        try {
            $decoded = \json_decode($contents, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        return \is_array($decoded) && (int)($decoded['schema'] ?? 0) === 1
            ? $decoded
            : [];
    }

    /** @param array<string,mixed> $payload */
    private function atomicJson(string $path, array $payload): void
    {
        $directory = \dirname($path);
        if (!\is_dir($directory)) {
            @\mkdir($directory, 0700, true);
        }
        $tmp = $path . '.tmp.' . \getmypid() . '.' . \bin2hex(\random_bytes(4));
        $json = \json_encode(
            $payload,
            \JSON_PRETTY_PRINT
                | \JSON_UNESCAPED_SLASHES
                | \JSON_INVALID_UTF8_SUBSTITUTE
                | \JSON_THROW_ON_ERROR,
        );
        if (\strlen($json) + \strlen(\PHP_EOL) > self::MAX_MANIFEST_BYTES) {
            throw new \RuntimeException('native transport manifest exceeds the bounded size limit');
        }
        if (@\file_put_contents($tmp, $json . \PHP_EOL, \LOCK_EX) === false) {
            throw new \RuntimeException('unable to write native transport manifest');
        }
        @\chmod($tmp, 0600);
        if (!@\rename($tmp, $path)) {
            @\unlink($tmp);
            throw new \RuntimeException('unable to publish native transport manifest');
        }
    }
}
