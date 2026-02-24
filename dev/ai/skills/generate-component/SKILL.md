---
name: generate-component
description: Creates PageBuilder components/widgets for themes in Weline Framework. Use when creating new widgets, adding section types to themes, or creating reusable UI blocks. Covers component structure, configuration, and theme integration.
globs:
  - "**/PageBuilder/**/*.php"
  - "**/PageBuilder/**/*.phtml"
alwaysApply: false
---

# PageBuilder Component Generation Skill

## Overview
This skill guides the creation of individual PageBuilder components (widgets) for themes in the Weline Framework. Components are reusable UI blocks that can be configured and placed in different regions of a page.

## When to Use
- User wants to create a new component/widget for PageBuilder
- User wants to add a new section type to an existing theme
- User wants to create reusable UI blocks

## Prerequisites
- Existing theme in `app/code/GuoLaiRen/PageBuilder/view/templates/style/{theme_name}/`
- Understanding of theme color variables
- Component category (header, content, footer)

## Component File Structure

```
components/
鈹溾攢鈹€ header/
鈹?  鈹斺攢鈹€ {component_name}.phtml
鈹溾攢鈹€ content/
鈹?  鈹斺攢鈹€ {component_name}.phtml
鈹溾攢鈹€ footer/
鈹?  鈹斺攢鈹€ {component_name}.phtml
鈹斺攢鈹€ component.json
```

## Component Template Structure

### Basic Template

```php
<?php
/**
 * {Theme Name} - {Component Name}
 * 
 * {Component Description}
 * 
 * @var \Weline\Framework\View\Template $this
 * @var array $colors 棰滆壊閰嶇疆
 * @var array $component_config 缁勪欢閰嶇疆
 * 
 * @fields_start
 * 
 * group:{group_name} => {Group Display Name}
 * {group_name}.{field_name} => {Field Label}:{field_type}:{default_value}|{options_or_hint}
 * 
 * @fields_end
 */

$config = $component_config ?? [];

// Ensure colors available
if (!isset($colors) || !is_array($colors)) {
    $colors = [];
}

// Extract colors with fallbacks
$bgColor = $colors['section_bg_1'] ?? '#ffffff';
$textColor = $colors['text_primary'] ?? '#333333';
$accentColor = $colors['accent_primary'] ?? '#7c3aed';

// Extract config with defaults
$title = $config['content.title'] ?? 'Default Title';
$description = $config['content.description'] ?? 'Default description';

$instanceId = 'component-name-' . uniqid();
?>

<section class="component-name" id="<?= $instanceId ?>">
    <style>
        #<?= $instanceId ?> {
            background: <?= $bgColor ?>;
            padding: 80px 0;
        }
        /* More scoped styles */
    </style>
    
    <div class="container">
        <!-- Component HTML -->
    </div>
</section>
```

## Field Type Reference

### Supported Field Types

| Type | Syntax | Example |
|------|--------|---------|
| text | `field_name => Label:text:default` | `title => 鏍囬:text:Hello World` |
| textarea | `field_name => Label:textarea:default|hint` | `content => 鍐呭:textarea:榛樿鍐呭|鏀寔HTML` |
| number | `field_name => Label:number:default|unit` | `width => 瀹藉害:number:100|px` |
| color | `field_name => Label:color:#ffffff` | `bg_color => 鑳屾櫙鑹?color:#ffffff` |
| select | `field_name => Label:select:default|opt1,opt2,opt3` | `align => 瀵归綈:select:center|left,center,right` |
| image | `field_name => Label:image:` | `bg_image => 鑳屾櫙鍥?image:` |

### Field Groups

```php
* group:content => 鍐呭璁剧疆
* content.title => 鏍囬:text:榛樿鏍囬
* content.subtitle => 鍓爣棰?text:榛樿鍓爣棰?* 
* group:style => 鏍峰紡璁剧疆
* style.background => 鑳屾櫙鑹?color:#ffffff
* style.padding => 鍐呰竟璺?number:80|px
```

## Common Component Types

### 1. Hero Section
- Full-width banner with title, description, CTA buttons
- Often includes background image/gradient
- Badge/label element

### 2. Features Grid
- Grid of feature cards (3-4 columns)
- Icon, title, description per card
- Hover effects

