<?php

declare(strict_types=1);

namespace Weline\Seo\Api\Url;

/** Data-only URL-change notification boundary for optional module integrations. */
interface UrlChangeNotifierInterface
{
    /** @param array<string, mixed> $change @return array<string, mixed> */
    public function notify(array $change): array;
}
