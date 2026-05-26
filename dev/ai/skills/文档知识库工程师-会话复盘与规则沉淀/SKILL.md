---
name: 文档知识库工程师-会话复盘与规则沉淀
description: Documentation engineer skill for scanning WelineFramework sessions, extracting user-corrected engineering practices, and turning them into reusable framework rules or self-learning knowledge.
---

# Role

This skill owns post-session knowledge extraction for WelineFramework work. It turns repeated user corrections, failed first attempts, and later-confirmed clean solutions into reusable framework practices instead of leaving them in transient chat history.

# When To Use

- Use after a completed implementation, review, or debugging session when the session exposed a better framework-native way to solve a class of problems.
- Use when the user explicitly asks to scan sessions, extract lessons, summarize corrected practices, build self-learning knowledge, or convert mistakes into reusable skills.
- Use when a change path moved from an initial wrong assumption to a later confirmed framework pattern that should guide future agents.
- Use for keywords such as 会话复盘, 自我学习, 技能沉淀, 纠错沉淀, 框架做法, session scan, and reusable lesson.

# Source Material

- `dev/ai/global-constraints.md`
- `dev/ai/skills/_index.md`
- `dev/ai/skills/文档知识库工程师-技能索引与知识库/SKILL.md`
- Current thread messages, repo-level instruction context such as injected `AGENTS.md` excerpts, automation memory, and any relevant rollout summaries or memory extracts.

# Responsibilities

- Scan two learning streams together:
  - user-explicit mentions, complaints, corrections, and "remember this" instructions,
  - handling-process mistakes that later converged on a confirmed framework-native practice.
- Scan the conversation and isolate statements where the user explicitly raised the engineering bar, corrected a wrong direction, or clarified the real framework boundary.
- Treat user-explicit framework practices and process mistakes that were later corrected into the framework-native way as first-class learning candidates, even when the correction happened inside the handling workflow rather than inside product code.
- Treat explicit user complaints, frustration, or "don't do this again" wording as high-priority correction signals; extract the engineering rule underneath the emotion instead of dismissing it as tone.
- Treat repo instructions surfaced inside the current session as valid correction signals when they explicitly tighten execution standards or reject a previously common shortcut.
- Treat user follow-ups such as "you missed...", "the real point is...", "you should have checked...", or "there is also..." as omission signals; inspect the missing step, missing constraint, missing context, missing hidden goal, missing boundary condition, or missing verification that caused the gap.
- Reopen or initialize the matching `dev/ai/codex/tasks/...` workspace before substantial session-review analysis or skill edits, and keep `progress.md` plus `result.md` current so the learning run itself is resumable.
- Separate one-off project detail from reusable framework practice.
- Classify each lesson by ownership before editing files:
  - cross-role stable rule -> `dev/ai/global-constraints.md`,
  - specialist execution rule -> the owning specialist skill,
  - already-documented rule restated in-session -> update memory and only tighten the owning skill if the session exposed an operational wording gap.
- If the session corrects the handling workflow itself, update the owning workflow/self-learning skill first, and then update any specialist skill whose wording still allowed the mistake.
- Rewrite the lesson as a stable rule that another agent can apply on a future task.
- Preserve the evidence chain: what was assumed first, what later proved correct, and why the corrected rule is reusable.
- Land the result in an AI-facing skill or knowledge file instead of leaving it only in a final report.

# Extraction Standard

Only promote a lesson into self-learning knowledge when all three conditions hold:

1. The session contained a wrong first move, incomplete mental model, or vague default.
2. A later correction was explicit, either from the user or from stronger verification evidence.
3. The corrected form is reusable beyond the one exact file or branch.

Explicit user complaints or direct corrections count as strong evidence for condition 2 even when the session did not contain a long technical back-and-forth, as long as the corrected framework practice is clear by the end.
Workflow mistakes that were later corrected into a clean framework practice also count as promotable evidence when the reusable default action is explicit by the end of the session.

Do not promote:

- Purely local file paths without a reusable pattern.
- Temporary environment accidents with no stable handling rule.
- Raw symptoms when the real boundary or fix standard was never confirmed.

