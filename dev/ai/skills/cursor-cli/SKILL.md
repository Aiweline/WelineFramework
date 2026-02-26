# Cursor CLI 技能

## 概述

Cursor 提供两种 CLI 方式：

### 1. `cursor` 命令 (IDE 附带)
- 用于打开文件、diff、merge 等 IDE 操作
- `cursor --goto file:line` 打开文件定位
- `cursor --chat` 打开独立聊天窗口

### 2. `agent` 命令 (独立安装)
- 独立的 CLI 工具，不依赖 IDE 窗口
- 支持非交互模式 `-p` 用于脚本集成
- **注意**: 首次使用需要 `agent login`

## 安装

### Windows (PowerShell)
```powershell
irm 'https://cursor.com/install?win32=true' | iex
```

安装路径: `C:\Users\<user>\AppData\Local\cursor-agent\agent.cmd`

### macOS / Linux
```bash
curl https://cursor.com/install -fsS | bash
```

### 登录授权
```bash
agent login
# 在浏览器中完成授权
```

### 验证安装
```bash
agent status
# 应显示: ✓ Logged in as your@email.com
```

## 使用方式

### 交互模式
```bash
agent
# 进入交互式 AI 对话
```

### 非交互模式 (脚本集成)
```bash
agent -p "你的问题" --output-format text
```

### 文件修改模式
```bash
agent -p --force "重构这个文件"
# --force 允许实际修改文件，否则只提出建议
```

## 响应速度

**注意**: `agent -p` 模式响应可能较慢（30s-90s+），取决于：
- 网络连接
- 工作空间大小（默认扫描当前目录）
- API 负载

## 在 Weline Framework 中的集成

### CursorAiService

`Agent\CursorBase\Service\CursorAiService` 封装了 CLI 调用：

```php
$cursorAi = ObjectManager::getInstance(CursorAiService::class);

// 检查是否可用
if ($cursorAi->isAvailable()) {
    $result = $cursorAi->chat('你的问题', '系统指令', [], 60);
    if ($result['success']) {
        echo $result['response'];
    }
}

// 获取安装状态
$status = $cursorAi->getInstallStatus();
if (!$status['installed']) {
    // 显示安装指南
    print_r($status['install_instructions']);
}
```

### InteractiveShellService

交互式 CLI 中使用 `/ai` 命令：
- `/ai` - 查看状态和安装情况
- `/ai on` - 启用 AI 对话（需要已安装）
- `/ai off` - 禁用
- `/ai install` - 显示安装指南
- `/ai login` - 显示登录指南

## 推荐架构

由于 CLI 响应较慢，推荐使用**任务池分发机制**：

```
┌─────────────────────────────────────────┐
│  CLI (cursor:supervisor:start)          │
│  ├─ /plan, /commit, /git 等本地命令     │
│  └─ 任务描述 → 写入 tasks.json          │
└──────────────────┬──────────────────────┘
                   │
                   ▼
┌─────────────────────────────────────────┐
│  任务池 (dev/ai/agents/tasks.json)      │
│  ├─ pending → running → done            │
└──────────────────┬──────────────────────┘
                   │
                   ▼
┌─────────────────────────────────────────┐
│  Cursor IDE 中的 AI (即时响应)          │
│  ├─ 检测到新任务                        │
│  ├─ 执行任务                            │
│  └─ 更新任务状态                        │
└─────────────────────────────────────────┘
```

这样 CLI 用于快速描述任务，实际 AI 处理在 Cursor IDE 中完成，响应更快更稳定。

## 安装

```bash
# macOS/Linux/WSL
curl https://cursor.com/install -fsS | bash

# Windows PowerShell
irm 'https://cursor.com/install?win32=true' | iex
```

## 核心用法

### 交互模式
```bash
# 启动交互会话
agent

# 带初始 prompt
agent "refactor the auth module"

# 指定工作空间
agent --workspace /path/to/project "fix the bug"
```

### 非交互模式 (脚本/自动化)
```bash
# 打印模式 - 适合脚本集成
agent -p "find and fix performance issues"

# 指定输出格式
agent -p "review code" --output-format text
agent -p "analyze" --output-format json
agent -p "stream" --output-format stream-json

# 流式输出
agent -p "task" --output-format stream-json --stream-partial-output
```

