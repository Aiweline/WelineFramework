<?php

declare(strict_types=1);

namespace Weline\Websites\Api;

use Weline\Websites\Model\Website;

final class WebsiteTargetLookup implements WebsiteTargetLookupInterface
{
    public function __construct(
        private readonly Website $website,
    ) {
    }

    public function find(int $websiteId): ?array
    {
        if ($websiteId < 0) {
            return null;
        }
        $row = $this->website->clearQuery()->clearData()
            ->where(Website::schema_fields_ID, $websiteId)
            ->find()
            ->fetchArray();
        if (!\is_array($row) || (int)($row[Website::schema_fields_ID] ?? 0) !== $websiteId) {
            return null;
        }
        return [
            'id' => $websiteId,
            'name' => (string)($row[Website::schema_fields_NAME] ?? ('#' . $websiteId)),
            'code' => (string)($row[Website::schema_fields_CODE] ?? ''),
        ];
    }
}
