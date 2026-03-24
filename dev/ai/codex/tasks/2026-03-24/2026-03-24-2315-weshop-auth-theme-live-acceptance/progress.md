# Progress - weshop-auth-theme-live-acceptance

- 2026-03-24 23:15 Created the task workspace after commit `c88ccd87` to drive the next WeShop acceptance-focused continuation wave.
- 2026-03-24 23:15 Re-aligned the execution strategy around live port `9982` verification plus parallel auth/theme audits because the repo already contains substantial `WeShop_Auth` and `WeShop_GoogleAuth` foundations.
- 2026-03-24 23:22 Live runtime verification on `127.0.0.1:9982` showed this environment exposes the frontend REST prefix as `api123`, not the earlier assumed `api`. The unified WeShop auth endpoints now reach controller validation and return `422` instead of framework `401`/`404`.
- 2026-03-24 23:24 Live storefront probes confirmed the clean customer-account routes work for `wishlist`, `recently-viewed`, and `compare`, with guest requests redirecting to login and AJAX compare mutations returning a JSON redirect payload instead of hard failures.
- 2026-03-24 23:28 Acceptance audit found the frontend login page still does not render the Google provider button live even though the default theme login template and generated hook registry both contain the expected `WeShop_Social::frontend::partials::login::buttons` injection point. The live HTML appears to be using a `Weline_Customer` login template path, so runtime layout/template precedence still needs investigation.
- 2026-03-24 23:34 Implemented the first post-verification fix slice:
  - added clean alias controllers for `WeShop_Compare` (`/compare`, `/compare/add`, `/compare/remove`)
  - updated `WeShop_Compare` and `WeShop_RMA` hook contracts so module `hook.php` files no longer declare cross-module host names and instead rely on `view/hooks/...` injection templates
  - added/updated unit tests covering the alias controllers, compare locale-safe availability assertions, and the compare/RMA hook contract guards
