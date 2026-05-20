<?php

declare(strict_types=1);

namespace Weline\Visitor\Extends\Module\Weline_Ai\Skill;

use Weline\Ai\Interface\SkillProviderInterface;
use Weline\Ai\Model\AiSkill;

final class VisitorPixelSkillProvider implements SkillProviderInterface
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function listSkills(): array
    {
        return [[
            'code' => 'weline-pixel-events',
            'name' => 'Weline Pixel Event Instrumentation',
            'description' => 'Use the Weline Visitor pixel runtime to mark and track meaningful PageBuilder conversion events.',
            'body' => $this->body(),
            'source_type' => AiSkill::SOURCE_MODULE,
            'source_module' => 'Weline_Visitor',
            'source' => 'module:Weline_Visitor',
            'version' => '1.0.0',
            'tags' => ['pixel', 'analytics', 'events', 'pagebuilder', 'conversion'],
            'local_path' => 'app/code/Weline/Visitor/extends/module/Weline_Ai/Skill/VisitorPixelSkillProvider.php',
            'readonly' => true,
        ]];
    }

    private function body(): string
    {
        return <<<'MD'
# Weline Pixel Event Instrumentation

## Purpose

When generating PageBuilder site plans, block contracts, HTML fragments, or component code, configure conversion and behavior tracking with the existing Weline Visitor pixel runtime. The goal is to make important visitor actions observable without inventing provider-specific scripts or changing the business flow.

## Runtime Contract

- The page runtime exposes `window.WelinePixel.track(eventName, meta, options)` when Weline Visitor pixel is loaded.
- Click tracking can be enabled declaratively by adding a class shaped as `weline-pixel::<event_name>` to the clickable element or one of its ancestors.
- Use snake_case event names. The runtime also normalizes hyphenated names to snake_case, but generated code should emit snake_case directly.
- Do not inject Google Analytics, Facebook Pixel, TikTok, Bing, gtag, fbq, ttq, UET, or any third-party pixel scripts. Weline and WeShop analytics dispatchers handle provider fan-out.
- Do not send network requests directly to pixel endpoints. Use the runtime API or the declarative class convention only.

## Event Placement Rules

- In Stage1 and build plans, include an `analytics_events` or equivalent implementation note for blocks that create measurable intent: hero CTA, pricing CTA, lead form, contact action, product card, add-to-cart action, checkout entry, download, booking, signup, search, and navigation CTA.
- In generated HTML, mark the exact interactive element, not the whole section, unless the whole section is the action target.
- Do not manually emit passive page_view, page_load, homepage, category, blog, or search_result_view events. The Weline pixel runtime already handles passive page and search telemetry.
- Prefer declarative click tracking when a click is enough: add `weline-pixel::<event_name>` to the button, anchor, or clickable card.
- Use explicit `window.WelinePixel.track(...)` only when the event is not a normal click, such as form submit, custom JavaScript completion, multi-step interaction, or when richer metadata is required.
- Tracking must never block navigation, form submission, checkout, or UI state changes. If you call `track` before navigation, pass `{ keepalive: true, element: targetElement, domEvent: event }` when available.

## Recommended Event Names

- Use existing commerce/funnel names when they apply: `view_item`, `add_to_cart`, `buy_now`, `add_to_wishlist`, `view_cart`, `begin_checkout`, `place_order`, `checkout_success`, `checkout_failure`, `search_focus`, `search_input`, `search_submit`, `search_suggestion_click`, `route_click`.
- For PageBuilder marketing blocks, use specific conversion names: `hero_cta_click`, `pricing_cta_click`, `lead_submit`, `signup_click`, `contact_click`, `download_click`, `booking_click`, `demo_request_click`, `whatsapp_click`, `newsletter_submit`.
- Avoid vague names such as `click`, `button_click`, `section_click`, or `ai_event` for meaningful conversions.

## Metadata And Attributes

- Add useful data attributes that the runtime can read: `data-product-id`, `data-item-id`, `data-sku`, `data-product-sku`, `data-product-name`, `data-name`, `data-price`, `data-pixel-value`, `data-qty`, `data-quantity`.
- For value-bearing actions, put `data-pixel-value` on the clicked element or a nearby context element. The runtime can also read `weline-pixel::<event_name>:value` helper classes.
- For product cards or item rows, keep metadata on the card/container and put the event class on the action button so the runtime can resolve product context.
- Use `additionalInfo.meta` through the `meta` argument when calling `track` explicitly. Include stable fields such as `source: 'pagebuilder_ai_site'`, `block_code`, `section`, `cta_label`, `form_name`, `href`, and `trigger`.
- Do not place secrets, emails, phone numbers, personal data, tokens, or admin URLs in pixel metadata unless they are already public page content and necessary for the event.

## PageBuilder Code Generation Pattern

- For `html_content`, add declarative classes and safe data attributes only.
- For `js_content`, write component-scoped JavaScript only. PageBuilder already supplies a `component` variable; do not wrap with `document.addEventListener`, IIFE, or global bootstrap code.
- If generated `js_content` submits a form event, use component-local listeners:

```javascript
const leadForm = component.querySelector('form[data-pb-lead-form]');
if (leadForm) {
    leadForm.addEventListener('submit', function (event) {
        if (window.WelinePixel && typeof window.WelinePixel.track === 'function') {
            window.WelinePixel.track('lead_submit', {
                source: 'pagebuilder_ai_site',
                form_name: leadForm.getAttribute('data-form-name') || 'lead',
                trigger: 'submit',
                domElement: leadForm
            }, { element: leadForm, domEvent: event, keepalive: true });
        }
    });
}
```

## Examples

```html
<a class="hero__cta weline-pixel::hero_cta_click"
   href="/contact.html"
   data-name="Hero primary CTA"
   data-pixel-value="0">
    Request a demo
</a>
```

```html
<article class="product-card"
         data-product-id="sku-123"
         data-product-name="Starter Kit"
         data-price="49">
    <a class="product-card__link weline-pixel::view_item" href="/product/starter-kit.html">View details</a>
    <button class="product-card__cart weline-pixel::add_to_cart" type="button">Add to cart</button>
</article>
```

## Final Check

Before returning a plan or component, silently verify that every primary CTA, lead form, product action, and checkout/contact entry has an appropriate Weline pixel event marker or explicit `WelinePixel.track` call. If a generated block has no meaningful visitor action, do not add fake tracking just to satisfy this skill.
MD;
    }
}
