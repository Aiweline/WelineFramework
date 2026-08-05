<?php
declare(strict_types=1);
namespace Weline\Seo\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/** 站点 SEO 统计数据模型 - 存储各平台索引量、点击、展示、排名等 */
#[Table(comment: '站点SEO统计数据表')]
#[Index(name: 'unique_website_account_platform_date', columns: ['website_id', 'account_id', 'platform', 'stats_date'], type: 'UNIQUE')]
#[Index(name: 'idx_website', columns: ['website_id'])]
#[Index(name: 'idx_account', columns: ['account_id'])]
#[Index(name: 'idx_platform', columns: ['platform'])]
#[Index(name: 'idx_stats_date', columns: ['stats_date'])]
class SeoWebsiteStats extends Model
{
    private const ACCEPTANCE_PLATFORM = 'acceptance_search_fixture';
    private const ACCEPTANCE_RECEIPT_SCHEMA = 'seo.search_acceptance_fixture_receipt.v1';
    public const schema_table = 'weline_seo_website_stats';
    public const schema_primary_key = 'id';
    public array $_unit_primary_keys = ['id'];
    #[Col('int', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: '记录ID')]
    public const schema_fields_ID = 'id';
    #[Col('int', 0, nullable: false, comment: '站点ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col('int', 0, nullable: false, comment: 'SEO账户ID')]
    public const schema_fields_ACCOUNT_ID = 'account_id';
    #[Col('varchar', 50, nullable: false, comment: '平台代码')]
    public const schema_fields_PLATFORM = 'platform';
    
    // 统计数据字段
    #[Col('int', 0, nullable: false, default: 0, comment: '已索引页面数')]
    public const schema_fields_INDEXED_PAGES = 'indexed_pages';
    #[Col('int', 0, nullable: false, default: 0, comment: '已提交URL数')]
    public const schema_fields_SUBMITTED_URLS = 'submitted_urls';
    #[Col('int', 0, nullable: false, default: 0, comment: '已抓取页面数')]
    public const schema_fields_CRAWLED_PAGES = 'crawled_pages';
    #[Col('int', 0, nullable: false, default: 0, comment: '点击量')]
    public const schema_fields_CLICKS = 'clicks';
    #[Col('int', 0, nullable: false, default: 0, comment: '展示量')]
    public const schema_fields_IMPRESSIONS = 'impressions';
    #[Col('decimal', '5,2', nullable: false, default: 0.00, comment: '点击率')]
    public const schema_fields_CTR = 'ctr';
    #[Col('decimal', '5,2', nullable: false, default: 0.00, comment: '平均排名')]
    public const schema_fields_AVERAGE_POSITION = 'average_position';
    
    #[Col('int', 0, nullable: false, default: 0, comment: '错误页面数')]
    public const schema_fields_ERROR_COUNT = 'error_count';
    #[Col('int', 0, nullable: false, default: 0, comment: '警告页面数')]
    public const schema_fields_WARNING_COUNT = 'warning_count';
    
    #[Col('int', 0, nullable: false, default: 0, comment: '每日配额')]
    public const schema_fields_DAILY_QUOTA = 'daily_quota';
    #[Col('int', 0, nullable: false, default: 0, comment: '已使用配额')]
    public const schema_fields_QUOTA_USED = 'quota_used';
    
    #[Col('text', comment: '额外数据JSON')]
    public const schema_fields_EXTRA_DATA = 'extra_data';
    
