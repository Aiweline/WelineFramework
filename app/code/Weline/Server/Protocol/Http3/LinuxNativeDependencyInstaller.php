<?php

declare(strict_types=1);

namespace Weline\Server\Protocol\Http3;

/**
 * Builds an immutable, private PIC-static QUIC toolchain on Linux.
 *
 * Static linkage keeps the OpenSSL 3.5 QUIC API isolated from the system
 * OpenSSL already loaded by PHP. Package managers install build tools only.
 */
final class LinuxNativeDependencyInstaller
{
    private const SCHEMA = 1;
    private const RECIPE = 'wls-linux-h3-pic-static-v2';
    private const BUILD_TIMEOUT = 1800;
    private const DOWNLOAD_TIMEOUT = 600;
    private const MAX_OUTPUT_BYTES = 1048576;

    /** @var array<string,array{version:string,archive:string,directory:string,size:int,sha256:string,urls:list<string>}> */
    private const SOURCES = [
        'openssl' => [
            'version' => '3.5.7',
            'archive' => 'openssl-3.5.7.tar.gz',
            'directory' => 'openssl-3.5.7',
            'size' => 53153930,
            'sha256' => 'a8c0d28a529ca480f9f36cf5792e2cd21984552a3c8e4aa11a24aa31aeac98e8',
            'urls' => [
                'https://api.github.com/repos/openssl/openssl/releases/assets/442677812',
                'https://www.openssl.org/source/openssl-3.5.7.tar.gz',
                'https://github.com/openssl/openssl/releases/download/openssl-3.5.7/openssl-3.5.7.tar.gz',
            ],
        ],
        'nghttp3' => [
            'version' => '1.16.0',
            'archive' => 'nghttp3-1.16.0.tar.xz',
            'directory' => 'nghttp3-1.16.0',
            'size' => 413004,
            'sha256' => '776f59a99905c9a348846807b2e5ac9bb3485fc0f8c0250ba803018d5238a16e',
            'urls' => [
                'https://api.github.com/repos/ngtcp2/nghttp3/releases/assets/434344871',
                'https://github.com/ngtcp2/nghttp3/releases/download/v1.16.0/nghttp3-1.16.0.tar.xz',
            ],
        ],
        'ngtcp2' => [
            'version' => '1.23.0',
            'archive' => 'ngtcp2-1.23.0.tar.xz',
            'directory' => 'ngtcp2-1.23.0',
            'size' => 708136,
            'sha256' => '59d5b4211e96970f2d3d5e6876f73dce03414800ba04aa56835b132fce8de730',
            'urls' => [
                'https://api.github.com/repos/ngtcp2/ngtcp2/releases/assets/434387551',
                'https://github.com/ngtcp2/ngtcp2/releases/download/v1.23.0/ngtcp2-1.23.0.tar.xz',
            ],
        ],
        'curl' => [
            'version' => '8.21.0',
            'archive' => 'curl-8.21.0.tar.xz',
            'directory' => 'curl-8.21.0',
            'size' => 2882336,
            'sha256' => 'aa1b66a70eace83dc624508745646c08ae561de512ab403adffb93ac87fc72e6',
            'urls' => [
                'https://api.github.com/repos/curl/curl/releases/assets/456334503',
                'https://curl.se/download/curl-8.21.0.tar.xz',
                'https://github.com/curl/curl/releases/download/curl-8_21_0/curl-8.21.0.tar.xz',
            ],
        ],
    ];

    private ?string $prefix = null;
    /** @var array<string,mixed>|null */
    private ?array $manifest = null;

    public function __construct(
        private readonly string $nativeRoot,
        private readonly NativeBuildProcessRunner $runner
    ) {
    }

