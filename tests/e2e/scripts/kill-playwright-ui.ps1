# 结束命令行中含 playwright + --ui 的 node 进程（避免上次 UI 未关占端口/占列表）
$ErrorActionPreference = 'SilentlyContinue'
Get-CimInstance Win32_Process -Filter "Name = 'node.exe'" | Where-Object {
    $_.CommandLine -and
    $_.CommandLine -like '*playwright*' -and
    $_.CommandLine -like '*--ui*'
} | ForEach-Object {
    Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue
}