    #[Col('date', comment: '统计数据日期')]
    public const schema_fields_STATS_DATE = 'stats_date';
    #[Col('datetime', comment: '最后同步时间')]
    public const schema_fields_LAST_SYNC_AT = 'last_sync_at';
    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
    public function _init(): void
    {
        $this->useMainDbMaster();
    }
    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
/**
     * 获取或创建当日统计记录
     */
    public function getOrCreateTodayStats(int $websiteId, int $accountId, string $platform): self
    {
        $today = date('Y-m-d');
        
        $this->reset()
            ->where(self::schema_fields_WEBSITE_ID, $websiteId)
            ->where(self::schema_fields_ACCOUNT_ID, $accountId)
            ->where(self::schema_fields_PLATFORM, $platform)
            ->where(self::schema_fields_STATS_DATE, $today)
            ->find()
            ->fetch();
        
        if (!$this->getId()) {
            $this->setData([
                self::schema_fields_WEBSITE_ID => $websiteId,
                self::schema_fields_ACCOUNT_ID => $accountId,
                self::schema_fields_PLATFORM => $platform,
                self::schema_fields_STATS_DATE => $today,
            ])->save();
        }
        
        return $this;
    }
    /**
     * 获取站点最新统计数据
     */
    public function getLatestStats(int $websiteId, int $accountId, string $platform): ?self
    {
        $this->reset()
            ->where(self::schema_fields_WEBSITE_ID, $websiteId)
            ->where(self::schema_fields_ACCOUNT_ID, $accountId)
            ->where(self::schema_fields_PLATFORM, $platform)
            ->order(self::schema_fields_STATS_DATE, 'DESC')
            ->find()
            ->fetch();
        
        return $this->getId() ? $this : null;
    }
    /**
     * 获取站点所有平台的最新统计数据
     * 
     * @return array ['platform' => stats_data, ...]
     */
    public function getAllPlatformLatestStats(int $websiteId): array
    {
        $result = [];
        
        // 获取该站点所有的统计记录（按日期降序）
        $allStats = $this->reset()
            ->where(self::schema_fields_WEBSITE_ID, $websiteId)
            ->order(self::schema_fields_STATS_DATE, 'DESC')
            ->select()
            ->fetchArray();
        
        // 为每个平台取最新一条
        foreach ($allStats as $stats) {
            $platform = $stats[self::schema_fields_PLATFORM] ?? '';
            if ($platform && !isset($result[$platform])) {
                $result[$platform] = $stats;
            }
        }
        
        return $result;
    }
    /**
     * 更新统计数据
     */
    public function updateStats(array $data): self
    {
        $allowedFields = [
            self::schema_fields_INDEXED_PAGES,
            self::schema_fields_SUBMITTED_URLS,
            self::schema_fields_CRAWLED_PAGES,
            self::schema_fields_CLICKS,
            self::schema_fields_IMPRESSIONS,
            self::schema_fields_CTR,
            self::schema_fields_AVERAGE_POSITION,
            self::schema_fields_ERROR_COUNT,
            self::schema_fields_WARNING_COUNT,
            self::schema_fields_DAILY_QUOTA,
            self::schema_fields_QUOTA_USED,
            self::schema_fields_EXTRA_DATA,
        ];
        
        foreach ($data as $key => $value) {
            if (in_array($key, $allowedFields)) {
                $this->setData($key, $value);
            }
        }
        
        $this->setData(self::schema_fields_LAST_SYNC_AT, date('Y-m-d H:i:s'));
        $this->save();
        
        return $this;
    }
    /**
     * 获取额外数据
     */
    public function getExtraData(): array
    {
        $data = $this->getData(self::schema_fields_EXTRA_DATA);
        if (is_string($data) && !empty($data)) {
            $decoded = json_decode($data, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }
    /**
     * 设置额外数据
     */
    public function setExtraData(array $data): self
    {
        $this->setData(self::schema_fields_EXTRA_DATA, json_encode($data, JSON_UNESCAPED_UNICODE));
        return $this;
    }
    /**
     * Seed deterministic Search evidence for an isolated acceptance run.
     *
     * This is deliberately implemented on the canonical stats model so fixture
     * rows pass through the same ORM save hooks as StatsSync. The dual gate and
     * backend-only Query descriptor keep the facility unreachable in production.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function applySearchAcceptanceFixture(array $params): array
    {
        self::assertSearchAcceptanceFixtureEnabled();

        $websiteId = self::acceptanceWebsiteId($params['website_id'] ?? null);
        $caseId = self::acceptanceIdentity($params['case_id'] ?? null, 'case_id');
        $requestKey = self::acceptanceIdentity($params['request_key'] ?? null, 'request_key');
        $rows = self::acceptanceRows($params['rows'] ?? null);
        $payloadHash = hash('sha256', self::canonicalJson([
            'website_id' => $websiteId,
            'case_id' => $caseId,
            'request_key' => $requestKey,
            'rows' => $rows,
        ]));

        // Preflight every deterministic identity before writing so a conflicting
        // replay cannot leave a newly-created prefix behind.
        $existingItems = [];
        foreach ($rows as $index => $row) {
            $accountId = self::acceptanceAccountId($caseId, $requestKey, $index);
            $item = $this->findAcceptanceRecord($websiteId, $accountId);
            if ($item !== null) {
                $meta = $item->getExtraData();
                if (($meta['acceptance_payload_hash'] ?? '') !== $payloadHash
                    || ($meta['acceptance_case_id'] ?? '') !== $caseId
                    || ($meta['acceptance_request_key'] ?? '') !== $requestKey
                    || (int)($meta['acceptance_row_index'] ?? -1) !== $index
                    || ($meta['date'] ?? '') !== $row['date']
                    || ($meta['query'] ?? '') !== $row['query']
                    || ($meta['page'] ?? '') !== $row['page']
                    || substr((string)$item->getData(self::schema_fields_STATS_DATE), 0, 10) !== $row['date']
                    || (int)$item->getData(self::schema_fields_INDEXED_PAGES) !== $row['indexed_pages']
                    || (int)$item->getData(self::schema_fields_CLICKS) !== $row['clicks']
                    || (int)$item->getData(self::schema_fields_IMPRESSIONS) !== $row['impressions']
                    || abs((float)$item->getData(self::schema_fields_AVERAGE_POSITION) - (float)$row['average_position']) > 0.000001
                    || (int)$item->getData(self::schema_fields_ERROR_COUNT) !== $row['error_count']
                    || (int)$item->getData(self::schema_fields_WARNING_COUNT) !== $row['warning_count']
                ) {
                    throw new \RuntimeException('SEO_SEARCH_ACCEPTANCE_FIXTURE_REQUEST_CONFLICT');
                }
            }
            $existingItems[$index] = $item;
        }

        $receiptRows = [];
        foreach ($rows as $index => $row) {
            $accountId = self::acceptanceAccountId($caseId, $requestKey, $index);
            $item = $existingItems[$index];
            if ($item === null) {
                $item = clone $this;
                $item->clearData()->clearQuery();
                $item->setData(self::schema_fields_WEBSITE_ID, $websiteId);
                $item->setData(self::schema_fields_ACCOUNT_ID, $accountId);
                $item->setData(self::schema_fields_PLATFORM, self::ACCEPTANCE_PLATFORM);
                $item->setData(self::schema_fields_STATS_DATE, $row['date']);
                $item->setData(self::schema_fields_INDEXED_PAGES, $row['indexed_pages']);
                $item->setData(self::schema_fields_CLICKS, $row['clicks']);
                $item->setData(self::schema_fields_IMPRESSIONS, $row['impressions']);
                $item->setData(self::schema_fields_CTR, $row['impressions'] > 0 ? $row['clicks'] / $row['impressions'] : 0.0);
                $item->setData(self::schema_fields_AVERAGE_POSITION, $row['average_position']);
                $item->setData(self::schema_fields_ERROR_COUNT, $row['error_count']);
                $item->setData(self::schema_fields_WARNING_COUNT, $row['warning_count']);
                $item->setData(self::schema_fields_LAST_SYNC_AT, $row['date'] . ' 12:00:00');
                $item->setExtraData([
                    'acceptance_fixture' => true,
                    'acceptance_schema' => self::ACCEPTANCE_RECEIPT_SCHEMA,
                    'acceptance_case_id' => $caseId,
                    'acceptance_request_key' => $requestKey,
                    'acceptance_payload_hash' => $payloadHash,
                    'acceptance_row_index' => $index,
                    'date' => $row['date'],
                    'query' => $row['query'],
                    'page' => $row['page'],
                ]);
                $item->save();
            }

            $receiptRows[] = [
                'id' => (int)$item->getId(),
                'account_id' => $accountId,
                'date' => $row['date'],
                'query' => $row['query'],
                'page' => $row['page'],
            ];
        }

        $receiptRows = array_values($receiptRows);
        $rowCount = count($receiptRows);
        $receiptDigest = hash('sha256', self::canonicalJson([
            'schema' => self::ACCEPTANCE_RECEIPT_SCHEMA,
            'website_id' => $websiteId,
            'case_id' => $caseId,
            'request_key' => $requestKey,
            'payload_hash' => $payloadHash,
            'row_count' => $rowCount,
            'rows' => $receiptRows,
        ]));

        return [
            'schema' => self::ACCEPTANCE_RECEIPT_SCHEMA,
            'action' => 'seed',
            'status' => 'seeded',
            'website_id' => $websiteId,
            'case_id' => $caseId,
            'request_key' => $requestKey,
            'payload_hash' => $payloadHash,
            'row_count' => $rowCount,
            'receipt_digest' => $receiptDigest,
            'rows' => $receiptRows,
        ];
    }

    /**
     * Delete only rows named by a previously issued acceptance receipt.
     * Missing rows are treated as already cleaned so cleanup is idempotent.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function cleanupSearchAcceptanceFixture(array $params): array
    {
        self::assertSearchAcceptanceFixtureEnabled();
        $receipt = is_array($params['receipt'] ?? null) ? $params['receipt'] : $params;
        if (($receipt['schema'] ?? '') !== self::ACCEPTANCE_RECEIPT_SCHEMA) {
            throw new \InvalidArgumentException('SEO_SEARCH_ACCEPTANCE_FIXTURE_RECEIPT_INVALID');
        }

        $websiteId = self::acceptanceWebsiteId($receipt['website_id'] ?? null);
        $caseId = self::acceptanceIdentity($receipt['case_id'] ?? null, 'case_id');
        $requestKey = self::acceptanceIdentity($receipt['request_key'] ?? null, 'request_key');
        $payloadHash = strtolower(trim((string)($receipt['payload_hash'] ?? '')));
        if (!preg_match('/^[a-f0-9]{64}$/D', $payloadHash)) {
            throw new \InvalidArgumentException('SEO_SEARCH_ACCEPTANCE_FIXTURE_RECEIPT_INVALID');
        }
        $receiptRows = $receipt['rows'] ?? null;
        if (!is_array($receiptRows) || $receiptRows === [] || count($receiptRows) > 256) {
            throw new \InvalidArgumentException('SEO_SEARCH_ACCEPTANCE_FIXTURE_RECEIPT_INVALID');
        }
        $rowCount = self::acceptancePositiveInteger($receipt['row_count'] ?? null, 'row_count');
        if ($rowCount !== count($receiptRows)) {
            throw new \InvalidArgumentException('SEO_SEARCH_ACCEPTANCE_FIXTURE_RECEIPT_INVALID');
        }

        $canonicalReceiptRows = [];
        foreach (array_values($receiptRows) as $index => $receiptRow) {
            if (!is_array($receiptRow)) {
                throw new \InvalidArgumentException('SEO_SEARCH_ACCEPTANCE_FIXTURE_RECEIPT_INVALID');
            }
            $keys = array_keys($receiptRow);
            sort($keys, SORT_STRING);
            if ($keys !== ['account_id', 'date', 'id', 'page', 'query']) {
                throw new \InvalidArgumentException('SEO_SEARCH_ACCEPTANCE_FIXTURE_RECEIPT_INVALID');
            }
            $id = self::acceptancePositiveInteger($receiptRow['id'] ?? null, 'id');
            $accountId = self::acceptancePositiveInteger($receiptRow['account_id'] ?? null, 'account_id');
            if ($accountId !== self::acceptanceAccountId($caseId, $requestKey, $index)) {
                throw new \InvalidArgumentException('SEO_SEARCH_ACCEPTANCE_FIXTURE_RECEIPT_INVALID');
            }
            $date = trim((string)($receiptRow['date'] ?? ''));
            $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
            $query = trim((string)($receiptRow['query'] ?? ''));
            $page = trim((string)($receiptRow['page'] ?? ''));
            if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date
                || $query === '' || strlen($query) > 512
                || $page === '' || strlen($page) > 2048
            ) {
                throw new \InvalidArgumentException('SEO_SEARCH_ACCEPTANCE_FIXTURE_RECEIPT_INVALID');
            }
            $canonicalReceiptRows[] = [
                'id' => $id,
                'account_id' => $accountId,
                'date' => $date,
                'query' => $query,
                'page' => $page,
            ];
        }

        $receiptDigest = strtolower(trim((string)($receipt['receipt_digest'] ?? '')));
        if (!preg_match('/^[a-f0-9]{64}$/D', $receiptDigest)) {
            throw new \InvalidArgumentException('SEO_SEARCH_ACCEPTANCE_FIXTURE_RECEIPT_INVALID');
        }
        $expectedReceiptDigest = hash('sha256', self::canonicalJson([
            'schema' => self::ACCEPTANCE_RECEIPT_SCHEMA,
            'website_id' => $websiteId,
            'case_id' => $caseId,
            'request_key' => $requestKey,
            'payload_hash' => $payloadHash,
            'row_count' => $rowCount,
            'rows' => $canonicalReceiptRows,
        ]));
        if (!hash_equals($expectedReceiptDigest, $receiptDigest)) {
            throw new \InvalidArgumentException('SEO_SEARCH_ACCEPTANCE_FIXTURE_RECEIPT_INVALID');
        }

        $itemsToDelete = [];
        foreach ($canonicalReceiptRows as $index => $receiptRow) {
            $item = $this->findAcceptanceRecord($websiteId, $receiptRow['account_id']);
            if ($item === null) {
                continue;
            }
            $meta = $item->getExtraData();
            if ((int)$item->getId() !== $receiptRow['id']
                || ($meta['acceptance_schema'] ?? '') !== self::ACCEPTANCE_RECEIPT_SCHEMA
                || ($meta['acceptance_case_id'] ?? '') !== $caseId
                || ($meta['acceptance_request_key'] ?? '') !== $requestKey
                || ($meta['acceptance_payload_hash'] ?? '') !== $payloadHash
                || (int)($meta['acceptance_row_index'] ?? -1) !== $index
                || ($meta['date'] ?? '') !== $receiptRow['date']
                || ($meta['query'] ?? '') !== $receiptRow['query']
                || ($meta['page'] ?? '') !== $receiptRow['page']
                || substr((string)$item->getData(self::schema_fields_STATS_DATE), 0, 10) !== $receiptRow['date']
            ) {
                throw new \RuntimeException('SEO_SEARCH_ACCEPTANCE_FIXTURE_REQUEST_CONFLICT');
            }
            $itemsToDelete[] = $item;
        }

        $deleted = 0;
        foreach ($itemsToDelete as $item) {
            $item->delete();
            ++$deleted;
        }

        return [
            'schema' => self::ACCEPTANCE_RECEIPT_SCHEMA,
            'action' => 'cleanup',
            'status' => 'cleaned',
            'website_id' => $websiteId,
            'case_id' => $caseId,
            'request_key' => $requestKey,
            'payload_hash' => $payloadHash,
            'row_count' => $rowCount,
            'receipt_digest' => $receiptDigest,
            'rows' => $canonicalReceiptRows,
            'deleted_rows' => $deleted,
        ];
    }

    private static function assertSearchAcceptanceFixtureEnabled(): void
    {
        if (getenv('WELINE_SEO_ACCEPTANCE_MODE') !== '1'
            || getenv('WELINE_SEO_ACCEPTANCE_FIXTURES') !== '1'
        ) {
            throw new \RuntimeException('SEO_SEARCH_ACCEPTANCE_FIXTURE_DISABLED');
        }
    }

    private static function acceptanceWebsiteId(mixed $value): int
    {
        if (is_int($value)) {
            $websiteId = $value;
        } elseif (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value)) {
            $websiteId = (int)$value;
        } else {
            throw new \InvalidArgumentException('website_id must be a non-negative integer.');
        }
        if ($websiteId < 0) {
            throw new \InvalidArgumentException('website_id must be a non-negative integer.');
        }
        return $websiteId;
    }

    private static function acceptanceIdentity(mixed $value, string $field): string
    {
        $identity = is_string($value) ? trim($value) : '';
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $identity)) {
            throw new \InvalidArgumentException($field . ' is invalid.');
        }
        return $identity;
    }

    /** @return list<array<string,int|float|string>> */
    private static function acceptanceRows(mixed $value): array
    {
        if (!is_array($value) || $value === [] || count($value) > 256) {
            throw new \InvalidArgumentException('rows must contain between 1 and 256 entries.');
        }
        $rows = [];
        $identities = [];
        foreach (array_values($value) as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('each Search acceptance row must be an object.');
            }
            $date = trim((string)($row['date'] ?? ''));
            $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
            if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) {
                throw new \InvalidArgumentException('row.date must be a real YYYY-MM-DD date.');
            }
            $query = trim((string)($row['query'] ?? ''));
            $page = trim((string)($row['page'] ?? ''));
            if ($query === '' || strlen($query) > 512 || $page === '' || strlen($page) > 2048) {
                throw new \InvalidArgumentException('row.query and row.page are required and bounded.');
            }
            $identity = $date . "\0" . $query . "\0" . $page;
            if (isset($identities[$identity])) {
                throw new \InvalidArgumentException('duplicate dated query/page row.');
            }
            $identities[$identity] = true;
            $impressions = self::acceptanceNonNegativeInteger($row['impressions'] ?? null, 'impressions');
            $clicks = self::acceptanceNonNegativeInteger($row['clicks'] ?? null, 'clicks');
            if ($clicks > $impressions) {
                throw new \InvalidArgumentException('clicks must not exceed impressions.');
            }
            $position = $row['average_position'] ?? 0;
            if ((!is_int($position) && !is_float($position) && !is_string($position))
                || !is_numeric($position)
                || !is_finite((float)$position)
                || (float)$position < 0.0
            ) {
                throw new \InvalidArgumentException('average_position must be a non-negative finite number.');
            }
            $rows[] = [
                'date' => $date,
                'query' => $query,
                'page' => $page,
                'impressions' => $impressions,
                'clicks' => $clicks,
                'average_position' => (float)$position,
                'indexed_pages' => self::acceptanceNonNegativeInteger($row['indexed_pages'] ?? 0, 'indexed_pages'),
                'error_count' => self::acceptanceNonNegativeInteger($row['error_count'] ?? 0, 'error_count'),
                'warning_count' => self::acceptanceNonNegativeInteger($row['warning_count'] ?? 0, 'warning_count'),
            ];
        }
        usort($rows, static fn(array $left, array $right): int => [$left['date'], $left['query'], $left['page']] <=> [$right['date'], $right['query'], $right['page']]);
        return $rows;
    }

