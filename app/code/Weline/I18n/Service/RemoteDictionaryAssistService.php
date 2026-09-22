<?php

declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\I18n\Model\Dictionary as WordDictionary;
use Weline\I18n\Model\Locale\Dictionary as LocaleDictionary;
use Weline\Websites\Model\Website;

/**
 * 远程协助翻译：取未译 / 录入（冲突跳过）+ publish。
 */
final class RemoteDictionaryAssistService
{
    public const LIMIT_DEFAULT = 50;
    public const LIMIT_MAX = 200;
    public const ITEMS_MAX = 100;
    public const FIELD_MAX_LEN = 8000;

    public function __construct(
        private readonly WordDictionary $dictionary,
        private readonly LocaleDictionary $localeDictionary,
        private readonly AiTranslationPublisher $publisher,
    ) {
    }

    /**
     * @param list<string> $locales
     * @return array{items:list<array{source:string,module:string,locale:string}>,next_cursor:?string,has_more:bool,limit:int}
     */
    public function pending(int $websiteId, array $locales, int $limit, ?string $cursor): array
    {
        $allowed = $this->assertWebsiteLocales($websiteId, $locales);
        $limit = max(1, min(self::LIMIT_MAX, $limit > 0 ? $limit : self::LIMIT_DEFAULT));
        $offset = $this->decodeCursor($cursor);

        $items = $this->queryPendingUnion($allowed, $limit + 1, $offset);
        $hasMore = count($items) > $limit;
        if ($hasMore) {
            $items = array_slice($items, 0, $limit);
        }

        return [
            'items' => $items,
            'next_cursor' => $hasMore ? $this->encodeCursor($offset + count($items)) : null,
            'has_more' => $hasMore,
            'limit' => $limit,
        ];
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array{written:int,skipped:int,invalid:int,invalid_items:list<array{index:int,reason:string}>}
     */
    public function ingest(int $websiteId, array $items): array
    {
        if (count($items) > self::ITEMS_MAX) {
            throw new \InvalidArgumentException((string)__('items 超过上限 %{1}', [self::ITEMS_MAX]));
        }

        $allowedCodes = $this->websiteLanguageCodes($websiteId);
        $allowedMap = array_fill_keys($allowedCodes, true);

        $written = 0;
        $skipped = 0;
        $invalid = 0;
        $invalidItems = [];
        $seen = [];
        $publishLocales = [];

        foreach ($items as $index => $raw) {
            if (!is_array($raw)) {
                $invalid++;
                $invalidItems[] = ['index' => (int)$index, 'reason' => 'empty_source'];
                continue;
            }
            $source = trim((string)($raw['source'] ?? ''));
            $locale = trim((string)($raw['locale'] ?? ''));
            $translation = (string)($raw['translation'] ?? '');

            if ($source === '') {
                $invalid++;
                $invalidItems[] = ['index' => (int)$index, 'reason' => 'empty_source'];
                continue;
            }
            if ($locale === '' || !isset($allowedMap[$locale])) {
                $invalid++;
                $invalidItems[] = ['index' => (int)$index, 'reason' => 'locale_not_allowed'];
                continue;
            }
            if (trim($translation) === '') {
                $invalid++;
                $invalidItems[] = ['index' => (int)$index, 'reason' => 'empty_translation'];
                continue;
            }
            if (mb_strlen($source) > self::FIELD_MAX_LEN || mb_strlen($translation) > self::FIELD_MAX_LEN) {
                $invalid++;
                $invalidItems[] = ['index' => (int)$index, 'reason' => 'too_long'];
                continue;
            }

            $batchKey = $locale . "\0" . $source;
            if (isset($seen[$batchKey])) {
                $invalid++;
                $invalidItems[] = ['index' => (int)$index, 'reason' => 'duplicate_in_batch'];
                continue;
            }
            $seen[$batchKey] = true;

            $existing = $this->findLocaleTranslate($source, $locale);
            if ($existing !== null && trim($existing) !== '' && $existing !== $source) {
                $skipped++;
                continue;
            }

            if (!$this->localeDictionary->upsert($source, $locale, $translation)) {
                $invalid++;
                $invalidItems[] = ['index' => (int)$index, 'reason' => 'empty_translation'];
                continue;
            }
            $written++;
            $publishLocales[$locale] = true;
        }

        foreach (array_keys($publishLocales) as $localeCode) {
            $this->publisher->publishLocale((string)$localeCode);
        }

        w_log_info('remote dictionary ingest', [
            'website_id' => $websiteId,
            'written' => $written,
            'skipped' => $skipped,
            'invalid' => $invalid,
        ], 'i18n');

        return [
            'written' => $written,
            'skipped' => $skipped,
            'invalid' => $invalid,
            'invalid_items' => $invalidItems,
        ];
    }

    /**
     * @param list<string> $locales
     * @return list<string>
     */
    public function assertWebsiteLocales(int $websiteId, array $locales): array
    {
        $allowed = $this->websiteLanguageCodes($websiteId);
        if ($allowed === [] && $locales !== []) {
            throw new \InvalidArgumentException((string)__('网站未配置语种'));
        }
        $allowedMap = array_fill_keys($allowed, true);
        $normalized = [];
        foreach ($locales as $locale) {
            $locale = trim((string)$locale);
            if ($locale === '') {
                continue;
            }
            if (!isset($allowedMap[$locale])) {
                throw new \InvalidArgumentException((string)__('locale 不在网站语种内：%{1}', [$locale]));
            }
            $normalized[] = $locale;
        }
        if ($normalized === []) {
            throw new \InvalidArgumentException((string)__('locales 不能为空'));
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @return list<string>
     */
    public function websiteLanguageCodes(int $websiteId): array
    {
        if ($websiteId < Website::ID_DEFAULT) {
            throw new \InvalidArgumentException((string)__('website_id 无效'));
        }
        $site = w_query('websites', 'getWebsiteById', ['website_id' => $websiteId]);
        if (!is_array($site) || !isset($site['website_id'])) {
            throw new \RuntimeException((string)__('网站不存在'), 404);
        }
        $codes = w_query('websites', 'getWebsiteLanguageCodes', ['website_id' => $websiteId]);
        if (!is_array($codes)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($c): string => trim((string)$c),
            $codes
        ), static fn (string $c): bool => $c !== ''));
    }

    /**
     * @param list<string> $locales
     * @return list<array{source:string,module:string,locale:string}>
     */
    private function queryPendingUnion(array $locales, int $limit, int $offset): array
    {
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        $parts = [];
        foreach ($locales as $locale) {
            $parts[] = 'SELECT d.word AS word, d.module AS module, '
                . $this->sqlQuote($locale) . ' AS locale FROM '
                . $this->dictionary->getTable()
                . ' d WHERE '
                . $this->pendingWhereSql($locale);
        }
        if ($parts === []) {
            return [];
        }

        $sql = '(' . implode(') UNION ALL (', $parts) . ') ORDER BY word, locale LIMIT '
            . $limit . ' OFFSET ' . $offset;

        $out = [];
        foreach ($this->fetchSql($sql) as $row) {
            $word = (string)($row['word'] ?? '');
            if ($word === '') {
                continue;
            }
            $out[] = [
                'source' => $word,
                'module' => (string)($row['module'] ?? ''),
                'locale' => (string)($row['locale'] ?? ''),
            ];
        }

        return $out;
    }

    private function pendingWhereSql(string $targetLocale): string
    {
        $locale = $this->sqlQuote($targetLocale);

        return 'd.word NOT LIKE ' . $this->sqlQuote('@meta::%')
            . ' AND NOT EXISTS (SELECT 1 FROM '
            . $this->localeDictionary->getTable()
            . ' l WHERE l.locale_code = ' . $locale
            . ' AND l.word = d.word AND TRIM(l.translate) <> \'\' AND l.translate <> d.word)';
    }

    private function findLocaleTranslate(string $word, string $locale): ?string
    {
        $md5 = LocaleDictionary::generateMd5($word, $locale);
        $existing = $this->localeDictionary->clear()->reset()
            ->where(LocaleDictionary::schema_fields_MD5, $md5)
            ->find()
            ->fetch();
        if (!$existing->getId()) {
            return null;
        }

        return (string)$existing->getData(LocaleDictionary::schema_fields_TRANSLATE);
    }

    /** @return list<array<string,mixed>> */
    private function fetchSql(string $sql): array
    {
        $statement = $this->dictionary->getConnection()->getConnector()->getLink()->query($sql);
        if ($statement === false) {
            return [];
        }
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    private function sqlQuote(string $value): string
    {
        $quoted = $this->dictionary->getConnection()->getConnector()->getLink()->quote($value);
        if (!is_string($quoted) || $quoted === '') {
            throw new \RuntimeException('Failed to quote SQL value');
        }

        return $quoted;
    }

    private function encodeCursor(int $offset): string
    {
        $json = json_encode(['o' => max(0, $offset)], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    private function decodeCursor(?string $cursor): int
    {
        if ($cursor === null || trim($cursor) === '') {
            return 0;
        }
        $pad = strlen($cursor) % 4;
        if ($pad > 0) {
            $cursor .= str_repeat('=', 4 - $pad);
        }
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (!is_string($raw) || $raw === '') {
            throw new \InvalidArgumentException((string)__('cursor 无效'));
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException((string)__('cursor 无效'));
        }

        return max(0, (int)($data['o'] ?? 0));
    }
}
