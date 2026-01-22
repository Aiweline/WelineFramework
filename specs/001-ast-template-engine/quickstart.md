# Quickstart: AST Taglib Template Compiler

## Goal
Validate that AST compilation produces equivalent output for tags, attributes, and PHP blocks, while honoring compile-time vs runtime behavior.

## Pre-conditions
- Template files available under `view/templates/`
- Taglib changes implemented

## Validation Steps
1. Run unit tests:
   - `php bin/w phpunit:run -b --path=app/code/Weline/Framework/View/test`
2. Manually compile a template with:
   - static `lang` attribute (compile-time)
   - dynamic `lang` attribute (runtime)
   - PHP block inside template
3. Verify output matches expectations and no PHP errors occur.

## Expected Results
- Static `lang` renders at compile time.
- Dynamic `lang` remains runtime.
- Tags with static link generation emit static output at compile time.
