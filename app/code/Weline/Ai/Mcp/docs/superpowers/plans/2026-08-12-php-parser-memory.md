# PHP Parser Memory and Isolation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Parse the known 1–2 MiB Weline PHP files within a 128 MiB PHP limit while ensuring a single parser resource failure cannot terminate the stdio MCP server or strand the project index in `indexing`.

**Architecture:** Replace the eagerly duplicated five-field associative token table with a `PhpTokenBuffer` that retains `token_get_all()` output once and stores only start offsets and line numbers in fixed-size vectors. Keep parser semantics stable, but replace per-token full-symbol filtering/sorting with a forward-only interval cursor. Parse files at or above 64 KiB in a bounded child process; smaller files stay inline only while the parent has a conservative 64 MiB memory reserve. Before materializing child JSON, enforce output-size, structural-complexity, depth, and live-memory bounds, then validate every symbol/relation field. Flush isolated parser results one file per database batch. Any parser or protocol failure becomes a per-file indexing error while the previous indexed revision and MCP transport survive.

**Tech Stack:** PHP 8.2+, `token_get_all`, `SplFixedArray`, existing `ProcessRunner`, SQLite project index, dependency-free PHP test runner.

## Global Constraints

- Do not modify either WelineFramework business file used as a real-world benchmark.
- Do not raise the MCP parent process memory limit as the fix.
- Preserve current symbol UIDs, ranges, body hashes, and lexical relation semantics.
- Preserve all unrelated dirty changes in `/Users/weline/Project/Official/Weline-Codex-Mcp`.
- Do not commit or push without a separate explicit user request.
- Run large-memory scenarios in child PHP processes so a RED OOM cannot terminate the main test runner.

---

### Task 1: Reproduce the 128 MiB parser failure

**Files:**
- Create: `tests/php-parser-memory.php`
- Modify: `tests/run.php`

**Interfaces:**
- Consumes: `LearningMcp\PhpSymbolParser::parse(string $content, string $relativePath): array`.
- Produces: a standalone process returning JSON with `symbols`, `relations`, and `peak_bytes`, plus a quick-suite assertion that runs it with `php -d memory_limit=128M`.

- [x] **Step 1: Write the failing test**

  Generate a deterministic token-dense PHP fixture containing one class, one large method, and a known helper call. Parse it with the real parser and assert that the class/method/relation are present and `memory_get_peak_usage(true) < 100_663_296`.

- [x] **Step 2: Run the test to verify RED**

  Run: `php -d memory_limit=128M tests/php-parser-memory.php`

  Expected: non-zero exit with `Allowed memory size ... exhausted` in `PhpSymbolParser::tokens()` because the current parser duplicates every token into a five-field associative array.

- [x] **Step 3: Add the child invocation to the quick suite**

  Use the existing real `ProcessRunner` and assert exit code zero plus a literal peak-memory ceiling. Do not mock the parser or process runner.

### Task 2: Replace eager token duplication and quadratic symbol lookup

**Files:**
- Create: `src/PhpTokenBuffer.php`
- Modify: `src/bootstrap.php`
- Modify: `src/PhpSymbolParser.php`

**Interfaces:**
- Produces: `LearningMcp\PhpTokenBuffer`, implementing `ArrayAccess<int,array{id:int|null,text:string,line:int,start_byte:int,end_byte:int}>` and `Countable`.
- Preserves: `PhpSymbolParser::parse(string $content, string $relativePath): array`.

- [x] **Step 1: Implement the minimal compact token buffer**

  Store raw tokenizer output exactly once. Store per-token `start_byte` and `line` in two `SplFixedArray` instances. Materialize the existing five-field token shape only when a parser access requests one token; do not retain materialized token records.

- [x] **Step 2: Switch parser helpers to the buffer**

  Change only internal parameter/return types required by the new buffer. Keep all public parser output unchanged.

- [x] **Step 3: Replace the second-pass `containingSymbol()` scan**

  Sort symbol intervals once by start byte (outer before inner on ties), advance one cursor as token offsets increase, maintain a nesting stack, and return the innermost active symbol in amortized O(1). Hoist object-operator token IDs and constant token-ID sets outside hot loops.

