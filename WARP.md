# WARP.md

This file provides guidance to WARP (warp.dev) when working with code in this repository.

Project overview
- Stack: PHP 8.1+, Composer 2, Nginx/Apache, MySQL/MariaDB or SQLite; optional Redis. Docker files are provided.
- Entry points:
  - Web: app/bootstrap.php initializes the framework and runs Weline\Framework\App::run().
  - CLI: bin/w (Windows wrapper: bin\w.bat) boots the app and executes Weline\Framework\Console\Cli.
- Structure (big picture):
  - app/code/Weline/*: Modular packages (e.g., Framework, ModuleManager, Router, Admin, Frontend, Theme, Database, Ai, etc.). Each module may have its own composer.json, docs, views, and tests.
  - generated/{code,language,routers}: Framework-generated artifacts used at runtime and by the CLI (e.g., DI compilation, routes, i18n extraction).
  - app/etc: Environment and configuration (env.sample.php, database, etc.).
  - bin/: Framework command-line tools (w, m). Prefer w for day-to-day tasks.
  - Dockerfile and docker-compose.yml: Optional containerized dev/runtime with nginx, php-fpm, supervisor; optional Redis/MySQL services.
- Autoloading: composer.json uses classmap for app/code and generated, plus PSR-4 namespaces for Weline\\ and Weline\\Framework\\.

Important repository guidance (from existing rules/docs)
- Cursor rules (.cursor/rules):
  - must-read-promot.mdc and read-ai-promot.mdc: Always read the “AI 提示词.md” prompt document before code generation. If that file is missing, ask for it or locate it in project docs.
  - context7.mdc: When code generation, setup, or library/API docs are needed, prefer using Context7 MCP tools to resolve library IDs and fetch docs automatically.
- README highlights:
  - Initial setup, built-in server, deployment modes, caching, modules, and an enterprise-grade database migration system (Weline_Database). The CLI exposes rich subcommands for these workflows (see below for the most used commands).

Setup and environment
- Dependencies
  - Install Composer dependencies:
    ```bash path=null start=null
    composer install
    ```
- Configure environment
  - Option A (interactive/full install): Use the installer to generate app/etc/env.php and DB settings.
    ```bash path=null start=null
    php bin/w system:install \
      --db-type=mysql --db-hostname=127.0.0.1 --db-database=weline \
      --db-username=weline --db-password=weline \
      --db-charset=utf8mb4 --db-collate=utf8mb4_general_ci
    ```
  - Option B (manual): Use app/etc/env.sample.php as a reference and create app/etc/env.php accordingly.
- Start (local, non-Docker)
  - Upgrade commands and framework setup, then start the built-in server:
    ```bash path=null start=null
    php bin/w command:upgrade
    php bin/w setup:upgrade
    php bin/w server:start
    ```
- Start (Docker)
  - Build and run the provided stack (php-fpm + nginx + supervisor; optional Redis/MySQL):
    ```bash path=null start=null
    docker compose up -d --build
    ```

Common commands
- List commands and get help
  ```bash path=null start=null
  php bin/w
  ```

- Deployment mode
  ```bash path=null start=null
  php bin/w deploy:mode:show
  php bin/w deploy:mode:set dev
  php bin/w deploy:mode:set prod
  ```

- Cache and templates
  ```bash path=null start=null
  php bin/w cache:status
  php bin/w cache:clear
  php bin/w cache:flush
  php bin/w template:clear
  ```

- Static assets and content deploy
  ```bash path=null start=null
  php bin/w resource:compiler
  php bin/w deploy:upgrade
  ```

- Modules (enable/disable/list/upgrade/remove)
  ```bash path=null start=null
  php bin/w module:listing
  php bin/w module:status
  php bin/w module:enable  Weline_Framework
  php bin/w module:disable Weline_Framework
  php bin/w module:upgrade
  php bin/w module:remove  <Module_Name>
  ```

- Database migrations (Weline_Database)
  ```bash path=null start=null
  # Check status for a module
  php bin/w db:migrate:status --module=Weline_Ai

  # Upgrade a specific migration file
  php bin/w db:migrate:upgrade --module=Weline_Ai --file=create_table__users_20250101-v1.0.0.php

  # Rollback a specific migration file
  php bin/w db:migrate:rollback --module=Weline_Ai --file=create_table__users_20250101-v1.0.0.php
  ```

- Linting/formatting
  - PHP-CS-Fixer (integrated via CLI tool)
    ```bash path=null start=null
    php bin/w dev:tool:phpcsfixer:enable
    php bin/w dev:tool:phpcsfixer        # run on current project
    php bin/w dev:tool:phpcsfixer:disable
    ```
  - PHPStan (installed via Composer)
    ```bash path=null start=null
    vendor/bin/phpstan analyse app/code
    ```

- Tests (PHPUnit)
  - Run via framework CLI integration
    ```bash path=null start=null
    php bin/w phpunit:run
    ```
  - Run directly with PHPUnit (module-level phpunit.xml)
    ```bash path=null start=null
    # Example: AI module
    vendor/bin/phpunit -c app/code/Weline/Ai/tests/phpunit.xml

    # Example: DataTable module
    vendor/bin/phpunit -c app/code/Weline/DataTable/Test/phpunit.xml
    ```
  - Run a single test
    ```bash path=null start=null
    # By class::method
    vendor/bin/phpunit -c app/code/Weline/Ai/tests/phpunit.xml --filter "MyTestClass::testSomething"

    # By file path
    vendor/bin/phpunit -c app/code/Weline/Ai/tests/phpunit.xml app/code/Weline/Ai/tests/unit/MyTestClass.php
    ```
  - Notes
    - app/bootstrap_phpunit.php sets SANDBOX/DEV/DEBUG for test runs and is referenced from module phpunit.xml where appropriate.

Windows notes
- Use the wrapper for convenience in PowerShell:
  ```powershell path=null start=null
  .\bin\w.bat setup:upgrade
  .\bin\w.bat server:start
  ```
  All other examples using php bin/w can be swapped to .\bin\w.bat on Windows.

Key files and paths
- composer.json: Defines autoloading and dependencies (phpunit/phpunit, phpstan/phpstan, friendsofphp/php-cs-fixer, etc.).
- app/bootstrap.php: Web/bootstrap entry, Composer autoload loading, framework runner.
- app/bootstrap_phpunit.php: Test bootstrap that enables SANDBOX/DEV/DEBUG and requires bootstrap.php.
- bin/w and bin/w.bat: CLI entrypoints.
- generated/: Framework-generated artifacts (code, routers, language data).
- Dockerfile, docker-compose.yml: Containerized runtime/dev stack.

Tips specific to this codebase (non-generic)
- Many tasks (cache, module lifecycle, asset compilation, deploy mode, PHPUnit integration) are exposed via php bin/w; prefer those over ad-hoc scripts for consistency with framework expectations.
- For tests, run them at the module level against the module’s phpunit.xml to ensure correct bootstrap and coverage destinations (for example, app/code/Weline/Ai/tests/phpunit.xml writes coverage to dev/phpunit/report/coverage/Weline_Ai).
- If you need to regenerate framework internals, use:
  ```bash path=null start=null
  php bin/w setup:di:compile   # compile DI dependencies
  php bin/w event:cache:clear  # rebuild event cache
  php bin/w plugin:di:compile  # compile plugin DI
  ```

Where to look first when changing behavior
- Routing/controllers: Within the module’s Controller namespace under app/code/Weline/<Module>/.
- Models/ORM: app/code/Weline/<Module>/Model under Weline\Framework\Database base classes.
- Views/themes: app/design and module view folders; themes can override module templates.
- CLI tasks: Implemented in modules and wired into the framework console; list via php bin/w.
