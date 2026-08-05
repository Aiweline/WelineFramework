<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Websites\Api\Catalog\Data\WebsiteSummary;
use Weline\Websites\Api\Catalog\WebsiteCatalogInterface;
use Weline\Websites\Model\Website;

final class WebsiteCatalog implements WebsiteCatalogInterface
{
    public function __construct(
        private readonly Website $website,
    ) {
    }

    public function defaultWebsiteId(): int
    {
        $row = (clone $this->website)->clearQuery()->clearData()
            ->where(Website::schema_fields_CODE, Website::CODE_DEFAULT)
            ->find()
            ->fetchArray();
        if (\is_array($row)
            && (string)($row[Website::schema_fields_CODE] ?? '') === Website::CODE_DEFAULT) {
            return \max(Website::ID_DEFAULT, (int)($row[Website::schema_fields_ID] ?? Website::ID_DEFAULT));
        }

        return Website::ID_DEFAULT;
    }

    public function all(): array
    {
        $rows = $this->website->clearQuery()->clearData()
            ->order(Website::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();

        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int)($row[Website::schema_fields_ID] ?? 0);
            if ($id < Website::ID_DEFAULT) {
                continue;
            }
            $result[] = new WebsiteSummary(
                $id,
                (string)($row[Website::schema_fields_NAME] ?? ('#' . $id)),
                (string)($row[Website::schema_fields_CODE] ?? ''),
                (string)($row[Website::schema_fields_URL] ?? ''),
            );
        }

        return $result;
    }

    public function count(): int
    {
        return (int)$this->website->reset()->count(Website::schema_fields_ID);
    }
}