When both a user-explicit correction and a wrong-first-then-fixed handling path appear in the same session, extract both; do not collapse the session down to only one stream.

# Workflow

1. Read the automation memory first, then reopen or create the matching task workspace so duplicate lessons and resume state are visible before new extraction work starts.
2. Read the current session and mark candidate moments:
   - user-explicit engineering requirements or "remember this" instructions,
   - user corrections,
   - user complaints or repeated dissatisfaction tied to a concrete engineering mistake,
   - workflow mistakes that were later corrected into a stable framework or process rule,
   - injected repo rules or automation-context instructions,
   - rejected implementations,
   - failed verifications that changed the conclusion standard,
   - final framework-native patterns that replaced a brittle patch.
3. For each candidate, write four fields in scratch form:
   - initial assumption,
   - correction trigger,
   - final rule,
   - reuse boundary.
4. Run an omission check before promoting the lesson:
   - Was a required step skipped?
   - Was an explicit or implicit constraint skipped?
   - Was prior context or memory skipped?
   - Was the user's real goal or hidden acceptance boundary skipped?
   - Was a boundary condition or verification step skipped?
5. Add an ownership field for each surviving lesson:
   - `global constraint`,
   - `specialist skill`,
   - `session-review/self-learning skill`,
   - `memory only`.
6. Drop anything that is only project-local or still unverified.
7. Normalize each surviving lesson into imperative guidance:
   - `default to ...`
   - `do not assume ...`
   - `when X happens, verify Y before concluding Z`
8. Serialize each surviving lesson in one of two reusable shapes:
   - Skill shape:
     - `Skill Name`
     - `Trigger`
     - `Wrong Pattern`
     - `Correct Pattern`
     - `Generalized Principle`
     - `Confidence`
   - Framework rule shape:
     - `Rule Name`
     - `Problem Pattern`
     - `Detection Signal`
     - `Root Cause`
     - `Correct Framework`
     - `Reusable Strategy`
     - `Upgrade Priority`
9. Group rules by theme such as auth boundary, generated artifacts, runtime verification, hook boundary, cron registration, visible UX requirements, or workflow learning.
10. If multiple examples express the same rule, keep one concise canonical rule and attach the strongest evidence example.
11. Prefer updating an existing skill or framework rule when the new lesson is the same underlying mistake with a tighter trigger, broader boundary, or clearer default action; only add a new rule when the old rule cannot absorb the new correction without becoming misleading.
12. Decide the landing target:
   - update `global-constraints.md` only for repository-wide rules,
   - update one or more specialist skills for role-specific execution guidance,
   - update the session-review/self-learning skill when the lesson is about how to extract, remember, or route corrections,
   - skip file edits when the rule already exists and the session only reconfirmed it with no newly exposed wording gap.
13. Write or update the AI-facing skill or knowledge file so future agents can load the rule set directly.
14. Before finishing, update automation memory with what was learned, what file changed, and whether the run only reconfirmed existing rules or introduced a new wording requirement.

# Session Interpretation Heuristics

- Treat the current session as more than the chat transcript. The effective input includes the user request, injected `AGENTS.md` instructions, automation metadata, and loaded memory for the same automation.
- If the strongest correction comes from session-provided repo rules rather than back-and-forth chat, it still counts as explicit correction evidence.
- If the current automation or user request itself tightens what must be remembered, treat that request text as a valid correction source and update the owning self-learning skill immediately.
- If the user explicitly says a complaint, correction, or repeated mistake should be remembered, update the owning skill and automation memory even when no repository-wide rule changes.
- If the user explicitly says to scan for "what the user clearly mentioned" plus "what the handling process first got wrong and later corrected," treat that as a two-stream extraction contract for future runs.
- If the user explicitly asks to extract "what the user clearly mentioned" together with "what the handling process first got wrong and later corrected," record both streams and normalize them into one reusable rule set rather than keeping only user quotations.
- If the user says something was missed, incomplete, too shallow, too mechanical, or not tied to the real goal, treat that as a framework-level omission signal rather than a local wording tweak.
- Do not record the complaint wording itself as policy; record the corrected execution standard that would have prevented the complaint.
- When the session only reaffirms an already-documented global rule, prefer updating automation memory over duplicating the rule in another file.
- Only tighten specialist skills on reconfirmation runs when their current wording still leaves room for the old mistake.
- When a new correction matches an existing skill or framework rule at the root-cause level, merge the lesson into that existing artifact by expanding trigger, wrong-pattern, or reusable-strategy coverage instead of creating a sibling duplicate.

