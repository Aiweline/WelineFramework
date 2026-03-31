param(
    [string]$LogDir = "E:\WelineFramework\DEV-workspace\var\log",
    [int]$PollSeconds = 3
)

$ErrorActionPreference = "Stop"

$targets = @(
    Join-Path $LogDir "wls.log",
    Join-Path $LogDir "exception.log",
    Join-Path $LogDir "php_error.log",
    Join-Path $LogDir "error.log"
)

$pattern = "WLS Runtime Error|Fatal error|ParseError|E_COMPILE_ERROR|TypeError|Uncaught|PDOException"

Write-Host "=== WLS 错误值守已启动 ===" -ForegroundColor Cyan
Write-Host "日志目录: $LogDir"
Write-Host "匹配规则: $pattern"
Write-Host "轮询间隔: ${PollSeconds}s"
Write-Host ""

foreach ($file in $targets) {
    if (-not (Test-Path $file)) {
        Write-Host "跳过不存在文件: $file" -ForegroundColor DarkYellow
    }
}

$existing = $targets | Where-Object { Test-Path $_ }
if ($existing.Count -eq 0) {
    throw "未找到可监控日志文件。"
}

Get-Content -Path $existing -Tail 0 -Wait |
    Select-String -Pattern $pattern |
    ForEach-Object {
        $ts = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
        Write-Host "[$ts] 命中错误: $($_.Path)" -ForegroundColor Red
        Write-Host $_.Line -ForegroundColor Yellow
        Write-Host "--------------------------------------------------"
    }
