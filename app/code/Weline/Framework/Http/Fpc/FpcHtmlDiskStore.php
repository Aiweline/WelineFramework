<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Fpc;

use Weline\Framework\App\Env;

/**
 * Generation-pointer FPC cold disk.
 *
 * Layout:
 *   generated/html/live → generations/{gen}/
 *   generated/html/previous → generations/{prev}/   (rollback)
 *   generated/html/generations/{gen}/{host}/{path}/index.html[.br]
 *   …/{path}/~q/{sha1(canonical_query)}/index.html[.br]  — Extra query_keys HTML
 *   …/{path}/~q/{sha1}/index.bq.br                         — same-identity BinQuery wire
 *
 * When brotli bytes are supplied, `.br` is the on-disk authority (Nginx edge
 * serves `index.html.br` / `index.bq.br`); plaintext `index.html` is not written.
 * Legacy plain leaves remain readable until the next br publish or purge.
 *
 * Path-only leaf = host+path (query empty). Allowlisted canonical query URIs
 * (Coordinator-normalized) get a sibling `~q/{sha1}` directory — never overwrite the
 * path-only leaf. Edge njs + try_files may serve HTML/BQ leaves by sha; publishBq
 * must recompute identity server-side (never trust client sha). Whole-tree
 * invalidate rotates to a new empty generation; URL purge unlinks leaves in the
 * write target (including index.bq.br).
 */
final class FpcHtmlDiskStore
{
    public const LIVE_NAME = 'live';
    public const PREVIOUS_NAME = 'previous';
    public const GENERATIONS_DIR = 'generations';
    public const STAGING_ID_FILE = '.staging-gen';

    /** Directory segment under a path leaf for Extra query_keys variants. */
    public const QUERY_VARIANT_SEGMENT = '~q';

    /** BinQuery wire leaf basename (brotli authority: index.bq.br). */
    public const BQ_BASENAME = 'index.bq';

    /** Keep live + previous; GC older generation dirs after rotate. */
    public const RETAIN_GENERATIONS = 2;

    public static function root(): string
    {
        return \rtrim((string)Env::GENERATED_DIR, '\\/') . \DIRECTORY_SEPARATOR . 'html';
    }

    /**
     * Nginx / readers follow this directory (symlink into generations/{gen}).
     */
    public static function liveRoot(): string
    {
        self::ensureLiveGeneration();

        return self::root() . \DIRECTORY_SEPARATOR . self::LIVE_NAME;
    }

    public static function generationsRoot(): string
    {
        return self::root() . \DIRECTORY_SEPARATOR . self::GENERATIONS_DIR;
    }

    public static function currentGenerationId(): string
    {
        self::ensureLiveGeneration();

        return self::readSymlinkGenerationId(self::LIVE_NAME) ?? '';
    }

    public static function previousGenerationId(): ?string
    {
        self::ensureLiveGeneration();

        return self::readSymlinkGenerationId(self::PREVIOUS_NAME);
    }

    /**
     * @return array{host:string,path:string}|null
     */
    public static function resolveHostPath(string $urlOrPath): ?array
    {
        $raw = \trim($urlOrPath);
        if ($raw === '') {
            return null;
        }
        $host = '';
        $path = '/';
        if (\preg_match('#^https?://#i', $raw) === 1) {
            $parts = \parse_url($raw);
            if (!\is_array($parts)) {
                return null;
            }
            $scheme = \strtolower((string)($parts['scheme'] ?? ''));
            $host = \strtolower((string)($parts['host'] ?? ''));
            if ($host === '') {
                return null;
            }
            $port = isset($parts['port']) ? (int)$parts['port'] : 0;
            if ($port > 0) {
                $defaultPort = $scheme === 'https' ? 443 : ($scheme === 'http' ? 80 : 0);
                if ($defaultPort === 0 || $port !== $defaultPort) {
                    $host .= ':' . $port;
                }
            }
            $path = self::normalizePath((string)($parts['path'] ?? '/'));
            // Empty query → path-only leaf. Non-empty query (caller must pass
            // Coordinator-canonical allowlisted query) → …/~q/{sha1}/ leaf.
            $query = (string)($parts['query'] ?? '');
            if ($query !== '') {
                $path = ($path === '/' ? '' : $path)
                    . '/' . self::QUERY_VARIANT_SEGMENT
                    . '/' . \sha1($query);
            }
        } else {
            return null;
        }

        return ['host' => $host, 'path' => $path];
    }

    public static function pageDirectory(string $urlOrPath): ?string
    {
        $resolved = self::resolveHostPath($urlOrPath);
        if ($resolved === null) {
            return null;
        }
        $rel = self::relativePageDir($resolved['host'], $resolved['path']);
        if ($rel === null) {
            return null;
        }
        $base = self::writeBaseRoot();

        return $base . \DIRECTORY_SEPARATOR . $rel;
    }

