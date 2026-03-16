---
name: windows-command-quoting
description: Windows 下 PHP 拼接命令行时的引号与转义。exec、PowerShell、WMIC、proc_open。避免 parse error、参数错位。
globs:
  - "**/Processer.php"
  - "**/Server/**/*.php"
  - "**/bin/*.php"
  - "**/Console/**/*.php"
alwaysApply: false
---

# windows-command-quoting（极简版）

## 何时使用

- PHP 中拼接 exec/proc_open/shell_exec 的 Windows 命令
- 拼 PowerShell -Command、-ArgumentList
- 拼 WMIC、cmd、taskkill、tasklist
- 出现 unexpected single-quoted、参数错位
- 启动 Worker、Dispatcher、后台进程

## 必做

- 单引号中 `''` 不是转义，用 `\'` 表示一个单引号
- 双引号中 `$var` 会解析，要输出 `$` 用 `\$`
- 含空格、逗号、变量的参数需正确转义
- 先拼好模式/参数再拼接，避免嵌套引号混乱

## 最小示例

```php
// 错误：'' 在 PHP 单引号中不是转义
$cmd = '... -like ''*name*'' ...';  // parse error!

// 正确：用 \' 或先拼变量
$pattern = '*--name=' . $name . '*';
$cmd = "... -like '" . $pattern . "' ...";
```

## 禁止

- 在 PHP 单引号串中用 `''` 试图转义
- 含变量/空格的参数不转义直接拼接
