<?php
declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\Dictionary as WordDictionary;
use Weline\I18n\Model\Locale\Dictionary as LocaleDictionary;

/**
 * 系统词典 → generated/language/{locale}.{shared|frontend|backend}.php 唯一写出入口。
 * 三袋互斥：一词只进一区；可选保留 {locale}.php 并集供 CLI。
 *
 * 稀表契约：袋内容 = 模块 CSV 基线 ∪ locale 稀覆盖（DB 后写优先）；
 * 禁止仅依赖整语种 DB 快照。
 */
class AiTranslationPublisher
{
    public function __construct(
        private readonly LocaleDictionary $localeDictionary
    ) {
    }

    /**
     * @param array<string, string>|null $areaByWord word => area；批量 republish 时由调用方一次加载传入
     */
    public function publishLocale(string $localeCode, ?array $areaByWord = null): bool
    {
        $localeCode = trim(str_replace('-', '_', $localeCode));
        if ($localeCode === '') {
            return false;
        }

        $transactions = ObjectManager::getInstance(TransactionCoordinatorInterface::class);
        $connection = $this->localeDictionary->getConnection();
        if ($transactions->isActive($connection)) {
            $transactions->afterCommit($connection, 'i18n.publish_locale.' . $localeCode, function () use ($localeCode, $areaByWord): void {
                if (!$this->publishCommittedLocale($localeCode, $areaByWord)) {
                    throw new \RuntimeException('i18n_locale_file_publish_failed');
                }
            });
            return true;
        }

        return $this->publishCommittedLocale($localeCode, $areaByWord);
    }

    /**
     * 批量导出：area 映射只加载一次。
     *
     * @param list<string> $localeCodes
     * @param array<string, string>|null $areaByWord
     * @return array{published: int, unchanged: int, failed: int}
     */
    public function publishLocales(array $localeCodes, ?array $areaByWord = null): array
    {
        $areaByWord ??= $this->loadBaseWordAreas();
        $published = 0;
        $unchanged = 0;
        $failed = 0;
        foreach ($localeCodes as $localeCode) {
            $localeCode = trim(str_replace('-', '_', (string)$localeCode));
            if ($localeCode === '') {
                continue;
            }
            try {
                $before = $this->localeFileFingerprint($localeCode);
                $ok = $this->publishLocale($localeCode, $areaByWord);
                if (!$ok) {
                    ++$failed;
                    continue;
                }
                $after = $this->localeFileFingerprint($localeCode);
                if ($before !== null && $after !== null && $before === $after) {
                    ++$unchanged;
                } else {
                    ++$published;
                }
            } catch (\Throwable $throwable) {
                ++$failed;
                w_log_error(
                    'I18n locale republish failed for ' . $localeCode . ': ' . $throwable->getMessage(),
                    [],
                    'i18n',
                );
            }
        }

        return [
            'published' => $published,
            'unchanged' => $unchanged,
            'failed' => $failed,
        ];
    }

    /**
     * @return array<string, string> word => area
     */
    public function loadBaseWordAreas(): array
    {
        /** @var WordDictionary $base */
        $base = ObjectManager::getInstance(WordDictionary::class);
        $map = [];
        $query = (clone $base)->clear()->reset()->select();
        foreach ($query->fetchIterator() as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $word = (string)($row[WordDictionary::schema_fields_WORD] ?? '');
            if ($word === '') {
                continue;
            }
            $area = (string)($row[WordDictionary::schema_fields_AREA] ?? '');
            $map[$word] = $area === ''
                ? DictionaryAreaPolicy::AREA_SHARED
                : DictionaryAreaPolicy::normalize($area);
        }

        return $map;
    }

