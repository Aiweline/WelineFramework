<?php

declare(strict_types=1);

namespace Weline\Inquiry\Api;

interface InquiryFormCatalogInterface
{
    /** @return list<array{code:string,name:string,default_locale:string}> */
    public function published(string $search = ''): array;
}