- [x] **Step 4: Run GREEN and semantic regression checks**

  Run: `php -d memory_limit=128M tests/php-parser-memory.php`

  Expected: zero exit, required symbols/relations present, peak below 96 MiB.

  Run: `php tests/run.php`

  Expected: all quick checks pass with no warning or fatal error.

### Task 3: Isolate resource-dense parser failures

**Files:**
- Create: `bin/php-symbol-parser-worker`
- Create: `src/PhpParserResultDecoder.php`
- Create: `tests/php-parser-isolation.php`
- Create: `tests/php-parser-high-water.php`
- Create: `tests/php-parser-payload.php`
- Modify: `src/ProcessRunner.php`
- Modify: `src/ProjectIndexer.php`
- Modify: `tests/run.php`

**Interfaces:**
- Worker input: relative path in argv and raw PHP content on stdin.
- Worker output: one JSON object shaped as `array{symbols:list<array<string,mixed>>,relations:list<array<string,mixed>>}`.
- Parent behavior: files of at least 65,536 bytes use `ProcessRunner`; smaller files only parse inline when the current PHP memory limit leaves at least 64 MiB above live parent usage. `ProcessRunner` exposes output-truncation flags, and the indexer rejects truncated or pathologically amplified output before decoding. JSON admission requires at least 64 MiB free and, for larger payloads, 32 times the captured size. Isolated PHP files flush in their own database batch, while inline PHP batches carry at most 64 KiB total source. A non-zero exit, timeout, overlarge/malformed JSON, low decode headroom, or invalid payload throws a bounded `RuntimeException` caught by the existing per-file index loop.

- [x] **Step 1: Write the failing isolation test**

  In a standalone 128 MiB process, index a valid small revision of `src/Large.php`, replace it with a token-dense resource bomb below the configured file-size ceiling, and reindex using a real `ProcessRunner`. Assert the parent survives, the result is `freshness=partial`, `state.phase=idle`, the error names `src/Large.php`, and the previously indexed content hash remains unchanged.

- [x] **Step 2: Run the isolation test to verify RED**

  Run: `php -d memory_limit=128M tests/php-parser-isolation.php`

  Expected: the current inline parser terminates the test process with OOM rather than returning a partial result.

- [x] **Step 3: Implement the worker and parent validation**

  Invoke the worker with argv-only `proc_open` through `ProcessRunner`, a fixed 128 MiB child limit, a 60-second timeout, and raw stdin. Do not expose absolute project paths to worker output. Bound stderr included in parent errors and validate decoded keys as lists before accepting a parse result.

- [x] **Step 4: Run the isolation test to verify GREEN**

  Run: `php -d memory_limit=128M tests/php-parser-isolation.php`

  Expected: zero exit with the previous index revision retained and an idle/partial state.

### Task 4: Verify real files and the complete repository

**Files:**
- Review only: every file changed by Tasks 1–3.

**Interfaces:**
- Real inputs:
  - `/Users/weline/Project/Official/框架/app/code/Weline/Server/bin/wls_gateway_controller.php`
  - `/Users/weline/Project/Official/框架/app/code/Weline/Server/Service/ServiceOrchestrator.php`

- [x] **Step 1: Run syntax checks**

  Run `php -l` for each changed PHP file and worker script.

- [x] **Step 2: Run quick and full suites**

  Run: `php tests/run.php`

  Run: `php tests/run.php --full`

- [x] **Step 3: Run real 128 MiB benchmarks**

  Parse each real input in its own `php -d memory_limit=128M` process. Record exit code, symbol count, relation count, peak bytes, and elapsed time. Require gateway counts `730 / 5486` and orchestrator counts `625 / 8130` unless the source changed during this task; if changed, stop and report the mismatch rather than weakening assertions.

- [x] **Step 4: Review the complete diff**

  Check architecture, parser-range correctness, subprocess trust boundaries, timeout/OOM handling, previous-index retention, permissions, unrelated dirty-file preservation, and test mutation coverage. Fix any in-scope finding, then rerun all affected checks.

## Self-Review

- Spec coverage: memory duplication, CPU complexity, child-process fault isolation, index-state recovery, semantic compatibility, and real-file verification each have an owning task.
- Placeholder scan: no deferred implementation or acceptance placeholders remain.
- Type consistency: the token buffer preserves the existing materialized token shape; worker and parent both use the existing parser result schema.
- Scope: WLS business files are read-only benchmark inputs; no installation config or unrelated dirty file is changed.
