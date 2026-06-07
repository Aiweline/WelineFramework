# Theme Editor Result After

## Event Name

`Weline_Theme::theme_editor::result_after`

## Trigger

Triggered by `Weline\Theme\Controller\Backend\ThemeEditor` after it builds a string response for selected visual editor actions.

Current actions:

- `save_layout`
- `publish_layout`
- `save_compiled_layout`
- `layout_preview`

## Payload

The event payload is a `Weline\Framework\DataObject\DataObject`.

```php
[
    'action' => 'publish_layout',
    'result' => $responseBody,
    'controller' => $themeEditor,
    'request' => $request,
]
```

Observers may replace `result` with a modified response string. Observers should return early for unknown actions.

## Usage

Register observers in a module `etc/event.xml`:

```xml
<event name="Weline_Theme::theme_editor::result_after">
    <observer name="Vendor_Module::theme_editor_result_after"
              instance="Vendor\Module\Observer\ThemeEditorResultAfter"
              disabled="false"
              shared="true"
              sort="100"/>
</event>
```
