# WeShop_Review::frontend::layouts::review-page::before - Review page header hook

## Intent
Inject banners, filters, or rich content directly above the product review list on the storefront review page.

## Hook information
- Hook name: `WeShop_Review::frontend::layouts::review-page::before`
- Module: `WeShop_Review`
- Area: `frontend`
- Section: `review`

## Trigger
Fired inside `app/design/WeShop/default/frontend/pages/review/index.phtml` before the review listing container.

## Usage
Place your template at `view/hooks/WeShop_Review/frontend/layouts/review-page/before.phtml` and expose contextual data (e.g., `product`, `total`, `average_rating`, `page`, `page_size`) via `$this->getData()`.

## Related files
- `app/code/WeShop/Review/hook.php`
- `app/design/WeShop/default/frontend/pages/review/index.phtml`
