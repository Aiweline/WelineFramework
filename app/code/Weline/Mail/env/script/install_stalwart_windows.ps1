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

Write-Output "Stalwart Windows native install plan:"
Write-Output "1. Download stalwart-x86_64-pc-windows-msvc.zip from the official release page."
Write-Output "2. Create $InstallDir\bin, $InstallDir\etc, $InstallDir\data, $InstallDir\logs."
Write-Output "3. Place stalwart.exe under $InstallDir\bin."
Write-Output "4. Install NSSM and register a Windows service named Stalwart."
Write-Output "5. Start the service and open http://127.0.0.1:8080/admin for bootstrap."
Write-Output "Automatic binary download is intentionally not performed yet to avoid changing Windows services without an operator-reviewed source URL."
exit 1
