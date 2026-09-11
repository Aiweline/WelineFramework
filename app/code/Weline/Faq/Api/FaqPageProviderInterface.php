<?php

declare(strict_types=1);

namespace Weline\Faq\Api;

/**
 * Module-contributed FAQ help pages (PaymentCustomerGuide-style injection).
 *
 * Providers live under extends/module/Weline_Faq/FaqPageProvider/.
 * Body content belongs in phtml templates with &lt;lang&gt; tags.
 */
interface FaqPageProviderInterface
{
    public function pageCode(): string;

    public function slug(): string;

    public function title(): string;

    public function summary(): string;

    public function sortOrder(): int;

    public function isEnabled(): bool;

    /** Module template path, e.g. Weline_B2B::templates/frontend/faq/b2b-wholesale.phtml */
    public function template(): string;

    /** Optional Hub grouping label. */
    public function group(): string;
}