# Canonical Rule Shapes

Use one of these shapes when rewriting lessons:

- Default rule: `When the user asks for X, default to Y instead of Z.`
- Boundary rule: `Keep A as the ownership boundary; do not push B into it.`
- Verification rule: `If signal M is missing, do not claim N; verify with P.`
- Failure interpretation rule: `Error E is evidence of F first; do not immediately rewrite the feature.`
- Framework-native refactor rule: `Replace rigid slot-specific logic with reusable mechanism Q when multiple independent instances must coexist.`

# Seed Patterns Already Confirmed

## Auth boundary

- When Weline API auth on a WordPress-backed site is under discussion, keep the authentication boundary intact and default to WordPress username plus application password as the primary model; keep `weline-key` only as a compatibility fallback.
- If the intended path is WordPress auth but live responses still show `weline_api_missing_key` or `weline_api_invalid_key`, suspect forwarded-header or application-password problems before redesigning the whole feature.

## Generated artifacts and framework sources

- Treat generated artifacts as evidence of framework registration, not the primary edit surface. Implement the real change in framework source, then regenerate or recollect the generated output through the standard flow.

## Taglib and reusable UI abstraction

- If an initial implementation hardwires address levels or widget slots, refactor to a reusable grouping model when multiple independent cascades must coexist. In this codebase, `for + code` beat a rigid fixed-slot design.

## Runtime verification boundary

- Do not claim hot reload, HTTP verification, or live browser success when the relevant WLS instance is not actually running.
- If the dedicated non-`9501` WLS test port is occupied or unstable, stop at targeted static or unit verification and report the runtime gap explicitly instead of implying end-to-end proof.
- When the user is asking about visible SEO, i18n, layout, or interactive behavior, source inspection and HTTP success are only prechecks; the reusable conclusion must come from live browser HTML or DOM evidence.

## Browser-first visible verification and test timing

- When the session clarifies that a change is browser-visible and the local site can be served, treat Codex in-app Browser smoke verification as the acceptance boundary; HTTP checks, route tests, and source inspection stay as prechecks only.
- When the session corrects premature test-writing behavior, move the lesson into QA or E2E execution guidance: first complete the visible flow, then Browser-smoke it, and only after that add or solidify regression tests.
- If the same browser-first or no-premature-test rule was already present in `global-constraints.md`, record the reconfirmation in automation memory and only edit the owning specialist skill when the workflow wording still allows the old mistake.

## Complaint-driven self-learning ownership

- When the user explicitly says complaints, corrections, or wrong-first-then-fixed behavior must be remembered, treat that instruction as a standing requirement of the self-learning workflow rather than a one-off reminder.
- Treat "用户明确提到" and "处理过程先错后对" as separate mandatory extraction lanes; future runs should report both when both exist.
- When the complaint or correction is about the handling workflow itself, promote the reusable process rule exactly the same way as a framework-code correction; do not limit learning extraction to product-code mistakes.
- For these runs, the owning skill must preserve three things together: the initial mistake, the correction trigger, and the future default action that avoids repeating the mistake.
- Route each extracted lesson to the narrowest owning skill that can prevent recurrence, and use automation memory to record duplicate confirmations instead of scattering the same rule across many files.
- If the only new lesson in the session is "remember user corrections and complaints systematically," update the session-review skill and automation memory instead of manufacturing unrelated framework rules.
- If the user upgrades the extraction contract itself, also preserve the missing-step taxonomy and the structured lesson fields so future runs can detect omissions and emit stable skill/framework-rule shapes instead of loose notes.

## Cron and command conclusion standard

- A timeout during `setup:upgrade` is not enough to declare a cron registration change failed. Verify the registration surface directly, for example via `cron:task:collect` and `cron:task:listing`, before concluding.

