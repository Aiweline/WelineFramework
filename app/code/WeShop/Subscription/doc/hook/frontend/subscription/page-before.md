# WeShop_Subscription::frontend::layouts::subscription::page-before

- Area: `frontend`
- Purpose: inject banners, notices, or custom filters above the subscription listing page.
- Host template: `app/design/WeShop/default/frontend/pages/subscription/index.phtml`

## Data Contract

The page may provide:

- `items`: normalized customer subscription rows
- `status_options`: status filter options
- `current_status`: currently selected status
- `total`: total subscription count under current filter

Implementations should tolerate empty arrays.
