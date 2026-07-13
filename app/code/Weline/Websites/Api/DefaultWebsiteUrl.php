<?php

declare(strict_types=1);

namespace Weline\Websites\Api;

use Weline\Websites\Model\Website;

/** Read-only public boundary for consumers that need a deployment base URL. */
final class DefaultWebsiteUrl
{
    public static function resolve(): string
    {
        /** @var Website $website */
        $website = \w_obj(Website::class);
        $row = $website->clearQuery()->clearData()
            ->where(Website::schema_fields_URL, '', '!=')
            ->order(Website::schema_fields_ID, 'ASC')
            ->find()
            ->fetchArray();

        return \is_array($row) ? \trim((string)($row[Website::schema_fields_URL] ?? '')) : '';
    }
}
