<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

/**
 * Immutable, read-only Website match used before storefront Scope installation.
 *
 * This value deliberately contains no Store/Channel identity. Early URL and
 * start-page parsing may discover Website metadata, while App remains the only
 * normal navigation entry that installs the frozen full ScopeIdentity.
 */
final readonly class StorefrontWebsiteContext
{
    public function __construct(
        public int $websiteId,
        public string $code,
        public string $name,
        public string $url,
        public string $defaultCurrency,
        public string $defaultLanguage,
        public string $defaultTimezone,
    ) {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException('website_id must be a non-negative integer.');
        }
        if (\preg_match('/^[a-z0-9][a-z0-9_-]{0,254}$/D', $code) !== 1) {
            throw new \InvalidArgumentException('Website code is not canonical.');
        }
        if ($websiteId === 0 && $code !== 'default') {
            throw new \InvalidArgumentException('website_id=0 must use code=default.');
        }
        if ($url === '' || $defaultCurrency === '' || $defaultLanguage === '' || $defaultTimezone === '') {
            throw new \InvalidArgumentException('Website navigation context is incomplete.');
        }
    }

    /**
     * @return array{website_id:int,code:string,name:string,url:string,default_currency:string,default_language:string,default_timezone:string}
     */
    public function toArray(): array
    {
        return [
            'website_id' => $this->websiteId,
            'code' => $this->code,
            'name' => $this->name,
            'url' => $this->url,
            'default_currency' => $this->defaultCurrency,
            'default_language' => $this->defaultLanguage,
            'default_timezone' => $this->defaultTimezone,
        ];
    }
}
