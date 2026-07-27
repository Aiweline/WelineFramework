<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx;

/**
 * Downloads and installs pinned Nginx into the project's isolated local install root.
 *
 * Platform matrix:
 * - Darwin/Linux: build from official source tarball into project prefix
 * - Windows: extract official nginx.zip (nginx.exe at install root)
 *
 * Installation is explicit. Ordinary server:start remains pure PHP and never
 * downloads or compiles Nginx. server:nginx:install performs the opt-in install
 * or force reinstall.
 */
final class ManagedNginxInstaller
{
    public const VERSION = '1.30.4';

    public const SOURCE_URL = 'https://nginx.org/download/nginx-1.30.4.tar.gz';

    public const SOURCE_SHA256 = '4261dc90e9e47c1c4041276e9aaa3d48ebe2e664f728e14fa95ae6c67d57a08b';

    public const WINDOWS_ZIP_URL = 'https://nginx.org/download/nginx-1.30.4.zip';

    /** Official Windows zip SHA-256 (nginx.org release package). */
    public const WINDOWS_ZIP_SHA256 = '159294214d403f34f0bb4ae598801ab1f6a0d8c8da707f8f08748e294a222a01';

    public function __construct(private readonly ManagedNginxPaths $paths = new ManagedNginxPaths())
    {
    }

    /**
     * @return array{ok:bool,message:string,manifest?:array<string,mixed>,platform?:string}
     */
    public function ensureInstalled(bool $force = false): array
    {
        if (!$force && $this->paths->isInstalled()) {
            if ($this->manifestMatches()) {
                return [
                    'ok' => true,
                    'message' => 'managed nginx already installed',
                    'manifest' => $this->readManifest() ?? [],
                    'platform' => \PHP_OS_FAMILY,
                ];
            }
            return [
                'ok' => false,
                'message' => 'managed nginx binary exists but its pinned manifest does not match '
                    . self::VERSION
                    . '; stop the managed edge, then run php bin/w server:nginx:install --force',
                'platform' => \PHP_OS_FAMILY,
            ];
        }

        return match (\PHP_OS_FAMILY) {
            'Windows' => $this->installWindows($force),
            'Darwin', 'Linux' => $this->installUnixFromSource($force),
            default => [
                'ok' => false,
                'message' => 'managed nginx install unsupported on OS family ' . \PHP_OS_FAMILY
                    . ' (supported: Darwin, Linux, Windows)',
                'platform' => \PHP_OS_FAMILY,
            ],
        };
    }

    /**
     * @return array{installed:bool,manifest_matches:bool,expected_version:string,manifest:array<string,mixed>|null}
     */
    public function installationStatus(): array
    {
        return [
            'installed' => $this->paths->isInstalled(),
            'manifest_matches' => $this->paths->isInstalled() && $this->manifestMatches(),
            'expected_version' => self::VERSION,
            'manifest' => $this->readManifest(),
        ];
    }

