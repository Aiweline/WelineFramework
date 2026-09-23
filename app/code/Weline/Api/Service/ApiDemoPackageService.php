<?php

declare(strict_types=1);

namespace Weline\Api\Service;

use Weline\Framework\App\Env;

/**
 * API Demo 包协助：探测模块 source/api-demo、投影下载 URL、realpath 卡死、zip 打包。
 *
 * 权威源仅：{moduleBase}/source/api-demo/{demo_id}/{php|js}/
 */
class ApiDemoPackageService
{
    public const LANG_PHP = 'php';
    public const LANG_JS = 'js';

    /** @var list<string> */
    public const SUPPORTED_LANGS = [self::LANG_PHP, self::LANG_JS];

    /** @var callable(string):(?string) */
    private $moduleBasePathResolver;

    /**
     * @param callable(string):(?string)|null $moduleBasePathResolver
     */
    public function __construct(?callable $moduleBasePathResolver = null)
    {
        $this->moduleBasePathResolver = $moduleBasePathResolver ?? static function (string $moduleName): ?string {
            $info = Env::getInstance()->getModuleInfo($moduleName);
            if (!\is_array($info)) {
                return null;
            }
            $base = (string)($info['base_path'] ?? '');
            if ($base === '') {
                return null;
            }

            return \rtrim($base, "/\\");
        };
    }

    /**
     * @param mixed $demo provider descriptor `demo`（true → 同名 provider；字符串 → 字面 id）
     */
    public function resolveDemoId(mixed $demo, string $provider): ?string
    {
        $provider = \trim($provider);
        if ($demo === true || $demo === 1) {
            return $this->isValidDemoId($provider) ? $provider : null;
        }

        if (!\is_string($demo)) {
            return null;
        }

        $demoId = \trim($demo);
        if ($demoId === '') {
            return null;
        }

        return $this->isValidDemoId($demoId) ? $demoId : null;
    }

    /**
     * @return list<'php'|'js'>
     */
    public function listAvailableLangs(string $moduleName, string $demoId): array
    {
        if (!$this->isValidModuleName($moduleName) || !$this->isValidDemoId($demoId)) {
            return [];
        }

        $apiDemoRoot = $this->resolveApiDemoRoot($moduleName);
        if ($apiDemoRoot === null) {
            return [];
        }

        $found = [];
        foreach (self::SUPPORTED_LANGS as $lang) {
            $dir = $apiDemoRoot . DIRECTORY_SEPARATOR . $demoId . DIRECTORY_SEPARATOR . $lang;
            if ($this->isNonEmptyDirectory($dir)) {
                $found[] = $lang;
            }
        }

        return $found;
    }

    /**
     * @return list<array{lang: string, label: string, url: string}>
     */
    public function buildDownloadUrls(string $moduleName, string $demoId): array
    {
        $langs = $this->listAvailableLangs($moduleName, $demoId);
        if ($langs === []) {
            return [];
        }

        $demos = [];
        foreach ($langs as $lang) {
            $label = $lang === self::LANG_PHP ? '下载 PHP Demo' : '下载 JS Demo';
            if (\function_exists('__')) {
                $label = (string)\__($label);
            }
            $demos[] = [
                'lang' => $lang,
                'label' => $label,
                'url' => $this->buildDownloadUrl($moduleName, $demoId, $lang),
            ];
        }

        return $demos;
    }

