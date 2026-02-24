---
name: windows-command-quoting
description: |
  Windows 下在 PHP 中拼接命令行（exec、PowerShell、WMIC、cmd）时的单双引号与转义规则。
  只要在代码里拼 Windows 命令（尤其是含引号、变量、空格、逗号），必须先看本技能，避免 parse error 或参数被拆错。
  
  MUST use when:
  - PHP 中拼接 exec() / proc_open() / shell_exec() / popen() 的 Windows 命令
  - 拼 PowerShell -Command 或 -ArgumentList 字符串
  - 拼 WMIC where "..." 或 WQL 条件
  - 拼 cmd /c start、taskkill、tasklist、netstat 等
  - 出现 "unexpected single-quoted string"、参数错位、PowerShell 报错
  - 启动后台进程、Worker、Dispatcher
  - Windows 下进程管理、端口检测、服务启动
  
  Keywords: Windows, exec, PowerShell, WMIC, 引号, 单引号, 双引号, 转义, -Command, -ArgumentList, WQL, cmd, 命令行, proc_open, popen, shell_exec, Start-Process, taskkill, tasklist, netstat, Get-Process, Get-WmiObject, Get-CimInstance, Win32_Process, 参数错位, 逗号分隔, 端口列表, Worker启动, Dispatcher启动, 后台进程, nohup, start /B, WindowStyle Hidden, -NoProfile, -ExecutionPolicy, ps1脚本, bat批处理, 进程启动, 进程管理, Windows命令, CMD命令, parse error, unexpected single-quoted
globs:
  - "**/Processer.php"
  - "**/Server/**/*.php"
  - "**/bin/*.php"
  - "**/Console/**/*.php"
alwaysApply: false
---

# Windows 命令行引号与转义技能

## 何时必看

- 在 **PHP** 里拼接任何会传给 **Windows** 执行的字符串（`exec`、`proc_open`、`popen`、PowerShell、WMIC、cmd）时，**先看本技能**。
- 命令里一旦出现：**引号、空格、逗号、变量**，极易触发：
  - PHP 解析错误（如 `unexpected single-quoted string`）
  - 外层 shell 把参数拆错（如逗号被当成参数分隔符）
  - PowerShell / WMIC 收到错误的字符串

---

## 1. PHP 字符串规则（先搞清楚谁解析谁）

| PHP 写法 | 含义 |
|----------|------|
| `'...'` 单引号 | 仅 `\\` 和 `\'` 有特殊含义，**不**解析 `$变量` |
| `"..."` 双引号 | 解析 `$var`、`\n` 等；要输出 `$` 或 `"` 需转义 `\$`、`\"` |
| `\'` | 在单引号字符串中表示**一个**单引号字符 |
| `''` 两个单引号 | **不是**转义！PHP 会理解为：字符串结束 + 新字符串开始 → 容易 **parse error** |

**易错**：在 PHP 单引号串里写 `''*--name=xxx*''` 想给 PowerShell 传 `'*--name=xxx*'`，会报错 `unexpected single-quoted string "*--name="`，因为第一个 `'` 就结束了字符串。

---

## 2. 正确写法模式

### 2.1 在 PHP 里给 PowerShell -like 传带单引号的模式

**错误**（parse error）：
```php
$psCmd = '... -like ''*--name=' . $name . '*'' ...';  // '' 在 PHP 里不是转义！
```

**正确**：用 `\'` 表示一个单引号，或先拼好模式再拼接：
```php
$pattern = '*--name=' . str_replace("'", "''", $name) . '*';
$psCmd = 'powershell -NoProfile -Command "Get-CimInstance Win32_Process | Where-Object { $_.CommandLine -like \'' . $pattern . '\' } | Select-Object -ExpandProperty ProcessId" 2>NUL';
```
- PHP 单引号串里 `\'` → 输出一个 `'`，所以 PowerShell 收到的是 `-like '*--name=xxx*'`。
- 若进程名可能含单引号，用 `str_replace("'", "''", $name)` 按 PowerShell 规则转义。

### 2.2 PowerShell -ArgumentList 与逗号

- **-ArgumentList** 会把**逗号**当作参数分隔符。
- 若某一参数本身含逗号（如端口列表 `"19981,19982,19983,19984"`），必须把**整个参数**用双引号包成一段，否则会被拆成多个参数导致后面参数错位。