    public static function htmlPath(string $urlOrPath): ?string
    {
        $dir = self::pageDirectory($urlOrPath);
        if ($dir === null) {
            return null;
        }

        return $dir . \DIRECTORY_SEPARATOR . 'index.html';
    }

    public static function brotliPath(string $urlOrPath): ?string
    {
        $html = self::htmlPath($urlOrPath);
        if ($html === null) {
            return null;
        }

        return $html . '.br';
    }

    /**
     * Plaintext BinQuery wire path (rarely written; prefer .br via publishBq).
     */
    public static function bqPath(string $urlOrPath): ?string
    {
        $dir = self::pageDirectory($urlOrPath);
        if ($dir === null) {
            return null;
        }

        return $dir . \DIRECTORY_SEPARATOR . self::BQ_BASENAME;
    }

    public static function bqBrotliPath(string $urlOrPath): ?string
    {
        $bq = self::bqPath($urlOrPath);
        if ($bq === null) {
            return null;
        }

        return $bq . '.br';
    }

    /**
     * Publish BinQuery wire for the same identity as HTML ~q leaf.
     * $canonicalStorefrontUri MUST already carry Coordinator-canonical query
     * (server-recomputed). Does not accept a client-supplied sha segment.
     */
    public static function publishBq(string $canonicalStorefrontUri, string $wirePlain = '', string $brotli = ''): bool
    {
        $bqPath = self::bqPath($canonicalStorefrontUri);
        if ($bqPath === null) {
            return false;
        }
        if ($wirePlain === '' && $brotli === '') {
            return false;
        }
        $dir = \dirname($bqPath);
        if (!self::ensureDirectory($dir)) {
            return false;
        }
        $brPath = $bqPath . '.br';
        if ($brotli !== '') {
            if (!self::writeAtomic($brPath, $brotli)) {
                return false;
            }
            @\unlink($bqPath);

            return true;
        }
        if (!self::writeAtomic($bqPath, $wirePlain)) {
            return false;
        }
        @\unlink($brPath);

        return true;
    }

    /**
     * @return array{body:string,encoding:?string,path:string}|null
     */
    public static function readBq(string $fullUri, string $acceptEncoding = ''): ?array
    {
        unset($acceptEncoding);
        $resolved = self::resolveHostPath($fullUri);
        if ($resolved === null) {
            return null;
        }
        $rel = self::relativePageDir($resolved['host'], $resolved['path']);
        if ($rel === null) {
            return null;
        }

        return self::readBqRelative($resolved['host'], $rel);
    }

    /**
     * Read BinQuery leaf by host + page-relative path already including ~q/{sha}.
     * Used by query-bin/~fpc short path (WLS miss fallback; Nginx uses try_files).
     *
     * @return array{body:string,encoding:?string,path:string}|null
     */
    public static function readBqByStorefrontRel(string $host, string $storefrontRel, string $sha): ?array
    {
        $host = \strtolower(\trim($host));
        $storefrontRel = \trim($storefrontRel, '/');
        $sha = \strtolower(\trim($sha));
        if ($host === '' || $storefrontRel === '' || \preg_match('/^[a-f0-9]{40}$/D', $sha) !== 1) {
            return null;
        }
        if (\str_contains($storefrontRel, '..')) {
            return null;
        }
        $pageRel = $storefrontRel . '/' . self::QUERY_VARIANT_SEGMENT . '/' . $sha;
        $rel = self::relativePageDir($host, '/' . $pageRel);
        if ($rel === null) {
            return null;
        }

        return self::readBqRelative($host, $rel);
    }

    /**
     * @return array{body:string,encoding:?string,path:string}|null
     */
    private static function readBqRelative(string $host, string $rel): ?array
    {
        unset($host);
        $bqPath = self::liveRoot() . \DIRECTORY_SEPARATOR . $rel . \DIRECTORY_SEPARATOR . self::BQ_BASENAME;
        $brPath = $bqPath . '.br';
        if (\is_file($brPath)) {
            $raw = @\file_get_contents($brPath);
            if (\is_string($raw) && $raw !== '') {
                return ['body' => $raw, 'encoding' => 'br', 'path' => $brPath];
            }
        }
        if (!\is_file($bqPath)) {
            return null;
        }
        $raw = @\file_get_contents($bqPath);
        if (!\is_string($raw) || $raw === '') {
            return null;
        }

        return ['body' => $raw, 'encoding' => null, 'path' => $bqPath];
    }