    /**
     * @param array<string, string>|null $areaByWord
     */
    private function publishCommittedLocale(string $localeCode, ?array $areaByWord = null): bool
    {
        $this->ensurePublishMemoryCeiling();
        $dir = Env::path_TRANSLATE_FILES_PATH;
        $unionFile = $dir . $localeCode . '.php';
        $areaFiles = [
            DictionaryAreaPolicy::AREA_SHARED => $dir . DictionaryAreaPolicy::localeBagBasename($localeCode, DictionaryAreaPolicy::AREA_SHARED),
            DictionaryAreaPolicy::AREA_FRONTEND => $dir . DictionaryAreaPolicy::localeBagBasename($localeCode, DictionaryAreaPolicy::AREA_FRONTEND),
            DictionaryAreaPolicy::AREA_BACKEND => $dir . DictionaryAreaPolicy::localeBagBasename($localeCode, DictionaryAreaPolicy::AREA_BACKEND),
        ];
        /** @var array<string, string|null> $previousByPath */
        $previousByPath = [];
        $mode = 0644;
        $replaced = false;
        $areaByWord ??= $this->loadBaseWordAreas();

        $ok = ObjectManager::getInstance(I18nResourceChangePublisher::class)->publishLocaleFile(
            $localeCode,
            function () use ($localeCode, $unionFile, $areaFiles, $areaByWord, &$previousByPath, &$mode, &$replaced): array {
                foreach ([$unionFile, ...array_values($areaFiles)] as $path) {
                    clearstatcache(true, $path);
                    if (is_file($path)) {
                        $prev = file_get_contents($path);
                        if ($prev === false) {
                            throw new \RuntimeException('i18n_locale_file_read_failed');
                        }
                        $previousByPath[$path] = $prev;
                        $mode = fileperms($path) & 0777;
                    } else {
                        $previousByPath[$path] = null;
                    }
                }

                // 稀表契约：袋 = 模块 CSV 基线 ∪ locale 稀覆盖（DB 后写覆盖 CSV）。
                // 禁止把「整语种 DB 快照」当作唯一内容源。
                $bags = $this->mergeLocaleBags($localeCode, $areaByWord);

                $total = count($bags[DictionaryAreaPolicy::AREA_SHARED])
                    + count($bags[DictionaryAreaPolicy::AREA_FRONTEND])
                    + count($bags[DictionaryAreaPolicy::AREA_BACKEND]);
                if ($total === 0 && is_string($previousByPath[$unionFile] ?? null) && $previousByPath[$unionFile] !== '') {
                    w_log_warning('I18n locale publish skipped empty merged bag', [
                        'locale' => $localeCode,
                    ], 'i18n');
                    $replaced = false;

                    return [
                        'content_sha256' => hash('sha256', (string)$previousByPath[$unionFile]),
                        'unchanged' => true,
                    ];
                }

                $hashes = [];
                $union = [];
                /** @var array<string, string> $contentsByPath */
                $contentsByPath = [];
                foreach ($bags as $area => $words) {
                    ksort($words, SORT_STRING);
                    $contents = '<?php return ' . var_export($words, true) . ';?>';
                    $hashes[$area] = hash('sha256', $contents);
                    $contentsByPath[$areaFiles[$area]] = $contents;
                    $union += $words;
                }
                ksort($union, SORT_STRING);
                $unionContents = '<?php return ' . var_export($union, true) . ';?>';
                $contentsByPath[$unionFile] = $unionContents;
                $contentSha = hash('sha256', implode('|', $hashes) . '|' . hash('sha256', $unionContents));

                $unchanged = true;
                foreach ($contentsByPath as $path => $contents) {
                    if (($previousByPath[$path] ?? null) !== $contents) {
                        $unchanged = false;
                        break;
                    }
                }
                if ($unchanged) {
                    $replaced = false;

                    return [
                        'content_sha256' => $contentSha,
                        'area_sha256' => $hashes,
                        'unchanged' => true,
                    ];
                }

                foreach ($contentsByPath as $path => $contents) {
                    $this->replaceFile($path, $contents, $mode);
                }
                $replaced = true;

                return [
                    'content_sha256' => $contentSha,
                    'area_sha256' => $hashes,
                    'unchanged' => false,
                ];
            },
            function () use ($areaFiles, $unionFile, &$previousByPath, &$mode, &$replaced): void {
                if (!$replaced) {
                    return;
                }
                foreach ([$unionFile, ...array_values($areaFiles)] as $path) {
                    $previous = $previousByPath[$path] ?? null;
                    if (is_string($previous)) {
                        $this->replaceFile($path, $previous, $mode);
                    } elseif (is_file($path) && !unlink($path)) {
                        throw new \RuntimeException('i18n_locale_file_remove_failed');
                    }
                    $this->invalidateFile($path);
                }
            },
        );
        if ($ok) {
            try {
                ObjectManager::getInstance(ClientI18nBagPublisher::class)->publishForLocale($localeCode);
            } catch (\Throwable $e) {
                w_log_error(
                    'ClientI18nBagPublisher failed for ' . $localeCode . ': ' . $e->getMessage(),
                    [],
                    'i18n',
                );
            }
        }

        return $ok;
    }