### 模式选择
```bash
# Agent 模式 (默认) - 完整工具访问
agent "task"

# Plan 模式 - 只规划不执行
agent --plan "design auth system"
agent --mode=plan "task"

# Ask 模式 - 只读探索
agent --mode=ask "how does this work"
```

## 关键参数

| 参数 | 说明 |
|------|------|
| `-p, --print` | 非交互打印模式，适合脚本 |
| `--output-format` | 输出格式: `text`, `json`, `stream-json` |
| `--workspace <dir>` | 指定工作目录 |
| `--model <model>` | 指定模型 |
| `--mode <mode>` | 模式: `plan`, `ask` (默认 agent) |
| `--plan` | Plan 模式快捷方式 |
| `-f, --force` | 强制允许命令执行 |
| `--yolo` | `--force` 别名 |
| `--api-key` | API Key (或用 `CURSOR_API_KEY` 环境变量) |
| `--trust` | 信任工作空间 (headless 模式) |
| `-c, --cloud` | 云端模式 |

## 会话管理

```bash
# 列出历史会话
agent ls

# 恢复最新会话
agent resume

# 继续上一个会话
agent --continue

# 恢复指定会话
agent --resume="chat-id"
```

## PHP 集成示例

### 非交互调用 (推荐)

```php
/**
 * 通过 Cursor CLI 执行 AI 请求
 */
public function executePrompt(string $prompt, string $workspace = null): array
{
    $cmd = 'agent -p ' . escapeshellarg($prompt);
    $cmd .= ' --output-format json';
    $cmd .= ' --trust';  // headless 模式信任工作空间
    
    if ($workspace) {
        $cmd .= ' --workspace ' . escapeshellarg($workspace);
    }
    
    $output = [];
    $returnCode = 0;
    exec($cmd, $output, $returnCode);
    
    $result = implode("\n", $output);
    
    return [
        'success' => $returnCode === 0,
        'response' => $result,
        'error' => $returnCode !== 0 ? "Exit code: {$returnCode}" : null,
    ];
}
```

### 流式输出

```php
/**
 * 流式获取 AI 响应
 */
public function streamPrompt(string $prompt, callable $onChunk): array
{
    $cmd = 'agent -p ' . escapeshellarg($prompt);
    $cmd .= ' --output-format stream-json';
    $cmd .= ' --stream-partial-output';
    $cmd .= ' --trust';
    
    $process = proc_open($cmd, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    
    $response = '';
    while (!feof($pipes[1])) {
        $chunk = fgets($pipes[1]);
        if ($chunk) {
            $data = json_decode($chunk, true);
            if ($data && isset($data['content'])) {
                $response .= $data['content'];
                $onChunk($data['content'], $response);
            }
        }
    }
    
    fclose($pipes[1]);
    fclose($pipes[2]);
    $returnCode = proc_close($process);
    
    return [
        'success' => $returnCode === 0,
        'response' => $response,
    ];
}
```

## 与旧方案对比

### ❌ 旧方案 (劫持 IDE - 不推荐)
```php
// 写入信号弹文件
file_put_contents($scratchFile, $prompt);
// 唤醒 Cursor IDE
exec('cursor --goto ' . $scratchFile);
// 模拟按键 Ctrl+K
$keyboard->triggerCursorExecution();
// 轮询文件等待响应...
```

问题:
- 依赖 IDE 窗口焦点
- 按键模拟不可靠
- 响应检测复杂
- 用户体验差

### ✅ 新方案 (Cursor CLI)
```php
// 直接调用 CLI
$result = exec('agent -p "prompt" --output-format json --trust');
```

优点:
- 无需 IDE 窗口
- 直接获取响应
- 支持流式输出
- 可在后台运行
- CI/CD 友好

## 注意事项

1. **认证**: 首次使用需要 `agent login`
2. **工作空间**: 使用 `--workspace` 指定项目目录以获取上下文
3. **Headless**: 自动化场景加 `--trust` 避免交互确认
4. **输出**: 脚本中使用 `-p` + `--output-format json` 便于解析
5. **模型**: 使用 `agent models` 查看可用模型

## 触发关键词

cursor cli, agent 命令, cursor agent, 命令行 ai, cli ai, 非交互, 脚本集成,
agent -p, --print, --output-format, headless, 自动化调用, cursor 调用
