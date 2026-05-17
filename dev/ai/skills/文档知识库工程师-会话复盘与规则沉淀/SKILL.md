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
- Current thread messages, automation memory, and any relevant rollout summaries or memory extracts.

# Responsibilities

- Scan the conversation and isolate statements where the user explicitly raised the engineering bar, corrected a wrong direction, or clarified the real framework boundary.
- Separate one-off project detail from reusable framework practice.
- Rewrite the lesson as a stable rule that another agent can apply on a future task.
- Preserve the evidence chain: what was assumed first, what later proved correct, and why the corrected rule is reusable.
- Land the result in an AI-facing skill or knowledge file instead of leaving it only in a final report.

# Extraction Standard

Only promote a lesson into self-learning knowledge when all three conditions hold:

1. The session contained a wrong first move, incomplete mental model, or vague default.
2. A later correction was explicit, either from the user or from stronger verification evidence.
3. The corrected form is reusable beyond the one exact file or branch.

Do not promote:

- Purely local file paths without a reusable pattern.
- Temporary environment accidents with no stable handling rule.
- Raw symptoms when the real boundary or fix standard was never confirmed.

# Workflow

1. Read the current session and mark candidate moments:
   - user corrections,
   - rejected implementations,
   - failed verifications that changed the conclusion standard,
   - final framework-native patterns that replaced a brittle patch.
2. For each candidate, write four fields in scratch form:
   - initial assumption,
   - correction trigger,
   - final rule,
   - reuse boundary.
3. Drop anything that is only project-local or still unverified.
4. Normalize each surviving lesson into imperative guidance:
   - `default to ...`
   - `do not assume ...`
   - `when X happens, verify Y before concluding Z`
5. Group rules by theme such as auth boundary, generated artifacts, runtime verification, hook boundary, cron registration, or visible UX requirements.
6. If multiple examples express the same rule, keep one concise canonical rule and attach the strongest evidence example.
7. Write or update an AI-facing skill so future agents can load the rule set directly.

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

## Cron and command conclusion standard

- A timeout during `setup:upgrade` is not enough to declare a cron registration change failed. Verify the registration surface directly, for example via `cron:task:collect` and `cron:task:listing`, before concluding.

## Framework-level versus module-local fixes

- Framework-level thinking is a default requirement for all code changes, not a special mode triggered only by the user's wording; trace the shared entry point or root contract before landing a module-local compatibility patch.
- For command or importer failures that look systemic, start from the owning command implementation and framework discovery path, not only from one missing output file or one module symptom.

## Hook-shell contract

- When the user says a personal-center feature should behave like addresses or other in-page sections, keep the account page as the host shell and inject feature UI through `account.sidebar` plus `account.sidebar.content` instead of linking out to standalone module pages.
- If a page already exposes a reusable hook host, prefer bridge templates and section contracts over hardcoding feature-specific blocks into the host layout.

## Visible requirement versus hidden backend success

- If the user points at a visible quote form, captcha block, popup, or page interaction gap, backend-only success is insufficient. The acceptance boundary is the visible frontend behavior, not only token or service correctness.

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
- Check that every rule is phrased as reusable guidance, not a diary entry.
- Check that the resulting skill stays concise and high-signal.
- Check that the final rule does not conflict with `dev/ai/global-constraints.md`.

# Constraints

- Do not copy raw chat history into the skill.
- Do not promote unverified guesses into framework policy.
- Do not bury reusable lessons inside root-level ad hoc reports.