    private function localeFileFingerprint(string $localeCode): ?string
    {
        $dir = Env::path_TRANSLATE_FILES_PATH;
        $paths = [
            $dir . $localeCode . '.php',
            $dir . DictionaryAreaPolicy::localeBagBasename($localeCode, DictionaryAreaPolicy::AREA_SHARED),
            $dir . DictionaryAreaPolicy::localeBagBasename($localeCode, DictionaryAreaPolicy::AREA_FRONTEND),
            $dir . DictionaryAreaPolicy::localeBagBasename($localeCode, DictionaryAreaPolicy::AREA_BACKEND),
        ];
        $parts = [];
        foreach ($paths as $path) {
            clearstatcache(true, $path);
            if (!is_file($path)) {
                $parts[] = 'missing';
                continue;
            }
            $mtime = (string)filemtime($path);
            $size = (string)filesize($path);
            $parts[] = $mtime . ':' . $size;
        }

        return hash('sha256', implode('|', $parts));
    }

    private function replaceFile(string $filename, string $contents, int $mode): void
    {
        $dir = dirname($filename);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('i18n_locale_directory_create_failed');
        }
        $temporary = tempnam($dir, '.i18n-publish-');
        if ($temporary === false) {
            throw new \RuntimeException('i18n_locale_file_create_failed');
        }
        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)
                || !chmod($temporary, $mode)
                || !rename($temporary, $filename)
            ) {
                throw new \RuntimeException('i18n_locale_file_publish_failed');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
        $this->invalidateFile($filename);
    }

    private function invalidateFile(string $filename): void
    {
        clearstatcache(true, $filename);
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($filename, true);
        }
    }

    private function ensurePublishMemoryCeiling(): void
    {
        $raw = \trim((string)\ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return;
        }
        $unit = \strtoupper(\substr($raw, -1));
        $number = (float)$raw;
        $bytes = match ($unit) {
            'G' => (int)($number * 1024 * 1024 * 1024),
            'M' => (int)($number * 1024 * 1024),
            'K' => (int)($number * 1024),
            default => (int)$number,
        };
        if ($bytes > 0 && $bytes < 512 * 1024 * 1024) {
            @\ini_set('memory_limit', '512M');
        }
    }

    /**
     * @param array<string, string> $areaByWord
     * @return array{
     *   shared: array<string, string>,
     *   frontend: array<string, string>,
     *   backend: array<string, string>
     * }
     */
    private function mergeLocaleBags(string $localeCode, array $areaByWord): array
    {
        $bags = [
            DictionaryAreaPolicy::AREA_SHARED => [],
            DictionaryAreaPolicy::AREA_FRONTEND => [],
            DictionaryAreaPolicy::AREA_BACKEND => [],
        ];

        $put = static function (array &$bags, array $areaByWord, string $word, string $translate): void {
            if ($word === '' || $translate === '' || str_starts_with($word, '@meta::')) {
                return;
            }
            if (I18nCsvCodec::isJunkTranslation($translate) || I18nCsvCodec::isGarbledText($word) || I18nCsvCodec::isGarbledText($translate)) {
                return;
            }
            $area = $areaByWord[$word] ?? DictionaryAreaPolicy::AREA_SHARED;
            if (!isset($bags[$area])) {
                $area = DictionaryAreaPolicy::AREA_SHARED;
            }
            $bags[$area][$word] = $translate;
        };

        foreach (Env::getInstance()->getActiveModules() as $module) {
            if (!\is_array($module)) {
                continue;
            }
            $base = \rtrim((string)($module['base_path'] ?? ''), '/\\');
            if ($base === '') {
                continue;
            }
            $csvFile = $base . DIRECTORY_SEPARATOR . 'i18n' . DIRECTORY_SEPARATOR . $localeCode . '.csv';
            foreach (I18nCsvCodec::readWords($csvFile) as $word => $translate) {
                $put($bags, $areaByWord, (string)$word, (string)$translate);
            }
        }

        // 稀覆盖后写覆盖 CSV（真 AI/人工译文）。
        $query = (clone $this->localeDictionary)->clear()->reset()
            ->where(LocaleDictionary::schema_fields_LOCALE_CODE, $localeCode)
            ->select();
        foreach ($query->fetchIterator() as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $word = (string)($row[LocaleDictionary::schema_fields_WORD] ?? '');
            $translate = (string)($row[LocaleDictionary::schema_fields_TRANSLATE] ?? '');
            $put($bags, $areaByWord, $word, $translate);
        }

        return $bags;
    }
}
