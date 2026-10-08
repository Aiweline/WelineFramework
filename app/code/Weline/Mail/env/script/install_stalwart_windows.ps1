param(
    [string]$Action = "check"
)

$InstallDir = if ($env:STALWART_INSTALL_DIR) { $env:STALWART_INSTALL_DIR } else { "C:\Program Files\Stalwart" }
$Binary = Join-Path $InstallDir "bin\stalwart.exe"

if ($Action -eq "check") {
    $stalwart = Get-Command stalwart.exe -ErrorAction SilentlyContinue
    if ($stalwart -or (Test-Path $Binary)) {
        Write-Output "Stalwart found"
        exit 0
    }
    Write-Output "Stalwart not found"
    exit 1
}

if ($Action -ne "install") {
    Write-Output "Unsupported action: $Action"
    exit 2
}

# Linux 生产路径已实现真实安装（install_stalwart_linux.sh → 官方 install.sh）。
# Windows 仍依赖 NSSM；暂不自动改服务。生产请用 Linux + env:install stalwart-mail-server -y。
Write-Output "MISSING"
Write-Output "Windows Stalwart auto-install is not implemented yet. Use Linux host with env:install stalwart-mail-server -y."
Write-Output "Manual outline: download stalwart-*-windows-msvc.zip, place under $InstallDir\bin, register NSSM service Stalwart, open http://127.0.0.1:8080/admin."
exit 1
