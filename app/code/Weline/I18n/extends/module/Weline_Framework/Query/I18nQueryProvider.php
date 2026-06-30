<?php

declare(strict_types=1);

namespace Weline\I18n\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Http\Cookie;
use Weline\Framework\Phrase\Parser;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\I18n\Model\Dictionary;
use Weline\I18n\Model\I18n;
use Weline\I18n\Model\Locals;

class I18nQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly I18n $i18n,
        private readonly Locals $localsModel,
        private readonly Dictionary $dictionary
    ) {
    }

    public function getProviderName(): string
    {
        return 'i18n';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'getInstalledLocales' => $this->getInstalledLocales($params),
            'getLocaleByCode' => $this->getLocaleByCode($params),
            'getLocaleName' => $this->getLocaleName($params),
            'getTranslations' => $this->getTranslations($params),
            'collect' => $this->collect($params),
            default => throw new \InvalidArgumentException((string)__('Unsupported i18n operation: %{1}', $operation)),
        };
    }

    /**
     * @return list<array{code: string, name: string, flag: string}>
     */
    private function getInstalledLocales(array $params): array
    {
        $displayLocale = (string)($params['display_locale_code'] ?? Cookie::getLangLocal() ?? 'zh_Hans_CN');
        $width = (int)($params['width'] ?? 20);
        $height = (int)($params['height'] ?? 15);
        $installed = (bool)($params['installed'] ?? true);

        $raw = $this->i18n->getLocalesWithFlagsDisplaySelf($displayLocale, $width, $height, $installed);

        $list = [];
        foreach ($raw as $code => $info) {
            $list[] = [
                'code' => (string)$code,
                'name' => (string)($info['name'] ?? $code),
                'flag' => (string)($info['flag'] ?? ''),
            ];
        }
        return $list;
    }

    private function getLocaleByCode(array $params): ?array
    {
        $code = (string)($params['code'] ?? '');
        $targetCode = (string)($params['target_code'] ?? $code);
        if ($code === '') {
            return null;
        }

        $locale = clone $this->localsModel;
        $locale->clear()
            ->where(Locals::schema_fields_CODE, $code)
            ->where(Locals::schema_fields_TARGET_CODE, $targetCode)
            ->where(Locals::schema_fields_IS_ACTIVE, 1)
            ->find()
            ->fetch();

        if (!$locale->getId()) {
            return null;
        }

        return [
            'code' => $locale->getData(Locals::schema_fields_CODE),
            'name' => $locale->getData(Locals::schema_fields_NAME),
            'target_code' => $locale->getData(Locals::schema_fields_TARGET_CODE),
            'is_active' => (int)$locale->getData(Locals::schema_fields_IS_ACTIVE),
        ];
    }

    private function getLocaleName(array $params): string
    {
        $code = (string)($params['code'] ?? '');
        $displayLocale = (string)($params['display_locale_code'] ?? Cookie::getLangLocal() ?? 'zh_Hans_CN');
        if ($code === '') {
            return '';
        }
        return $this->i18n->getLocaleName($code, $displayLocale);
    }

    private function getTranslations(array $params): array
    {
        $words = $params['words'] ?? [];
        if (!\is_array($words)) {
            $words = [];
        }

        $allWords = Parser::getWords();
        $translations = [];
        foreach ($words as $word) {
            if (!\is_string($word) || $word === '') {
                continue;
            }
            $translations[$word] = isset($allWords[$word]) && \is_string($allWords[$word])
                ? $allWords[$word]
                : $word;
        }

        return [
            'dictionary' => $translations,
            'translations' => $translations,
        ];
    }

    private function collect(array $params): array
    {
        $words = $params['words'] ?? [];
        if (!\is_array($words) || $words === []) {
            return [
                'success' => true,
                'message' => (string)__('No frontend translation words to collect.'),
                'count' => 0,
            ];
        }

        $module = (string)($params['module'] ?? 'Weline_I18n');
        if ($module === '') {
            $module = 'Weline_I18n';
        }

        $collectData = [];
        foreach ($words as $key => $_word) {
            if (!\is_string($key) || $key === '') {
                continue;
            }
            $collectData[] = [
                Dictionary::schema_fields_WORD => $key,
                Dictionary::schema_fields_IS_BACKEND => 0,
                Dictionary::schema_fields_MODULE => \substr($module, 0, 255),
            ];
        }

        if ($collectData === []) {
            return [
                'success' => true,
                'message' => (string)__('No frontend translation words to collect.'),
                'count' => 0,
            ];
        }

        $created = 0;
        try {
            foreach ($collectData as $row) {
                $dictionary = clone $this->dictionary;
                $dictionary->clear()
                    ->load(Dictionary::schema_fields_WORD, $row[Dictionary::schema_fields_WORD]);

                if ($dictionary->getId()) {
                    continue;
                }

                $dictionary->getQuery()
                    ->clearQuery()
                    ->insert($row, [], '', true)
                    ->fetch();
                $created++;
            }
        } catch (\Throwable $exception) {
            w_log_error('Frontend worker i18n collect failed: ' . $exception->getMessage(), [], 'i18n');
            throw $exception;
        }

        return [
            'success' => true,
            'message' => (string)__('Collected frontend translation words.'),
            'count' => \count($collectData),
            'created' => $created,
        ];
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'i18n',
            'name' => __('I18n query provider'),
            'description' => __('Provides locale and frontend translation operations.'),
            'module' => 'Weline_I18n',
            'operations' => [
                [
                    'name' => 'getInstalledLocales',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 1,
                    'cache_ttl' => 60,
                    'description' => __('Get installed locales.'),
                    'params' => [
                        ['name' => 'display_locale_code', 'type' => 'string', 'required' => false, 'max_length' => 32],
                        ['name' => 'width', 'type' => 'int', 'required' => false, 'min' => 1, 'max' => 64],
                        ['name' => 'height', 'type' => 'int', 'required' => false, 'min' => 1, 'max' => 64],
                        ['name' => 'installed', 'type' => 'bool', 'required' => false],
                    ],
                    'returns' => ['type' => 'array'],
                ],
                [
                    'name' => 'getLocaleByCode',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 1,
                    'cache_ttl' => 60,
                    'description' => __('Get locale by code.'),
                    'params' => [
                        ['name' => 'code', 'type' => 'string', 'required' => true, 'max_length' => 32],
                        ['name' => 'target_code', 'type' => 'string', 'required' => false, 'max_length' => 32],
                    ],
                    'returns' => ['type' => 'array'],
                ],
                [
                    'name' => 'getLocaleName',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 1,
                    'cache_ttl' => 60,
                    'description' => __('Get locale display name.'),
                    'params' => [
                        ['name' => 'code', 'type' => 'string', 'required' => true, 'max_length' => 32],
                        ['name' => 'display_locale_code', 'type' => 'string', 'required' => false, 'max_length' => 32],
                    ],
                    'returns' => ['type' => 'string'],
                ],
                [
                    'name' => 'getTranslations',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 1,
                    'cache_ttl' => 60,
                    'description' => __('Get frontend translation dictionary.'),
                    'params' => [
                        ['name' => 'words', 'type' => 'list', 'required' => false, 'max_items' => 200],
                    ],
                    'returns' => ['type' => 'array'],
                ],
                [
                    'name' => 'collect',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 2,
                    'description' => __('Collect frontend translation words.'),
                    'params' => [
                        ['name' => 'words', 'type' => 'map', 'required' => true, 'max_items' => 200],
                        ['name' => 'module', 'type' => 'string', 'required' => false, 'max_length' => 255],
                    ],
                    'returns' => ['type' => 'array'],
                ],
            ],
        ];
    }
}
