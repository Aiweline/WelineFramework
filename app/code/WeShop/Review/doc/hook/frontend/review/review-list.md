# WeShop_Review::frontend::partials::review-item::after - Review item hook

## Intent
Wrap each rendered product review with additional badges, action buttons, or contextual content such as media or moderation notes.

## Hook information
- Hook name: `WeShop_Review::frontend::partials::review-item::after`
- Module: `WeShop_Review`
- Area: `frontend`
- Section: `review`

## Trigger
Executed inside the review loop in `app/design/WeShop/default/frontend/pages/review/index.phtml`, right after each review article.

## Usage
Create a template at `view/hooks/WeShop_Review/frontend/partials/review-item/after.phtml`. The hook runs beside each review card and should be tolerant of missing optional review fields.

## Related files
- `app/code/WeShop/Review/hook.php`
- `app/design/WeShop/default/frontend/pages/review/index.phtml`
