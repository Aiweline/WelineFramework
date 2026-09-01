<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\App\Env;
use Weline\I18n\Service\TranslationCollector;

/**
 * 支付客户指南词条目录：收集 guide/payment 模板文案并审计目标语言缺口。
 */
class PaymentGuideI18nCatalog
{
    public const HUB_MODULE = 'Weline_Payment';

    public const SOURCE_LOCALE = 'zh_Hans_CN';

    private const GUIDE_PATH_FRAGMENT = 'guide/payment/';

    public function __construct(
        private readonly PaymentCustomerGuideRegistry $guideRegistry,
        private readonly TranslationCollector $translationCollector,
    ) {
    }

    /**
     * @return array{
     *     locale: string,
     *     methods: array<string, array<string, mixed>>,
     *     shared: array<string, mixed>,
     *     summary: array{total_phrases:int,missing_phrases:int,complete:bool}
     * }
     */
    public function audit(?string $methodCode = null, string $locale = 'en_US'): array
    {
        $locale = $this->normalizeLocale($locale);
        $catalog = $this->buildCatalog($methodCode);
        $methods = [];
        $missingTotal = 0;
        $phraseTotal = 0;

        foreach ($catalog['methods'] as $code => $methodData) {
            $missing = $this->findMissingTranslations(
                (string) ($methodData['source_module'] ?? self::HUB_MODULE),
                array_keys($methodData['phrases']),
                $locale,
            );
            $phraseCount = count($methodData['phrases']);
            $missingCount = count($missing);
            $phraseTotal += $phraseCount;
            $missingTotal += $missingCount;

            $methods[$code] = [
                'method_code' => $code,
                'source_module' => $methodData['source_module'],
                'phrase_count' => $phraseCount,
                'missing_count' => $missingCount,
                'complete' => $missingCount === 0,
                'phrases' => $methodData['phrases'],
                'missing' => $missing,
            ];
        }

        $sharedMissing = $this->findMissingTranslations(
            self::HUB_MODULE,
            array_keys($catalog['shared']['phrases']),
            $locale,
        );
        $sharedPhraseCount = count($catalog['shared']['phrases']);
        $sharedMissingCount = count($sharedMissing);
        $phraseTotal += $sharedPhraseCount;
        $missingTotal += $sharedMissingCount;

        return [
            'locale' => $locale,
            'methods' => $methods,
            'shared' => [
                'source_module' => self::HUB_MODULE,
                'phrase_count' => $sharedPhraseCount,
                'missing_count' => $sharedMissingCount,
                'complete' => $sharedMissingCount === 0,
                'phrases' => $catalog['shared']['phrases'],
                'missing' => $sharedMissing,
            ],
            'summary' => [
                'total_phrases' => $phraseTotal,
                'missing_phrases' => $missingTotal,
                'complete' => $missingTotal === 0,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public function listPhraseKeys(?string $methodCode = null, bool $includeShared = true): array
    {
        $catalog = $this->buildCatalog($methodCode);
        $phrases = [];

        foreach ($catalog['methods'] as $methodData) {
            foreach (array_keys($methodData['phrases']) as $phrase) {
                $phrases[$phrase] = true;
            }
        }

        if ($includeShared) {
            foreach (array_keys($catalog['shared']['phrases']) as $phrase) {
                $phrases[$phrase] = true;
            }
        }

        return array_values(array_keys($phrases));
    }

    /**
     * @return array{methods: array<string, array<string, mixed>>, shared: array<string, mixed>}
     */
    private function buildCatalog(?string $methodCode): array
    {
        $methodCode = $methodCode !== null ? strtolower(trim($methodCode)) : '';
        $methods = [];
        $entries = $this->guideRegistry->listPublishedEntries();

        foreach ($entries as $entry) {
            $code = strtolower(trim((string) ($entry['method_code'] ?? '')));
            if ($code === '') {
                continue;
            }
            if ($methodCode !== '' && $code !== $methodCode) {
                continue;
            }

            $sourceModule = (string) ($entry['source_module'] ?? self::HUB_MODULE);
            $methods[$code] = [
                'source_module' => $sourceModule,
                'phrases' => $this->collectModuleGuidePhrases(
                    $sourceModule,
                    self::GUIDE_PATH_FRAGMENT . $code . '/',
                ),
            ];
        }

        $shared = [
            'phrases' => $this->collectModuleGuidePhrases(self::HUB_MODULE, self::GUIDE_PATH_FRAGMENT, true),
        ];

        return [
            'methods' => $methods,
            'shared' => $shared,
        ];
    }

    /**
     * @return array<string, array{file:string,context:string,module:string}>
     */
    private function collectModuleGuidePhrases(string $moduleName, string $pathFragment, bool $sharedOnly = false): array
    {
        $modulePath = $this->resolveModulePath($moduleName);
        if ($modulePath === '') {
            return [];
        }

        $phrases = [];
        foreach ($this->translationCollector->collectLazy($modulePath, $moduleName) as $phrase => $info) {
            $file = str_replace('\\', '/', (string) ($info['file'] ?? ''));
            if (!str_contains($file, self::GUIDE_PATH_FRAGMENT)) {
                continue;
            }

            if ($sharedOnly) {
                if (!str_contains($file, '/partials/') && !str_ends_with($file, '/index.phtml')) {
                    continue;
                }
            } elseif (!str_contains($file, $pathFragment)) {
                continue;
            }

            $phrases[$phrase] = [
                'file' => $file,
                'context' => (string) ($info['context'] ?? ''),
                'module' => (string) ($info['module'] ?? $moduleName),
            ];
        }

        ksort($phrases);

        return $phrases;
    }

    /**
     * @param list<string> $phrases
     * @return list<string>
     */
    private function findMissingTranslations(string $moduleName, array $phrases, string $locale): array
    {
        if ($phrases === []) {
            return [];
        }

        $modulePath = $this->resolveModulePath($moduleName);
        if ($modulePath === '') {
            return $phrases;
        }

        $translations = $this->readLocaleCsv($modulePath, $locale);
        $missing = [];
        foreach ($phrases as $phrase) {
            if (!$this->isTranslated($phrase, $translations[$phrase] ?? '', $locale)) {
                $missing[] = $phrase;
            }
        }

        return $missing;
    }

    private function isTranslated(string $source, string $translation, string $locale): bool
    {
        $translation = trim($translation);
        if ($translation === '') {
            return false;
        }

        if ($locale === self::SOURCE_LOCALE) {
            return $translation === $source;
        }

        return $translation !== $source;
    }

    /**
     * @return array<string, string>
     */
    private function readLocaleCsv(string $modulePath, string $locale): array
    {
        $csvFile = rtrim($modulePath, '/\\') . '/i18n/' . $locale . '.csv';
        if (!is_file($csvFile)) {
            return [];
        }

        $handle = fopen($csvFile, 'rb');
        if ($handle === false) {
            return [];
        }

        $rows = [];
        $isFirstLine = true;
        while (($line = fgets($handle)) !== false) {
            if ($isFirstLine) {
                $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;
                $isFirstLine = false;
            }
            $parsed = $this->parseCsvLine(trim($line));
            if ($parsed === null) {
                continue;
            }
            [$source, $translation] = $parsed;
            if ($source !== '') {
                $rows[$source] = $translation;
            }
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @return array{0:string,1:string}|null
     */
    private function parseCsvLine(string $line): ?array
    {
        if ($line === '') {
            return null;
        }

        if ($line[0] === '"') {
            if (!preg_match('/^"((?:[^"]|"")*)"(?:,(.*))?$/', $line, $matches)) {
                return null;
            }
            $source = str_replace('""', '"', $matches[1]);
            $translation = isset($matches[2]) ? $this->parseCsvField($matches[2]) : $source;

            return [$source, $translation];
        }

        $commaPos = strpos($line, ',');
        if ($commaPos === false) {
            return [$line, $line];
        }

        return [
            substr($line, 0, $commaPos),
            $this->parseCsvField(substr($line, $commaPos + 1)),
        ];
    }

    private function parseCsvField(string $field): string
    {
        $field = trim($field);
        if ($field === '') {
            return '';
        }
        if ($field[0] === '"' && str_ends_with($field, '"')) {
            return str_replace('""', '"', substr($field, 1, -1));
        }

        return $field;
    }

    private function resolveModulePath(string $moduleName): string
    {
        $moduleName = trim($moduleName);
        if ($moduleName === '') {
            return '';
        }

        $modules = Env::getInstance()->getModuleList();
        $module = $modules[$moduleName] ?? null;
        if (!is_array($module)) {
            return '';
        }

        return rtrim((string) ($module['base_path'] ?? ''), '/\\');
    }

    private function normalizeLocale(string $locale): string
    {
        $locale = trim($locale);
        if ($locale === '') {
            return 'en_US';
        }

        return $locale;
    }
}