## Framework-level versus module-local fixes

- Framework-level thinking is a default requirement for all code changes, not a special mode triggered only by the user's wording; trace the shared entry point or root contract before landing a module-local compatibility patch.
- For command or importer failures that look systemic, start from the owning command implementation and framework discovery path, not only from one missing output file or one module symptom.

## Existing scaffold versus greenfield assumptions

- When the user asks for a broad capability such as "complete e-commerce" in this repo, inspect the existing module matrix, theme pages, and acceptance docs first; default to closing the highest-leverage missing chain instead of proposing a greenfield rebuild.
- If the repo already contains the major storefront/account/checkout modules, narrow the work to the real visible breakage and keep moving through the next blocker rather than restating the entire platform scope.

## Repo work traceability

- For repository work, do not rely on chat history alone. Initialize or reopen the matching `dev/ai/codex/tasks/...` workspace before substantial analysis or edits, keep `progress.md` current during execution, and leave `result.md` with the resume point.
- Only treat transient non-repo Q&A or one-shot system lookups as exceptions; code, docs, debugging, verification, and rule changes all require persistent task records.
- Session-review and self-learning runs are not exempt: if the run updates skills, docs, rules, or automation memory for this repository, the learning workflow itself must leave task records before editing those artifacts.

## Hook-shell contract

- When the user says a personal-center feature should behave like addresses or other in-page sections, keep the account page as the host shell and inject feature UI through `account.sidebar` plus `account.sidebar.content` instead of linking out to standalone module pages.
- If a page already exposes a reusable hook host, prefer bridge templates and section contracts over hardcoding feature-specific blocks into the host layout.

## Visible requirement versus hidden backend success

- If the user points at a visible quote form, captcha block, popup, or page interaction gap, backend-only success is insufficient. The acceptance boundary is the visible frontend behavior, not only token or service correctness.

## Data written but UI unchanged

- When imported or repaired data is present in the database but the storefront still shows placeholders or stale output, inspect the full visible chain: database state, source template fallback, controller/page cache key, and WLS reload state. Do not stop at "DB updated successfully".
- If the user follows fake/demo data work with "然后导入", treat the import, cache invalidation, and visible-surface verification as one execution chain rather than three separate optional steps.

## Stale reference before template rewrite

- For cart or list rows that lose image, name, or SKU, verify first whether the row points at a stale or deleted product record before rewriting templates. A durable fix may require snapshot fields or rebinding logic in the service layer, not another placeholder branch in the view.

## Quality gate before one-off output patching

- When generated PageBuilder output looks weak in Browser, fix the shared generation contract, recovery prompt, selector coverage, or completion gate first; do not hardcode one bad page or one bad block into a special-case output patch.
- If the real build flow is scheduler- or queue-owned, validate fixes by re-running that owned flow and Browser-checking the generated pages instead of shortcutting with a manual queue execution path and claiming whole-flow completion.

# Output Format

For each extracted lesson, prefer this compact template:

```text
【主题】
一句话概括分类。

【初始误区】
最初做错了什么，或默认假设错在什么地方。

【纠正信号】
用户怎样纠正，或哪条验证证据推翻了原假设。

【沉淀规则】
未来应默认采用的框架做法。

【复用边界】
这条规则适用于哪些任务，不适用于哪些情况。
```

# Expected Output

- A concise set of reusable rules extracted from the session.
- A skill or knowledge file that future agents can load directly.
- Clear distinction between reusable framework practice and one-off project detail.

# Validation

- Check that every promoted rule has a concrete correction source.
- Check whether the correction source was chat content, injected repo instructions, or stronger runtime evidence, and record that distinction accurately.
- Check whether the user expressed dissatisfaction or a direct "remember this" instruction; if so, ensure the final learning artifact captures the underlying rule explicitly.
- Check that every rule is phrased as reusable guidance, not a diary entry.
- Check that the resulting skill stays concise and high-signal.
- Check that the final rule does not conflict with `dev/ai/global-constraints.md`.

# Constraints

- Do not copy raw chat history into the skill.
- Do not promote unverified guesses into framework policy.
- Do not bury reusable lessons inside root-level ad hoc reports.
