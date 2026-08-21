<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 */

namespace Weline\Theme\Font;

use Weline\Theme\Font\Exception\FontNotFoundException;
use Weline\Theme\Font\TrueType\File as TrueTypeFile;

/**
 * Language / custom-charset font subsetting with on-disk cache.
 *
 * Cache layout:
 * - language: `{cacheRoot}/{sourceHash}/{basename}.{lang}.{charset8}.ttf`
 * - chars:    `{cacheRoot}/{sourceHash}/{basename}.chars.{charset8}.ttf`
 */
class FontSubsetService
{
    public const KIND_LANG = 'lang';

    public const KIND_CHARS = 'chars';

    private LanguageCharsetResolver $charsetResolver;

    private string $cacheRoot;

    public function __construct(
        ?LanguageCharsetResolver $charsetResolver = null,
        ?string $cacheRoot = null
    ) {
        $this->charsetResolver = $charsetResolver ?? new LanguageCharsetResolver();
        $this->cacheRoot = $cacheRoot ?? $this->defaultCacheRoot();
    }

    /**
     * Runtime / upgrade: subset by language. Reuses cache when present.
     *
     * @throws FontNotFoundException
     * @throws \RuntimeException
     */
    public function getSubsetPath(string $sourceFontPath, string $langCode, string $extraChars = ''): string
    {
        $source = $this->assertReadableFont($sourceFontPath);
        $lang = $this->charsetResolver->normalize($langCode);
        $charset = $this->charsetResolver->resolve($lang, $extraChars);

        return $this->resolveOrBuild($source, self::KIND_LANG, $lang, $charset);
    }

    /**
     * Convert an absolute subset cache path to a public URL under /pub/media/font-subset/.
     *
     * @throws \RuntimeException
     */
    public function pathToUrl(string $absoluteSubsetPath): string
    {
        $root = realpath($this->cacheRoot);
        if ($root === false) {
            $root = rtrim(str_replace('\\', '/', $this->cacheRoot), '/');
        } else {
            $root = str_replace('\\', '/', $root);
        }
        $file = realpath($absoluteSubsetPath);
        if ($file === false || !is_file($absoluteSubsetPath)) {
            throw new \RuntimeException('Font subset file not found: ' . $absoluteSubsetPath);
        }
        $file = str_replace('\\', '/', $file);
        $rootTrim = rtrim($root, '/');
        if (!str_starts_with($file, $rootTrim . '/') && $file !== $rootTrim) {
            throw new \RuntimeException('Font subset path escapes cache root');
        }
        $rel = ltrim(substr($file, strlen($rootTrim)), '/');

        return '/pub/media/font-subset/' . $rel;
    }

    /**
     * Whether a language subset cache file already exists (no build).
     */
    public function hasLangSubset(string $sourceFontPath, string $langCode, string $extraChars = ''): bool
    {
        try {
            $source = $this->assertReadableFont($sourceFontPath);
        } catch (FontNotFoundException) {
            return false;
        }

        $lang = $this->charsetResolver->normalize($langCode);
        $charset = $this->charsetResolver->resolve($lang, $extraChars);
        $target = $this->buildTargetPath($source, self::KIND_LANG, $lang, $charset);

        return is_file($target) && filesize($target) > 0;
    }

    /**
     * Ensure language subset exists; skip build when cache hit.
     *
     * @return array{path:string,built:bool,skipped:bool}
     *
     * @throws FontNotFoundException
     * @throws \RuntimeException
     */
    public function ensureLangSubset(string $sourceFontPath, string $langCode, string $extraChars = ''): array
    {
        if ($this->hasLangSubset($sourceFontPath, $langCode, $extraChars)) {
            return [
                'path' => $this->getSubsetPath($sourceFontPath, $langCode, $extraChars),
                'built' => false,
                'skipped' => true,
            ];
        }

        $path = $this->getSubsetPath($sourceFontPath, $langCode, $extraChars);

        return [
            'path' => $path,
            'built' => true,
            'skipped' => false,
        ];
    }

    /**
     * Extract / subset by an explicit character string. Cached by charset fingerprint.
     * Next call with the same source + characters returns the same file.
     *
     * @throws FontNotFoundException
     * @throws \RuntimeException
     */
    public function extractChars(string $sourceFontPath, string $chars): string
    {
        $source = $this->assertReadableFont($sourceFontPath);
        $charset = $this->normalizeChars($chars);
        if ($charset === '') {
            throw new \InvalidArgumentException('extractChars requires a non-empty character string');
        }

        return $this->resolveOrBuild($source, self::KIND_CHARS, 'chars', $charset);
    }

