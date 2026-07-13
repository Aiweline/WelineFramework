<?php

declare(strict_types=1);

namespace Weline\Websites\Api\Catalog\Data;

/** Immutable website projection for cross-module listings. */
final readonly class WebsiteSummary
{
    public function __construct(
        public int $id,
        public string $name,
        public string $code,
        public string $url,
    ) {
    }

    /** @return array{website_id:int,name:string,code:string,url:string} */
    public function toArray(): array
    {
        return [
            'website_id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'url' => $this->url,
        ];
    }
}
