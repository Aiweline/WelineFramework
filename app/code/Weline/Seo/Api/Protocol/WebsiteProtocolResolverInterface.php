<?php

declare(strict_types=1);

namespace Weline\Seo\Api\Protocol;

use Weline\Seo\Api\Protocol\Data\WebsiteContext;

interface WebsiteProtocolResolverInterface
{
    public function currentWebsite(): WebsiteContext;

    public function currentBaseUrl(): string;
}
