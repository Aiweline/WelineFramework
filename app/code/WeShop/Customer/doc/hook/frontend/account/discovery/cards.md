# WeShop_Customer::frontend::account::discovery::cards

- Area: `frontend`
- Purpose: inject additional discovery cards into the storefront customer account dashboard.
- Expected host: `app/design/WeShop/default/frontend/pages/customer/index.phtml`
- Typical use cases:
  - wishlist insights
  - compare shortlist follow-up cards
  - membership benefit cards
  - subscription reminders
  - personalized recommendation modules

The built-in account-center shell now renders first-party compare, wishlist, recently viewed, and recommendation previews from `AccountDashboardDataService`. Use this hook for adjacent discovery or retention slices instead of replacing those core sections.