    /**
     * Shared / hydrate pointer: always the live logical path (…/html/live/…).
     * Never realpath into generations/{id}; never staging write-base paths.
     */
    public static function logicalLiveBodyPath(string $urlOrPath, bool $brotli = false): ?string
    {
        $resolved = self::resolveHostPath($urlOrPath);
        if ($resolved === null) {
            return null;
        }
        $rel = self::relativePageDir($resolved['host'], $resolved['path']);
        if ($rel === null) {
            return null;
        }
        self::ensureLiveGeneration();
        $html = self::root()
            . \DIRECTORY_SEPARATOR . self::LIVE_NAME
            . \DIRECTORY_SEPARATOR . $rel
            . \DIRECTORY_SEPARATOR . 'index.html';

        return $brotli ? ($html . '.br') : $html;
    }

    public static function publish(string $fullUri, string $html, string $brotli = ''): bool
    {
        $htmlPath = self::htmlPath($fullUri);
        if ($htmlPath === null) {
            return false;
        }
        if ($html === '' && $brotli === '') {
            return false;
        }
        $dir = \dirname($htmlPath);
        if (!self::ensureDirectory($dir)) {
            return false;
        }
        $brPath = $htmlPath . '.br';
        // Brotli authority: write only .br and drop plaintext so edge/disk stay single-leaf.
        if ($brotli !== '') {
            if (!self::writeAtomic($brPath, $brotli)) {
                return false;
            }
            @\unlink($htmlPath);

            return true;
        }
        if (!self::writeAtomic($htmlPath, $html)) {
            return false;
        }
        @\unlink($brPath);

        return true;
    }

    /**
     * @return array{body:string,encoding:?string,path:string}|null
     */
    public static function read(string $fullUri, string $acceptEncoding = ''): ?array
    {
        // Readers always follow live (promoted) tree — not an in-flight staging gen.
        // $acceptEncoding is retained for call-site compatibility; .br is returned
        // whenever present so WLS can decode for identity/gzip clients.
        unset($acceptEncoding);
        $resolved = self::resolveHostPath($fullUri);
        if ($resolved === null) {
            return null;
        }
        $rel = self::relativePageDir($resolved['host'], $resolved['path']);
        if ($rel === null) {
            return null;
        }
        $htmlPath = self::liveRoot() . \DIRECTORY_SEPARATOR . $rel . \DIRECTORY_SEPARATOR . 'index.html';
        $brPath = $htmlPath . '.br';
        if (\is_file($brPath)) {
            $raw = @\file_get_contents($brPath);
            if (\is_string($raw) && $raw !== '') {
                return ['body' => $raw, 'encoding' => 'br', 'path' => $brPath];
            }
        }
        if (!\is_file($htmlPath)) {
            return null;
        }
        $raw = @\file_get_contents($htmlPath);
        if (!\is_string($raw) || $raw === '') {
            return null;
        }

        return ['body' => $raw, 'encoding' => null, 'path' => $htmlPath];
    }

    /**
     * URL-level soft invalidate: drop leaves in the write target (staging or live).
     * Path-only URLs also cascade-delete sibling `~q/*` variant leaves.
     *
     * @param list<string> $urls
     */
    public static function purgeUrls(array $urls): void
    {
        $stop = self::writeBaseRoot();
        foreach ($urls as $url) {
            $url = (string)$url;
            $dir = self::pageDirectory($url);
            if ($dir === null) {
                continue;
            }
            $cascadeVariants = !self::urlHasNonEmptyQuery($url);
            if (\is_dir($dir)) {
                self::unlinkPageFiles($dir);
                if ($cascadeVariants) {
                    self::purgeQueryVariantChildren($dir);
                }
                self::removeEmptyParents($dir, $stop);
            }
        }
    }

    /**
     * Whether the URL carries a non-empty query string (variant leaf identity).
     */
    public static function urlHasNonEmptyQuery(string $urlOrPath): bool
    {
        $raw = \trim($urlOrPath);
        if ($raw === '' || \preg_match('#^https?://#i', $raw) !== 1) {
            return false;
        }
        $parts = \parse_url($raw);
        if (!\is_array($parts)) {
            return false;
        }

        return (string)($parts['query'] ?? '') !== '';
    }

    /**
     * Remove all `~q/{sha1}` variant leaves under a path-only page directory.
     */
    private static function purgeQueryVariantChildren(string $pageDir): void
    {
        $qRoot = $pageDir . \DIRECTORY_SEPARATOR . self::QUERY_VARIANT_SEGMENT;
        if (!\is_dir($qRoot) || !self::isUnderRoot($qRoot)) {
            return;
        }
        $items = @\scandir($qRoot);
        if (!\is_array($items)) {
            return;
        }
        foreach ($items as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            // sha1 hex only
            if (\preg_match('/^[a-f0-9]{40}$/D', $name) !== 1) {
                continue;
            }
            $variantDir = $qRoot . \DIRECTORY_SEPARATOR . $name;
            if (!\is_dir($variantDir) || \is_link($variantDir)) {
                continue;
            }
            self::unlinkPageFiles($variantDir);
            @\rmdir($variantDir);
        }
        $left = @\scandir($qRoot);
        if (\is_array($left)) {
            $children = \array_values(\array_filter(
                $left,
                static fn(string $n): bool => $n !== '.' && $n !== '..',
            ));
            if ($children === []) {
                @\rmdir($qRoot);
            }
        }
    }