**实际案例**（2026-02-04 Dispatcher 启动问题）：

期望传递：
```
dispatcher.php 127.0.0.1 9981 "19981,19982,19983,19984" default --name=xxx
```

错误写法（只检查空格和引号）：
```php
// 错误：正则只匹配 [\s"] 不匹配逗号
if (preg_match('/[\s"]/', $arg)) { ... }
```

实际被解析为：
```
dispatcher.php 127.0.0.1 9981 19981 19982 19983 19984 default --name=xxx
```

结果：`$argv[3]` 变成 `19981`（单个端口），`$argv[4]` 变成 `19982`（被当成 instanceName），**Dispatcher 只分流到第一个 Worker**！

**正确**：对含逗号的参数加双引号（在拼参数数组时统一 escape）：
```php
$escapedArgs = array_map(function($arg) {
    // 关键：正则必须包含逗号 [\s",]
    if (preg_match('/[\s",]/', $arg)) {  // 含空格、逗号、双引号就包起来
        return '"' . str_replace('"', '`"', $arg) . '"';
    }
    return $arg;
}, $argList);
$argsStr = implode(',', $escapedArgs);
```

**验证命令**（检查进程实际收到的参数）：
```powershell
Get-WmiObject Win32_Process -Filter "CommandLine LIKE '%dispatcher.php%'" | Select-Object CommandLine
```

### 2.3 WMIC WQL 中的引号

- WQL 条件外层用双引号，内部字符串用**单引号**：`where "CommandLine like '%xxx%'"`。
- 在 PHP 里用双引号拼 WMIC 时，外层双引号要转义：`\"`；若用单引号拼，WMIC 的双引号直接写 `"` 即可。
```php
$cmd = 'wmic process where "CommandLine like \'' . $wmicPattern . '\'" get ProcessId 2>NUL';
```

---

## 3. 检查清单（写/改命令时必过一遍）

1. **PHP**：当前用的是单引号还是双引号？里面的 `'` 或 `"` 是否用 `\'`、`\"` 正确转义？**禁止**在单引号串里用 `''` 表示一个引号。
2. **PowerShell**：`-Command "..."` 里再出现引号时，是否用 `\'` 或 `\"` 正确传到 PowerShell？PowerShell 里单引号内的单引号要写成 `''`。
3. **-ArgumentList**：是否有参数含**逗号**或**空格**？有则整段用双引号包住，并在数组 escape 时处理。
4. **WMIC**：`where "..."` 里 like 的字符串是否用单引号包住？`%` 是 WQL 通配符。

---

## 4. 常见错误与对应修复

| 现象 | 可能原因 | 修复方向 |
|------|----------|----------|
| PHP parse error: unexpected single-quoted string | 单引号串里写了 `''` | 改用 `\'` 或拆成变量拼接 |
| Dispatcher/Worker 参数错位（如证书路径变成端口号） | -ArgumentList 中逗号被当成分隔符 | 对含逗号的参数整体加双引号 |
| **Dispatcher 只分流到第一个 Worker** | 端口列表含逗号未加引号被拆成多参数 | 正则从 `/[\s"]/` 改为 `/[\s",]/` |
| WMIC 返回空 / 查不到进程 | 命令行被 Start-Process 隐藏或取不到 | 用 PowerShell Get-CimInstance 回退 |
| PowerShell 报错 "无法识别..." | 字符串被 PHP 或 cmd 提前截断/拆开 | 逐层检查 PHP → cmd → PowerShell 引号转义 |
| `wmic` 命令找不到 | Windows 新版本移除了 wmic.exe | 改用 `Get-WmiObject` 或 `Get-CimInstance` |

---

## 5. 小结

- **PHP 单引号里不要用 `''` 表示一个引号**，要用 `\'`。
- **拼 Windows 命令时先定"层"**：PHP 字符串 → (可选) cmd → PowerShell/WMIC，每层引号、逗号、空格都要考虑。
- 含**逗号**的参数在 **-ArgumentList** 里必须用双引号包成**一个**参数。
- 遇到 **exec/Processer/Start-*.php** 里拼 Windows 命令，**先打开本技能再写**，可显著减少引号与参数错位问题。