    /** @return array<string,string> */
    public function environment(): array
    {
        if ($this->readyManifest() === []) {
            return [];
        }
        $prefix = $this->dependencyPrefix();
        return [
            'PATH' => $prefix . '/bin' . \PATH_SEPARATOR . (string)\getenv('PATH'),
            'PKG_CONFIG_PATH' => $this->pkgConfigPath(),
            'PKG_CONFIG_LIBDIR' => $this->pkgConfigPath(),
            'OPENSSL_CONF' => $prefix . '/ssl/openssl.cnf',
            'OPENSSL_MODULES' => $prefix . '/lib/ossl-modules',
        ];
    }

    /** @return array<string,mixed> */
    public function readyManifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }
        $prefix = $this->dependencyPrefix();
        $path = $prefix . '/manifest.json';
        $manifest = $this->readJson($path);
        if (($manifest['schema'] ?? null) !== self::SCHEMA
            || ($manifest['ready'] ?? false) !== true
            || ($manifest['recipe'] ?? '') !== self::RECIPE
            || ($manifest['fingerprint'] ?? '') !== $this->fingerprint()
            || ($manifest['prefix'] ?? '') !== $prefix
            || ($manifest['sources'] ?? null) !== $this->sourceIdentity()
            || !$this->trustedPath($prefix, true)
            || !$this->trustedPath($path, false)
        ) {
            return $this->manifest = [];
        }
        foreach ([
            '/bin/curl',
            '/lib/libssl.a',
            '/lib/libcrypto.a',
            '/lib/libnghttp3.a',
            '/lib/libngtcp2.a',
            '/lib/libngtcp2_crypto_ossl.a',
            '/lib/pkgconfig/openssl.pc',
            '/lib/pkgconfig/libnghttp3.pc',
            '/lib/pkgconfig/libngtcp2.pc',
            '/lib/pkgconfig/libngtcp2_crypto_ossl.pc',
            '/ssl/openssl.cnf',
        ] as $relative) {
            if (!$this->trustedPath($prefix . $relative, false)) {
                return $this->manifest = [];
            }
        }
        if (!\is_executable($prefix . '/bin/curl')) {
            return $this->manifest = [];
        }
        $expectedCurlSha256 = (string)($manifest['http3_curl_sha256'] ?? '');
        $actualCurlSha256 = (string)@\hash_file('sha256', $prefix . '/bin/curl');
        if (\preg_match('/^[a-f0-9]{64}$/D', $expectedCurlSha256) !== 1
            || !\hash_equals($expectedCurlSha256, $actualCurlSha256)
        ) {
            return $this->manifest = [];
        }
        return $this->manifest = $manifest;
    }

    /**
     * @return array{success:bool,output:string,manifest:array<string,mixed>}
     */
    public function install(): array
    {
        $ready = $this->readyManifest();
        if ($ready !== []) {
            return ['success' => true, 'output' => 'Reused verified Linux HTTP/3 dependency bundle.', 'manifest' => $ready];
        }

        $tools = [
            'cc' => ['cc', 'gcc', 'clang'],
            'make' => ['make', 'gmake'],
            'perl' => ['perl'],
            'curl' => ['curl'],
            'tar' => ['tar'],
            'pkg-config' => ['pkg-config', 'pkgconf'],
        ];
        $executables = [];
        foreach ($tools as $id => $names) {
            $executable = $this->runner->findExecutable($names);
            if ($executable === null) {
                return ['success' => false, 'output' => 'Missing Linux native build tool: ' . $id, 'manifest' => []];
            }
            $executables[$id] = $executable;
        }

        $this->ensureDirectory($this->nativeRoot . '/downloads');
        $this->ensureDirectory($this->nativeRoot . '/build');
        $this->ensureDirectory($this->nativeRoot . '/deps');
        $prefix = $this->dependencyPrefix();
        if (\file_exists($prefix)) {
            if (!$this->trustedPath($prefix, true)) {
                return ['success' => false, 'output' => 'Refusing an untrusted incomplete HTTP/3 dependency prefix.', 'manifest' => []];
            }
            $quarantine = $prefix . '.incomplete.' . \date('YmdHis') . '.' . \bin2hex(\random_bytes(3));
            if (!@\rename($prefix, $quarantine)) {
                return ['success' => false, 'output' => 'Unable to quarantine an incomplete HTTP/3 dependency prefix.', 'manifest' => []];
            }
        }
        $this->ensureDirectory($prefix);

        $work = $this->nativeRoot . '/build/source-' . \getmypid() . '-' . \bin2hex(\random_bytes(6));
        $this->ensureDirectory($work);
        $output = '';
        try {
            $archives = [];
            foreach (self::SOURCES as $id => $source) {
                $download = $this->download($source, $executables['curl']);
                $output = $this->appendOutput($output, $download['output']);
                if (!$download['success']) {
                    throw new \RuntimeException('Unable to download verified ' . $id . ' source archive.');
                }
                $archives[$id] = $download['path'];
                $this->extract($download['path'], $source, $work, $executables['tar']);
            }

            $jobs = $this->buildJobs();
            $baseEnvironment = [
                'LC_ALL' => 'C',
                'LANG' => 'C',
                'TZ' => 'UTC',
                'SOURCE_DATE_EPOCH' => '1781006345',
                'CFLAGS' => '-O2 -fPIC',
            ];
            $openssl = $work . '/' . self::SOURCES['openssl']['directory'];
            $output = $this->runRequired([
                './Configure',
                '--prefix=' . $prefix,
                '--openssldir=' . $prefix . '/ssl',
                '--libdir=lib',
                'no-shared',
                'no-tests',
                'no-docs',
                '-O2',
                '-fPIC',
            ], $openssl, $baseEnvironment, $output);
            $output = $this->runRequired([$executables['make'], '-j' . $jobs, 'build_sw'], $openssl, $baseEnvironment, $output);
            $output = $this->runRequired([$executables['make'], 'install'], $openssl, $baseEnvironment, $output);

            $toolchainEnvironment = $baseEnvironment + [
                'CPPFLAGS' => '-I' . $prefix . '/include',
                'LDFLAGS' => '-L' . $prefix . '/lib',
                'PKG_CONFIG_PATH' => $this->pkgConfigPath(),
                'PKG_CONFIG_LIBDIR' => $this->pkgConfigPath(),
            ];
            $nghttp3 = $work . '/' . self::SOURCES['nghttp3']['directory'];
            $output = $this->runRequired([
                './configure',
                '--prefix=' . $prefix,
                '--libdir=' . $prefix . '/lib',
                '--enable-lib-only',
                '--disable-shared',
                '--enable-static',
                '--disable-dependency-tracking',
            ], $nghttp3, $toolchainEnvironment, $output);
            $output = $this->runRequired([$executables['make'], '-j' . $jobs], $nghttp3, $toolchainEnvironment, $output);
            $output = $this->runRequired([$executables['make'], 'install'], $nghttp3, $toolchainEnvironment, $output);

            $ngtcp2 = $work . '/' . self::SOURCES['ngtcp2']['directory'];
            $output = $this->runRequired([
                './configure',
                '--prefix=' . $prefix,
                '--libdir=' . $prefix . '/lib',
                '--enable-lib-only',
                '--disable-shared',
                '--enable-static',
                '--disable-dependency-tracking',
                '--with-openssl',
                '--without-gnutls',
                '--without-boringssl',
                '--without-picotls',
                '--without-wolfssl',
            ], $ngtcp2, $toolchainEnvironment, $output);
            $output = $this->runRequired([$executables['make'], '-j' . $jobs], $ngtcp2, $toolchainEnvironment, $output);
            $output = $this->runRequired([$executables['make'], 'install'], $ngtcp2, $toolchainEnvironment, $output);

            $curl = $work . '/' . self::SOURCES['curl']['directory'];
            $output = $this->runRequired([
                './configure',
                '--prefix=' . $prefix,
                '--with-openssl=' . $prefix,
                '--with-ngtcp2=' . $prefix,
                '--with-nghttp3=' . $prefix,
                '--disable-shared',
                '--enable-static',
                '--enable-ssls-export',
                '--disable-docs',
                '--disable-manual',
                '--disable-ldap',
                '--disable-ldaps',
                '--disable-rtsp',
                '--without-libpsl',
                '--without-zstd',
                '--without-brotli',
                '--without-libidn2',
                '--without-zlib',
            ], $curl, $toolchainEnvironment, $output);
            $output = $this->runRequired([$executables['make'], '-j' . $jobs], $curl, $toolchainEnvironment, $output);
            $output = $this->runRequired([$executables['make'], 'install'], $curl, $toolchainEnvironment, $output);

            $versions = $this->verifyInstalled($executables, $toolchainEnvironment);
            $manifest = [
                'schema' => self::SCHEMA,
                'ready' => true,
                'recipe' => self::RECIPE,
                'linkage' => 'pic-static',
                'fingerprint' => $this->fingerprint(),
                'prefix' => $prefix,
                'platform' => 'Linux',
                'architecture' => (string)\php_uname('m'),
                'libc' => $this->libcIdentity(),
                'sources' => $this->sourceIdentity(),
                'versions' => $versions,
                'http3_curl' => $prefix . '/bin/curl',
                'http3_curl_sha256' => (string)\hash_file('sha256', $prefix . '/bin/curl'),
                'built_at' => \date(\DATE_ATOM),
            ];
            $this->atomicJson($prefix . '/manifest.json', $manifest);
            $this->manifest = $manifest;
            return ['success' => true, 'output' => $output, 'manifest' => $manifest];
        } catch (\Throwable $exception) {
            $output = $this->appendOutput($output, "\n" . $exception->getMessage());
            $failed = $prefix . '.failed.' . \date('YmdHis') . '.' . \bin2hex(\random_bytes(3));
            if ($this->trustedPath($prefix, true)) {
                @\rename($prefix, $failed);
            }
            return ['success' => false, 'output' => $output, 'manifest' => []];
        } finally {
            $this->removeOwnedBuildDirectory($work);
        }
    }

    public function dependencyPrefix(): string
    {
        if ($this->prefix !== null) {
            return $this->prefix;
        }
        return $this->prefix = $this->nativeRoot . '/deps/linux-'
            . \strtolower((string)\preg_replace('/[^a-zA-Z0-9_.-]+/', '-', (string)\php_uname('m')))
            . '-' . \substr($this->fingerprint(), 0, 24);
    }

    private function fingerprint(): string
    {
        return \hash('sha256', \json_encode([
            'recipe' => self::RECIPE,
            'architecture' => (string)\php_uname('m'),
            'integer_size' => \PHP_INT_SIZE,
            'libc' => $this->libcIdentity(),
            'sources' => $this->sourceIdentity(),
        ], \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
    }

    /** @return array<string,array{version:string,size:int,sha256:string}> */
    private function sourceIdentity(): array
    {
        $identity = [];
        foreach (self::SOURCES as $id => $source) {
            $identity[$id] = [
                'version' => $source['version'],
                'size' => $source['size'],
                'sha256' => $source['sha256'],
            ];
        }
        return $identity;
    }

    private function libcIdentity(): string
    {
        $ldd = $this->runner->findExecutable(['ldd']);
        if ($ldd === null) {
            return 'unknown-libc';
        }
        $result = $this->runner->run([$ldd, '--version'], 10);
        $output = \trim($result['output']);
        return $output === '' ? 'unknown-libc' : \substr(\hash('sha256', $output), 0, 16);
    }

    private function pkgConfigPath(): string
    {
        return $this->dependencyPrefix() . '/lib/pkgconfig';
    }

    /**
     * @param array{version:string,archive:string,directory:string,size:int,sha256:string,urls:list<string>} $source
     * @return array{success:bool,path:string,output:string}
     */
    private function download(array $source, string $curl): array
    {
        $directory = $this->nativeRoot . '/downloads';
        $target = $directory . '/' . $source['sha256'] . '-' . $source['archive'];
        if ($this->verifiedArchive($target, $source)) {
            return ['success' => true, 'path' => $target, 'output' => 'Reused ' . $source['archive'] . ".\n"];
        }
        if (\file_exists($target)) {
            return ['success' => false, 'path' => '', 'output' => 'Cached source archive failed its pinned digest: ' . $target];
        }

        $output = '';
        foreach ($source['urls'] as $url) {
            $temporary = $target . '.part.' . \getmypid() . '.' . \bin2hex(\random_bytes(4));
            $result = $this->runner->run([
                $curl,
                '--fail',
                '--location',
                '--silent',
                '--show-error',
                '--proto',
                '=https',
                '--proto-redir',
                '=https',
                '--tlsv1.2',
                '--retry',
                '3',
                '--connect-timeout',
                '15',
                '--max-time',
                (string)self::DOWNLOAD_TIMEOUT,
                '--header',
                'Accept: application/octet-stream',
                '--output',
                $temporary,
                $url,
            ], self::DOWNLOAD_TIMEOUT + 30);
            $output = $this->appendOutput($output, $result['output']);
            if ($result['success'] && $this->verifiedArchive($temporary, $source)) {
                @\chmod($temporary, 0600);
                if (@\rename($temporary, $target)) {
                    return ['success' => true, 'path' => $target, 'output' => $output];
                }
            }
            @\unlink($temporary);
        }
        return ['success' => false, 'path' => '', 'output' => $output];
    }

    /** @param array{size:int,sha256:string} $source */
    private function verifiedArchive(string $path, array $source): bool
    {
        return \is_file($path)
            && \filesize($path) === $source['size']
            && \hash_equals($source['sha256'], (string)\hash_file('sha256', $path));
    }

    /** @param array{archive:string,directory:string} $source */
    private function extract(string $archive, array $source, string $work, string $tar): void
    {
        $list = $this->runner->run([$tar, '-tf', $archive], 60);
        if (!$list['success']) {
            throw new \RuntimeException('Unable to list ' . $source['archive']);
        }
        foreach (\preg_split('/\r?\n/', $list['output']) ?: [] as $member) {
            $member = \trim($member);
            if ($member === '') {
                continue;
            }
            if (\str_starts_with($member, '/')
                || \preg_match('#(?:^|/)\.\.(?:/|$)#', $member) === 1
            ) {
                throw new \RuntimeException('Unsafe path in ' . $source['archive']);
            }
        }
        $extract = $this->runner->run([
            $tar,
            '-xf',
            $archive,
            '--no-same-owner',
            '--no-same-permissions',
            '-C',
            $work,
        ], 180);
        if (!$extract['success'] || !\is_dir($work . '/' . $source['directory'])) {
            throw new \RuntimeException('Unable to extract ' . $source['archive'] . ': ' . \trim($extract['output']));
        }
    }

    /**
     * @param list<string> $command
     * @param array<string,string> $environment
     */
    private function runRequired(
        array $command,
        string $workingDirectory,
        array $environment,
        string $output
    ): string {
        $result = $this->runner->run($command, self::BUILD_TIMEOUT, $workingDirectory, $environment);
        $output = $this->appendOutput($output, $result['output']);
        if (!$result['success']) {
            throw new \RuntimeException('Native dependency command failed: ' . \implode(' ', $command));
        }
        return $output;
    }

    /**
     * @param array<string,string> $executables
     * @param array<string,string> $environment
     * @return array<string,string>
     */
    private function verifyInstalled(array $executables, array $environment): array
    {
        $expected = [
            'openssl' => self::SOURCES['openssl']['version'],
            'libnghttp3' => self::SOURCES['nghttp3']['version'],
            'libngtcp2' => self::SOURCES['ngtcp2']['version'],
            'libngtcp2_crypto_ossl' => self::SOURCES['ngtcp2']['version'],
        ];
        $versions = [];
        foreach ($expected as $package => $version) {
            $result = $this->runner->run([
                $executables['pkg-config'], '--modversion', $package,
            ], 30, null, $environment);
            $actual = \trim($result['output']);
            if (!$result['success'] || $actual !== $version) {
                throw new \RuntimeException('Pinned package version mismatch for ' . $package . ': ' . $actual);
            }
            $versions[$package] = $actual;
        }
        $curl = $this->dependencyPrefix() . '/bin/curl';
        $curlVersion = $this->runner->run([$curl, '--version'], 30, null, $environment);
        if (!$curlVersion['success']
            || \preg_match('/^curl\s+8\.21\.0\b/m', $curlVersion['output']) !== 1
            || \preg_match('/^Features:.*\bHTTP3\b/im', $curlVersion['output']) !== 1
            || \preg_match('/^Features:.*\bSSLS-EXPORT\b/im', $curlVersion['output']) !== 1
            || !\str_contains($curlVersion['output'], 'OpenSSL/3.5.7')
            || !\str_contains($curlVersion['output'], 'ngtcp2/1.23.0')
            || !\str_contains($curlVersion['output'], 'nghttp3/1.16.0')
        ) {
            throw new \RuntimeException('Pinned curl did not expose the required HTTP/3 and SSL-session export stack.');
        }
        $versions['curl'] = self::SOURCES['curl']['version'];
        return $versions;
    }

    private function buildJobs(): int
    {
        $getconf = $this->runner->findExecutable(['getconf']);
        if ($getconf === null) {
            return 2;
        }
        $result = $this->runner->run([$getconf, '_NPROCESSORS_ONLN'], 10);
        $jobs = (int)\trim($result['output']);
        return \max(1, \min(8, $jobs));
    }

    private function ensureDirectory(string $path): void
    {
        if (!\is_dir($path) && !@\mkdir($path, 0700, true) && !\is_dir($path)) {
            throw new \RuntimeException('Unable to create native dependency directory: ' . $path);
        }
        @\chmod($path, 0700);
    }

    private function trustedPath(string $path, bool $directory): bool
    {
        if (($directory && !\is_dir($path)) || (!$directory && !\is_file($path))) {
            return false;
        }
        $realRoot = \realpath($this->nativeRoot);
        $real = \realpath($path);
        if (!\is_string($realRoot) || !\is_string($real)
            || !\str_starts_with($real, $realRoot . \DIRECTORY_SEPARATOR)
        ) {
            return false;
        }
        if (\function_exists('posix_geteuid') && \fileowner($real) !== \posix_geteuid()) {
            return false;
        }
        $permissions = @\fileperms($real);
        return !\is_int($permissions) || ($permissions & 0022) === 0;
    }

    private function removeOwnedBuildDirectory(string $path): void
    {
        $buildRoot = $this->nativeRoot . '/build';
        if (!\is_dir($path)
            || !\str_starts_with($path, $buildRoot . \DIRECTORY_SEPARATOR)
            || (\function_exists('posix_geteuid') && \fileowner($path) !== \posix_geteuid())
        ) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink()
                ? @\rmdir($item->getPathname())
                : @\unlink($item->getPathname());
        }
        @\rmdir($path);
    }

    private function appendOutput(string $output, string $chunk): string
    {
        $combined = $output . $chunk;
        return \strlen($combined) <= self::MAX_OUTPUT_BYTES
            ? $combined
            : "[earlier dependency output truncated]\n" . \substr($combined, -self::MAX_OUTPUT_BYTES);
    }

    /** @return array<string,mixed> */
    private function readJson(string $path): array
    {
        if (!\is_file($path)) {
            return [];
        }
        $decoded = \json_decode((string)@\file_get_contents($path), true);
        return \is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $payload */
    private function atomicJson(string $path, array $payload): void
    {
        $temporary = $path . '.tmp.' . \getmypid() . '.' . \bin2hex(\random_bytes(4));
        $json = \json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        if (@\file_put_contents($temporary, $json . \PHP_EOL, \LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write native dependency manifest.');
        }
        @\chmod($temporary, 0600);
        if (!@\rename($temporary, $path)) {
            @\unlink($temporary);
            throw new \RuntimeException('Unable to publish native dependency manifest.');
        }
    }
}
