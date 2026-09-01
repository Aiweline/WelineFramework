<?php

declare(strict_types=1);

namespace Weline\Marketing\Api\Deal;

/**
 * Extension point for other modules to create/update automatic deal discounts.
 *
 * Registered via Marketing `etc/module.php` `provides`.
 * Cross-module code must depend on this interface only — never RuleEngine or Rule models.
 */
interface ExternalDealDiscountProviderInterface
{
    public function upsert(ExternalDealDiscountRequest $request): ExternalDealDiscountResult;
}
