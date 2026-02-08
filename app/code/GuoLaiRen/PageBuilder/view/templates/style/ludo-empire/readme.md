# LudoEmpire (ludo-empire) Template

## Template Overview

A vibrant purple + magenta themed PageBuilder template designed for Ludo board game websites. Inspired by mainstream gaming apps like Ludo King, Ludo Star, and Ludo Club.

### Use Cases

- Online Ludo gaming platforms
- Board game communities
- Gaming app landing pages
- Tournament & competition sites
- Casual gaming portals

### Core Features

1. **15 Components** - Full set of components for gaming websites
2. **Purple + Magenta Theme** - Fun, vibrant, colorful design with dice/board game elements
3. **Responsive Design** - Mobile-first, works on all devices
4. **Component Architecture** - Drag-and-drop visual builder support
5. **14 Page Layouts** - Pre-configured layouts for all page types
6. **Self-contained CSS** - All styles scoped via unique instance IDs

## File Structure

```
ludo-empire/
├── header.phtml            - Header region (navigation + pixel)
├── content.phtml           - Content region renderer
├── footer.phtml            - Footer region (4-column grid)
├── layout.phtml            - Main layout with component mappings
├── readme.md               - This file
├── colors/
│   └── default.phtml       - Purple + magenta dark theme (80+ entries)
├── components/
│   ├── component.json      - 15 components, 3 regions
│   ├── header/
│   │   └── nav.phtml       - Navigation with dice logo + Play Now CTA
│   ├── content/
│   │   ├── hero-slider.phtml     - Hero: Roll the Dice, Rule the Board!
│   │   ├── games.phtml           - 4 game modes (Classic, Quick, Tournament, Team)
│   │   ├── advantages.phtml      - 6 advantages with icons
│   │   ├── app-download.phtml    - App download with phone mockup
│   │   ├── testimonials.phtml    - 3 player reviews with star ratings
│   │   ├── faq.phtml             - FAQ accordion
│   │   ├── cta-banner.phtml      - Ready to Roll? CTA
│   │   ├── blog-list.phtml       - Blog grid with category filter
│   │   ├── blog-detail.phtml     - Blog post with sidebar
│   │   ├── blog-category.phtml   - Blog category listing
│   │   ├── legal-content.phtml   - Legal document display
│   │   ├── about-content.phtml   - About page with stats
│   │   └── contact-content.phtml - Contact form + info
│   └── footer/
│       └── links.phtml           - Footer with 3 columns
└── layouts/
    └── default/
        ├── home_page.json
        ├── custom_page.json
        ├── about_page.json
        ├── contact_page.json
        ├── games_page.json
        ├── faq_page.json
        ├── blog_list.json
        ├── blog_post.json
        ├── blog_category.json
        ├── privacy_policy.json
        ├── terms_of_service.json
        ├── cookie_policy.json
        ├── refund_policy.json
        └── shipping_policy.json
```

## Components

| Component | File | Description |
|-----------|------|-------------|
| Navigation | header/nav.phtml | Logo with dice icon, nav links, Play Now CTA |
| Hero Slider | content/hero-slider.phtml | Hero banner with stats and decorative dice |
| Game Modes | content/games.phtml | 4 game mode cards with player counts |
| Advantages | content/advantages.phtml | 6 advantage cards with icons |
| App Download | content/app-download.phtml | Download section with phone mockup |
| Testimonials | content/testimonials.phtml | Player reviews with star ratings |
| FAQ | content/faq.phtml | Accordion FAQ section |
| CTA Banner | content/cta-banner.phtml | Call-to-action banner |
| Blog List | content/blog-list.phtml | Blog grid with filtering |
| Blog Detail | content/blog-detail.phtml | Blog post with sidebar |
| Blog Category | content/blog-category.phtml | Category listing |
| Legal Content | content/legal-content.phtml | Legal pages display |
| About Content | content/about-content.phtml | About page with stats |
| Contact Content | content/contact-content.phtml | Contact form + info |
| Footer Links | footer/links.phtml | 3-column footer links |

## Color Palette

- **Primary**: #8b5cf6 (Purple)
- **Secondary**: #ec4899 (Magenta)
- **Tertiary**: #06b6d4 (Cyan)
- **Body BG**: #090012 (Deep Purple Black)
- **Text Primary**: #f5f3ff (Light Purple White)
- **Text Secondary**: #c4b5fd (Light Purple)

## Responsive Breakpoints

- Desktop: > 992px
- Tablet: 768px - 992px
- Mobile: < 768px
- Small Mobile: < 576px

## CSS Prefix

All CSS classes use the `le-` prefix to avoid conflicts.

## Font

Nunito (Google Fonts) - Fun, rounded, perfect for gaming UI.

---

**Template Path**: `GuoLaiRen_PageBuilder::style/ludo-empire`

**Version**: 1.0.0
