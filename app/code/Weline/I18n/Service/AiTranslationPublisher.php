<?php
declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Phrase\Parser as PhraseParser;
use Weline\I18n\Model\Locale\Dictionary as LocaleDictionary;
use Weline\I18n\Parser as I18nParser;

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

        $words = $this->readExistingWords($localeCode);
        $rows = $this->localeDictionary->clear()->reset()
            ->where(LocaleDictionary::schema_fields_LOCALE_CODE, $localeCode)
            ->select()
            ->fetchArray();

        foreach ((array)$rows as $row) {
            $word = (string)($row[LocaleDictionary::schema_fields_WORD] ?? '');
            $translate = (string)($row[LocaleDictionary::schema_fields_TRANSLATE] ?? '');
            if ($word !== '' && $translate !== '') {
                $words[$word] = $translate;
            }
        }

        $filename = Env::path_TRANSLATE_FILES_PATH . $localeCode . '.php';
        $dir = dirname($filename);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $result = file_put_contents($filename, '<?php return ' . var_export($words, true) . ';?>');
        if ($result === false) {
            return false;
        }

        $this->refreshRuntimeCaches($filename);

        return true;
    }

    private function refreshRuntimeCaches(string $filename): void
    {
        clearstatcache(true, $filename);
        w_cache('i18n')->clear();
        w_cache('phrase')->clear();
        PhraseParser::clearWorkerCaches();
        I18nParser::clearWorkerCaches();

        $dispatchClass = '\\Weline\\Server\\Service\\Control\\BroadcastControlDispatchService';
        if (class_exists($dispatchClass)) {
            try {
                ObjectManager::getInstance($dispatchClass)->cacheClear();
            } catch (\Throwable) {
            }
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
