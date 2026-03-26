<?php

declare(strict_types=1);

namespace WeShop\Search\Api;

interface SearchBrowseEngineInterface extends SearchEngineInterface
{
    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function browseProducts(array $request): array;
}
