# Frontend Head Favicon Hook

Hook: `Weline_Theme::frontend::partials::head::favicon`

Allows a frontend module to replace the default favicon links rendered in the document head.

Implementations should output complete favicon `<link>` tags, for example:

```html
<link rel="icon" type="image/svg+xml" href="/path/to/favicon.svg">
<link rel="Shortcut Icon" href="/path/to/favicon.svg">
<link rel="Bookmark" href="/path/to/favicon.svg">
```

If no implementation is registered, the Weline Theme default favicon is rendered by the theme head partial.
