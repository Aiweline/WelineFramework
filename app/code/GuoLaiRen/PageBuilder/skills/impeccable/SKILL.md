---
name: impeccable
description: >-
  Create distinctive, production-grade frontend interfaces with high design quality.
  Use for PageBuilder component HTML/CSS/JS generation when the output must avoid
  generic AI aesthetics, weak hierarchy, poor contrast, fragile responsive behavior,
  and bland copy.
---

# Impeccable

Source: pbakaus/impeccable, Apache License 2.0, adapted from upstream commit
1aedbcf538e3fa6694ccbf00294cc18e59ba1f21 for Weline PageBuilder adapter prompt injection.

This local PageBuilder skill is intentionally prompt-only. Do not tell the model
to run local scripts, edit skill files, create harness shortcuts, or read external
reference files. Apply the rules below directly while generating PageBuilder
component JSON fields, HTML fragments, CSS, and component-scoped JavaScript.

## Operating Goal

Generate PageBuilder components that look intentional, concrete, and production-ready.
The result should feel grounded in the current site brief, industry, audience,
language, and confirmed style direction. Avoid outputs that could be identified as
generic AI UI from their structure, palette, type choices, or copy.

## Design Context Rules

- Use the current PageBuilder prompt, plan_json context, website profile, locale,
  style snapshot, and selected vertical style as the active design context.
- Do not ask the user for more brand context during component generation. If context
  is thin, choose the most conservative direction that satisfies the prompt without
  inventing unsupported claims.
- Preserve explicit PageBuilder contracts over this skill when they conflict:
  JSON-only output, component prefix rules, image-slot requirements, i18n, analytics
  event markers, and safe skeletons are higher priority.
- Make one clear visual commitment per component: restrained product UI, editorial
  content, high-conversion marketing, luxury/refined, playful, industrial, dense
  dashboard, or another concrete direction implied by the prompt.

## Hard Visual Rules

- Do not use Inter, Roboto, Arial, Open Sans, or generic system defaults as the
  automatic answer. Use the font stack supplied by the prompt when available. If no
  font is supplied, choose a type approach that fits the site direction and keep it
  consistent within the component.
- Build hierarchy through meaningful scale, weight, spacing, and contrast. Avoid flat
  type scales where headings, labels, and body copy feel nearly identical.
- Keep body text readable. Body copy needs strong contrast against its background;
  muted text must not become light gray on tinted or colored surfaces.
- Do not use pure black, pure white, or flat gray as the whole palette. Tint neutrals
  subtly toward the site brand hue when a brand hue is known.
- Avoid purple-to-blue gradients, neon-on-dark, cyan-on-black, warm beige defaults,
  and other category reflex palettes unless the user brief or confirmed style
  explicitly requires them.
- Do not use gradient text. Use a solid color, size, weight, or layout for emphasis.
- Do not wrap everything in cards. Cards are for grouped, repeated, or actionable
  units. Never put cards inside cards.
- Keep cards and panels modestly rounded. Avoid 24px+ radii on normal cards, inputs,
  and content panels unless the confirmed design system already uses them.
- Do not pair a decorative 1px border with a wide soft shadow on the same element.
  Use one clear elevation or boundary treatment.
- Avoid identical card grids with icon, heading, and paragraph repeated across every
  section. Vary structure based on the content role.
- Do not use decorative blobs, bokeh orbs, diagonal stripe backgrounds, sketchy SVG
  scenes, or fake CSS product illustrations as filler.

## Layout And Responsive Rules

- Use the simplest layout that fits the content. Flex is enough for one-dimensional
  rows; grid is for real two-dimensional structure.
- Set stable dimensions for media, icons, metric rows, tabs, grids, and controls so
  generated content does not shift layout on hover or at smaller breakpoints.
- Constrain long text. Headings and labels must wrap cleanly on mobile; no word,
  number, price, or CTA label may overflow its container.
- Use `text-wrap: balance` for short headings and `text-wrap: pretty` for longer
  prose when CSS support is acceptable in the target prompt.
- Use responsive grids shaped like `repeat(auto-fit, minmax(...))` only when the
  content is actually a collection. Do not force all content into a three-card grid.
- Make spacing rhythmic, not uniform. Tighten controls and metadata; give primary
  content enough breathing room.

## Interaction And Motion Rules

- Buttons and links need clear hit areas, focus states, disabled states when relevant,
  and labels that describe the action.
- Do not hide content behind animation. A component must be usable when JavaScript is
  delayed, disabled, or motion is reduced.
- If motion is requested or useful, animate opacity, transform, filter, or clip-path
  with purposeful timing. Avoid bounce and elastic easing.
- Always include a reduced-motion alternative for non-trivial animations.
- Component JavaScript must stay scoped to the PageBuilder component contract. Do not
  add global bootstraps, direct network calls, or unrelated document-wide listeners.

## UX Copy Rules

- Every word must serve the component's job. Avoid generic claims such as
  streamline, empower, supercharge, leverage, unleash, world-class, next-generation,
  cutting-edge, game-changer, mission-critical, or seamless.
- Do not repeat a heading in the paragraph below it. Add specific evidence, offer
  detail, or operational meaning instead.
- CTA labels should be verb plus object: "Request a quote", "View plans",
  "Start checkout", "Download guide".
- Avoid aphoristic filler and meta phrases such as "not just X, but Y",
  "X theater", or "actually X".

## Final Silent Check

Before returning component output, silently verify:

- The component satisfies the current PageBuilder schema and adapter contract.
- The visual direction is specific to the site context, not a default AI template.
- Contrast, hierarchy, spacing, responsive wrapping, and focus states are acceptable.
- Primary CTAs and meaningful interactions remain obvious and usable.
- No banned visual trope, filler copy, generic palette, or unscoped JavaScript slipped in.
