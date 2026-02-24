---
name: website-to-template
description: Converts external websites into PageBuilder templates/themes for Weline Framework. Use when user wants to clone/imitate a website design, create themes from HTML templates, or convert external websites into components.
globs:
alwaysApply: false
---

# Website to Template Conversion Skill

## Overview
This skill guides the conversion of an existing website into a PageBuilder template/theme for the Weline Framework. It includes downloading HTML, analyzing structure, splitting into components, and creating proper configuration mappings.

## When to Use
- User wants to imitate/clone an existing website design
- User wants to create a new theme based on an external website
- User wants to convert HTML templates into PageBuilder components

## Prerequisites
- Target website URL
- Understanding of the PageBuilder template structure
- Access to the `app/code/GuoLaiRen/PageBuilder/view/templates/style/` directory

## Template Structure

```
app/code/GuoLaiRen/PageBuilder/view/templates/style/{theme_name}/
鈹溾攢鈹€ asset/
鈹?  鈹溾攢鈹€ img/              # Images and icons
鈹?  鈹溾攢鈹€ css/              # Optional custom CSS
鈹?  鈹斺攢鈹€ js/               # Optional custom JS
鈹溾攢鈹€ colors/
鈹?  鈹斺攢鈹€ default.phtml     # Color scheme configuration
鈹溾攢鈹€ components/
鈹?  鈹溾攢鈹€ header/           # Header components
鈹?  鈹?  鈹斺攢鈹€ nav.phtml
鈹?  鈹溾攢鈹€ content/          # Content components
鈹?  鈹?  鈹溾攢鈹€ hero.phtml
鈹?  鈹?  鈹溾攢鈹€ features.phtml
鈹?  鈹?  鈹斺攢鈹€ ...
鈹?  鈹溾攢鈹€ footer/           # Footer components
鈹?  鈹?  鈹斺攢鈹€ links.phtml
鈹?  鈹斺攢鈹€ component.json    # Component registry
鈹溾攢鈹€ layouts/
鈹?  鈹斺攢鈹€ default/          # Default layout configurations
鈹?      鈹溾攢鈹€ home_page.json
鈹?      鈹溾攢鈹€ custom_page.json
鈹?      鈹斺攢鈹€ ...
鈹斺攢鈹€ layout.phtml          # Main layout template
```

## Step-by-Step Process

### Step 1: Analyze Target Website

1. **Download the HTML**
   - Use `WebFetch` tool to download the target website's HTML
   - Save for reference and analysis

2. **Identify Color Scheme**
   - Extract primary colors (backgrounds, text, accents)
   - Note gradient patterns
   - Document hover states and transitions

3. **Identify Page Sections**
   - Header/Navigation
   - Hero/Banner section
   - Content sections (features, services, testimonials, etc.)
   - Footer

4. **Note Responsive Breakpoints**
   - Mobile (< 576px)
   - Tablet (< 992px)
   - Desktop

### Step 2: Create Theme Directory Structure

Create directories for:
- asset/img
- colors
- components/header, content, footer
- layouts/default

### Step 3: Create Color Configuration

Create `colors/default.phtml` with color variables including:
- Background colors (body, hero, nav, sections, cards, footer)
- Text colors (primary, secondary, muted)
- Accent colors (primary, secondary, gradients)
- Button colors
- Border and divider colors
- Shadow definitions
- Base CSS variables

### Step 4: Create Main Layout Template

Create `layout.phtml` that:
- Loads page and style data
- Defines component file mappings
- Implements render function for components
- Loads color configuration
- Outputs HTML structure with header, content, footer regions
- Includes base CSS styles using theme colors

### Step 5: Create Component Templates

Each component should:
- Document configurable fields with @fields_start/@fields_end
- Use  array for all colors
- Use unique instance IDs for CSS scoping
- Provide sensible defaults
- Be responsive

**Important:** Do NOT include `declare(strict_types=1);` in .phtml files

### Step 6: Create Component Registry

Create `components/component.json` with:
- Template metadata (name, description, version, author)
- Region definitions (header, content, footer)
- Component definitions with code, name, description, icon, category, file, default_config

### Step 7: Create Default Layout Configurations

Create JSON files in `layouts/default/` for each page type:
- home_page.json
- custom_page.json
- etc.

Each should define header, content, footer arrays with component configurations.

## Best Practices

1. **Color Management**: Always use  array, provide fallbacks
2. **CSS Scoping**: Use unique instance IDs
3. **Responsive Design**: Mobile-first, test at all breakpoints
4. **Configuration Fields**: Document all options, provide defaults
5. **Performance**: Minimize inline CSS, optimize images

## Validation Checklist

- [ ] Color configuration complete
- [ ] Main layout template renders correctly
- [ ] All components use theme colors
- [ ] Component registry is valid JSON
- [ ] Layout configurations are valid JSON
- [ ] Responsive design works
- [ ] No declare(strict_types=1) in .phtml files
- [ ] All options documented
- [ ] Navigation works
- [ ] Assets load properly

## Example Reference

See the `sattaking` theme:
`app/code/GuoLaiRen/PageBuilder/view/templates/style/sattaking/`
