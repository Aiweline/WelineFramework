# 一键安装引导脚本（Windows PowerShell）：克隆仓库并执行 bin\install.bat。
# 用法（在 PowerShell 中复制整行执行）：
#   iex (New-Object Net.WebClient).DownloadString('https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.ps1')
# 指定分支（如 server-opt）：在 URL 后加参数，见下方 $Branch 说明。
# 需已安装 Git；若未安装，install.bat 会尝试自动安装。

param([string]$Branch = "master")

$RepoUrl = if ($env:WELINE_REPO_URL) { $env:WELINE_REPO_URL } else { "https://gitee.com/aiweline/WelineFramework.git" }
$InstallDir = "weline"

# 检测是否已在项目目录（脚本在 bin 下，上级应有 setup\server_installer\run.php）
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$Root = Split-Path -Parent $ScriptDir
$RunPhp = Join-Path $Root "setup\server_installer\run.php"
$InstallBat = Join-Path $Root "bin\install.bat"

if ((Test-Path $RunPhp) -and (Test-Path $InstallBat)) {
    Write-Host "Already in WelineFramework. Running install..."
    Set-Location $Root
    & $InstallBat
    exit $LASTEXITCODE
}

$TargetPath = Join-Path (Get-Location) $InstallDir
if (Test-Path (Join-Path $TargetPath ".git")) {
    Write-Host "Directory $InstallDir already exists. Updating..."
    Set-Location $TargetPath
    git fetch origin 2>$null
    git checkout $Branch 2>$null; if (-not $?) { git pull origin $Branch 2>$null }
    Set-Location (Get-Location)
} else {
    Write-Host "Cloning WelineFramework (branch: $Branch) into $InstallDir..."
    git clone -b $Branch $RepoUrl $InstallDir
}
Set-Location $TargetPath
& ".\bin\install.bat"
exit $LASTEXITCODE
