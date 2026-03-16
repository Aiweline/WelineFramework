---
name: cursor-cli
description: Cursor CLI。cursor 命令（IDE）、agent 命令（独立）。安装、登录、交互/非交互模式、脚本集成。
globs: []
alwaysApply: false
---

# cursor-cli（极简版）

## 何时使用

- 使用 cursor/agent 命令行
- 打开文件、diff、merge
- 非交互脚本集成（agent -p）

## 必做

- cursor：IDE 附带，`cursor --goto file:line`、`cursor --chat`
- agent：独立安装，需 `agent login` 授权
- 非交互模式用 `agent -p "prompt"`

## 最小示例

```bash
# 安装 agent (Windows)
irm 'https://cursor.com/install?win32=true' | iex

# 登录
agent login

# 交互模式
agent

# 非交互
agent -p "任务描述"
```

## 禁止

- 未登录就使用 agent 非交互模式
- 混淆 cursor 与 agent 命令