    /**
     * Slug-level soft invalidate under host trees (locale/currency prefix variants).
     * Used when UrlMatrix is degraded or relative-only; does not rotate generations.
     *
     * @param list<string> $hosts Host keys as stored under live/{host}/ (no scheme).
     * @param list<string> $slugs Storefront paths without locale/currency prefix (e.g. policy/privacy).
     */
    public static function purgeSlugs(array $hosts, array $slugs): void
    {
        $stop = self::writeBaseRoot();
        $normalizedSlugs = [];
        foreach ($slugs as $slug) {
            $slug = \trim(\str_replace('\\', '/', (string)$slug), '/');
            if ($slug === '' || \str_contains($slug, '..')) {
                continue;
            }
            if (\preg_match('#^[a-z0-9][a-z0-9_./-]*$#Di', $slug) !== 1) {
                continue;
            }
            $normalizedSlugs[$slug] = $slug;
        }
        if ($normalizedSlugs === []) {
            return;
        }
        foreach ($hosts as $host) {
            $host = \strtolower(\trim((string)$host));
            if ($host === '' || \preg_match('/^[a-z0-9._:-]+$/Di', $host) !== 1) {
                continue;
            }
            $hostRoot = $stop . \DIRECTORY_SEPARATOR . $host;
            if (!\is_dir($hostRoot)) {
                continue;
            }
            foreach ($normalizedSlugs as $slug) {
                self::purgeSlugUnderHostRoot($hostRoot, $slug, $stop);
            }
        }
    }

    /**
     * Whole-tree soft invalidate: flip live to a new empty generation.
     * Previous live becomes `previous` (rollback). Older gens are GC'd.
     */
    public static function purgeAll(): void
    {
        self::rotateToEmptyGeneration();
    }

    /**
     * Open a staging generation for rebuild-then-promote (theme publish warm).
     * Subsequent publish()/purgeUrls() write into staging until promote/abandon.
     * When $seedFromLive is true (default), copy current live tree into staging
     * so a partial warm + promote cannot flip visitors onto a near-empty gen.
     */
    public static function beginStagingGeneration(bool $seedFromLive = true): string
    {
        self::ensureLiveGeneration();
        $genId = self::newGenerationId();
        $dir = self::generationsRoot() . \DIRECTORY_SEPARATOR . $genId;
        if (!self::ensureDirectory($dir)) {
            throw new \RuntimeException('fpc_html_disk_staging_mkdir_failed');
        }
        if ($seedFromLive) {
            self::seedGenerationFromLive($genId);
        }
        $marker = self::root() . \DIRECTORY_SEPARATOR . self::STAGING_ID_FILE;
        if (@\file_put_contents($marker, $genId . "\n", \LOCK_EX) === false) {
            throw new \RuntimeException('fpc_html_disk_staging_marker_failed');
        }

        return $genId;
    }

    public static function stagingGenerationId(): ?string
    {
        $marker = self::root() . \DIRECTORY_SEPARATOR . self::STAGING_ID_FILE;
        if (!\is_file($marker)) {
            return null;
        }
        $id = \trim((string)@\file_get_contents($marker));

        return self::isSafeGenerationId($id) ? $id : null;
    }

    /**
     * Atomically point live at the staging generation; prior live → previous.
     */
    public static function promoteStagingGeneration(): bool
    {
        $stagingId = self::stagingGenerationId();
        if ($stagingId === null) {
            return false;
        }
        $stagingDir = self::generationsRoot() . \DIRECTORY_SEPARATOR . $stagingId;
        if (!\is_dir($stagingDir)) {
            @\unlink(self::root() . \DIRECTORY_SEPARATOR . self::STAGING_ID_FILE);

            return false;
        }
        $ok = self::flipLiveToGeneration($stagingId);
        @\unlink(self::root() . \DIRECTORY_SEPARATOR . self::STAGING_ID_FILE);

        return $ok;
    }

    public static function abandonStagingGeneration(): void
    {
        $stagingId = self::stagingGenerationId();
        @\unlink(self::root() . \DIRECTORY_SEPARATOR . self::STAGING_ID_FILE);
        if ($stagingId === null) {
            return;
        }
        $liveId = self::readSymlinkGenerationId(self::LIVE_NAME);
        $prevId = self::readSymlinkGenerationId(self::PREVIOUS_NAME);
        if ($stagingId === $liveId || $stagingId === $prevId) {
            return;
        }
        self::purgeTree(self::generationsRoot() . \DIRECTORY_SEPARATOR . $stagingId);
    }

