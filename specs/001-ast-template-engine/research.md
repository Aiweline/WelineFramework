# Research Notes: AST Taglib Template Compiler

## Decision 1: Compile-time vs runtime tag handling
- Decision: Compile-time evaluation for static `lang` parameters and static link tags; dynamic parameters stay runtime.
- Rationale: Preserves correctness for dynamic data while reducing runtime overhead for static content.
- Alternatives considered: Always runtime for safety; always compile-time for speed.

## Decision 2: Execution context for compile-time tags
- Decision: Compile-time execution may access the Taglib context object and template context variables.
- Rationale: Matches current usage expectations and enables tag callbacks to use context data.
- Alternatives considered: pure-function-only whitelist; full PHP access.

## Decision 3: Caching behavior
- Decision: No changes to existing template cache strategy; compilation output relies on current cache flow.
- Rationale: Scope is AST compilation and tag execution logic, not cache policy changes.
- Alternatives considered: content-hash cache invalidation; dependency-based invalidation.

## Decision 4: Backward compatibility posture
- Decision: Breaking changes are acceptable; new AST-defined behavior is authoritative, but performance must be considered.
- Rationale: Allows correct AST semantics without being blocked by legacy quirks.
- Alternatives considered: strict compatibility; limited compatibility with exceptions.
