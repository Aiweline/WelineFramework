# PokerArena (poker-arena) Template

## Overview

Premium emerald green + gold poker/casino card game website template. Inspired by mainstream poker platforms like PokerStars, 888poker, and PartyPoker. Features sophisticated casino elegance with subtle poker chip and card decorative elements.

### Use Cases

- Online poker platforms
- Casino/card game websites
- Tournament platforms
- Gaming landing pages
- Entertainment/gaming apps

### Core Features

1. **Emerald + Gold Theme** - Premium casino-inspired dark green and gold palette
2. **15 Components** - Full component library for all page types
3. **Responsive Design** - Mobile-first at 992px, 768px, 576px breakpoints
4. **Poker Decorations** - Card suit symbols, chip elements, felt-green backgrounds
5. **Component System** - Visual drag-and-drop support
6. **14 Layout Presets** - Ready-made layouts for all page types

## Template Structure

```
poker-arena/
├── header.phtml           - Header region (navigation)
├── content.phtml          - Content region renderer
├── footer.phtml           - Footer region (4-column grid)
├── layout.phtml           - Main layout file
├── readme.md              - This documentation
├── colors/
│   └── default.phtml      - Emerald+gold dark theme (80+ entries)
├── components/
│   ├── component.json     - Component manifest (15 components)
│   ├── header/
│   │   └── nav.phtml      - Navigation with spade logo
│   ├── content/
│   │   ├── hero-slider.phtml    - Hero with floating card suits
│   │   ├── games.phtml          - 4 poker variants showcase
│   │   ├── advantages.phtml     - 6 platform advantages
│   │   ├── app-download.phtml   - Mobile app download
│   │   ├── testimonials.phtml   - Player reviews
│   │   ├── faq.phtml            - FAQ accordion
│   │   ├── cta-banner.phtml     - Call-to-action banner
│   │   ├── blog-list.phtml      - Blog listing
│   │   ├── blog-detail.phtml    - Blog post detail
│   │   ├── blog-category.phtml  - Blog category listing
│   │   ├── legal-content.phtml  - Legal documents
│   │   ├── about-content.phtml  - About page
│   │   └── contact-content.phtml - Contact page
│   └── footer/
│       └── links.phtml          - 4-column footer
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

## Color Scheme

| Token | Value | Usage |
|-------|-------|-------|
| Primary | #047857 | Emerald green |
| Secondary | #d97706 | Gold |
| Accent | #b45309 | Amber |
| Body BG | #060d08 | Dark green-black |
| Text Primary | #ecfdf5 | Mint white |
| Text Secondary | #86efac | Light green |

## CSS Prefix

All classes use `pa-` prefix (PokerArena).

## Responsive Breakpoints

- **Desktop**: > 992px
- **Tablet**: 768px - 992px
- **Mobile**: < 768px
- **Small**: < 576px

---

**Template Path**: `GuoLaiRen_PageBuilder::style/poker-arena`  
**Version**: 1.0.0