    /**
     * Point live back at previous generation (swap). Returns false when no previous.
     */
    public static function rollbackToPrevious(): bool
    {
        self::ensureLiveGeneration();
        $prevId = self::readSymlinkGenerationId(self::PREVIOUS_NAME);
        if ($prevId === null || $prevId === '') {
            return false;
        }
        $prevDir = self::generationsRoot() . \DIRECTORY_SEPARATOR . $prevId;
        if (!\is_dir($prevDir)) {
            return false;
        }
        $currentId = self::readSymlinkGenerationId(self::LIVE_NAME);

        return self::flipLiveToGeneration($prevId, $currentId);
    }

    /**
     * Legacy hash cold-disk trees (transition only — not generation HTML).
     */
    public static function purgeLegacyHashTrees(): void
    {
        $base = \rtrim((string)BP, '\\/') . \DIRECTORY_SEPARATOR . 'var' . \DIRECTORY_SEPARATOR . 'cache';
        self::purgeTree($base . \DIRECTORY_SEPARATOR . 'router-fpc-payloads');
        self::purgeTree($base . \DIRECTORY_SEPARATOR . 'router-fpc-path-index');
    }

    public static function rotateToEmptyGeneration(): string
    {
        self::ensureLiveGeneration();
        $genId = self::newGenerationId();
        $dir = self::generationsRoot() . \DIRECTORY_SEPARATOR . $genId;
        if (!self::ensureDirectory($dir)) {
            throw new \RuntimeException('fpc_html_disk_rotate_mkdir_failed');
        }
        if (!self::flipLiveToGeneration($genId)) {
            throw new \RuntimeException('fpc_html_disk_rotate_flip_failed');
        }

        return $genId;
    }

    private static function writeBaseRoot(): string
    {
        self::ensureLiveGeneration();
        $stagingId = self::stagingGenerationId();
        if ($stagingId !== null) {
            return self::generationsRoot() . \DIRECTORY_SEPARATOR . $stagingId;
        }

        return self::liveRoot();
    }

    /**
     * Copy live generation contents into a new staging gen (files preferred as hardlinks).
     */
    private static function seedGenerationFromLive(string $genId): void
    {
        if (!self::isSafeGenerationId($genId)) {
            throw new \RuntimeException('fpc_html_disk_staging_seed_bad_id');
        }
        $liveId = self::readSymlinkGenerationId(self::LIVE_NAME);
        if ($liveId === null || $liveId === '' || $liveId === $genId) {
            return;
        }
        $src = self::generationsRoot() . \DIRECTORY_SEPARATOR . $liveId;
        $dest = self::generationsRoot() . \DIRECTORY_SEPARATOR . $genId;
        if (!\is_dir($src)) {
            return;
        }
        self::mirrorTree($src, $dest);
    }

