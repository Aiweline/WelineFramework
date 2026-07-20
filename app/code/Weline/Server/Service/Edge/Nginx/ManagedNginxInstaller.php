<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx;

/**
 * Downloads and installs a pinned nginx build into extend/server/nginx for this project.
 *
 * Platform matrix:
 * - Darwin/Linux: build from official source tarball into project prefix
 * - Windows: extract official nginx.zip (nginx.exe at install root)
 *
 * Ordinary server:start with managed=true will call ensureInstalled when the
 * binary is missing (Darwin/Linux source build, Windows zip). Explicit
 * server:nginx:install / --install-nginx remains available for force reinstall.
 */
final class ManagedNginxInstaller
{
    public const VERSION = '1.26.3';

    public const SOURCE_URL = 'https://nginx.org/download/nginx-1.26.3.tar.gz';

    public const SOURCE_SHA256 = '69ee2b237744036e61d24b836668aad3040dda461fe6f570f1787eab570c75aa';

    public const WINDOWS_ZIP_URL = 'https://nginx.org/download/nginx-1.26.3.zip';

    /** Official Windows zip SHA-256 (nginx.org release package). */
    public const WINDOWS_ZIP_SHA256 = '39ca13277b361910f9e463a7e958e11566f7ede8a6f0df08a21b659ca92f3662';

    public function __construct(private readonly ManagedNginxPaths $paths = new ManagedNginxPaths())
    {
    }

