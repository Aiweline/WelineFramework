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

    /**
     * Current-request website default language when this provider can decide.
     * null means this provider cannot decide (fall through to lower priority).
     */
    public function defaultLanguage(): ?string;

    /**
     * Current-request website default currency when this provider can decide.
     * null means this provider cannot decide (fall through to lower priority).
     */
    public function defaultCurrency(): ?string;

    /** null means this provider cannot decide. */
    public function supportsLanguage(string $code): ?bool;

    /** null means this provider cannot decide. */
    public function supportsCurrency(string $code): ?bool;

    /**
     * Installed locale catalog (may include inactive).
     * null = this provider does not participate; empty array = participates with none.
     *
     * @return list<string>|null
     */
    public function installedLanguageCodes(): ?array;
}