    private static function mirrorTree(string $src, string $dest): void
    {
        if (!self::ensureDirectory($dest)) {
            throw new \RuntimeException('fpc_html_disk_staging_seed_mkdir_failed');
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        $srcPrefix = \rtrim(\str_replace(['/', '\\'], \DIRECTORY_SEPARATOR, $src), \DIRECTORY_SEPARATOR)
            . \DIRECTORY_SEPARATOR;
        foreach ($iterator as $node) {
            if (!$node instanceof \SplFileInfo) {
                continue;
            }
            $path = $node->getPathname();
            $normalized = \str_replace(['/', '\\'], \DIRECTORY_SEPARATOR, $path);
            if (!\str_starts_with($normalized, $srcPrefix) && $normalized !== \rtrim($srcPrefix, \DIRECTORY_SEPARATOR)) {
                continue;
            }
            $rel = \substr($normalized, \strlen($srcPrefix));
            if ($rel === false || $rel === '') {
                continue;
            }
            $target = $dest . \DIRECTORY_SEPARATOR . $rel;
            if ($node->isDir() && !$node->isLink()) {
                if (!self::ensureDirectory($target)) {
                    throw new \RuntimeException('fpc_html_disk_staging_seed_mkdir_failed');
                }
                continue;
            }
            $parent = \dirname($target);
            if (!self::ensureDirectory($parent)) {
                throw new \RuntimeException('fpc_html_disk_staging_seed_mkdir_failed');
            }
            if (\is_file($target) || \is_link($target)) {
                @\unlink($target);
            }
            // Prefer hardlink (COW-friendly); fall back to byte copy.
            if (!@\link($path, $target) && !@\copy($path, $target)) {
                throw new \RuntimeException('fpc_html_disk_staging_seed_copy_failed');
            }
        }
    }

    private static function ensureLiveGeneration(): void
    {
        $root = self::root();
        if (!\is_dir($root) && !@\mkdir($root, 0775, true) && !\is_dir($root)) {
            throw new \RuntimeException('fpc_html_disk_root_mkdir_failed');
        }
        $gens = self::generationsRoot();
        if (!\is_dir($gens) && !@\mkdir($gens, 0775, true) && !\is_dir($gens)) {
            throw new \RuntimeException('fpc_html_disk_generations_mkdir_failed');
        }

        $livePath = $root . \DIRECTORY_SEPARATOR . self::LIVE_NAME;
        if (self::isValidLivePointer($livePath)) {
            return;
        }

        // Migrate legacy flat html/{host}/… into the first generation.
        $genId = self::newGenerationId();
        $genDir = $gens . \DIRECTORY_SEPARATOR . $genId;
        if (!@\mkdir($genDir, 0775, true) && !\is_dir($genDir)) {
            throw new \RuntimeException('fpc_html_disk_bootstrap_mkdir_failed');
        }
        self::migrateLegacyHostTreesInto($genDir);
        if (!self::replaceSymlink($livePath, self::GENERATIONS_DIR . '/' . $genId)) {
            throw new \RuntimeException('fpc_html_disk_bootstrap_live_link_failed');
        }
    }

    private static function isValidLivePointer(string $livePath): bool
    {
        if (\is_link($livePath)) {
            $target = @\readlink($livePath);
            if (!\is_string($target) || $target === '') {
                return false;
            }
            $resolved = \realpath($livePath);

            return $resolved !== false && \is_dir($resolved);
        }
        // Refuse treating a plain directory named live as pointer.
        return false;
    }

    private static function migrateLegacyHostTreesInto(string $genDir): void
    {
        $root = self::root();
        $items = @\scandir($root);
        if (!\is_array($items)) {
            return;
        }
        foreach ($items as $name) {
            if ($name === '.' || $name === '..'
                || $name === self::LIVE_NAME
                || $name === self::PREVIOUS_NAME
                || $name === self::GENERATIONS_DIR
                || $name === self::STAGING_ID_FILE
            ) {
                continue;
            }
            $src = $root . \DIRECTORY_SEPARATOR . $name;
            if (!\is_dir($src) || \is_link($src)) {
                continue;
            }
            // Host-looking dirs only (same safety as relativePageDir host).
            if (\preg_match('/^[a-z0-9._:-]+$/Di', $name) !== 1) {
                continue;
            }
            $dest = $genDir . \DIRECTORY_SEPARATOR . $name;
            if (\is_dir($dest)) {
                continue;
            }
            @\rename($src, $dest);
        }
    }

    private static function flipLiveToGeneration(string $newGenId, ?string $forcePreviousId = null): bool
    {
        if (!self::isSafeGenerationId($newGenId)) {
            return false;
        }
        $root = self::root();
        $livePath = $root . \DIRECTORY_SEPARATOR . self::LIVE_NAME;
        $prevPath = $root . \DIRECTORY_SEPARATOR . self::PREVIOUS_NAME;
        $currentId = self::readSymlinkGenerationId(self::LIVE_NAME);
        $previousId = $forcePreviousId !== null ? $forcePreviousId : $currentId;

        if (!self::replaceSymlink($livePath, self::GENERATIONS_DIR . '/' . $newGenId)) {
            return false;
        }
        if ($previousId !== null && $previousId !== '' && $previousId !== $newGenId) {
            self::replaceSymlink($prevPath, self::GENERATIONS_DIR . '/' . $previousId);
        }
        self::gcOldGenerations([$newGenId, $previousId]);

        return true;
    }

    /**
     * @param list<?string> $keepIds
     */
    private static function gcOldGenerations(array $keepIds): void
    {
        $keep = [];
        foreach ($keepIds as $id) {
            if (\is_string($id) && self::isSafeGenerationId($id)) {
                $keep[$id] = true;
            }
        }
        // Always keep whatever live/previous currently resolve to.
        foreach ([self::LIVE_NAME, self::PREVIOUS_NAME] as $link) {
            $id = self::readSymlinkGenerationId($link);
            if ($id !== null) {
                $keep[$id] = true;
            }
        }
        $staging = self::stagingGenerationId();
        if ($staging !== null) {
            $keep[$staging] = true;
        }

        $gensRoot = self::generationsRoot();
        $items = @\scandir($gensRoot);
        if (!\is_array($items)) {
            return;
        }
        $candidates = [];
        foreach ($items as $name) {
            if ($name === '.' || $name === '..' || isset($keep[$name])) {
                continue;
            }
            if (!self::isSafeGenerationId($name)) {
                continue;
            }
            $path = $gensRoot . \DIRECTORY_SEPARATOR . $name;
            if (\is_dir($path)) {
                $candidates[] = $name;
            }
        }
        // Retain policy: keep at most RETAIN_GENERATIONS named by keep set; delete the rest.
        // keep set already encodes live+previous (+staging). Delete all candidates.
        foreach ($candidates as $name) {
            self::purgeTree($gensRoot . \DIRECTORY_SEPARATOR . $name);
        }
        unset($keep); // RETAIN_GENERATIONS documented; keep set enforces live+previous.
    }

    private static function readSymlinkGenerationId(string $linkName): ?string
    {
        $path = self::root() . \DIRECTORY_SEPARATOR . $linkName;
        if (!\is_link($path)) {
            return null;
        }
        $target = @\readlink($path);
        if (!\is_string($target) || $target === '') {
            return null;
        }
        $target = \str_replace('\\', '/', $target);
        if (\preg_match('#(?:^|/)' . \preg_quote(self::GENERATIONS_DIR, '#') . '/([A-Za-z0-9._-]+)$#', $target, $m) === 1) {
            return self::isSafeGenerationId($m[1]) ? $m[1] : null;
        }
        $base = \basename($target);

        return self::isSafeGenerationId($base) ? $base : null;
    }

    private static function replaceSymlink(string $linkPath, string $relativeTarget): bool
    {
        $dir = \dirname($linkPath);
        $tmp = $dir . \DIRECTORY_SEPARATOR . '.link-' . \bin2hex(\random_bytes(6));
        if (\function_exists('symlink')) {
            if (!@\symlink($relativeTarget, $tmp)) {
                @\unlink($tmp);

                return false;
            }
        } else {
            return false;
        }
        if (\is_link($linkPath) || \file_exists($linkPath)) {
            // Atomic replace on same filesystem.
            if (!@\rename($tmp, $linkPath)) {
                @\unlink($tmp);
                // Fallback: unlink then rename.
                @\unlink($linkPath);
                if (\is_dir($linkPath) && !\is_link($linkPath)) {
                    return false;
                }
                if (!@\rename($tmp, $linkPath)) {
                    @\unlink($tmp);

                    return false;
                }
            }
        } else {
            if (!@\rename($tmp, $linkPath)) {
                @\unlink($tmp);

                return false;
            }
        }

        return \is_link($linkPath);
    }

    private static function newGenerationId(): string
    {
        try {
            return \date('YmdHis') . '-' . \bin2hex(\random_bytes(4));
        } catch (\Throwable) {
            return \date('YmdHis') . '-' . \str_replace('.', '', (string)\microtime(true));
        }
    }

    private static function isSafeGenerationId(string $id): bool
    {
        return $id !== '' && \preg_match('/^[A-Za-z0-9._-]+$/D', $id) === 1
            && !\str_contains($id, '..');
    }

    private static function relativePageDir(string $host, string $path): ?string
    {
        $host = \strtolower(\trim($host));
        if ($host === '' || \str_contains($host, '..') || \str_contains($host, '/') || \str_contains($host, '\\')) {
            return null;
        }
        if (\preg_match('/^[a-z0-9._:-]+$/D', $host) !== 1) {
            return null;
        }
        $path = self::normalizePath($path);
        if ($path === '/') {
            return $host;
        }
        $segments = \explode('/', \trim($path, '/'));
        $safe = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
            if (\preg_match('/^[A-Za-z0-9._~%-]+$/D', $segment) !== 1) {
                return null;
            }
            $safe[] = $segment;
        }

        return $host . \DIRECTORY_SEPARATOR . \implode(\DIRECTORY_SEPARATOR, $safe);
    }

