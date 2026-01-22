# Tasks: AST Taglib Template Compiler

1. [X] Review `Taglib.php` current parsing flow and identify regex-driven parsing hotspots.
2. [X] Add tokenizer component inside Taglib (or supporting classes) with state machine for TEXT/TAG/ATTR/ATTR_VALUE/STRING/PHP.
3. [X] Add token data structure carrying type/value/line/column.
4. [X] Build parser to construct AST nodes (Program/Fragment/Text/Php/Tag/Attr).
5. [X] Add semantic analyzer to bind tags to tag definitions and execution stages.
6. [X] Enforce rule: static `lang` arguments compile-time, dynamic arguments runtime.
7. [X] Ensure compile-time tag execution can access Taglib context and template variables.
8. [X] Add compile-time executor to replace nodes with static output.
9. [X] Add optional AST optimizations (merge adjacent TextNode, remove empty FragmentNode).
10. [X] Generate PHP output from AST nodes (Text echo, Php passthrough, runtime tags).
11. [X] Keep existing template cache strategy; ensure no cache policy changes.
12. [X] Update Taglib tests to cover tokenizer and parser basics.
13. [X] Add tests for compile-time vs runtime behavior on `lang` tag.
14. [X] Add tests for PHP-in-attribute parsing and callback context access.
15. [X] Run `php bin/w phpunit:run -b --path=app/code/Weline/Framework/View/test` and fix failures.