    /**
     * Whether a custom-chars subset cache exists (no build).
     */
    public function hasCharsSubset(string $sourceFontPath, string $chars): bool
    {
        try {
            $source = $this->assertReadableFont($sourceFontPath);
        } catch (FontNotFoundException) {
            return false;
        }

        $charset = $this->normalizeChars($chars);
        if ($charset === '') {
            return false;
        }
        $target = $this->buildTargetPath($source, self::KIND_CHARS, 'chars', $charset);

        return is_file($target) && filesize($target) > 0;
    }

    /**
     * @return array{path:string,built:bool,skipped:bool}
     *
     * @throws FontNotFoundException
     * @throws \RuntimeException
     */
    public function ensureCharsSubset(string $sourceFontPath, string $chars): array
    {
        if ($this->hasCharsSubset($sourceFontPath, $chars)) {
            return [
                'path' => $this->extractChars($sourceFontPath, $chars),
                'built' => false,
                'skipped' => true,
            ];
        }

        $path = $this->extractChars($sourceFontPath, $chars);

        return [
            'path' => $path,
            'built' => true,
            'skipped' => false,
        ];
    }

    public function getCacheRoot(): string
    {
        return $this->cacheRoot;
    }

    public function getCharsetResolver(): LanguageCharsetResolver
    {
        return $this->charsetResolver;
    }

    /**
     * @throws FontNotFoundException
     * @throws \RuntimeException
     */
    private function resolveOrBuild(string $source, string $kind, string $label, string $charset): string
    {
        $target = $this->buildTargetPath($source, $kind, $label, $charset);
        if (is_file($target) && filesize($target) > 0) {
            return $target;
        }

        $dir = dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create font subset cache dir: ' . $dir);
        }

        $tmp = $target . '.' . getmypid() . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $this->buildSubset($source, $tmp, $charset);

        if (!rename($tmp, $target)) {
            @unlink($tmp);
            if (is_file($target) && filesize($target) > 0) {
                return $target;
            }
            throw new \RuntimeException('Unable to publish font subset: ' . $target);
        }

        return $target;
    }

    private function buildTargetPath(string $source, string $kind, string $label, string $charset): string
    {
        $sourceStat = stat($source);
        $sourceKey = hash(
            'sha256',
            $source . '|' . (string)($sourceStat['size'] ?? 0) . '|' . (string)($sourceStat['mtime'] ?? 0)
        );
        $charsetFp = substr(hash('sha256', $charset), 0, 8);
        $base = pathinfo($source, PATHINFO_FILENAME);
        $safeLabel = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $label) ?: 'default';
        $dir = rtrim($this->cacheRoot, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . substr($sourceKey, 0, 2)
            . DIRECTORY_SEPARATOR
            . $sourceKey;

        if ($kind === self::KIND_CHARS) {
            return $dir . DIRECTORY_SEPARATOR . $base . '.chars.' . $charsetFp . '.ttf';
        }

        return $dir . DIRECTORY_SEPARATOR . $base . '.' . $safeLabel . '.' . $charsetFp . '.ttf';
    }

    private function normalizeChars(string $chars): string
    {
        // LATIN_BASE + dedupe via charset resolver (same path as language subsets).
        return $this->charsetResolver->resolve('', $chars);
    }

    /**
     * @throws FontNotFoundException
     * @throws \RuntimeException
     */
    private function buildSubset(string $source, string $targetTmp, string $charset): void
    {
        $font = Font::load($source);
        if (!$font instanceof TrueTypeFile) {
            throw new \RuntimeException('Unsupported font type for subsetting: ' . $source);
        }

        $font->parse();
        $font->setSubset($charset);
        $font->reduce();

        if (file_put_contents($targetTmp, '') === false) {
            $font->close();
            throw new \RuntimeException('Unable to create temp subset file: ' . $targetTmp);
        }

        $font->open($targetTmp, BinaryStream::modeReadWrite);
        $font->encode(['OS/2']);
        $font->close();

        if (!is_file($targetTmp) || filesize($targetTmp) <= 0) {
            @unlink($targetTmp);
            throw new \RuntimeException('Font subset encode produced empty file');
        }
    }

    /**
     * @throws FontNotFoundException
     */
    private function assertReadableFont(string $sourceFontPath): string
    {
        $path = $sourceFontPath;
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new FontNotFoundException($sourceFontPath);
        }

        $real = realpath($path);

        return $real !== false ? $real : $path;
    }

    private function defaultCacheRoot(): string
    {
        if (defined('PUB') && is_string(PUB) && PUB !== '') {
            return rtrim((string)PUB, '/\\') . DIRECTORY_SEPARATOR . 'media'
                . DIRECTORY_SEPARATOR . 'font-subset';
        }
        $base = defined('BP') ? (string)BP : (string)(getcwd() ?: sys_get_temp_dir());

        return rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'pub'
            . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . 'font-subset';
    }
}
