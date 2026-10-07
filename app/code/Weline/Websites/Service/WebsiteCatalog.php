<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\Cache\Service\ScopeSharedMemo;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Websites\Api\Catalog\Data\WebsiteSummary;
use Weline\Websites\Api\Catalog\WebsiteCatalogInterface;
use Weline\Websites\Model\Website;

/**
 * Website structural catalog: L1 Worker process + L2 shared (pool website).
 * Not Framework App\State — Websites owns this bag.
 */
final class WebsiteCatalog implements WebsiteCatalogInterface
{
    private const POOL = 'website';
    private const RESOURCE_ALL = 'websites.catalog.all';
    private const RESOURCE_DEFAULT_ID = 'websites.catalog.default_id';
    private const PROCESS_ALL_KEY = 'all';
    private const PROCESS_DEFAULT_ID_KEY = 'default_id';
    private const PROCESS_BAG_MAX = 8;
    private const SHARED_TTL = 600;

    /** @var array<string, mixed> */
    private static array $processBag = [];

    public function __construct(
        private readonly Website $website,
    ) {
    }

    public static function clearProcessCache(): void
    {
        self::$processBag = [];
        $global = ScopeIdentity::global();
        ScopeSharedMemo::forgetScoped(self::POOL, self::RESOURCE_ALL, $global);
        ScopeSharedMemo::forgetScoped(self::POOL, self::RESOURCE_DEFAULT_ID, $global);
        ScopeSharedMemo::purgeProcessPrefix('websites.catalog.');
    }

    public function defaultWebsiteId(): int
    {
        if (\array_key_exists(self::PROCESS_DEFAULT_ID_KEY, self::$processBag)) {
            return (int)self::$processBag[self::PROCESS_DEFAULT_ID_KEY];
        }

        $id = (int)ScopeSharedMemo::rememberScoped(
            self::POOL,
            self::RESOURCE_DEFAULT_ID,
            function (): int {
                $row = (clone $this->website)->clearQuery()->clearData()
                    ->where(Website::schema_fields_CODE, Website::CODE_DEFAULT)
                    ->find()
                    ->fetchArray();
                $resolved = Website::ID_DEFAULT;
                if (\is_array($row)
                    && (string)($row[Website::schema_fields_CODE] ?? '') === Website::CODE_DEFAULT) {
                    $resolved = \max(
                        Website::ID_DEFAULT,
                        (int)($row[Website::schema_fields_ID] ?? Website::ID_DEFAULT),
                    );
                }

                return $resolved;
            },
            ScopeIdentity::global(),
            self::SHARED_TTL,
        );

        return self::$processBag[self::PROCESS_DEFAULT_ID_KEY] = $id;
    }

    public function all(): array
    {
        if (\array_key_exists(self::PROCESS_ALL_KEY, self::$processBag)
            && \is_array(self::$processBag[self::PROCESS_ALL_KEY])
        ) {
            return $this->hydrateSummaries(self::$processBag[self::PROCESS_ALL_KEY]);
        }

        $payload = ScopeSharedMemo::rememberScoped(
            self::POOL,
            self::RESOURCE_ALL,
            function (): array {
                $rows = $this->website->clearQuery()->clearData()
                    ->order(Website::schema_fields_ID, 'ASC')
                    ->select()
                    ->fetchArray();

                $out = [];
                foreach ($rows as $row) {
                    if (!\is_array($row)) {
                        continue;
                    }
                    $id = (int)($row[Website::schema_fields_ID] ?? 0);
                    if ($id < Website::ID_DEFAULT) {
                        continue;
                    }
                    $out[] = [
                        'website_id' => $id,
                        'name' => (string)($row[Website::schema_fields_NAME] ?? ('#' . $id)),
                        'code' => (string)($row[Website::schema_fields_CODE] ?? ''),
                        'url' => (string)($row[Website::schema_fields_URL] ?? ''),
                    ];
                }

                return $out;
            },
            ScopeIdentity::global(),
            self::SHARED_TTL,
        );

        if (!\is_array($payload)) {
            $payload = [];
        }
        if (\count(self::$processBag) >= self::PROCESS_BAG_MAX
            && !\array_key_exists(self::PROCESS_ALL_KEY, self::$processBag)
        ) {
            self::$processBag = [];
        }
        self::$processBag[self::PROCESS_ALL_KEY] = $payload;

        return $this->hydrateSummaries($payload);
    }

    public function count(): int
    {
        return (int)$this->website->reset()->count(Website::schema_fields_ID);
    }

    /**
     * @param list<array{website_id:int,name:string,code:string,url:string}> $payload
     * @return list<WebsiteSummary>
     */
    private function hydrateSummaries(array $payload): array
    {
        $result = [];
        foreach ($payload as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $result[] = new WebsiteSummary(
                (int)($row['website_id'] ?? 0),
                (string)($row['name'] ?? ''),
                (string)($row['code'] ?? ''),
                (string)($row['url'] ?? ''),
            );
        }

        return $result;
    }
}
