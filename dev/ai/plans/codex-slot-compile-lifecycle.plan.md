---
name: Codex Recovered Plan - Slot Compile Lifecycle
overview: Recover the unfinished WLS slot tag compilation lifecycle fix plan from Codex session logs.
source_session: C:\Users\17142\.codex\sessions\2026\03\21\rollout-2026-03-21T13-47-11-019d0eee-e8be-7430-a423-419b8e1e6cd8.jsonl
source_timestamp: 2026-03-21T05:57:15.057Z
status: completed
isProject: false
todos:
  - id: codex-slot-compile-lifecycle-1
    content: Patch the taglib compile lifecycle to reset slot registration per compile cycle
    status: completed
  - id: codex-slot-compile-lifecycle-2
    content: Pass source file and line metadata into compile-time tag callbacks
    status: completed
  - id: codex-slot-compile-lifecycle-3
    content: Run a focused verification for repeated slot template compilation
    status: completed
---

# Codex Recovered Plan - Slot Compile Lifecycle

## Recovery Note

Recovered from the last `update_plan` found in the source session. **已在当前仓库核对**：实现已落地，本文件已更新为完成态。

## Original Explanation

Found a WLS/static-state issue in Slot tag compilation. Implementing a compile-cycle reset plus better source metadata, then verifying with a minimal reproduction.

## Completion (revalidated 2026-03-21)

| 项 | 实现位置 |
|----|-----------|
| 顶层编译周期前后清空 `Slot::$registeredSlots` | `Weline\Framework\View\Taglib::resetCompileScopedTagState()`，由 `compile()` 在 `topLevelCompile` 时于 tokenize 前与 `finally` 中调用 |
| 编译期回调注入 `file` / `line` | `registerTagCallbacks()` → `enrichTagConfigWithSource()`；`CodeGenerator::buildTagParams()` 提供 `line` |
| WLS 请求结束兜底重置 | `StateManager::registerFrameworkResets()` 中 `registerStaticReset(\Weline\Theme\Taglib\Slot::class, 'registeredSlots', [])` |
| 回归测试 | `Weline\Theme\Test\Unit\SlotTaglibCompileStateTest`（两次编译同 id 不误判重复；重复 id 异常信息含模板文件名） |

验证命令：

```bash
php vendor/phpunit/phpunit/phpunit --configuration dev/phpunit/config.xml --filter SlotTaglibCompileStateTest
```

结案日志：`dev/ai/codex/tasks/2026-03-21/2026-03-21-1630-slot-compile-lifecycle-plan-closeout.md`