    private static function acceptanceNonNegativeInteger(mixed $value, string $field): int
    {
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value)) {
            $integer = (int)$value;
        } else {
            throw new \InvalidArgumentException($field . ' must be a non-negative integer.');
        }
        if ($integer < 0) {
            throw new \InvalidArgumentException($field . ' must be a non-negative integer.');
        }
        return $integer;
    }

    private static function acceptancePositiveInteger(mixed $value, string $field): int
    {
        $integer = self::acceptanceNonNegativeInteger($value, $field);
        if ($integer === 0) {
            throw new \InvalidArgumentException($field . ' must be positive.');
        }
        return $integer;
    }

    private static function acceptanceAccountId(string $caseId, string $requestKey, int $index): int
    {
        $prefix = (int)hexdec(substr(hash('sha256', $caseId . "\0" . $requestKey), 0, 8));
        return 1000000000 + ($prefix % 700000000) + $index;
    }

    private function findAcceptanceRecord(int $websiteId, int $accountId): ?self
    {
        $model = clone $this;
        $model->clearData()->clearQuery();
        $model->where(self::schema_fields_WEBSITE_ID, $websiteId)
            ->where(self::schema_fields_ACCOUNT_ID, $accountId)
            ->where(self::schema_fields_PLATFORM, self::ACCEPTANCE_PLATFORM)
            ->select()
            ->fetch();
        foreach ($model->getItems() as $item) {
            if ($item instanceof self) {
                return $item;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $value */
    private static function canonicalJson(array $value): string
    {
        $normalize = static function (mixed $item) use (&$normalize): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (!array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            foreach ($item as $key => $child) {
                $item[$key] = $normalize($child);
            }
            return $item;
        };
        return json_encode($normalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function save_before(): void
    {
        $now = date('Y-m-d H:i:s');
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }
        $this->setData(self::schema_fields_UPDATED_AT, $now);
    }
}
