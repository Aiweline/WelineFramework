# WeShop_QA::frontend::partials::question-item::after

- Area: `frontend`
- Purpose: extend each storefront Q&A item with extra metadata, badges, or follow-up actions.
- Host template: `app/design/WeShop/default/frontend/pages/qa/index.phtml`

## Data Contract

The host page may provide:

- `product`: current product array
- `qa_list`: approved question rows

Implementations should avoid assuming every question has an answer yet.
