<?php

declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\Framework\App\Env;

/**
 * I18n 收集增量指纹：按模块记录「该模块被收集实际扫描的源文件集合」的 mtime/文件数/总字节，
 * 用于 cron 按模块增量收集，避免每小时全量重扫。
 *
 * 扫描口径与 {@see TranslationCollector::collectLazy()}（源码扫描）及
 * `DictionaryCompiler::discoverModuleCsvFiles()`（模块 i18n/*.csv）一致：
 * - 扩展名 php / phtml / js（TranslationCollector::SCAN_FILE_EXTENSIONS）；
 * - 跳过 TranslationCollector::EXCLUDED_DIRS 中的目录；
 * - 跳过 0 字节与超过 512KB 的文件（TranslationCollector::MAX_FILE_SIZE）；
 * - 额外包含模块 i18n/ 下的 CSV（收集读取的源语言包；收集成功后会回写，故 remember() 必须在收集之后调用）。
 *
 * 上述三个常量是 TranslationCollector 的私有常量，这里用反射取值并保留本地兜底，
 * 避免复制常量后与扫描口径漂移。
 */
final class I18nCollectFingerprint
{
    /** 状态文件格式版本：口径变更时递增，旧状态整体视为「有变化」。 */
    private const STATE_VERSION = 1;

    private const DEFAULT_MAX_FILE_SIZE = 524288;

    /** @var array<string, true> */
    private const DEFAULT_SCAN_EXTENSIONS = [
        'php' => true,
        'phtml' => true,
        'js' => true,
    ];

    /** @var array<string, true> */
    private const DEFAULT_EXCLUDED_DIRS = [
        'node_modules' => true,
        'vendor' => true,
        'dist' => true,
        'build' => true,
        'generated' => true,
        'var' => true,
        'pub' => true,
        'doc' => true,
        'docs' => true,
        'test' => true,
        'tests' => true,
        'coverage' => true,
        'tmp' => true,
        'temp' => true,
        'log' => true,
        'logs' => true,
        'cache' => true,
    ];

    /** @var array{excluded: array<string, true>, extensions: array<string, true>, max_size: int}|null */
    private static ?array $scanProfile = null;

    /**
     * @param string $stateFile 状态文件路径；默认 VAR_DIR/state/i18n_collect.json
     * @param array<string, string> $moduleDirectories 模块名 => 模块根目录；默认取 Env 活跃模块（单测注入用）
     */
    public function __construct(
        private readonly string $stateFile = '',
        private readonly array $moduleDirectories = [],
    ) {
    }

    /**
     * 需要重扫的模块名（指纹缺失或不一致的活跃模块）。
     *
     * @return list<string>
     */
    public function changedModules(): array
    {
        \clearstatcache(true);
        $directories = $this->resolveModuleDirectories();
        if ($directories === []) {
            return [];
        }

        $state = $this->readState();
        $changed = [];
        foreach ($directories as $module => $directory) {
            $fingerprint = $this->describe($directory)['fingerprint'];
            $recorded = $state['modules'][$module]['fingerprint'] ?? null;
            if (!\is_string($recorded) || $recorded !== $fingerprint) {
                $changed[] = (string)$module;
            }
        }

        \sort($changed);

        return $changed;
    }

    /**
     * 记录本轮已成功收集的模块（按收集后的磁盘状态计算指纹）。
     *
     * @param list<string> $modules
     */
    public function remember(array $modules): void
    {
        if ($modules === []) {
            return;
        }
        $directories = $this->resolveModuleDirectories();
        if ($directories === []) {
            return;
        }

        \clearstatcache(true);
        $state = $this->readState();
        $now = \date('c');
        $dirty = false;
        foreach ($modules as $module) {
            $module = \trim((string)$module);
            if ($module === '' || !isset($directories[$module])) {
                continue;
            }
            $state['modules'][$module] = $this->describe($directories[$module]) + ['collected_at' => $now];
            $dirty = true;
        }
        if (!$dirty) {
            return;
        }

        $state['version'] = self::STATE_VERSION;
        $state['updated_at'] = $now;
        $this->writeState($state);
    }

    /**
     * @return array{fingerprint: string, max_mtime: int, file_count: int, total_bytes: int}
     */
    private function describe(string $directory): array
    {
        $maxMtime = 0;
        $fileCount = 0;
        $totalBytes = 0;

        foreach ($this->scanFiles($directory) as $file) {
            $stat = @\stat($file);
            if (!\is_array($stat)) {
                continue;
            }
            $maxMtime = \max($maxMtime, (int)($stat['mtime'] ?? 0));
            $fileCount++;
            $totalBytes += (int)($stat['size'] ?? 0);
        }

        return [
            'fingerprint' => \hash('sha256', self::STATE_VERSION . '|' . $maxMtime . '|' . $fileCount . '|' . $totalBytes),
            'max_mtime' => $maxMtime,
            'file_count' => $fileCount,
            'total_bytes' => $totalBytes,
        ];
    }

