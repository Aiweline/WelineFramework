<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\Database\Connection\ConnectionInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteCurrency;
use Weline\Websites\Model\WebsiteDomain;
use Weline\Websites\Model\WebsiteLanguage;

class DefaultWebsiteService
{
    private const LOCAL_DEFAULT_DOMAINS = ['127.0.0.1', 'localhost'];

    public function __construct(
        private readonly Website $website,
        private readonly WebsiteDomain $websiteDomain,
        private readonly WebsiteCurrency $websiteCurrency,
        private readonly WebsiteLanguage $websiteLanguage,
    ) {
    }

    /**
     * 确保系统默认站点存在。默认站点固定使用 website_id=0、code=default。
     *
     * @return array<string, mixed>
     */
    public function ensureDefaultWebsite(bool $withLocalDomains = true): array
    {
        $changed = false;
        $existing = $this->findDefaultByCode();
        if ($existing !== null) {
            $oldId = (int)($existing[Website::schema_fields_ID] ?? Website::ID_DEFAULT);
            if ($oldId !== Website::ID_DEFAULT) {
                $this->moveDefaultWebsiteId($oldId);
                $changed = true;
                $existing = null;
            }
            if ($existing === null || $this->rowNeedsDefaultUpdate($existing)) {
                $this->updateDefaultRow();
                $changed = true;
            }
        } else {
            $rowAtDefaultId = $this->findById(Website::ID_DEFAULT);
            if ($rowAtDefaultId === null) {
                $this->insertDefaultRow();
                $changed = true;
            } else {
                if ($this->rowNeedsDefaultUpdate($rowAtDefaultId)) {
                    $this->updateDefaultRow();
                    $changed = true;
                }
            }
        }

        $changed = $this->ensureDefaultCurrencyAndLanguage() || $changed;
        if ($withLocalDomains) {
            $changed = $this->ensureLocalDomains() || $changed;
        }
        if ($changed) {
            $this->clearWebsiteCaches();
        }

        return $this->findDefaultByCode() ?? $this->defaultRow();
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultRow(): array
    {
        return [
            Website::schema_fields_ID => Website::ID_DEFAULT,
            Website::schema_fields_NAME => '默认网站',
            Website::schema_fields_CODE => Website::CODE_DEFAULT,
            Website::schema_fields_URL => 'http://localhost',
            Website::schema_fields_DEFAULT_CURRENCY => 'CNY',
            Website::schema_fields_DEFAULT_LANGUAGE => 'zh_Hans_CN',
            Website::schema_fields_DEFAULT_TIMEZONE => 'Asia/Shanghai',
            Website::schema_fields_SCOPE => '',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findDefaultByCode(): ?array
    {
        try {
            $row = $this->website->clearQuery()->clearData()
                ->where(Website::schema_fields_CODE, Website::CODE_DEFAULT)
                ->find()
                ->fetchArray();
        } catch (\Throwable) {
            return null;
        }

        return \is_array($row) && \array_key_exists(Website::schema_fields_ID, $row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findById(int $websiteId): ?array
    {
        try {
            $row = $this->website->clearQuery()->clearData()
                ->where(Website::schema_fields_ID, $websiteId)
                ->find()
                ->fetchArray();
        } catch (\Throwable) {
            return null;
        }

        return \is_array($row) && \array_key_exists(Website::schema_fields_ID, $row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowNeedsDefaultUpdate(array $row): bool
    {
        foreach ($this->defaultRow() as $field => $value) {
            if ((string)($row[$field] ?? '') !== (string)$value) {
                return true;
            }
        }

        return false;
    }

    private function insertDefaultRow(): void
    {
        $data = $this->defaultRow();
        $columns = \array_keys($data);
        $connection = $this->connection();
        $driver = $connection->getDriverType();
        $table = $this->quoteIdentifier($this->website->getOriginTableName(), $driver);
        $quotedColumns = \array_map(fn(string $column): string => $this->quoteIdentifier($column, $driver), $columns);
        $placeholders = \array_map(fn(string $column): string => ':' . $column, $columns);
        $sql = 'INSERT INTO ' . $table
            . ' (' . \implode(', ', $quotedColumns) . ')'
            . ' VALUES (' . \implode(', ', $placeholders) . ')';

        if ($driver === 'mysql') {
            $connection->execute('SET @WELINE_OLD_SQL_MODE=@@SESSION.sql_mode');
            $connection->execute("SET SESSION sql_mode=CONCAT_WS(',', @@SESSION.sql_mode, 'NO_AUTO_VALUE_ON_ZERO')");
        }

        try {
            $this->executePrepared($sql, $data);
        } finally {
            if ($driver === 'mysql') {
                $connection->execute('SET SESSION sql_mode=@WELINE_OLD_SQL_MODE');
            }
        }
    }

    private function updateDefaultRow(): void
    {
        $data = $this->defaultRow();
        unset($data[Website::schema_fields_ID]);
        $driver = $this->connection()->getDriverType();
        $table = $this->quoteIdentifier($this->website->getOriginTableName(), $driver);
        $sets = [];
        foreach (\array_keys($data) as $column) {
            $sets[] = $this->quoteIdentifier($column, $driver) . ' = :' . $column;
        }
        $data['where_website_id'] = Website::ID_DEFAULT;

        $sql = 'UPDATE ' . $table
            . ' SET ' . \implode(', ', $sets)
            . ' WHERE ' . $this->quoteIdentifier(Website::schema_fields_ID, $driver) . ' = :where_website_id';
        $this->executePrepared($sql, $data);
    }

    private function moveDefaultWebsiteId(int $oldId): void
    {
        if ($oldId === Website::ID_DEFAULT) {
            return;
        }

        $rowAtDefaultId = $this->findById(Website::ID_DEFAULT);
        if ($rowAtDefaultId !== null && (string)($rowAtDefaultId[Website::schema_fields_CODE] ?? '') !== Website::CODE_DEFAULT) {
            throw new \RuntimeException((string)__(
                '默认站点 ID %{1} 已被站点 %{2} 占用，无法迁移 default 站点。',
                [(string)Website::ID_DEFAULT, (string)($rowAtDefaultId[Website::schema_fields_CODE] ?? '')]
            ));
        }

        if ($rowAtDefaultId === null) {
            $this->updateWebsiteId($this->website->getOriginTableName(), Website::schema_fields_ID, $oldId, Website::ID_DEFAULT);
        }

        foreach ($this->tablesWithWebsiteIdColumn() as $table) {
            $this->updateWebsiteId($table, Website::schema_fields_ID, $oldId, Website::ID_DEFAULT);
        }
    }

    private function updateWebsiteId(string $tableName, string $field, int $oldId, int $newId): void
    {
        $driver = $this->connection()->getDriverType();
        $table = $this->quoteIdentifier($tableName, $driver);
        $column = $this->quoteIdentifier($field, $driver);
        $this->executePrepared(
            'UPDATE ' . $table . ' SET ' . $column . ' = :new_id WHERE ' . $column . ' = :old_id',
            ['new_id' => $newId, 'old_id' => $oldId]
        );
    }

    private function ensureDefaultCurrencyAndLanguage(): bool
    {
        $changed = false;
        if (!\in_array('CNY', $this->websiteCurrency->getWebsiteCurrencyCodes(Website::ID_DEFAULT), true)) {
            $this->websiteCurrency->clearQuery()->clearData(true)->insert([[
                WebsiteCurrency::schema_fields_WEBSITE_ID => Website::ID_DEFAULT,
                WebsiteCurrency::schema_fields_CURRENCY_CODE => 'CNY',
            ]], WebsiteCurrency::schema_fields_WEBSITE_ID . ',' . WebsiteCurrency::schema_fields_CURRENCY_CODE)->fetch();
            $changed = true;
        }
        if (!\in_array('zh_Hans_CN', $this->websiteLanguage->getWebsiteLanguageCodes(Website::ID_DEFAULT), true)) {
            $this->websiteLanguage->clearQuery()->clearData(true)->insert([[
                WebsiteLanguage::schema_fields_WEBSITE_ID => Website::ID_DEFAULT,
                WebsiteLanguage::schema_fields_LANGUAGE_CODE => 'zh_Hans_CN',
            ]], WebsiteLanguage::schema_fields_WEBSITE_ID . ',' . WebsiteLanguage::schema_fields_LANGUAGE_CODE)->fetch();
            $changed = true;
        }

        return $changed;
    }

    private function ensureLocalDomains(): bool
    {
        $changed = false;
        $existingDomains = $this->websiteDomain->getWebsiteDomains(Website::ID_DEFAULT);
        $existingDomainSet = \array_map(
            static fn(array $row): string => (string)($row[WebsiteDomain::schema_fields_DOMAIN] ?? ''),
            $existingDomains
        );
        $hasPrimary = false;
        foreach ($existingDomains as $row) {
            if (!empty($row[WebsiteDomain::schema_fields_IS_PRIMARY])) {
                $hasPrimary = true;
                break;
            }
        }

        $firstNew = true;
        foreach (self::LOCAL_DEFAULT_DOMAINS as $domain) {
            if (\in_array($domain, $existingDomainSet, true)) {
                continue;
            }

            /** @var WebsiteDomain $newDomain */
            $newDomain = ObjectManager::getInstance(WebsiteDomain::class, [], false);
            $newDomain->setWebsiteId(Website::ID_DEFAULT);
            $newDomain->setDomain($domain);
            $newDomain->setSubPath('');
            $newDomain->setIsPrimary(!$hasPrimary && $firstNew);
            $newDomain->setStatus(WebsiteDomain::STATUS_ACTIVE);
            $newDomain->save();
            $changed = true;
            $firstNew = false;
            $existingDomainSet[] = $domain;
        }

        return $changed;
    }

    private function clearWebsiteCaches(): void
    {
        try {
            w_cache('website')->clear();
            \Weline\Framework\Http\Url::bumpWebsiteParserSitesVersion();
            \Weline\Websites\Observer\DetectWebsite::clearProcessCache();
        } catch (\Throwable) {
        }
    }

    private function connection(): ConnectionInterface
    {
        return $this->website->getQuery(false)->getConnectionInterface();
    }

    /**
     * @return list<string>
     */
    private function tablesWithWebsiteIdColumn(): array
    {
        $driver = $this->connection()->getDriverType();
        try {
            return match ($driver) {
                'mysql' => $this->mysqlTablesWithWebsiteIdColumn(),
                'pgsql' => $this->pgsqlTablesWithWebsiteIdColumn(),
                default => $this->sqliteTablesWithWebsiteIdColumn(),
            };
        } catch (\Throwable $throwable) {
            if (\function_exists('w_log_warning')) {
                \w_log_warning('[DefaultWebsiteService] website_id reference scan failed: ' . $throwable->getMessage());
            }
            return [
                $this->websiteDomain->getOriginTableName(),
                $this->websiteCurrency->getOriginTableName(),
                $this->websiteLanguage->getOriginTableName(),
            ];
        }
    }

    /**
     * @return list<string>
     */
    private function mysqlTablesWithWebsiteIdColumn(): array
    {
        $rows = $this->fetchPreparedRows(
            'SELECT TABLE_NAME AS table_name FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = :column_name',
            ['column_name' => Website::schema_fields_ID]
        );

        return $this->normalizeTableNames($rows);
    }

    /**
     * @return list<string>
     */
    private function pgsqlTablesWithWebsiteIdColumn(): array
    {
        $rows = $this->fetchPreparedRows(
            "SELECT table_schema || '.' || table_name AS table_name "
            . 'FROM information_schema.columns '
            . 'WHERE column_name = :column_name '
            . "AND table_schema NOT IN ('pg_catalog', 'information_schema')",
            ['column_name' => Website::schema_fields_ID]
        );

        return $this->normalizeTableNames($rows);
    }

    /**
     * @return list<string>
     */
    private function sqliteTablesWithWebsiteIdColumn(): array
    {
        $tables = $this->fetchPreparedRows(
            "SELECT name AS table_name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'",
            []
        );
        $result = [];
        foreach ($this->normalizeTableNames($tables) as $table) {
            $columns = $this->fetchPreparedRows('PRAGMA table_info(' . $this->quoteIdentifier($table, 'sqlite') . ')', []);
            foreach ($columns as $column) {
                if ((string)($column['name'] ?? '') === Website::schema_fields_ID) {
                    $result[] = $table;
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private function normalizeTableNames(array $rows): array
    {
        $tables = [];
        foreach ($rows as $row) {
            $table = (string)($row['table_name'] ?? '');
            if ($table !== '') {
                $tables[] = $table;
            }
        }

        return \array_values(\array_unique($tables));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function executePrepared(string $sql, array $params): void
    {
        $stmt = $this->connection()->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function fetchPreparedRows(string $sql, array $params): array
    {
        $stmt = $this->connection()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return \is_array($rows) ? $rows : [];
    }

    private function quoteIdentifier(string $identifier, string $driver): string
    {
        $quote = $driver === 'mysql' ? '`' : '"';
        $escapedQuote = $quote . $quote;
        $parts = \explode('.', $identifier);
        $quoted = \array_map(
            static fn(string $part): string => $quote . \str_replace($quote, $escapedQuote, $part) . $quote,
            $parts
        );
        return \implode('.', $quoted);
    }
}
