<?php

declare(strict_types=1);

namespace Weline\I18n\Api\Translation;

interface TranslationCollectorInterface
{
    /**
     * @return array<string, array{file:string,context:string,module:string}>
     */
    public function collect(?string $modulePath = null, ?string $moduleName = null): array;

    /**
     * @return \Generator<string, array{file:string,context:string,module:string}>
     */
    public function collectLazy(?string $modulePath = null, ?string $moduleName = null): \Generator;
}
