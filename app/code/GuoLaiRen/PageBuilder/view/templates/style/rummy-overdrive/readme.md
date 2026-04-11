# Rummy Overdrive Theme

## Theme Overview

**Rummy Overdrive** is a high-contrast rummy landing theme built for bolder section separation, stronger motion cues, and cleaner mobile reading. It keeps the existing PageBuilder PHP config conventions while changing the HTML presentation more aggressively than the earlier light-layout clones.

## Core Direction

- Neon-dark base with bright lime, aqua, ember, and sand contrast sections
- New homepage module structures instead of simple clone-and-recolor output
- Responsive-first spacing, snap tracks, and swipe-friendly interaction patterns
- Compatible with the existing `teen-patti-master`, `tpmst`, and related PageBuilder data/config patterns
- Tailwind-ready shell plus theme-local CSS and SVG motion layers

## Key Modules

- `header/nav.phtml`: glass-dark sticky nav with bottom-edge comet streak
- `content/hero-slider.phtml`: centered theatre hero with animated gradient ellipses and rummy-specific feature cards
- `content/advantages.phtml`: acid-lime manifesto block with dark stacked benefit cards
- `content/games.phtml`: three equal overdrive room cards with hover sweep effects and mobile snap scrolling
- `content/testimonials.phtml`: review lane carousel with compact rating summary and touch-ready navigation
- `content/faq.phtml`: two-column inline accordion that opens answers inside each card
- `footer/links.phtml`: dark signal-footer with streak motion and centered social actions

## Design Notes

- Module backgrounds are intentionally high-contrast so adjacent sections do not blend together
- Accent palette: ember pink, electric aqua, acid lime, and warm sand
- Typography: `Sora` for headings and `Space Grotesk` for body copy
- SVG assets are theme-local and can be swapped without changing the PHP data logic

## Layout Coverage

This theme includes homepage, about, contact, FAQ, games, blog list/category/detail, and legal/policy page layouts.

## Usage

1. Create a new page in PageBuilder.
2. Select `rummy-overdrive` as the style template.
3. Keep the original config keys or override module fields in the editor.
4. Preview on mobile first, then refine desktop spacing if needed.

## Compatibility

- PHP 7.4+
- Modern browsers
- Existing PageBuilder component config format
