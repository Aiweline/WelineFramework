<?php

declare(strict_types=1);

namespace Weline\Framework\App\Localization;

interface LocalizationProviderInterface
{
    /** Higher priority providers represent a narrower request scope. */
    public function priority(): int;

    /** @return list<string> */
    public function languageCodes(): array;

    /** @return list<string> */
    public function currencyCodes(): array;

    /** null means this provider cannot decide. */
    public function supportsLanguage(string $code): ?bool;

    /** null means this provider cannot decide. */
    public function supportsCurrency(string $code): ?bool;
}
