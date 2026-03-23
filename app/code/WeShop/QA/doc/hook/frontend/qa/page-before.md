# WeShop_QA::frontend::layouts::qa-page::before

- Area: `frontend`
- Purpose: inject banners, filters, or helper content before the storefront Q&A listing.
- Host template: `app/design/WeShop/default/frontend/pages/qa/index.phtml`

## Data Contract

The host page may provide:

- `product`: current product array
- `product_id`: current product id
- `qa_list`: approved question rows
- `question_count`: total approved question count

Implementations should stay tolerant of missing product or question data.