    private function manifestMatches(): bool
    {
        $manifest = $this->readManifest();
        if ($manifest === null) {
            return false;
        }
        if (!\hash_equals(self::VERSION, (string)($manifest['version'] ?? ''))) {
            return false;
        }
        if (!\hash_equals(\PHP_OS_FAMILY, (string)($manifest['platform'] ?? ''))) {
            return false;
        }
        if (\PHP_OS_FAMILY !== 'Windows') {
            $buildFlags = \is_array($manifest['build_flags'] ?? null)
                ? $manifest['build_flags']
                : [];
            if (($buildFlags['has_pcre'] ?? false) !== true
                || ($buildFlags['without_rewrite'] ?? true) !== false
            ) {
                return false;
            }
        }
        $binaryArchitecture = $this->binaryArchitecture($this->paths->binary());
        if ($binaryArchitecture === ''
            || !\hash_equals($binaryArchitecture, (string)($manifest['arch'] ?? ''))
            || (\PHP_OS_FAMILY !== 'Windows'
                && !\hash_equals($binaryArchitecture, $this->normalizeArchitecture((string)\php_uname('m'))))
        ) {
            return false;
        }
        $expected = \PHP_OS_FAMILY === 'Windows' ? self::WINDOWS_ZIP_SHA256 : self::SOURCE_SHA256;
        $actual = (string)($manifest['artifact_sha256'] ?? $manifest['source_sha256'] ?? '');
        $expectedBinarySha256 = \strtolower((string)($manifest['binary_sha256'] ?? ''));
        $actualBinarySha256 = \is_file($this->paths->binary())
            ? \hash_file('sha256', $this->paths->binary())
            : false;

        return $actual !== ''
            && \hash_equals(\strtolower($expected), \strtolower($actual))
            && \preg_match('/\A[a-f0-9]{64}\z/D', $expectedBinarySha256) === 1
            && \is_string($actualBinarySha256)
            && \hash_equals($expectedBinarySha256, \strtolower($actualBinarySha256));
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readManifest(): ?array
    {
        $file = $this->paths->manifestFile();
        if (!\is_file($file)) {
            return null;
        }
        $decoded = \json_decode((string)\file_get_contents($file), true);
        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string,mixed> $manifest
     */
    private function writeManifest(array $manifest): void
    {
        $root = $this->paths->installRoot();
        if (!\is_dir($root) && !@\mkdir($root, 0755, true) && !\is_dir($root)) {
            throw new \RuntimeException('Unable to create nginx install root: ' . $root);
        }
        $this->writeManifestFile($this->paths->manifestFile(), $manifest);
    }

    /**
     * @param array<string,mixed> $manifest
     */
    private function writeManifestFile(string $file, array $manifest): void
    {
        $json = \json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        if (\file_put_contents($file, $json, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write managed nginx install manifest.');
        }
    }

    /**
     * @return array{ok:bool,message:string,manifest?:array<string,mixed>,platform?:string}
     */
    private function installUnixFromSource(bool $force): array
    {
        $preflight = $this->unixPreflight();
        if (!($preflight['ok'] ?? false)) {
            return [
                'ok' => false,
                'message' => (string)$preflight['message'],
                'platform' => \PHP_OS_FAMILY,
            ];
        }

        $cacheDir = $this->paths->projectRoot() . DIRECTORY_SEPARATOR . 'var'
            . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'nginx-build';
        if (!\is_dir($cacheDir) && !@\mkdir($cacheDir, 0755, true) && !\is_dir($cacheDir)) {
            return ['ok' => false, 'message' => 'unable to create build cache: ' . $cacheDir, 'platform' => \PHP_OS_FAMILY];
        }
        $tarball = $cacheDir . DIRECTORY_SEPARATOR . 'nginx-' . self::VERSION . '.tar.gz';
        try {
            $this->downloadFile(self::SOURCE_URL, $tarball);
            $this->assertSha256($tarball, self::SOURCE_SHA256);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'platform' => \PHP_OS_FAMILY];
        }

        $srcDir = $cacheDir . DIRECTORY_SEPARATOR . 'nginx-' . self::VERSION;
        if ($force && \is_dir($srcDir)) {
            $this->removeTree($srcDir);
        }
        if (!\is_dir($srcDir)) {
            $cmd = 'tar -xzf ' . \escapeshellarg($tarball) . ' -C ' . \escapeshellarg($cacheDir);
            $out = [];
            $code = 0;
            @\exec($cmd . ' 2>&1', $out, $code);
            if ($code !== 0 || !\is_dir($srcDir)) {
                return [
                    'ok' => false,
                    'message' => 'tar extract failed: ' . \trim(\implode("\n", $out)),
                    'platform' => \PHP_OS_FAMILY,
                ];
            }
        }

        $prefix = $this->paths->installRoot();
        if ($force && \is_dir($prefix)) {
            // Keep other extend/server siblings; only wipe nginx prefix on force.
            $this->removeTree($prefix);
        }
        if (!\is_dir($prefix) && !@\mkdir($prefix, 0755, true) && !\is_dir($prefix)) {
            return ['ok' => false, 'message' => 'unable to create install prefix: ' . $prefix, 'platform' => \PHP_OS_FAMILY];
        }

        $deps = $this->resolveUnixBuildFlags();
        if (!$deps['has_pcre']) {
            return [
                'ok' => false,
                'message' => 'managed nginx build refused: PCRE and the HTTP rewrite module are required.',
                'platform' => \PHP_OS_FAMILY,
            ];
        }
        $configure = './configure --prefix=' . \escapeshellarg($prefix)
            . ' --with-http_ssl_module --with-http_v2_module --with-http_v3_module'
            . ($deps['configure_extra'] !== '' ? ' ' . $deps['configure_extra'] : '');
        if ($deps['cc_opts'] !== []) {
            $configure .= ' --with-cc-opt=' . \escapeshellarg(\implode(' ', \array_values(\array_unique($deps['cc_opts']))));
        }
        if ($deps['ld_opts'] !== []) {
            $configure .= ' --with-ld-opt=' . \escapeshellarg(\implode(' ', \array_values(\array_unique($deps['ld_opts']))));
        }

        $jobs = $this->detectParallelJobs();
        $buildScript = 'cd ' . \escapeshellarg($srcDir)
            . ' && make clean >/dev/null 2>&1 || true'
            . ' && ' . $configure
            . ' && make -j' . $jobs
            . ' && make install';

        $out = [];
        $code = 0;
        @\exec($buildScript . ' 2>&1', $out, $code);
        if ($code !== 0 || !$this->paths->isInstalled()) {
            $hint = $this->unixFailureHint();
            return [
                'ok' => false,
                'message' => 'nginx build/install failed on ' . \PHP_OS_FAMILY . '/' . \php_uname('m')
                    . '. ' . $hint . ' Output: '
                    . $this->tailText(\trim(\implode("\n", $out)), 4000),
                'platform' => \PHP_OS_FAMILY,
            ];
        }

        @\chmod($this->paths->binary(), 0755);
        $binarySha256 = \hash_file('sha256', $this->paths->binary());
        if (!\is_string($binarySha256)) {
            return ['ok' => false, 'message' => 'unable to hash installed nginx binary', 'platform' => \PHP_OS_FAMILY];
        }
        $binaryArchitecture = $this->binaryArchitecture($this->paths->binary());
        if ($binaryArchitecture === '') {
            return ['ok' => false, 'message' => 'unable to identify installed nginx binary architecture', 'platform' => \PHP_OS_FAMILY];
        }
        $manifest = [
            'version' => self::VERSION,
            'source_url' => self::SOURCE_URL,
            'artifact_sha256' => self::SOURCE_SHA256,
            'source_sha256' => self::SOURCE_SHA256,
            'platform' => \PHP_OS_FAMILY,
            'arch' => $binaryArchitecture,
            'php_process_arch' => $this->normalizeArchitecture((string)\php_uname('m')),
            'prefix' => $prefix,
            'binary' => $this->paths->binary(),
            'binary_sha256' => $binarySha256,
            'build_flags' => [
                'http_v2_module' => true,
                'http_v3_module' => true,
                'has_pcre' => $deps['has_pcre'],
                'has_openssl' => $deps['has_openssl'],
                'has_zlib' => $deps['has_zlib'],
                'without_rewrite' => false,
                'without_gzip' => !$deps['has_zlib'],
            ],
            'installed_at' => \date('c'),
        ];
        $this->writeManifest($manifest);
        return [
            'ok' => true,
            'message' => 'managed nginx installed from source (' . \PHP_OS_FAMILY . '/' . \php_uname('m') . ')',
            'manifest' => $manifest,
            'platform' => \PHP_OS_FAMILY,
        ];
    }

    /**
     * @return array{ok:bool,message:string}
     */
    private function unixPreflight(): array
    {
        $missing = [];
        foreach (['tar', 'make'] as $bin) {
            if (!$this->commandExists($bin)) {
                $missing[] = $bin;
            }
        }
        $cc = $this->detectCc();
        if ($cc === null) {
            $missing[] = 'cc/clang/gcc';
        }
        if ($missing !== []) {
            return [
                'ok' => false,
                'message' => 'missing build tools: ' . \implode(', ', $missing) . '. ' . $this->unixFailureHint(),
            ];
        }
        if (!$this->hasPcreHeaders([])) {
            return [
                'ok' => false,
                'message' => 'managed nginx requires PCRE development headers because the isolated config uses '
                    . 'the HTTP rewrite module. ' . $this->unixFailureHint(),
            ];
        }

        return ['ok' => true, 'message' => 'ok'];
    }

    /**
     * @return array{
     *   cc_opts:list<string>,
     *   ld_opts:list<string>,
     *   configure_extra:string,
     *   has_pcre:bool,
     *   has_openssl:bool,
     *   has_zlib:bool
     * }
     */
    private function resolveUnixBuildFlags(): array
    {
        $ccOpts = ['-Wno-error'];
        // Apple Clang exposes this warning switch; GCC rejects the switch itself.
        if (\PHP_OS_FAMILY === 'Darwin') {
            $ccOpts[] = '-Wno-error=unterminated-string-initialization';
        }
        $ldOpts = [];
        $includeDirs = [];
        $libDirs = [];

        if (\PHP_OS_FAMILY === 'Darwin') {
            foreach (['openssl@3', 'openssl', 'pcre2', 'pcre', 'zlib'] as $brewPkg) {
                $brewPrefix = $this->brewPrefix($brewPkg);
                if ($brewPrefix === null) {
                    continue;
                }
                if (\is_dir($brewPrefix . '/include')) {
                    $includeDirs[] = $brewPrefix . '/include';
                }
                if (\is_dir($brewPrefix . '/lib')) {
                    $libDirs[] = $brewPrefix . '/lib';
                }
            }
        }

        if (\PHP_OS_FAMILY === 'Linux') {
            foreach ([
                '/usr',
                '/usr/local',
                '/usr/local/opt/openssl',
                '/usr/local/opt/openssl@3',
                '/opt/homebrew/opt/openssl@3',
            ] as $root) {
                if (\is_dir($root . '/include')) {
                    $includeDirs[] = $root . '/include';
                }
                if (\is_dir($root . '/lib') || \is_dir($root . '/lib64')) {
                    if (\is_dir($root . '/lib')) {
                        $libDirs[] = $root . '/lib';
                    }
                    if (\is_dir($root . '/lib64')) {
                        $libDirs[] = $root . '/lib64';
                    }
                }
            }
            foreach (['openssl', 'libssl', 'libpcre', 'libpcre2-8', 'zlib'] as $pkg) {
                $cflags = \trim((string)@\shell_exec('pkg-config --cflags-only-I ' . \escapeshellarg($pkg) . ' 2>/dev/null'));
                $libs = \trim((string)@\shell_exec('pkg-config --libs-only-L ' . \escapeshellarg($pkg) . ' 2>/dev/null'));
                if ($cflags !== '') {
                    foreach (\preg_split('/\s+/', $cflags) ?: [] as $flag) {
                        if (\str_starts_with($flag, '-I') && \strlen($flag) > 2) {
                            $includeDirs[] = \substr($flag, 2);
                        }
                    }
                }
                if ($libs !== '') {
                    foreach (\preg_split('/\s+/', $libs) ?: [] as $flag) {
                        if (\str_starts_with($flag, '-L') && \strlen($flag) > 2) {
                            $libDirs[] = \substr($flag, 2);
                        }
                    }
                }
            }
        }

        foreach (\array_unique($includeDirs) as $dir) {
            $ccOpts[] = '-I' . $dir;
        }
        foreach (\array_unique($libDirs) as $dir) {
            $ldOpts[] = '-L' . $dir;
        }

        $hasOpenssl = $this->hasOpensslHeaders($includeDirs);
        $hasPcre = $this->hasPcreHeaders($includeDirs);
        $hasZlib = $this->hasZlibHeaders($includeDirs);

        $configureOptions = [];
        if (!$hasZlib) {
            $configureOptions[] = '--without-http_gzip_module';
        }

        return [
            'cc_opts' => $ccOpts,
            'ld_opts' => $ldOpts,
            'configure_extra' => \implode(' ', $configureOptions),
            'has_pcre' => $hasPcre,
            'has_openssl' => $hasOpenssl,
            'has_zlib' => $hasZlib,
        ];
    }

    /**
     * @param list<string> $includeDirs
     */
    private function hasOpensslHeaders(array $includeDirs): bool
    {
        foreach ($includeDirs as $dir) {
            if (\is_file($dir . '/openssl/ssl.h')) {
                return true;
            }
        }
        return \is_file('/usr/include/openssl/ssl.h')
            || \is_file('/usr/local/include/openssl/ssl.h')
            || $this->pkgExists('openssl')
            || $this->pkgExists('libssl');
    }

    /**
     * @param list<string> $includeDirs
     */
    private function hasPcreHeaders(array $includeDirs): bool
    {
        foreach ($includeDirs as $dir) {
            if (\is_file($dir . '/pcre.h') || \is_file($dir . '/pcre2.h')) {
                return true;
            }
        }
        return \is_file('/usr/include/pcre.h')
            || \is_file('/usr/include/pcre2.h')
            || \is_file('/usr/local/include/pcre.h')
            || $this->pkgExists('libpcre')
            || $this->pkgExists('libpcre2-8')
            || $this->brewPrefix('pcre') !== null
            || $this->brewPrefix('pcre2') !== null;
    }

    /**
     * @param list<string> $includeDirs
     */
    private function hasZlibHeaders(array $includeDirs): bool
    {
        foreach ($includeDirs as $dir) {
            if (\is_file($dir . '/zlib.h')) {
                return true;
            }
        }
        return \is_file('/usr/include/zlib.h')
            || \is_file('/usr/local/include/zlib.h')
            || $this->macOsSdkHeaderExists('zlib.h')
            || $this->pkgExists('zlib')
            || $this->brewPrefix('zlib') !== null;
    }

    private function macOsSdkHeaderExists(string $header): bool
    {
        if (\PHP_OS_FAMILY !== 'Darwin' || !$this->commandExists('xcrun')) {
            return false;
        }
        $output = [];
        $code = 0;
        @\exec('xcrun --show-sdk-path 2>/dev/null', $output, $code);
        $sdk = $code === 0 ? \trim((string)($output[0] ?? '')) : '';

        return $sdk !== '' && \is_file($sdk . '/usr/include/' . \ltrim($header, '/'));
    }

    private function unixFailureHint(): string
    {
        return match (\PHP_OS_FAMILY) {
            'Darwin' => 'Install Xcode CLT and Homebrew deps: brew install openssl@3 pcre2',
            'Linux' => 'Install build tools and headers, e.g. apt: build-essential libssl-dev libpcre3-dev zlib1g-dev'
                . ' | dnf/yum: gcc make openssl-devel pcre-devel zlib-devel'
                . ' | apk: build-base openssl-dev pcre-dev zlib-dev',
            default => 'Install a C toolchain plus OpenSSL and PCRE development headers.',
        };
    }

    /**
     * @return array{ok:bool,message:string,manifest?:array<string,mixed>,platform?:string}
     */
    private function installWindows(bool $force): array
    {
        if (!$this->commandExists('powershell') || !\function_exists('iconv')) {
            return [
                'ok' => false,
                'message' => 'Windows managed nginx lifecycle requires powershell.exe and the PHP iconv extension',
                'platform' => 'Windows',
            ];
        }
        if (!\class_exists(\ZipArchive::class) && !$this->commandExists('powershell') && !$this->commandExists('tar')) {
            return [
                'ok' => false,
                'message' => 'Windows managed nginx install requires PHP ZipArchive, or PowerShell, or tar',
                'platform' => 'Windows',
            ];
        }

        $prefix = $this->paths->installRoot();
        $localRoot = \dirname($prefix);
        $cacheDir = $localRoot . DIRECTORY_SEPARATOR . 'installer-cache';
        if (!\is_dir($cacheDir) && !@\mkdir($cacheDir, 0755, true) && !\is_dir($cacheDir)) {
            return ['ok' => false, 'message' => 'unable to create local installer cache: ' . $cacheDir, 'platform' => 'Windows'];
        }
        if (\is_dir($prefix) && !$force) {
            return [
                'ok' => false,
                'message' => 'incomplete Windows nginx install root exists; rerun with --force after confirming it is not running',
                'platform' => 'Windows',
            ];
        }

        $zip = $cacheDir . DIRECTORY_SEPARATOR . 'nginx-' . self::VERSION . '.zip';
        $extractDir = '';
        $candidate = '';
        try {
            $this->downloadFile(self::WINDOWS_ZIP_URL, $zip);
            $this->assertSha256($zip, self::WINDOWS_ZIP_SHA256);

            $token = \bin2hex(\random_bytes(8));
            $extractDir = $cacheDir . DIRECTORY_SEPARATOR . 'win-extract-' . $token;
            $candidate = $localRoot . DIRECTORY_SEPARATOR . 'install-candidate-' . $token;
            $this->resetDirectory($extractDir);
            $this->extractZip($zip, $extractDir);

            $nested = $extractDir . DIRECTORY_SEPARATOR . 'nginx-' . self::VERSION;
            if (!\is_dir($nested)) {
                // Some extractions nest differently; pick first directory containing nginx.exe.
                $nested = $this->findWindowsNginxRoot($extractDir) ?? $extractDir;
            }

            $this->resetDirectory($candidate);
            $this->copyTree($nested, $candidate);
            $candidateBinary = $candidate . DIRECTORY_SEPARATOR . 'nginx.exe';
            if (!\is_file($candidateBinary)) {
                throw new \RuntimeException('windows nginx.exe missing from validated install candidate');
            }
            $binarySha256 = \hash_file('sha256', $candidateBinary);
            if (!\is_string($binarySha256)) {
                throw new \RuntimeException('unable to hash Windows nginx install candidate');
            }
            $binaryArchitecture = $this->binaryArchitecture($candidateBinary);
            if ($binaryArchitecture === '') {
                throw new \RuntimeException('unable to identify Windows nginx install candidate architecture');
            }
            $manifest = [
                'version' => self::VERSION,
                'source_url' => self::WINDOWS_ZIP_URL,
                'artifact_sha256' => self::WINDOWS_ZIP_SHA256,
                'source_sha256' => self::WINDOWS_ZIP_SHA256,
                'platform' => 'Windows',
                'arch' => $binaryArchitecture,
                'php_process_arch' => $this->normalizeArchitecture((string)\php_uname('m')),
                'prefix' => $prefix,
                'binary' => $prefix . DIRECTORY_SEPARATOR . 'nginx.exe',
                'binary_sha256' => $binarySha256,
                'build_flags' => [
                    'http_v2_module' => true,
                    'http_v3_module' => false,
                    'http_v3_reason' => 'ngx_http_v3_module is not supported on Win32',
                ],
                'installed_at' => \date('c'),
                'note' => 'Official nginx.org Windows zip is typically x86/x64; on ARM Windows use x64 PHP emulation if needed.',
            ];
            $this->writeManifestFile(
                $candidate . DIRECTORY_SEPARATOR . \basename($this->paths->manifestFile()),
                $manifest,
            );
            $this->publishWindowsCandidate($candidate, $prefix);

            return [
                'ok' => true,
                'message' => 'managed nginx installed from windows zip',
                'manifest' => $manifest,
                'platform' => 'Windows',
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'platform' => 'Windows'];
        } finally {
            if ($extractDir !== '') {
                $this->removeTree($extractDir);
            }
            if ($candidate !== '') {
                $this->removeTree($candidate);
            }
        }
    }

    private function extractZip(string $zip, string $destination): void
    {
        $failures = [];
        if (\class_exists(\ZipArchive::class)) {
            $zipArchive = new \ZipArchive();
            $opened = $zipArchive->open($zip);
            if ($opened === true && $zipArchive->extractTo($destination)) {
                $zipArchive->close();
                return;
            }
            if ($opened === true) {
                $status = \method_exists($zipArchive, 'getStatusString')
                    ? $zipArchive->getStatusString()
                    : 'status=' . (string)$zipArchive->status . ', statusSys=' . (string)$zipArchive->statusSys;
                $zipArchive->close();
                $failures[] = 'ZipArchive extract failed (' . $status . ')';
            } else {
                $failures[] = 'ZipArchive open failed (code=' . (string)$opened . ')';
            }
            $this->resetDirectory($destination);
        }
        if ($this->commandExists('tar')) {
            $cmd = 'tar -xf ' . \escapeshellarg($zip) . ' -C ' . \escapeshellarg($destination);
            $out = [];
            $code = 0;
            @\exec($cmd . ' 2>&1', $out, $code);
            if ($code === 0) {
                return;
            }
            $failures[] = 'tar failed: ' . \trim(\implode("\n", $out));
            $this->resetDirectory($destination);
        }
        if ($this->commandExists('powershell') || $this->commandExists('powershell.exe')) {
            $script = 'Expand-Archive -LiteralPath '
                . $this->powerShellLiteral($zip)
                . ' -DestinationPath '
                . $this->powerShellLiteral($destination)
                . ' -Force';
            $encoded = \iconv('UTF-8', 'UTF-16LE', $script);
            if (!\is_string($encoded)) {
                throw new \RuntimeException('unable to encode PowerShell Expand-Archive command');
            }
            $ps = 'powershell -NoProfile -NonInteractive -EncodedCommand ' . \base64_encode($encoded);
            $out = [];
            $code = 0;
            @\exec($ps . ' 2>&1', $out, $code);
            if ($code === 0) {
                return;
            }
            $failures[] = 'Expand-Archive failed: ' . \trim(\implode("\n", $out));
        }
        throw new \RuntimeException(
            $failures === []
                ? 'no zip extractor available'
                : 'unable to extract windows nginx zip: ' . \implode(' | ', $failures),
        );
    }

    private function powerShellLiteral(string $value): string
    {
        return "'" . \str_replace("'", "''", $value) . "'";
    }

    private function resetDirectory(string $dir): void
    {
        if (\is_dir($dir)) {
            $this->removeTree($dir);
        }
        if (\is_dir($dir) || (!@\mkdir($dir, 0755, true) && !\is_dir($dir))) {
            throw new \RuntimeException('unable to prepare local nginx installer directory: ' . $dir);
        }
    }

    private function publishWindowsCandidate(string $candidate, string $prefix): void
    {
        $rollback = \dirname($prefix) . DIRECTORY_SEPARATOR . 'install-rollback-' . \bin2hex(\random_bytes(8));
        $hadPrevious = \is_dir($prefix);
        if ($hadPrevious && !@\rename($prefix, $rollback)) {
            throw new \RuntimeException('unable to stage existing Windows nginx install for rollback');
        }

        try {
            if (!@\rename($candidate, $prefix)) {
                throw new \RuntimeException('unable to atomically publish Windows nginx install candidate');
            }
            if (!$this->paths->isInstalled() || !$this->manifestMatches()) {
                throw new \RuntimeException('published Windows nginx install failed binary/manifest validation');
            }
        } catch (\Throwable $e) {
            if (\is_dir($prefix)) {
                $this->removeTree($prefix);
            }
            if (\is_dir($prefix)) {
                throw new \RuntimeException(
                    'Windows nginx install failed and the invalid candidate could not be removed'
                    . ($hadPrevious ? '; rollback remains at ' . $rollback : '')
                    . ': '
                    . $e->getMessage(),
                    0,
                    $e,
                );
            }
            if ($hadPrevious && !@\rename($rollback, $prefix)) {
                throw new \RuntimeException(
                    'Windows nginx install failed and rollback restoration failed; previous install remains at '
                    . $rollback
                    . ': '
                    . $e->getMessage(),
                    0,
                    $e,
                );
            }
            throw $e;
        }

        if ($hadPrevious) {
            $this->removeTree($rollback);
            if (\is_dir($rollback)) {
                throw new \RuntimeException('Windows nginx install succeeded but rollback cleanup failed: ' . $rollback);
            }
        }
    }

    private function findWindowsNginxRoot(string $root): ?string
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isFile() && \strtolower($item->getFilename()) === 'nginx.exe') {
                return $item->getPath();
            }
        }
        return null;
    }

    private function downloadFile(string $url, string $destination): void
    {
        if (\is_file($destination) && \filesize($destination) > 1000) {
            return;
        }
        $tmp = $destination . '.part';
        @\unlink($tmp);

        if ($this->commandExists('curl')) {
            $cmd = 'curl -fsSL --connect-timeout 30 --max-time 300 -o '
                . \escapeshellarg($tmp) . ' ' . \escapeshellarg($url);
            $out = [];
            $code = 0;
            @\exec($cmd . ' 2>&1', $out, $code);
            if ($code === 0 && \is_file($tmp) && \filesize($tmp) > 1000) {
                @\rename($tmp, $destination);
                return;
            }
        }

        if (\PHP_OS_FAMILY === 'Windows' && ($this->commandExists('powershell') || $this->commandExists('powershell.exe'))) {
            $ps = 'powershell -NoProfile -Command "Invoke-WebRequest -UseBasicParsing -Uri \''
                . \str_replace("'", "''", $url)
                . '\' -OutFile \''
                . \str_replace("'", "''", $tmp)
                . '\'"';
            $out = [];
            $code = 0;
            @\exec($ps . ' 2>&1', $out, $code);
            if ($code === 0 && \is_file($tmp) && \filesize($tmp) > 1000) {
                @\rename($tmp, $destination);
                return;
            }
        }

        $ctx = \stream_context_create([
            'http' => [
                'timeout' => 300,
                'follow_location' => 1,
                'header' => "User-Agent: WelineFramework-ManagedNginx/1.0\r\n",
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $data = @\file_get_contents($url, false, $ctx);
        if ($data === false || $data === '') {
            throw new \RuntimeException('download failed: ' . $url);
        }
        if (\file_put_contents($tmp, $data) === false) {
            throw new \RuntimeException('unable to write download: ' . $destination);
        }
        @\rename($tmp, $destination);
    }

    private function assertSha256(string $file, string $expected): void
    {
        $actual = \hash_file('sha256', $file);
        if (!\is_string($actual) || !\hash_equals(\strtolower($expected), \strtolower($actual))) {
            @\unlink($file);
            throw new \RuntimeException('SHA-256 mismatch for ' . $file);
        }
    }

    private function detectCc(): ?string
    {
        foreach (['clang', 'gcc', 'cc'] as $bin) {
            if ($this->commandExists($bin)) {
                return $bin;
            }
        }
        return null;
    }

    private function detectParallelJobs(): int
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $n = (int)\trim((string)@\shell_exec('echo %NUMBER_OF_PROCESSORS%'));
            return \max(1, $n > 0 ? $n : 2);
        }
        if (\is_readable('/proc/cpuinfo')) {
            return \max(1, \substr_count((string)@\file_get_contents('/proc/cpuinfo'), 'processor'));
        }
        if (\PHP_OS_FAMILY === 'Darwin') {
            return \max(1, (int)\trim((string)@\shell_exec('sysctl -n hw.ncpu 2>/dev/null')));
        }
        $nproc = (int)\trim((string)@\shell_exec('nproc 2>/dev/null'));
        return \max(1, $nproc > 0 ? $nproc : 2);
    }

    private function binaryArchitecture(string $binary): string
    {
        if (!\is_file($binary)) {
            return '';
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            $handle = @\fopen($binary, 'rb');
            if (!\is_resource($handle)) {
                return '';
            }
            $header = @\fread($handle, 64);
            if (!\is_string($header) || \strlen($header) < 64 || \substr($header, 0, 2) !== 'MZ') {
                @\fclose($handle);
                return '';
            }
            $offset = \unpack('Voffset', \substr($header, 0x3c, 4));
            $peOffset = (int)($offset['offset'] ?? 0);
            if ($peOffset <= 0 || @\fseek($handle, $peOffset) !== 0) {
                @\fclose($handle);
                return '';
            }
            $pe = @\fread($handle, 6);
            @\fclose($handle);
            if (!\is_string($pe) || \strlen($pe) < 6 || \substr($pe, 0, 4) !== "PE\0\0") {
                return '';
            }
            $machine = \unpack('vmachine', \substr($pe, 4, 2));
            return match ((int)($machine['machine'] ?? 0)) {
                0x8664 => 'x86_64',
                0xaa64 => 'arm64',
                0x014c => 'x86',
                default => '',
            };
        }
        $header = @\file_get_contents($binary, false, null, 0, 32);
        if (\is_string($header) && \strlen($header) >= 20) {
            if (\substr($header, 0, 4) === "\x7fELF") {
                $format = \ord($header[5]) === 2 ? 'n' : 'v';
                $machine = \unpack($format . 'machine', \substr($header, 18, 2));
                $detected = match ((int)($machine['machine'] ?? 0)) {
                    62 => 'x86_64',
                    183 => 'arm64',
                    3 => 'x86',
                    default => '',
                };
                if ($detected !== '') {
                    return $detected;
                }
            }
            $magic = \bin2hex(\substr($header, 0, 4));
            if ($magic === 'cffaedfe' || $magic === 'cefaedfe') {
                $cpu = \unpack('Vcpu', \substr($header, 4, 4));
                $detected = match ((int)($cpu['cpu'] ?? 0)) {
                    0x0100000c => 'arm64',
                    0x01000007 => 'x86_64',
                    7 => 'x86',
                    default => '',
                };
                if ($detected !== '') {
                    return $detected;
                }
            }
        }
        $output = [];
        $code = 0;
        @\exec('file -b ' . \escapeshellarg($binary) . ' 2>/dev/null', $output, $code);
        if ($code !== 0) {
            return '';
        }
        $description = \strtolower(\implode(' ', $output));
        return match (true) {
            \str_contains($description, 'arm64'), \str_contains($description, 'aarch64') => 'arm64',
            \str_contains($description, 'x86-64'), \str_contains($description, 'x86_64') => 'x86_64',
            \str_contains($description, '80386'), \str_contains($description, 'i386') => 'x86',
            default => '',
        };
    }

    private function normalizeArchitecture(string $architecture): string
    {
        return match (\strtolower(\trim($architecture))) {
            'amd64', 'x86_64', 'x64' => 'x86_64',
            'arm64', 'aarch64' => 'arm64',
            'x86', 'i386', 'i686' => 'x86',
            default => \strtolower(\trim($architecture)),
        };
    }

    private function commandExists(string $name): bool
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $out = [];
            @\exec('where ' . \escapeshellarg($name) . ' 2>NUL', $out, $code);
            return $code === 0 && isset($out[0]) && $out[0] !== '';
        }
        $out = [];
        @\exec('command -v ' . \escapeshellarg($name) . ' 2>/dev/null', $out, $code);
        return $code === 0 && isset($out[0]) && $out[0] !== '';
    }

    private function brewPrefix(string $name): ?string
    {
        if (\PHP_OS_FAMILY !== 'Darwin' || !$this->commandExists('brew')) {
            return null;
        }
        $out = [];
        @\exec('brew --prefix ' . \escapeshellarg($name) . ' 2>/dev/null', $out, $code);
        $prefix = isset($out[0]) ? \trim($out[0]) : '';
        return ($code === 0 && $prefix !== '' && \is_dir($prefix)) ? $prefix : null;
    }

    private function pkgExists(string $name): bool
    {
        if (!$this->commandExists('pkg-config')) {
            return false;
        }
        $out = [];
        @\exec('pkg-config --exists ' . \escapeshellarg($name) . ' 2>/dev/null', $out, $code);
        return $code === 0;
    }

    private function tailText(string $text, int $max): string
    {
        if (\function_exists('mb_substr')) {
            return \mb_substr($text, -$max);
        }
        return \substr($text, -$max);
    }

    private function removeTree(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $path = $item->getPathname();
            if ($item->isDir()) {
                @\rmdir($path);
            } else {
                @\unlink($path);
            }
        }
        @\rmdir($dir);
    }

    private function copyTree(string $src, string $dst): void
    {
        $src = \rtrim($src, '/\\');
        $dst = \rtrim($dst, '/\\');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $target = $dst . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
            if ($item->isDir()) {
                if (!\is_dir($target)) {
                    if (!@\mkdir($target, 0755, true) && !\is_dir($target)) {
                        throw new \RuntimeException('unable to create nginx install directory: ' . $target);
                    }
                }
            } else {
                $parent = \dirname($target);
                if (!\is_dir($parent)) {
                    if (!@\mkdir($parent, 0755, true) && !\is_dir($parent)) {
                        throw new \RuntimeException('unable to create nginx install directory: ' . $parent);
                    }
                }
                if (!@\copy($item->getPathname(), $target)) {
                    throw new \RuntimeException('unable to copy managed nginx file: ' . $target);
                }
            }
        }
    }
}
