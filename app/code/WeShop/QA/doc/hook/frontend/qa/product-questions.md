# WeShop_QA::frontend::layouts::product-questions::content

- Area: `frontend`
- Purpose: render product-level questions and answers inside product detail tabs.
- Host template: `app/design/WeShop/default/frontend/pages/product/view.phtml` and WeShop product tab hook layouts.
- Default implementation: `app/code/WeShop/QA/view/hooks/WeShop_QA/frontend/layouts/product-questions/content.phtml`

## Data Contract

The host page may provide:

- `product`: current product array
- `qa` or `qa_list`: list of question rows

Implementations should gracefully handle missing question data.
