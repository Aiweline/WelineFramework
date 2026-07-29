<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Project-owned immutable TLS material with per-domain monotonic activation.
 *
 * Source certificates remain the project fact source. Both gateway
 * registration and the same-Master WLS fallback consume only a validated
 * content-addressed snapshot, so a renewal can never replace a live TLS
 * generation halfway through a read.
 */
final class ProjectCertificateGenerationStore
{
    public const SCHEMA_VERSION = 1;
    private const MAX_MATERIAL_BYTES = 1_048_576;

    private readonly string $projectRoot;
    private readonly string $storeRoot;
    private readonly int $projectOwner;
    private readonly int $projectGroup;

    public function __construct(?string $projectRoot = null)
    {
        $root = \realpath($projectRoot ?? (string)BP);
        if (!\is_string($root) || $root === '' || !\is_dir($root) || \is_link($root)) {
            throw new \RuntimeException('Unable to resolve a safe WLS project root.');
        }
        $this->projectRoot = \rtrim($root, '/\\');
        $this->storeRoot = $this->projectRoot . DIRECTORY_SEPARATOR . 'app'
            . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'ssl'
            . DIRECTORY_SEPARATOR . '.wls-generations';
        $owner = @\lstat($this->projectRoot);
        $this->projectOwner = \is_array($owner) && \is_int($owner['uid'] ?? null)
            ? (int)$owner['uid']
            : -1;
        $this->projectGroup = \is_array($owner) && \is_int($owner['gid'] ?? null)
            ? (int)$owner['gid']
            : -1;
    }

    /**
     * Validate and atomically activate one domain's certificate material.
     *
     * If a newly supplied source is invalid but the currently active snapshot
     * is still valid, the current generation is returned with
     * retained_previous=true and remains active.
     *
     * @return array{
     *   domain:string,
     *   generation:int,
     *   source_digest:string,
     *   cert_path:string,
     *   key_path:string,
     *   chain_path:string,
     *   retained_previous:bool,
     *   activation_error:string
     * }
     */
    public function activate(
        string $domain,
        string $certificate,
        string $privateKey,
        string $chain = '',
        array $sourceRoots = [],
    ): array {
        $domain = $this->normalizeDomain($domain);
        $this->ensureStoreDirectories();
        $lockPath = $this->storeRoot . DIRECTORY_SEPARATOR . 'activation.lock';
        $this->assertSafeTarget($lockPath);
        $lock = @\fopen($lockPath, 'c+b');
        if (!\is_resource($lock) || !@\flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Unable to lock project certificate activation.');
        }
        @\chmod($lockPath, 0600);
        $this->preserveProjectArtifactOwnership($lockPath, $lock);
        try {
            $active = $this->readActiveUnlocked($domain);
            try {
                $this->assertSourcesInsideRoots(
                    [$certificate, $privateKey, $chain],
                    $sourceRoots,
                );
                $material = $this->validateSourceMaterial(
                    $domain,
                    $certificate,
                    $privateKey,
                    $chain,
                );
                $snapshot = $this->publishSnapshot($material);
                if ($active !== null
                    && \hash_equals(
                        (string)$active['source_digest'],
                        (string)$snapshot['source_digest'],
                    )
                ) {
                    return $active + [
                        'retained_previous' => false,
                        'activation_error' => '',
                    ];
                }
                $generation = \max(0, (int)($active['generation'] ?? 0)) + 1;
                $next = [
                    'schema_version' => self::SCHEMA_VERSION,
                    'domain' => $domain,
                    'generation' => $generation,
                    'source_digest' => (string)$snapshot['source_digest'],
                    'cert_path' => (string)$snapshot['cert_path'],
                    'key_path' => (string)$snapshot['key_path'],
                    'chain_path' => (string)$snapshot['chain_path'],
                    'cert_sha256' => (string)$snapshot['cert_sha256'],
                    'key_sha256' => (string)$snapshot['key_sha256'],
                    'chain_sha256' => (string)$snapshot['chain_sha256'],
                    'activated_at' => \gmdate(DATE_ATOM),
                    'previous' => $active === null ? null : [
                        'generation' => (int)$active['generation'],
                        'source_digest' => (string)$active['source_digest'],
                        'cert_path' => (string)$active['cert_path'],
                        'key_path' => (string)$active['key_path'],
                        'chain_path' => (string)$active['chain_path'],
                    ],
                ];
                $this->publishManifest($this->activeManifestFile($domain), $next);
                return $next + [
                    'retained_previous' => false,
                    'activation_error' => '',
                ];
            } catch (\Throwable $throwable) {
                if ($active !== null) {
                    return \array_replace($active, [
                        'retained_previous' => true,
                        'activation_error' => $throwable->getMessage(),
                    ]);
                }
                throw new \RuntimeException(
                    'No valid active certificate generation is available for ' . $domain
                    . ': ' . $throwable->getMessage(),
                    0,
                    $throwable,
                );
            }
        } finally {
            @\flock($lock, LOCK_UN);
            @\fclose($lock);
        }
    }