    private static function normalizePath(string $raw): string
    {
        $raw = \trim($raw);
        if ($raw === '') {
            return '/';
        }
        if ($raw[0] !== '/') {
            $raw = '/' . \ltrim($raw, '/');
        }
        if ($raw !== '/') {
            $raw = \rtrim($raw, '/');
            if ($raw === '') {
                $raw = '/';
            }
        }

        return $raw;
    }

    private static function ensureDirectory(string $dir): bool
    {
        if (\is_dir($dir)) {
            return self::isUnderRoot($dir);
        }
        if (!@\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            return false;
        }

        return self::isUnderRoot($dir);
    }

    private static function writeAtomic(string $path, string $bytes): bool
    {
        if (!self::isUnderRoot($path)) {
            return false;
        }
        $dir = \dirname($path);
        $tmp = $dir . \DIRECTORY_SEPARATOR . '.tmp-' . \bin2hex(\random_bytes(8));
        if (@\file_put_contents($tmp, $bytes, \LOCK_EX) === false) {
            @\unlink($tmp);

            return false;
        }
        if (!@\rename($tmp, $path)) {
            @\unlink($tmp);

            return false;
        }

        return true;
    }

    private static function purgeSlugUnderHostRoot(string $hostRoot, string $slugRel, string $stopRoot): void
    {
        $slugFs = \str_replace('/', \DIRECTORY_SEPARATOR, $slugRel);
        $candidates = [
            $hostRoot . \DIRECTORY_SEPARATOR . $slugFs,
        ];
        $level1 = @\scandir($hostRoot);
        if (\is_array($level1)) {
            foreach ($level1 as $a) {
                if ($a === '.' || $a === '..') {
                    continue;
                }
                $pathA = $hostRoot . \DIRECTORY_SEPARATOR . $a;
                if (!\is_dir($pathA) || \is_link($pathA)) {
                    continue;
                }
                if (!self::isLocaleOrCurrencyDirName($a)) {
                    continue;
                }
                $candidates[] = $pathA . \DIRECTORY_SEPARATOR . $slugFs;
                $level2 = @\scandir($pathA);
                if (!\is_array($level2)) {
                    continue;
                }
                foreach ($level2 as $b) {
                    if ($b === '.' || $b === '..') {
                        continue;
                    }
                    $pathB = $pathA . \DIRECTORY_SEPARATOR . $b;
                    if (!\is_dir($pathB) || \is_link($pathB)) {
                        continue;
                    }
                    if (!self::isLocaleOrCurrencyDirName($b)) {
                        continue;
                    }
                    $candidates[] = $pathB . \DIRECTORY_SEPARATOR . $slugFs;
                }
            }
        }
        foreach ($candidates as $dir) {
            if (!\is_dir($dir)) {
                continue;
            }
            self::unlinkPageFiles($dir);
            self::removeEmptyParents($dir, $stopRoot);
        }
    }

