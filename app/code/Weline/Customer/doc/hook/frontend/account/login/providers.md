# Storefront Customer Login Providers

Use `Weline_Customer::frontend::account::login::providers` to inject **non-OAuth identity bridges** (for example Multipass official login) into the storefront customer login page without coupling `Weline_Customer` to optional provider modules.

**Do not** register Google / Facebook / Instagram / custom OAuth social login via this Hook. Those use the `SocialLoginProvider` extends point (`doc/social-login-provider.md`) and Theme slot `account-login-social-providers` + widget `account-social-login`.

Implementation path examples:

- Extends OAuth providers: `extends.php` → `extends/module/Weline_Customer/SocialLoginProvider/`
- Slot + widget: `view/templates/frontend/account/login.phtml`（**仅声明槽**，禁止旁路 `fetch` 同部件）、`extends/module/Weline_Widget/Weline_Customer/widget.php`、`view/templates/frontend/widgets/account-social-login.phtml`
- Hook identity bridge: `view/hooks/Weline_Customer/frontend/account/login/providers.phtml`
