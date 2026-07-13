<?php

declare(strict_types=1);

namespace Weline\Framework\Phrase;

interface GlobalDictionaryProviderInterface
{
    /** @return array<string, string> */
    public function words(string $locale): array;
}
