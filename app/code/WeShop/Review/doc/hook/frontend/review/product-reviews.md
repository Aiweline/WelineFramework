# WeShop_Review::frontend::layouts::product-reviews::content

- Area: `frontend`
- Purpose: render review content inside the product detail tab body.
- Host template: `app/design/WeShop/default/frontend/pages/product/view.phtml` and `WeShop_Product` tab layout hooks.
- Default implementation: `app/code/WeShop/Review/view/hooks/WeShop_Review/frontend/layouts/product-reviews/content.phtml`

## Data Contract

The host page may provide:

- `product`: current product array
- `reviews`: current product review list array

Implementations should tolerate empty data and render fallback UI.