    private static function isLocaleOrCurrencyDirName(string $name): bool
    {
        if (\preg_match('/^[A-Z]{3}$/', $name) === 1) {
            return true;
        }

        return \preg_match('/^[a-z]{2}(_[A-Za-z0-9]+)+$/', $name) === 1;
    }

    private static function unlinkPageFiles(string $dir): void
    {
        foreach (['index.html', 'index.html.br', self::BQ_BASENAME, self::BQ_BASENAME . '.br'] as $name) {
            $file = $dir . \DIRECTORY_SEPARATOR . $name;
            if (\is_file($file)) {
                @\unlink($file);
            }
        }
    }

    private static function removeEmptyParents(string $dir, string $stopRoot): void
    {
        $stop = \rtrim(\str_replace(['/', '\\'], \DIRECTORY_SEPARATOR, $stopRoot), \DIRECTORY_SEPARATOR);
        $current = \rtrim(\str_replace(['/', '\\'], \DIRECTORY_SEPARATOR, $dir), \DIRECTORY_SEPARATOR);
        while ($current !== '' && $current !== $stop && \str_starts_with($current, $stop . \DIRECTORY_SEPARATOR)) {
            if (!\is_dir($current)) {
                break;
            }
            $items = @\scandir($current);
            if (!\is_array($items)) {
                break;
            }
            $children = \array_values(\array_filter($items, static fn(string $n): bool => $n !== '.' && $n !== '..'));
            if ($children !== []) {
                break;
            }
            if (!@\rmdir($current)) {
                break;
            }
            $parent = \dirname($current);
            if ($parent === $current) {
                break;
            }
            $current = $parent;
        }
    }

    private static function purgeTree(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $node) {
            if (!$node instanceof \SplFileInfo) {
                continue;
            }
            $path = $node->getPathname();
            if ($node->isLink()) {
                @\unlink($path);
            } elseif ($node->isDir()) {
                @\rmdir($path);
            } elseif ($node->isFile()) {
                @\unlink($path);
            }
        }
        @\rmdir($dir);
    }

    private static function isUnderRoot(string $path): bool
    {
        $rootConfigured = \rtrim(\str_replace(['/', '\\'], \DIRECTORY_SEPARATOR, self::root()), \DIRECTORY_SEPARATOR);
        $normalized = \str_replace(['/', '\\'], \DIRECTORY_SEPARATOR, $path);
        $prefix = $rootConfigured . \DIRECTORY_SEPARATOR;
        if (\str_starts_with($normalized, $prefix) || $normalized === $rootConfigured) {
            return true;
        }
        $rootReal = \realpath(self::root());
        if ($rootReal === false) {
            return false;
        }
        $real = \realpath($path);
        if ($real === false) {
            return \str_starts_with($normalized, $rootReal . \DIRECTORY_SEPARATOR);
        }

        return $real === $rootReal || \str_starts_with($real, $rootReal . \DIRECTORY_SEPARATOR);
    }
}
