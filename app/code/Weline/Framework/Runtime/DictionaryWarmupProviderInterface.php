<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

interface DictionaryWarmupProviderInterface
{
    public function preloadWorkerDictionaries(): void;
}