    /**
     * @param list<string> $sources
     * @param array<int|string,string> $sourceRoots
     */
    private function assertSourcesInsideRoots(array $sources, array $sourceRoots): void
    {
        if ($sourceRoots === []) {
            $sourceRoots = [
                $this->projectRoot . DIRECTORY_SEPARATOR . 'app'
                    . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'ssl',
            ];
        }
        $roots = [];
        foreach ($sourceRoots as $root) {
            $canonical = \realpath((string)$root);
            if (\is_string($canonical) && $canonical !== '' && \is_dir($canonical)) {
                $roots[] = $canonical;
            }
        }
        if ($roots === []) {
            throw new \RuntimeException('No enrolled certificate source root is available.');
        }
        foreach ($sources as $source) {
            if ($source === '') {
                continue;
            }
            $real = \realpath($source);
            if (!\is_string($real) || !\is_file($real)) {
                throw new \RuntimeException('Certificate material file is unavailable.');
            }
            foreach ($roots as $root) {
                if ($this->pathInside($real, $root)) {
                    continue 2;
                }
            }
            throw new \RuntimeException(
                'Certificate source is outside every enrolled certificate root: ' . $source
            );
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    public function active(string $domain): ?array
    {
        $domain = $this->normalizeDomain($domain);
        return $this->readActiveUnlocked($domain);
    }

    /**
     * @return array{
     *   source_digest:string,
     *   cert_pem:string,
     *   key_pem:string,
     *   chain_pem:string,
     *   cert_sha256:string,
     *   key_sha256:string,
     *   chain_sha256:string
     * }
     */
    private function validateSourceMaterial(
        string $domain,
        string $certificate,
        string $privateKey,
        string $chain,
    ): array {
        $certificatePem = $this->readStableFile($certificate, false);
        $keyPem = $this->readStableFile($privateKey, true);
        $chainPem = $chain === '' ? '' : $this->readStableFile($chain, false);
        return $this->validateMaterial($domain, $certificatePem, $keyPem, $chainPem);
    }

    /**
     * @return array{
     *   source_digest:string,
     *   cert_pem:string,
     *   key_pem:string,
     *   chain_pem:string,
     *   cert_sha256:string,
     *   key_sha256:string,
     *   chain_sha256:string
     * }
     */
    private function validateMaterial(
        string $domain,
        string $certificatePem,
        string $keyPem,
        string $chainPem,
    ): array {
        if (!\preg_match(
            '/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s',
            $certificatePem,
            $leafMatch,
        )) {
            throw new \RuntimeException('Certificate source contains no PEM certificate.');
        }
        $leaf = @\openssl_x509_read((string)$leafMatch[0]);
        $private = @\openssl_pkey_get_private($keyPem);
        $parsed = $leaf !== false ? @\openssl_x509_parse($leaf, false) : false;
        if ($leaf === false || $private === false || !\is_array($parsed)) {
            throw new \RuntimeException('Certificate or private key PEM is invalid.');
        }
        if (!@\openssl_x509_check_private_key($leaf, $private)) {
            throw new \RuntimeException('Certificate and private key do not match.');
        }
        $now = \time();
        if ((int)($parsed['validFrom_time_t'] ?? PHP_INT_MAX) > $now
            || (int)($parsed['validTo_time_t'] ?? 0) <= $now
        ) {
            throw new \RuntimeException('Certificate is not currently valid.');
        }
        if (!$this->certificateCoversDomain($parsed, $domain)) {
            throw new \RuntimeException('Certificate SAN does not cover ' . $domain . '.');
        }
        $this->validateCertificateBundle($certificatePem);
        if ($chainPem !== '') {
            $this->validateCertificateBundle($chainPem);
        }
        $fullchain = \rtrim($certificatePem) . "\n";
        if ($chainPem !== '') {
            $fullchain .= \rtrim($chainPem) . "\n";
        }
        $certHash = \hash('sha256', $fullchain);
        $keyHash = \hash('sha256', $keyPem);
        // The protocol publishes fullchain.pem as the certificate source and
        // therefore sends no separate chain reference. Keep this digest
        // byte-for-byte compatible with the Controller's source fence.
        $sourceDigest = \hash('sha256', $certHash . ':' . $keyHash . ':');
        return [
            'source_digest' => $sourceDigest,
            'cert_pem' => $fullchain,
            'key_pem' => $keyPem,
            'chain_pem' => $chainPem === '' ? '' : \rtrim($chainPem) . "\n",
            'cert_sha256' => $certHash,
            'key_sha256' => $keyHash,
            'chain_sha256' => $chainPem === '' ? '' : \hash(
                'sha256',
                \rtrim($chainPem) . "\n",
            ),
        ];
    }

    private function validateCertificateBundle(string $pem): void
    {
        if (!\preg_match_all(
            '/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s',
            $pem,
            $matches,
        ) || $matches[0] === []) {
            throw new \RuntimeException('Certificate bundle contains no PEM certificate.');
        }
        foreach ($matches[0] as $certificate) {
            if (@\openssl_x509_read((string)$certificate) === false) {
                throw new \RuntimeException('Certificate bundle contains an invalid certificate.');
            }
        }
    }

    /**
     * @param array<string,mixed> $parsed
     */
    private function certificateCoversDomain(array $parsed, string $domain): bool
    {
        $san = \trim((string)($parsed['extensions']['subjectAltName'] ?? ''));
        if ($san === '') {
            return false;
        }
        foreach (\explode(',', $san) as $entry) {
            $entry = \trim($entry);
            if (\filter_var($domain, FILTER_VALIDATE_IP) !== false
                && \str_starts_with(\strtoupper($entry), 'IP ADDRESS:')
            ) {
                $candidate = \trim(\substr($entry, \strlen('IP Address:')));
                if (\filter_var($candidate, FILTER_VALIDATE_IP) !== false
                    && \hash_equals(
                        (string)@\inet_pton($domain),
                        (string)@\inet_pton($candidate),
                    )
                ) {
                    return true;
                }
                continue;
            }
            if (!\str_starts_with(\strtoupper($entry), 'DNS:')) {
                continue;
            }
            try {
                $pattern = $this->normalizeDomain(\substr($entry, 4));
            } catch (\Throwable) {
                continue;
            }
            if ($this->domainPatternMatches($pattern, $domain)) {
                return true;
            }
        }
        return false;
    }

    private function domainPatternMatches(string $pattern, string $domain): bool
    {
        if (!\str_starts_with($pattern, '*.')) {
            return \hash_equals($pattern, $domain);
        }
        if (\str_starts_with($domain, '*.')) {
            return \hash_equals($pattern, $domain);
        }
        if (\substr_count($pattern, '.') !== \substr_count($domain, '.')) {
            return false;
        }
        return \str_ends_with($domain, \substr($pattern, 1));
    }

    /**
     * @param array<string,string> $material
     * @return array<string,string>
     */
    private function publishSnapshot(array $material): array
    {
        $digest = (string)$material['source_digest'];
        $snapshots = $this->storeRoot . DIRECTORY_SEPARATOR . 'snapshots';
        $target = $snapshots . DIRECTORY_SEPARATOR . $digest;
        if (\is_dir($target) && !\is_link($target)) {
            return $this->verifySnapshot($target, $material);
        }
        $temporary = $snapshots . DIRECTORY_SEPARATOR . '.tmp-'
            . \bin2hex(\random_bytes(12));
        if (!@\mkdir($temporary, 0700) || \is_link($temporary)) {
            throw new \RuntimeException('Unable to create certificate snapshot staging directory.');
        }
        $this->preserveProjectArtifactOwnership($temporary);
        try {
            $this->atomicWrite(
                $temporary . DIRECTORY_SEPARATOR . 'fullchain.pem',
                (string)$material['cert_pem'],
                0600,
            );
            $this->atomicWrite(
                $temporary . DIRECTORY_SEPARATOR . 'privkey.pem',
                (string)$material['key_pem'],
                0600,
            );
            if ((string)$material['chain_pem'] !== '') {
                $this->atomicWrite(
                    $temporary . DIRECTORY_SEPARATOR . 'chain.pem',
                    (string)$material['chain_pem'],
                    0600,
                );
            }
            $this->publishManifest(
                $temporary . DIRECTORY_SEPARATOR . 'snapshot.json',
                [
                    'schema_version' => self::SCHEMA_VERSION,
                    'source_digest' => $digest,
                    'cert_sha256' => (string)$material['cert_sha256'],
                    'key_sha256' => (string)$material['key_sha256'],
                    'chain_sha256' => (string)$material['chain_sha256'],
                    'created_at' => \gmdate(DATE_ATOM),
                ],
            );
            if (!@\rename($temporary, $target)) {
                if (!\is_dir($target) || \is_link($target)) {
                    throw new \RuntimeException('Unable to publish immutable certificate snapshot.');
                }
                $this->removeDirectory($temporary);
            }
        } catch (\Throwable $throwable) {
            $this->removeDirectory($temporary);
            throw $throwable;
        }
        return $this->verifySnapshot($target, $material);
    }

    /**
     * @param array<string,string> $material
     * @return array<string,string>
     */
    private function verifySnapshot(string $directory, array $material): array
    {
        $cert = $directory . DIRECTORY_SEPARATOR . 'fullchain.pem';
        $key = $directory . DIRECTORY_SEPARATOR . 'privkey.pem';
        $chain = $directory . DIRECTORY_SEPARATOR . 'chain.pem';
        $this->preserveProjectArtifactOwnership($directory);
        $this->preserveProjectArtifactOwnership($cert);
        $this->preserveProjectArtifactOwnership($key);
        if (\is_file($chain)) {
            $this->preserveProjectArtifactOwnership($chain);
        }
        $certHash = $this->safeHashFile($cert);
        $keyHash = $this->safeHashFile($key);
        $chainHash = \is_file($chain) && !\is_link($chain) ? $this->safeHashFile($chain) : '';
        if (!\hash_equals((string)$material['cert_sha256'], $certHash)
            || !\hash_equals((string)$material['key_sha256'], $keyHash)
            || !\hash_equals((string)$material['chain_sha256'], $chainHash)
        ) {
            throw new \RuntimeException('Existing certificate snapshot failed content verification.');
        }
        if (\PHP_OS_FAMILY !== 'Windows') {
            $mode = @\fileperms($key);
            if (!\is_int($mode) || ($mode & 0077) !== 0) {
                throw new \RuntimeException('Certificate snapshot private key permissions are unsafe.');
            }
        }
        return [
            'source_digest' => (string)$material['source_digest'],
            'cert_path' => $cert,
            'key_path' => $key,
            'chain_path' => $chainHash === '' ? '' : $chain,
            'cert_sha256' => $certHash,
            'key_sha256' => $keyHash,
            'chain_sha256' => $chainHash,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readActiveUnlocked(string $domain): ?array
    {
        $file = $this->activeManifestFile($domain);
        if (!\file_exists($file) && !\is_link($file)) {
            return null;
        }
        $this->preserveProjectArtifactOwnership($file);
        $manifest = $this->readManifest($file);
        if (!\hash_equals($domain, (string)($manifest['domain'] ?? ''))
            || (int)($manifest['generation'] ?? 0) < 1
            || \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                (string)($manifest['source_digest'] ?? ''),
            ) !== 1
        ) {
            throw new \RuntimeException('Active certificate generation manifest is invalid.');
        }
        $directory = $this->storeRoot . DIRECTORY_SEPARATOR . 'snapshots'
            . DIRECTORY_SEPARATOR . (string)$manifest['source_digest'];
        // Snapshot locations are derived from the content digest under the
        // current project root. Persisted absolute paths describe the host
        // that activated the generation and must not make a copied project
        // unusable or authorize reads outside the migrated project.
        $certPath = $directory . DIRECTORY_SEPARATOR . 'fullchain.pem';
        $keyPath = $directory . DIRECTORY_SEPARATOR . 'privkey.pem';
        $chainPath = (string)($manifest['chain_sha256'] ?? '') === ''
            ? ''
            : $directory . DIRECTORY_SEPARATOR . 'chain.pem';
        $cert = $this->readStableFile($certPath, false);
        $key = $this->readStableFile($keyPath, true);
        $chain = $chainPath === '' ? '' : $this->readStableFile($chainPath, false);
        if (!$this->pathInside($certPath, $directory)
            || !$this->pathInside($keyPath, $directory)
            || ($chainPath !== '' && !$this->pathInside($chainPath, $directory))
            || !\hash_equals((string)$manifest['cert_sha256'], \hash('sha256', $cert))
            || !\hash_equals((string)$manifest['key_sha256'], \hash('sha256', $key))
            || !\hash_equals(
                (string)($manifest['chain_sha256'] ?? ''),
                $chain === '' ? '' : \hash('sha256', $chain),
            )
        ) {
            throw new \RuntimeException('Active certificate snapshot integrity check failed.');
        }
        $validated = $this->validateMaterial($domain, $cert, $key, '');
        if (!\hash_equals(
            (string)$manifest['source_digest'],
            (string)$validated['source_digest'],
        )) {
            throw new \RuntimeException('Active certificate source digest is invalid.');
        }
        return \array_replace($manifest, [
            'cert_path' => $certPath,
            'key_path' => $keyPath,
            'chain_path' => $chainPath,
            'retained_previous' => false,
            'activation_error' => '',
        ]);
    }

    private function readStableFile(string $path, bool $privateKey): string
    {
        if ($path === '' || \str_contains($path, "\0") || \is_link($path)) {
            throw new \RuntimeException('Certificate material path is unsafe.');
        }
        $real = \realpath($path);
        if (!\is_string($real) || !\is_file($real) || \is_link($real)) {
            throw new \RuntimeException('Certificate material file is unavailable.');
        }
        if (!$this->samePath($path, $real)) {
            throw new \RuntimeException('Symbolic-link or non-canonical certificate paths are forbidden.');
        }
        $before = @\lstat($real);
        $stream = @\fopen($real, 'rb');
        if (!\is_array($before) || !\is_resource($stream)) {
            throw new \RuntimeException('Unable to open certificate material safely.');
        }
        try {
            $opened = @\fstat($stream);
            if (!\is_array($opened)
                || ((int)($opened['mode'] ?? 0) & 0170000) !== 0100000
                || (int)($opened['size'] ?? -1) < 1
                || (int)$opened['size'] > self::MAX_MATERIAL_BYTES
            ) {
                throw new \RuntimeException('Certificate material size or file type is invalid.');
            }
            if (\PHP_OS_FAMILY !== 'Windows') {
                if ($this->projectOwner >= 0
                    && (int)($opened['uid'] ?? -1) !== $this->projectOwner
                ) {
                    throw new \RuntimeException('Certificate material owner differs from project owner.');
                }
                if ($privateKey && ((int)$opened['mode'] & 0077) !== 0) {
                    throw new \RuntimeException(
                        'Private key source must not grant group or other permissions.'
                    );
                }
            }
            $contents = \stream_get_contents($stream, self::MAX_MATERIAL_BYTES + 1);
            $after = @\fstat($stream);
        } finally {
            @\fclose($stream);
        }
        $latest = @\lstat($real);
        if (!\is_string($contents)
            || \strlen($contents) < 1
            || \strlen($contents) > self::MAX_MATERIAL_BYTES
            || !\is_array($after)
            || !\is_array($latest)
        ) {
            throw new \RuntimeException('Certificate material read was incomplete.');
        }
        foreach (['dev', 'ino', 'mode', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $field) {
            if ((int)($before[$field] ?? -1) !== (int)($opened[$field] ?? -2)
                || (int)($opened[$field] ?? -1) !== (int)($after[$field] ?? -2)
                || (int)($after[$field] ?? -1) !== (int)($latest[$field] ?? -2)
            ) {
                throw new \RuntimeException('Certificate material changed while being read.');
            }
        }
        return $contents;
    }

    private function safeHashFile(string $path): string
    {
        return \hash('sha256', $this->readStableFile($path, \str_ends_with($path, 'privkey.pem')));
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = \strtolower(\rtrim(\trim($domain), '.'));
        $wildcard = \str_starts_with($domain, '*.');
        $base = $wildcard ? \substr($domain, 2) : $domain;
        if (!$wildcard && \filter_var($base, FILTER_VALIDATE_IP) !== false) {
            $packed = @\inet_pton($base);
            if (!\is_string($packed)) {
                throw new \InvalidArgumentException('Invalid TLS IP address: ' . $domain);
            }
            return (string)@\inet_ntop($packed);
        }
        if (\function_exists('idn_to_ascii')) {
            $variant = \defined('INTL_IDNA_VARIANT_UTS46')
                ? \constant('INTL_IDNA_VARIANT_UTS46')
                : 0;
            $ascii = @\idn_to_ascii($base, IDNA_DEFAULT, $variant);
            if (\is_string($ascii) && $ascii !== '') {
                $base = \strtolower($ascii);
            }
        }
        if (\strlen($base) > 253
            || \preg_match(
                '/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*'
                    . '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D',
                $base,
            ) !== 1
        ) {
            throw new \InvalidArgumentException('Invalid TLS domain name: ' . $domain);
        }
        return $wildcard ? '*.' . $base : $base;
    }

    private function ensureStoreDirectories(): void
    {
        foreach ([
            $this->storeRoot,
            $this->storeRoot . DIRECTORY_SEPARATOR . 'snapshots',
            $this->storeRoot . DIRECTORY_SEPARATOR . 'active',
        ] as $directory) {
            if (\is_link($directory)
                || (!\is_dir($directory)
                    && !@\mkdir($directory, 0700, true)
                    && !\is_dir($directory))
            ) {
                throw new \RuntimeException(
                    'Project certificate generation directory is unavailable: ' . $directory
                );
            }
            @\chmod($directory, 0700);
            $this->preserveProjectArtifactOwnership($directory);
        }
    }

    private function activeManifestFile(string $domain): string
    {
        return $this->storeRoot . DIRECTORY_SEPARATOR . 'active'
            . DIRECTORY_SEPARATOR . \substr(\hash('sha256', $domain), 0, 32) . '.json';
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function publishManifest(string $path, array $payload): void
    {
        $envelope = [
            'payload' => $payload,
            'sha256' => \hash('sha256', GatewayClient::canonicalJson($payload)),
        ];
        $encoded = \json_encode(
            $envelope,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        if (!\is_string($encoded)) {
            throw new \RuntimeException('Unable to encode certificate generation manifest.');
        }
        $this->atomicWrite($path, $encoded, 0600);
    }

    /**
     * @return array<string,mixed>
     */
    private function readManifest(string $path): array
    {
        $encoded = $this->readStableFile($path, false);
        $envelope = \json_decode($encoded, true);
        $payload = \is_array($envelope) && \is_array($envelope['payload'] ?? null)
            ? $envelope['payload']
            : null;
        if (!\is_array($payload)
            || !\hash_equals(
                (string)($envelope['sha256'] ?? ''),
                \hash('sha256', GatewayClient::canonicalJson($payload)),
            )
        ) {
            throw new \RuntimeException('Certificate generation manifest failed integrity validation.');
        }
        return $payload;
    }

    private function atomicWrite(string $path, string $contents, int $mode): void
    {
        $this->assertSafeTarget($path);
        $temporary = \dirname($path) . DIRECTORY_SEPARATOR . '.'
            . \basename($path) . '.tmp-' . \bin2hex(\random_bytes(12));
        $stream = @\fopen($temporary, 'x+b');
        if (!\is_resource($stream)) {
            throw new \RuntimeException('Unable to create certificate generation staging file.');
        }
        try {
            @\chmod($temporary, $mode);
            $written = 0;
            $length = \strlen($contents);
            while ($written < $length) {
                $chunk = @\fwrite($stream, \substr($contents, $written));
                if (!\is_int($chunk) || $chunk < 1) {
                    throw new \RuntimeException('Unable to write certificate generation staging file.');
                }
                $written += $chunk;
            }
            if (!@\fflush($stream)) {
                throw new \RuntimeException('Unable to flush certificate generation staging file.');
            }
            if (\function_exists('fsync') && !@\fsync($stream)) {
                throw new \RuntimeException('Unable to sync certificate generation staging file.');
            }
            $this->preserveProjectArtifactOwnership($temporary, $stream);
        } finally {
            @\fclose($stream);
        }
        if (!@\rename($temporary, $path)) {
            @\unlink($temporary);
            throw new \RuntimeException('Unable to atomically publish certificate generation file.');
        }
        @\chmod($path, $mode);
    }

    /**
     * Root may coordinate enrollment/promotion, but every derived certificate
     * generation remains a project-owned fact. Never apply this repair to the
     * original certificate sources or to paths outside the private store.
     *
     * @param resource|null $handle
     */
    private function preserveProjectArtifactOwnership(string $path, mixed $handle = null): void
    {
        if (\PHP_OS_FAMILY === 'Windows'
            || $this->projectOwner < 0
            || $this->projectGroup < 0
            || !\function_exists('posix_geteuid')
            || \posix_geteuid() !== 0
        ) {
            return;
        }
        $store = \realpath($this->storeRoot);
        $real = \realpath($path);
        $status = @\lstat($path);
        if (!\is_string($store)
            || !\is_string($real)
            || !$this->pathInside($real, $store)
            || \is_link($path)
            || !\is_array($status)
            || (!\is_file($path) && !\is_dir($path))
        ) {
            throw new \RuntimeException(
                'Certificate generation ownership target is unsafe.'
            );
        }
        $ownerApplied = \is_resource($handle)
            && \function_exists('fchown')
            && @\fchown($handle, $this->projectOwner);
        if (!$ownerApplied) {
            $ownerApplied = \function_exists('lchown')
                ? @\lchown($path, $this->projectOwner)
                : @\chown($path, $this->projectOwner);
        }
        $groupApplied = \is_resource($handle)
            && \function_exists('fchgrp')
            && @\fchgrp($handle, $this->projectGroup);
        if (!$groupApplied) {
            $groupApplied = \function_exists('lchgrp')
                ? @\lchgrp($path, $this->projectGroup)
                : @\chgrp($path, $this->projectGroup);
        }
        $actual = @\lstat($path);
        if (!$ownerApplied
            || !$groupApplied
            || !\is_array($actual)
            || (int)($actual['uid'] ?? -1) !== $this->projectOwner
            || (int)($actual['gid'] ?? -1) !== $this->projectGroup
        ) {
            throw new \RuntimeException(
                'Unable to preserve the project owner on certificate generations.'
            );
        }
    }

    private function assertSafeTarget(string $path): void
    {
        if (\is_link($path)) {
            throw new \RuntimeException('Symbolic-link certificate generation targets are forbidden.');
        }
        $parent = \realpath(\dirname($path));
        if (!\is_string($parent) || !$this->pathInside($parent, $this->storeRoot)) {
            throw new \RuntimeException('Certificate generation target escapes the project store.');
        }
    }

    private function pathInside(string $path, string $root): bool
    {
        $path = \str_replace('\\', '/', \rtrim($path, '/\\'));
        $root = \str_replace('\\', '/', \rtrim($root, '/\\'));
        if (\PHP_OS_FAMILY === 'Windows') {
            $path = \strtolower($path);
            $root = \strtolower($root);
        }
        return $path === $root || \str_starts_with($path, $root . '/');
    }

    private function samePath(string $path, string $real): bool
    {
        $absolute = \str_starts_with($path, '/')
            || \preg_match('/\A[A-Za-z]:[\\\\\\/]/D', $path) === 1
            ? $path
            : $this->projectRoot . DIRECTORY_SEPARATOR . $path;
        $absolute = \str_replace('\\', '/', \rtrim($absolute, '/\\'));
        $real = \str_replace('\\', '/', \rtrim($real, '/\\'));
        if (\PHP_OS_FAMILY === 'Windows') {
            $absolute = \strtolower($absolute);
            $real = \strtolower($real);
        }
        return $absolute === $real;
    }

    private function removeDirectory(string $directory): void
    {
        if (!\is_dir($directory) || \is_link($directory)) {
            return;
        }
        foreach ((array)@\scandir($directory) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            \is_dir($path) && !\is_link($path) ? $this->removeDirectory($path) : @\unlink($path);
        }
        @\rmdir($directory);
    }
}