    /**
     * @return array{ok:bool,message:string,manifest?:array<string,mixed>,platform?:string}
     */
    public function ensureInstalled(bool $force = false): array
    {
        if (!$force && $this->paths->isInstalled() && $this->manifestMatches()) {
            return [
                'ok' => true,
                'message' => 'managed nginx already installed',
                'manifest' => $this->readManifest() ?? [],
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
        $expected = \PHP_OS_FAMILY === 'Windows' ? self::WINDOWS_ZIP_SHA256 : self::SOURCE_SHA256;
        $actual = (string)($manifest['artifact_sha256'] ?? $manifest['source_sha256'] ?? '');

        return $actual !== '' && \hash_equals(\strtolower($expected), \strtolower($actual));
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
        \file_put_contents(
            $this->paths->manifestFile(),
            \json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
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
        $configure = './configure --prefix=' . \escapeshellarg($prefix)
            . ' --with-http_ssl_module --with-http_v2_module'
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
        $manifest = [
            'version' => self::VERSION,
            'source_url' => self::SOURCE_URL,
            'artifact_sha256' => self::SOURCE_SHA256,
            'source_sha256' => self::SOURCE_SHA256,
            'platform' => \PHP_OS_FAMILY,
            'arch' => \php_uname('m'),
            'prefix' => $prefix,
            'binary' => $this->paths->binary(),
            'build_flags' => [
                'has_pcre' => $deps['has_pcre'],
                'has_openssl' => $deps['has_openssl'],
                'without_rewrite_gzip' => !$deps['has_pcre'],
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
        return ['ok' => true, 'message' => 'ok'];
    }

    /**
     * @return array{
     *   cc_opts:list<string>,
     *   ld_opts:list<string>,
     *   configure_extra:string,
     *   has_pcre:bool,
     *   has_openssl:bool
     * }
     */
    private function resolveUnixBuildFlags(): array
    {
        // Newer clang treats some nginx 1.26 string initializers as errors.
        $ccOpts = ['-Wno-error', '-Wno-error=unterminated-string-initialization'];
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

        $configureExtra = '';
        if (!$hasPcre) {
            // Edge reverse-proxy MVP can run without rewrite/gzip when PCRE is absent.
            $configureExtra = '--without-http_rewrite_module --without-http_gzip_module';
        }

        return [
            'cc_opts' => $ccOpts,
            'ld_opts' => $ldOpts,
            'configure_extra' => $configureExtra,
            'has_pcre' => $hasPcre,
            'has_openssl' => $hasOpenssl,
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

    private function unixFailureHint(): string
    {
        return match (\PHP_OS_FAMILY) {
            'Darwin' => 'Install Xcode CLT and Homebrew deps: brew install openssl@3 pcre2',
            'Linux' => 'Install build tools and headers, e.g. apt: build-essential libssl-dev libpcre3-dev zlib1g-dev'
                . ' | dnf/yum: gcc make openssl-devel pcre-devel zlib-devel'
                . ' | apk: build-base openssl-dev pcre-dev zlib-dev',
            default => 'Install a C toolchain, OpenSSL headers, and optionally PCRE.',
        };
    }

    /**
     * @return array{ok:bool,message:string,manifest?:array<string,mixed>,platform?:string}
     */
    private function installWindows(bool $force): array
    {
        if (!\class_exists(\ZipArchive::class) && !$this->commandExists('powershell') && !$this->commandExists('tar')) {
            return [
                'ok' => false,
                'message' => 'Windows managed nginx install requires PHP ZipArchive, or PowerShell, or tar',
                'platform' => 'Windows',
            ];
        }

        $cacheDir = $this->paths->projectRoot() . DIRECTORY_SEPARATOR . 'var'
            . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'nginx-build';
        if (!\is_dir($cacheDir) && !@\mkdir($cacheDir, 0755, true) && !\is_dir($cacheDir)) {
            return ['ok' => false, 'message' => 'unable to create build cache: ' . $cacheDir, 'platform' => 'Windows'];
        }
        $zip = $cacheDir . DIRECTORY_SEPARATOR . 'nginx-' . self::VERSION . '.zip';
        try {
            $this->downloadFile(self::WINDOWS_ZIP_URL, $zip);
            $this->assertSha256($zip, self::WINDOWS_ZIP_SHA256);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'platform' => 'Windows'];
        }

        $extractDir = $cacheDir . DIRECTORY_SEPARATOR . 'win-extract';
        if (\is_dir($extractDir)) {
            $this->removeTree($extractDir);
        }
        @\mkdir($extractDir, 0755, true);
        try {
            $this->extractZip($zip, $extractDir);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'platform' => 'Windows'];
        }

        $nested = $extractDir . DIRECTORY_SEPARATOR . 'nginx-' . self::VERSION;
        if (!\is_dir($nested)) {
            // Some extractions nest differently; pick first directory containing nginx.exe
            $nested = $this->findWindowsNginxRoot($extractDir) ?? $extractDir;
        }
        $prefix = $this->paths->installRoot();
        if (\is_dir($prefix) && $force) {
            $this->removeTree($prefix);
        }
        if (!\is_dir($prefix) && !@\mkdir($prefix, 0755, true) && !\is_dir($prefix)) {
            return ['ok' => false, 'message' => 'unable to create install prefix', 'platform' => 'Windows'];
        }
        $this->copyTree($nested, $prefix);
        if (!$this->paths->isInstalled()) {
            return [
                'ok' => false,
                'message' => 'windows nginx.exe missing after extract under ' . $prefix,
                'platform' => 'Windows',
            ];
        }
        $manifest = [
            'version' => self::VERSION,
            'source_url' => self::WINDOWS_ZIP_URL,
            'artifact_sha256' => self::WINDOWS_ZIP_SHA256,
            'source_sha256' => self::WINDOWS_ZIP_SHA256,
            'platform' => 'Windows',
            'arch' => \php_uname('m'),
            'prefix' => $prefix,
            'binary' => $this->paths->binary(),
            'installed_at' => \date('c'),
            'note' => 'Official nginx.org Windows zip is typically x86/x64; on ARM Windows use x64 PHP emulation if needed.',
        ];
        $this->writeManifest($manifest);
        return [
            'ok' => true,
            'message' => 'managed nginx installed from windows zip',
            'manifest' => $manifest,
            'platform' => 'Windows',
        ];
    }

    private function extractZip(string $zip, string $destination): void
    {
        if (\class_exists(\ZipArchive::class)) {
            $zipArchive = new \ZipArchive();
            if ($zipArchive->open($zip) !== true) {
                throw new \RuntimeException('unable to open windows nginx zip');
            }
            if (!$zipArchive->extractTo($destination)) {
                $zipArchive->close();
                throw new \RuntimeException('unable to extract windows nginx zip via ZipArchive');
            }
            $zipArchive->close();
            return;
        }
        if ($this->commandExists('tar')) {
            $cmd = 'tar -xf ' . \escapeshellarg($zip) . ' -C ' . \escapeshellarg($destination);
            $out = [];
            $code = 0;
            @\exec($cmd . ' 2>&1', $out, $code);
            if ($code === 0) {
                return;
            }
        }
        if ($this->commandExists('powershell') || $this->commandExists('powershell.exe')) {
            $ps = 'powershell -NoProfile -Command "Expand-Archive -LiteralPath '
                . \str_replace('"', '""', $zip)
                . ' -DestinationPath '
                . \str_replace('"', '""', $destination)
                . ' -Force"';
            $out = [];
            $code = 0;
            @\exec($ps . ' 2>&1', $out, $code);
            if ($code === 0) {
                return;
            }
            throw new \RuntimeException('Expand-Archive failed: ' . \trim(\implode("\n", $out)));
        }
        throw new \RuntimeException('no zip extractor available');
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
                    @\mkdir($target, 0755, true);
                }
            } else {
                $parent = \dirname($target);
                if (!\is_dir($parent)) {
                    @\mkdir($parent, 0755, true);
                }
                @\copy($item->getPathname(), $target);
            }
        }
    }
}
