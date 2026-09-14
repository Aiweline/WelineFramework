<?php
declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\Locale\Dictionary as LocaleDictionary;

class AiTranslationPublisher
{
    public function __construct(
        private readonly LocaleDictionary $localeDictionary
    ) {
    }

    public function publishLocale(string $localeCode): bool
    {
        $localeCode = trim(str_replace('-', '_', $localeCode));
        if ($localeCode === '') {
            return false;
        }

        $transactions = ObjectManager::getInstance(TransactionCoordinatorInterface::class);
        $connection = $this->localeDictionary->getConnection();
        if ($transactions->isActive($connection)) {
            // 事务内只登记提交后发布；同语言的重复请求合并，回滚不触碰公共文件。
            $transactions->afterCommit($connection, 'i18n.publish_locale.' . $localeCode, function () use ($localeCode): void {
                if (!$this->publishCommittedLocale($localeCode)) {
                    throw new \RuntimeException('i18n_locale_file_publish_failed');
                }
            });
            return true;
        }

        return $this->publishCommittedLocale($localeCode);
    }

    private function publishCommittedLocale(string $localeCode): bool
    {
        $filename = Env::path_TRANSLATE_FILES_PATH . $localeCode . '.php';
        $previous = null;
        $mode = 0644;
        $replaced = false;
        return ObjectManager::getInstance(I18nResourceChangePublisher::class)->publishLocaleFile(
            $localeCode,
            function () use ($localeCode, $filename, &$previous, &$mode, &$replaced): array {
                // 本回调在语言资源修订行锁内执行，不能提前读取 DB 或文件快照。
                clearstatcache(true, $filename);
                if (is_file($filename)) {
                    $previous = file_get_contents($filename);
                    if ($previous === false) {
                        throw new \RuntimeException('i18n_locale_file_read_failed');
                    }
                    $mode = fileperms($filename) & 0777;
                }
                $words = $this->readExistingWords($localeCode);
                // 词典常 >10000 行：禁止无界 fetchArray（否则 Unbounded SELECT，publish 失败，locale 文件停更）。
                $query = (clone $this->localeDictionary)->clear()->reset()
                    ->where(LocaleDictionary::schema_fields_LOCALE_CODE, $localeCode)
                    ->select();
                foreach ($query->fetchIterator() as $row) {
                    if (!\is_array($row)) {
                        continue;
                    }
                    $word = (string)($row[LocaleDictionary::schema_fields_WORD] ?? '');
                    $translate = (string)($row[LocaleDictionary::schema_fields_TRANSLATE] ?? '');
                    if ($word !== '' && $translate !== '') {
                        $words[$word] = $translate;
                    }
                }
                ksort($words, SORT_STRING);
                $contents = '<?php return ' . var_export($words, true) . ';?>';
                // 内容相同不等于上次版本已提交；显式发布始终补齐规范 changed。
                $replaced = true;
                $this->replaceFile($filename, $contents, $mode);
                return ['content_sha256' => hash('sha256', $contents)];
            },
            function () use ($filename, &$previous, &$mode, &$replaced): void {
                if (!$replaced) {
                    return;
                }
                if (is_string($previous)) {
                    $this->replaceFile($filename, $previous, $mode);
                } elseif (is_file($filename) && !unlink($filename)) {
                    throw new \RuntimeException('i18n_locale_file_remove_failed');
                }
                $this->invalidateFile($filename);
            },
        );
    }

    /** 同目录临时文件原子替换，发布及失败恢复共用相同写入路径。 */
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

    /**
     * @return array<string, string>
     */
    private function readExistingWords(string $localeCode): array
    {
        $filename = Env::path_TRANSLATE_FILES_PATH . $localeCode . '.php';
        if (!is_file($filename)) {
            return [];
        }

        try {
            $data = include $filename;
        } catch (\Throwable) {
            return [];
        }

        return $this->flattenWords(is_array($data) ? $data : []);
    }

    /**
     * @param array<mixed> $data
     * @return array<string, string>
     */
    private function flattenWords(array $data): array
    {
        $words = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $words += $this->flattenWords($value);
                continue;
            }
            if (is_string($key) && $key !== '' && (is_string($value) || is_numeric($value))) {
                $words[$key] = (string)$value;
            }
        }

        return $words;
    }
}
