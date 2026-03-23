# Task Workspace Template

推荐直接使用脚手架创建：

```bash
php dev/ai/codex/scripts/init-task.php "short title" --source="user request"
```

创建后的目录结构：

```text
dev/ai/codex/tasks/YYYY-MM-DD/YYYY-MM-DD-HHMM-short-slug/
├── task.md
├── plan.md
├── progress.md
├── result.md
└── artifacts/
```

## 文件职责

- `task.md`
  记录任务标题、状态、目标、范围、约束、相关文件、恢复入口。
- `plan.md`
  记录本任务的执行步骤和验证目标；步骤状态建议使用 `- [ ]`、`- [x]`。
- `progress.md`
  记录按时间追加的过程日志，不要只在结束时补写。
- `result.md`
  记录结果、验证命令、变更文件、遗留风险和下一次恢复入口。
- `artifacts/`
  保存截图、日志、导出材料等补充证据。

## 状态建议

- `planned`
- `in_progress`
- `blocked`
- `review`
- `done`
- `cancelled`

## 使用规则

1. 每个任务只写自己的目录。
2. 不再把共享状态写入 `dev/ai/codex/ACTIVE.md`。
3. 大计划写 `dev/ai/codex/plans/*.plan.md`，执行状态写当前任务目录。
4. 完成后记得同步 `memory/YYYY-MM-DD.md`。
