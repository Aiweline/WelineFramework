<?php

declare(strict_types=1);

namespace Weline\Blog\Api\Sitemap;

use Weline\Blog\Service\BlogContentResolver;
use Weline\Framework\Manager\ObjectManager;

/**
 * DI migration factory so BlogSitemapUrlBuilder can typehint the content source interface.
 */
final class BlogSitemapContentSourceInterfaceFactory
{
    public function create(array $data = []): BlogSitemapContentSourceInterface
    {
        return ObjectManager::getInstance(BlogContentResolver::class);
    }
}