### 3. Content Cards
- Testimonials, team members, services
- Image, text, metadata
- Various layouts (grid, carousel)

### 4. FAQ Accordion
- Question/answer pairs
- Expand/collapse functionality
- Search/filter option

### 5. CTA Section
- Call-to-action with buttons
- Background styling
- Form integration

### 6. Gallery
- Image grid or masonry
- Lightbox support
- Captions

### 7. Stats/Numbers
- Animated counters
- Icons or illustrations
- Description text

### 8. Timeline
- Chronological events
- Vertical or horizontal
- Milestone markers

## Step-by-Step Creation

### Step 1: Plan Component

1. Define purpose and use cases
2. Identify configurable elements
3. Plan responsive behavior
4. Review existing theme colors

### Step 2: Create Component File

```php
<?php
/**
 * {Theme} - {Component Name}
 * 
 * @fields_start
 * 
 * group:content => 鍐呭璁剧疆
 * content.title => 鏍囬:text:榛樿鏍囬
 * 
 * @fields_end
 */

// ... component code
?>
```

### Step 3: Use Theme Colors

```php
// Always use theme colors
$bgColor = $colors['section_bg_1'] ?? '#f8f9fa';
$textPrimary = $colors['text_primary'] ?? '#ffffff';
$accentPrimary = $colors['accent_primary'] ?? '#7c3aed';
```

### Step 4: Implement CSS Scoping

```php
$instanceId = 'my-component-' . uniqid();
?>
<section id="<?= $instanceId ?>">
    <style>
        #<?= $instanceId ?> { /* styles */ }
        #<?= $instanceId ?> .child { /* styles */ }
    </style>
</section>
```

### Step 5: Add Responsive Styles

```css
@media (max-width: 992px) {
    #<?= $instanceId ?> .grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 576px) {
    #<?= $instanceId ?> .grid {
        grid-template-columns: 1fr;
    }
}
```

### Step 6: Register in component.json

```json
{
    "components": {
        "my-component": {
            "code": "my-component",
            "name": "缁勪欢鍚嶇О",
            "description": "缁勪欢鎻忚堪",
            "icon": "bi-grid",
            "category": "content",
            "file": "content/my-component.phtml",
            "default_config": {
                "content.title": "榛樿鏍囬"
            }
        }
    }
}
```

### Step 7: Add to Layout

Update `layouts/default/home_page.json` or create new layout:

```json
{
    "layout_config": {
        "content": [
            {
                "code": "my-component",
                "enabled": true,
                "instance_id": "my-component-default",
                "config": {}
            }
        ]
    }
}
```

## Best Practices

### DO:
- Use unique instance IDs for CSS scoping
- Always use theme color variables
- Provide sensible default values
- Document all configurable options
- Test responsive behavior
- Use semantic HTML
- Include accessibility attributes

### DON'T:
- Use `declare(strict_types=1);` in .phtml files
- Hardcode colors
- Use global CSS selectors
- Forget responsive styles
- Skip fallback values

## Common Color Variables

```php
// Backgrounds
$colors['body_bg']
$colors['section_bg_1']
$colors['section_bg_2']
$colors['card_bg']
$colors['footer_bg']

// Text
$colors['text_primary']
$colors['text_secondary']
$colors['text_muted']

// Accents
$colors['accent_primary']
$colors['accent_primary_light']
$colors['accent_secondary']
$colors['accent_gradient']

// Buttons
$colors['button_primary_bg']
$colors['button_primary_text']
$colors['button_secondary_border']

// Borders
$colors['border_light']
$colors['border_default']
```

## Validation Checklist

- [ ] Component uses theme colors
- [ ] CSS is properly scoped
- [ ] Responsive styles included
- [ ] All fields documented
- [ ] Default values provided
- [ ] No declare(strict_types=1)
- [ ] Registered in component.json
- [ ] Added to relevant layouts
- [ ] Tested in visual editor

## Example Components

Reference the `sattaking` theme components:
- `hero.phtml` - Hero section
- `features.phtml` - Feature grid
- `faq.phtml` - FAQ accordion
- `games.phtml` - Card grid
- `benefits.phtml` - Benefits list
