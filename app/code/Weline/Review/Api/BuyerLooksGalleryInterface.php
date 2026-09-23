<?php

declare(strict_types=1);

namespace Weline\Review\Api;

/**
 * Homepage / looks-wall gallery items sourced from approved review images.
 *
 * Buyer looks (买家秀) is a presentation surface; product reviews remain the
 * production + moderation source. Do not invent a parallel UGC store.
 */
interface BuyerLooksGalleryInterface
{
    /**
     * Recent approved reviews that have at least one attached image.
     *
     * @return list<array{
     *   image:string,
     *   title:string,
     *   text:string,
     *   link:string,
     *   link_label:string,
     *   review_id:int,
     *   entity_uuid:string
     * }>
     */
    public function galleryItems(int $limit = 6, ?int $websiteId = null, ?string $entityUuid = null): array;
}
