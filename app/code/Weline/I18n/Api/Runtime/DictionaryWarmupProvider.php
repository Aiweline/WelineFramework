<?php

declare(strict_types=1);

namespace Weline\I18n\Api\Runtime;

use Weline\Framework\Runtime\DictionaryWarmupProviderInterface;
use Weline\I18n\Parser;

final class DictionaryWarmupProvider implements DictionaryWarmupProviderInterface
{
    public function preloadWorkerDictionaries(): void
    {
        Parser::preloadWorkerDictionaries();
    }
}
