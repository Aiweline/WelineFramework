# UI/UX search and quality reference

## Search domains

| Domain | Use for |
|---|---|
| `product` | Product-type patterns such as SaaS, ecommerce, portfolio, healthcare, or service |
| `style` | Visual direction, effects, density, and tone |
| `typography` | Font pairing and hierarchy |
| `color` | Product-appropriate palette roles |
| `landing` | Page narrative, CTA, trust, pricing, and social proof |
| `chart` | Trend, comparison, distribution, timeline, funnel, and composition charts |
| `ux` | Interaction, loading, feedback, accessibility, and common anti-patterns |
| `web` | Semantic HTML, ARIA, focus, keyboard, performance, and responsive behavior |
| `react` | React/Next rendering and performance concerns when that stack is confirmed |

Use `-n <count>` to bound results and `-f markdown` when the output is intended for a design artifact.

## Review checklist

### Hierarchy and layout

- One primary action or decision point dominates each state.
- Sections have a clear reading order and use a consistent spacing scale.
- Containers, grids, alignment, and density match the target product rather than a generic landing-page template.
- Responsive behavior is defined at content-driven breakpoints; no accidental clipping or horizontal scroll.

### Components and interaction

- Reuse the target's existing component and token system.
- Use consistent icon source, viewBox, size, stroke/fill, and accessible label.
- Interactive elements expose hover, active, focus-visible, disabled, loading, success, and error states where applicable.
- Hover effects avoid scale/layout shifts; clickable regions and touch targets are large enough.
- Motion communicates state and respects reduced-motion preferences.

### Color and typography

- Text/background contrast remains readable in every supported theme.
- Primary, status, surface, border, and text colors come from semantic tokens.
- Type sizes, weights, and line heights form a clear hierarchy without excessive variants.
- Do not guess official brand marks or create a parallel palette in a site module.

### Accessibility and content

- Semantic elements and labels match their behavior.
- Keyboard order follows visual order; focus is visible and not trapped.
- Images/icons have appropriate alternatives; decorative items stay out of the accessibility tree.
- Copy is concise, translatable, and explains recovery in empty/error states.

### Weline integration

- Theme/template/component ownership follows the matched Weline skill.
- Browser business requests use `Weline.Api.*`, not native HTTP helpers.
- Default-theme styling uses current tokens/components rather than a new framework.
- Storefront sections preserve semantic `weline-code`.
- Final evidence comes from the rendered Browser surface, not the design search output alone.
