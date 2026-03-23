# WeShop_Subscription::frontend::layouts::subscription::item-after

- Area: `frontend`
- Purpose: append contextual actions or custom metadata after each subscription item card.
- Host template: `app/design/WeShop/default/frontend/pages/subscription/index.phtml`

## Data Contract

Each hook invocation can provide:

- `subscription`: normalized subscription row for the current card

Implementations should avoid assuming optional fields are always present.