    /**
     * realpath 卡死在 {moduleBase}/source/api-demo/ 下，返回绝对语言目录。
     *
     * @throws \InvalidArgumentException
     */
    public function assertSafeDemoDir(string $moduleName, string $demoId, string $lang): string
    {
        $lang = \strtolower(\trim($lang));
        if (!$this->isValidModuleName($moduleName)) {
            throw new \InvalidArgumentException('Invalid module name.');
        }
        if (!$this->isValidDemoId($demoId)) {
            throw new \InvalidArgumentException('Invalid demo id.');
        }
        if (!\in_array($lang, self::SUPPORTED_LANGS, true)) {
            throw new \InvalidArgumentException('Invalid demo lang.');
        }

        $apiDemoRoot = $this->resolveApiDemoRoot($moduleName);
        if ($apiDemoRoot === null) {
            throw new \InvalidArgumentException('API demo root not found.');
        }

        $candidate = $apiDemoRoot . DIRECTORY_SEPARATOR . $demoId . DIRECTORY_SEPARATOR . $lang;
        $resolved = \realpath($candidate);
        if ($resolved === false || !\is_dir($resolved)) {
            throw new \InvalidArgumentException('Demo directory not found.');
        }

        $normalizedRoot = \rtrim($apiDemoRoot, "/\\") . DIRECTORY_SEPARATOR;
        $normalizedResolved = \rtrim($resolved, "/\\") . DIRECTORY_SEPARATOR;
        if (!\str_starts_with($normalizedResolved, $normalizedRoot)) {
            throw new \InvalidArgumentException('Demo path escapes api-demo root.');
        }

        if (!$this->isNonEmptyDirectory($resolved)) {
            throw new \InvalidArgumentException('Demo directory is empty.');
        }

        return $resolved;
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function zipDemo(string $moduleName, string $demoId, string $lang): string
    {
        $directory = $this->assertSafeDemoDir($moduleName, $demoId, $lang);

        if (!\class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('ZipArchive is not available.');
        }

        $zipPath = \tempnam(\sys_get_temp_dir(), 'weline-api-demo-');
        if ($zipPath === false) {
            throw new \RuntimeException('Failed to create temp zip file.');
        }

        try {
            $zip = new \ZipArchive();
            $openResult = $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            if ($openResult !== true) {
                throw new \RuntimeException('Failed to open zip archive.');
            }

            $rootName = $demoId . '-' . \strtolower(\trim($lang));
            $this->addDirectoryToZip($zip, $directory, $rootName);
            $zip->close();

            $content = \file_get_contents($zipPath);
            if ($content === false || $content === '') {
                throw new \RuntimeException('Failed to read zip archive.');
            }

            return $content;
        } finally {
            if (\is_file($zipPath)) {
                @\unlink($zipPath);
            }
        }
    }

    public function buildDownloadUrl(string $moduleName, string $demoId, string $lang): string
    {
        $query = \http_build_query([
            'module' => $moduleName,
            'demo' => $demoId,
            'lang' => $lang,
        ], '', '&', \PHP_QUERY_RFC3986);

        return '/api/api-demo/download?' . $query;
    }

    public function isValidDemoId(string $demoId): bool
    {
        return $demoId !== '' && \preg_match('/^[a-z0-9_]+$/', $demoId) === 1;
    }

    public function isValidModuleName(string $moduleName): bool
    {
        return \preg_match('/^[A-Za-z][A-Za-z0-9]*_[A-Za-z][A-Za-z0-9]*$/', $moduleName) === 1;
    }

    private function resolveApiDemoRoot(string $moduleName): ?string
    {
        $base = ($this->moduleBasePathResolver)($moduleName);
        if (!\is_string($base) || $base === '') {
            return null;
        }

        $root = \realpath($base . DIRECTORY_SEPARATOR . 'source' . DIRECTORY_SEPARATOR . 'api-demo');
        if ($root === false || !\is_dir($root)) {
            return null;
        }

        return $root;
    }

    private function isNonEmptyDirectory(string $path): bool
    {
        if (!\is_dir($path)) {
            return false;
        }

        $items = @\scandir($path);
        if ($items === false) {
            return false;
        }

        foreach ($items as $item) {
            if ($item !== '.' && $item !== '..') {
                return true;
            }
        }

        return false;
    }

    private function addDirectoryToZip(\ZipArchive $zip, string $directory, string $rootName): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $baseLength = \strlen(\rtrim($directory, "\\/")) + 1;
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }

            $relativePath = \substr($item->getPathname(), $baseLength);
            if ($relativePath === false || $relativePath === '') {
                continue;
            }

            $zipPath = \str_replace('\\', '/', $rootName . '/' . $relativePath);
            if ($item->isDir()) {
                $zip->addEmptyDir($zipPath);
                continue;
            }

            if ($item->isFile()) {
                $zip->addFile($item->getPathname(), $zipPath);
            }
        }
    }
}
