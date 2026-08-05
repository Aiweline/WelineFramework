<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Backend\Api\Config\KeysInterface;
use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Database\ConnectionFactory;
use Weline\SystemConfig\Api\ConfigStore;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteCurrency;
use Weline\Websites\Model\WebsiteDomain;
use Weline\Websites\Model\WebsiteLanguage;

/** Builds the allowlisted, deterministic Website ResourceChange snapshot. */
final class WebsiteChangeSnapshotFactory
{
    private const FRONTEND_START_PAGE_CONFIG_KEY = 'frontend_start_page_path';
    private const FRONTEND_START_PAGE_CONFIG_MODULE = 'Weline_Websites';

    public function __construct(
        private readonly Website $website,
        private readonly WebsiteDomain $domains,
        private readonly WebsiteCurrency $currencies,
        private readonly WebsiteLanguage $languages,
        private readonly ConfigStore $config,
        private readonly NamespacePath $namespacePath,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function capture(int $websiteId, ?ConnectionFactory $connection = null): ?array
    {
        if ($websiteId < Website::ID_DEFAULT) {
            throw new \InvalidArgumentException(__('website_id 不能为负数'));
        }

        $website = clone $this->website;
        $domains = clone $this->domains;
        $currencies = clone $this->currencies;
        $languages = clone $this->languages;
        if ($connection !== null) {
            $website->setConnection($connection);
            $domains->setConnection($connection);
            $currencies->setConnection($connection);
            $languages->setConnection($connection);
            $this->config->useConnection($connection);
        }

        $row = $website->clearData()->clearQuery()
            ->where(Website::schema_fields_ID, $websiteId)
            ->find()
            ->fetchArray();
        if (is_array($row) && array_is_list($row)) {
            $row = $row[0] ?? null;
        }
        if (!is_array($row) || !array_key_exists(Website::schema_fields_ID, $row)) {
            return null;
        }

        $websiteCode = trim((string)($row[Website::schema_fields_CODE] ?? ''));
        if ($websiteCode === '') {
            throw new \RuntimeException(__('网站快照缺少 code'));
        }

        $domainRows = [];
        foreach ($domains->getWebsiteDomains($websiteId) as $domain) {
            if (!is_array($domain)) {
                continue;
            }
            $domainRows[] = [
                'domain' => strtolower(trim((string)($domain[WebsiteDomain::schema_fields_DOMAIN] ?? ''))),
                'pool_id' => isset($domain[WebsiteDomain::schema_fields_POOL_ID])
                    ? (int)$domain[WebsiteDomain::schema_fields_POOL_ID]
                    : null,
                'sub_path' => $this->normalizeSubPath((string)($domain[WebsiteDomain::schema_fields_SUB_PATH] ?? '')),
                'is_primary' => (int)($domain[WebsiteDomain::schema_fields_IS_PRIMARY] ?? 0) === 1,
            ];
        }
        usort($domainRows, static fn(array $left, array $right): int => [
            $left['is_primary'] ? 0 : 1,
            $left['domain'],
            $left['sub_path'],
            $left['pool_id'] ?? 0,
        ] <=> [
            $right['is_primary'] ? 0 : 1,
            $right['domain'],
            $right['sub_path'],
            $right['pool_id'] ?? 0,
        ]);

        $currencyCodes = array_values(array_unique(array_map(
            'strval',
            $currencies->getWebsiteCurrencyCodes($websiteId),
        )));
        $languageCodes = array_values(array_unique(array_map(
            'strval',
            $languages->getWebsiteLanguageCodes($websiteId),
        )));
        sort($currencyCodes, SORT_STRING);
        sort($languageCodes, SORT_STRING);

        return [
            Website::schema_fields_ID => (int)$row[Website::schema_fields_ID],
            Website::schema_fields_NAME => (string)($row[Website::schema_fields_NAME] ?? ''),
            Website::schema_fields_CODE => $websiteCode,
            Website::schema_fields_URL => (string)($row[Website::schema_fields_URL] ?? ''),
            Website::schema_fields_DEFAULT_CURRENCY => $this->nullableString(
                $row[Website::schema_fields_DEFAULT_CURRENCY] ?? null,
            ),
            Website::schema_fields_DEFAULT_LANGUAGE => $this->nullableString(
                $row[Website::schema_fields_DEFAULT_LANGUAGE] ?? null,
            ),
            Website::schema_fields_DEFAULT_TIMEZONE => (string)($row[Website::schema_fields_DEFAULT_TIMEZONE] ?? ''),
            Website::schema_fields_SCOPE => (string)($row[Website::schema_fields_SCOPE] ?? ''),
            'domains' => $domainRows,
            'currency' => $currencyCodes,
            'language' => $languageCodes,
            'start_page_config' => [
                'frontend' => $this->resolvedConfigValue(
                    self::FRONTEND_START_PAGE_CONFIG_KEY,
                    self::FRONTEND_START_PAGE_CONFIG_MODULE,
                    ConfigStore::area_FRONTEND,
                    $websiteCode,
                ),
                'backend' => $this->resolvedConfigValue(
                    KeysInterface::key_start_page_path,
                    KeysInterface::start_module,
                    ConfigStore::area_BACKEND,
                    $websiteCode,
                ),
            ],
        ];
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @return list<string>
     */
    public function changedFields(?array $before, ?array $after): array
    {
        $fields = [];
        foreach (array_unique(array_merge(array_keys($before ?? []), array_keys($after ?? []))) as $field) {
            if (!array_key_exists($field, $before ?? [])
                || !array_key_exists($field, $after ?? [])
                || ($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $fields[] = (string)$field;
            }
        }
        sort($fields, SORT_STRING);
        return $fields;
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @return array{namespaces:list<string>,previous_namespaces:list<string>,urls:list<string>,previous_urls:list<string>}
     */
    public function impact(?array $before, ?array $after): array
    {
        $currentNamespaces = $this->namespaces($after);
        $previousNamespaces = array_values(array_diff($this->namespaces($before), $currentNamespaces));
        $currentUrls = $this->urls($after);
        $previousUrls = array_values(array_diff($this->urls($before), $currentUrls));
        sort($previousNamespaces, SORT_STRING);
        sort($previousUrls, SORT_STRING);

        return [
            'namespaces' => $currentNamespaces,
            'previous_namespaces' => $previousNamespaces,
            'urls' => $currentUrls,
            'previous_urls' => $previousUrls,
        ];
    }

    /** @param array<string, mixed>|null $snapshot @return list<string> */
    public function namespaces(?array $snapshot): array
    {
        if ($snapshot === null) {
            return [];
        }
        $code = trim((string)($snapshot[Website::schema_fields_CODE] ?? ''));
        if ($code === '') {
            throw new \RuntimeException(__('网站快照缺少 namespace code'));
        }

        $paths = [
            $this->namespacePath->website($code),
            $this->namespacePath->website($code, ['domain']),
            $this->namespacePath->website($code, ['currency']),
            $this->namespacePath->website($code, ['language']),
            $this->namespacePath->website($code, ['config', 'start-page']),
            $this->namespacePath->global('websites-registry'),
        ];
        sort($paths, SORT_STRING);
        return $paths;
    }

    /** @param array<string, mixed>|null $snapshot @return list<string> */
    public function urls(?array $snapshot): array
    {
        if ($snapshot === null) {
            return [];
        }
        $urls = [];
        $websiteUrl = trim((string)($snapshot[Website::schema_fields_URL] ?? ''));
        if ($websiteUrl !== '') {
            $urls[$websiteUrl] = $websiteUrl;
        }
        $scheme = strtolower((string)parse_url($websiteUrl, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            $scheme = 'https';
        }
        foreach ((array)($snapshot['domains'] ?? []) as $domain) {
            if (!is_array($domain)) {
                continue;
            }
            $host = trim((string)($domain['domain'] ?? ''));
            if ($host === '') {
                continue;
            }
            $url = $scheme . '://' . $host . $this->normalizeSubPath((string)($domain['sub_path'] ?? ''));
            $urls[$url] = $url;
        }
        $urls = array_values($urls);
        sort($urls, SORT_STRING);
        return $urls;
    }

    private function resolvedConfigValue(string $key, string $module, string $area, string $scope): string
    {
        $resolved = $this->config->resolveConfig(
            key: $key,
            module: $module,
            area: $area,
            scope: $scope,
            locale: ConfigStore::LOCALE_DEFAULT,
            default: '',
        );
        $value = $resolved['value'] ?? '';
        return is_scalar($value) ? trim((string)$value, '/ ') : '';
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string)$value;
    }

    private function normalizeSubPath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || $path === '/') {
            return '';
        }
        return '/' . trim($path, '/');
    }
}
