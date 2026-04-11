# Aviator Afterburn Theme

## Theme Overview

**Aviator Afterburn** is a high-contrast Aviator landing-page theme built around a flight-deck visual system instead of a generic casino stack. It follows the existing PageBuilder PHP config conventions while reshaping the presentation layer with stronger section contrast, runway-card modules, and mobile-first spacing.

## Core Direction

- High-contrast module backgrounds to improve scan speed
- Flight-deck inspired layouts instead of recycled left-right hero blocks
- Mobile-first responsive spacing, card sizing, and carousel behavior
- Compatible with the existing `teen-patti-master`, `tpmst`, and related PageBuilder data/config patterns
- Tailwind-ready shell with theme-local CSS for richer layout control

## Key Modules

- `header/nav.phtml`: flight-deck navigation with layered mobile panel and download CTA
- `content/hero-slider.phtml`: centered hero narrative with a console-style visual stage
- `content/advantages.phtml`: lane-based advantages layout with staggered cards
- `content/games.phtml`: runway-board room carousel with boarding-pass card styling
- `content/testimonials.phtml`: horizontal player-review rail with summary pod
- `content/faq.phtml`: timeline-style FAQ with inline expansion
- `content/about-content.phtml`: flight-brief about page with stats board and values deck
- `content/contact-content.phtml`: support-route contact page with desk cards and message dock
- `footer/links.phtml`: compact afterburn footer with branded closing section

## Design Notes

- Palette: afterburn orange, signal blue, deep ink, and cool ice backgrounds
- Typography: `Outfit` for headings and `Plus Jakarta Sans` for body copy
- SVG assets are theme-local and can be replaced without changing PHP logic
- Layout JSON and component field usage stay inside the supported config protocol

## Layout Coverage

This theme includes homepage, about, contact, FAQ, games, blog list/category/detail, and legal/policy page layouts.

## Usage

1. Create a new page in PageBuilder.
2. Select `aviator-afterburn` as the style template.
3. Keep the existing config keys or override module fields in the editor.
4. Preview on mobile first, then refine desktop spacing if needed.

## Compatibility

- PHP 7.4+
- Modern browsers
- Existing PageBuilder component config format