    /**
     * 产出该模块被收集扫描的文件绝对路径（只 stat，不读文件内容）。
     *
     * @return \Generator<int, string>
     */
    private function scanFiles(string $directory): \Generator
    {
        $directory = \rtrim($directory, '/\\');
        if ($directory === '' || !\is_dir($directory)) {
            return;
        }

        $profile = $this->scanProfile();
        $i18nPrefix = \str_replace('\\', '/', $directory . '/i18n') . '/';

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator(
                    $directory,
                    \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO,
                ),
                static function (\SplFileInfo $current) use ($profile): bool {
                    if (!$current->isDir()) {
                        return true;
                    }

                    return !isset($profile['excluded'][\strtolower($current->getFilename())]);
                },
            ),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            $extension = \strtolower($file->getExtension());
            if ($extension === 'csv') {
                // 只有模块 i18n/ 下的 CSV 会被收集读取（DictionaryCompiler::discoverModuleCsvFiles）
                if (!\str_starts_with(\str_replace('\\', '/', $path), $i18nPrefix)) {
                    continue;
                }
                yield $path;
                continue;
            }
            if (!isset($profile['extensions'][$extension])) {
                continue;
            }
            $size = $file->getSize();
            if ($size === 0 || $size > $profile['max_size']) {
                continue;
            }
            yield $path;
        }
    }

    /**
     * @return array{excluded: array<string, true>, extensions: array<string, true>, max_size: int}
     */
    private function scanProfile(): array
    {
        if (self::$scanProfile !== null) {
            return self::$scanProfile;
        }

        $excluded = self::DEFAULT_EXCLUDED_DIRS;
        $extensions = self::DEFAULT_SCAN_EXTENSIONS;
        $maxSize = self::DEFAULT_MAX_FILE_SIZE;
        try {
            $reflection = new \ReflectionClass(TranslationCollector::class);
            $value = $reflection->getConstant('EXCLUDED_DIRS');
            if (\is_array($value) && $value !== []) {
                $excluded = $value;
            }
            $value = $reflection->getConstant('SCAN_FILE_EXTENSIONS');
            if (\is_array($value) && $value !== []) {
                $extensions = $value;
            }
            $value = $reflection->getConstant('MAX_FILE_SIZE');
            if (\is_int($value) && $value > 0) {
                $maxSize = $value;
            }
        } catch (\Throwable) {
            // 口径常量取不到时用本地兜底，宁可多扫也不漏扫。
        }

        return self::$scanProfile = [
            'excluded' => $excluded,
            'extensions' => $extensions,
            'max_size' => $maxSize,
        ];
    }

    /**
     * @return array<string, string> 模块名 => 模块根目录
     */
    private function resolveModuleDirectories(): array
    {
        if ($this->moduleDirectories !== []) {
            $directories = [];
            foreach ($this->moduleDirectories as $module => $directory) {
                $directory = \rtrim((string)$directory, '/\\');
                if ($directory === '' || (string)$module === '') {
                    continue;
                }
                $directories[(string)$module] = $directory;
            }

            return $directories;
        }

        try {
            $modules = Env::getInstance()->getActiveModules();
        } catch (\Throwable) {
            return [];
        }

        $directories = [];
        foreach ($modules as $module => $info) {
            $basePath = \rtrim((string)($info['base_path'] ?? ''), '/\\');
            if ($basePath === '' || !\is_dir($basePath)) {
                continue;
            }
            $directories[(string)$module] = $basePath;
        }

        return $directories;
    }

    /**
     * @return array{version?: int, updated_at?: string, modules: array<string, array<string, mixed>>}
     */
    private function readState(): array
    {
        $file = $this->stateFilePath();
        if ($file === '' || !\is_file($file)) {
            return ['modules' => []];
        }
        $raw = @\file_get_contents($file);
        if (!\is_string($raw) || $raw === '') {
            return ['modules' => []];
        }
        $state = \json_decode($raw, true);
        if (!\is_array($state)) {
            return ['modules' => []];
        }
        if ((int)($state['version'] ?? 0) !== self::STATE_VERSION) {
            // 口径版本变化：旧状态不可比，全部当作有变化。
            return ['modules' => []];
        }
        if (!\is_array($state['modules'] ?? null)) {
            return ['modules' => []];
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function writeState(array $state): void
    {
        $file = $this->stateFilePath();
        if ($file === '') {
            return;
        }
        $directory = \dirname($file);
        if (!\is_dir($directory) && !@\mkdir($directory, 0755, true) && !\is_dir($directory)) {
            return;
        }
        $json = \json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!\is_string($json)) {
            return;
        }
        $tmp = $file . '.tmp.' . \getmypid();
        if (@\file_put_contents($tmp, $json) === false) {
            return;
        }
        if (!@\rename($tmp, $file)) {
            @\unlink($tmp);
        }
    }

    private function stateFilePath(): string
    {
        if ($this->stateFile !== '') {
            return $this->stateFile;
        }
        if (!\defined('VAR_DIR')) {
            return '';
        }

        return \rtrim((string)\constant('VAR_DIR'), '/\\') . '/state/i18n_collect.json';
    }
}
