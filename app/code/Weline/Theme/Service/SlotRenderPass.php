<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Theme\Model\WelineTheme;

/**
 * Single processSlots* algorithm scratch — not request facts.
 *
 * Request-scoped page facts live in RequestContext bags
 * (storefront.render_context.v1 / product.pdp_seed_offer.v1 /
 * product.storefront.page_assigns.v1). This object must not be ProcessShared.
 */
final class SlotRenderPass
{
    /** @var array<string, true> */
    public array $filledSlotIds = [];

    /**
     * @var list<array{
     *   slot_id:string,
     *   layout_id:int,
     *   node_uid:string,
     *   widget_code:string,
     *   widget_module:string,
     *   widget_name:string,
     *   reason:string,
     *   message:string
     * }>
     */
    public array $unavailableWidgets = [];

    /** @var list<array<string, mixed>> */
    public array $orphanWidgets = [];

    public ?WelineTheme $renderTheme = null;

    public string $renderArea = 'frontend';

    /** @var array<string, string> */
    public array $domOpaqueTokens = [];
}
