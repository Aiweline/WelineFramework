# Backend Account Security Cards

Use `WeShop_Auth::backend::account::security::cards` to inject account-security management cards into the backend account security center.

Typical examples:

- two-factor authentication settings
- third-party sign-in binding
- device or session management

Hook implementations should render one Bootstrap grid column, usually `col-xl-4 col-lg-6`, containing one card-sized block.
