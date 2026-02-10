<#
.SYNOPSIS
    RDP Wrapper 自动安装脚本
.DESCRIPTION
    从 GitHub 下载 RDP Wrapper (sebaxakerhtc/rdpwrap) 最新版本并自动安装。
    支持 Windows 10/11 多用户同时远程桌面。
    非管理员运行时自动请求提权（UAC）。
#>

param(
    [string]$InstallDir = "C:\Program Files\RDP Wrapper",
    [switch]$ForceReinstall
)

$ErrorActionPreference = "Stop"

Write-Host "========================================" -ForegroundColor Cyan
Write-Host " RDP Wrapper 自动安装脚本" -ForegroundColor Cyan
Write-Host " GitHub: sebaxakerhtc/rdpwrap" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# 检查管理员权限，非管理员自动提权
$currentPrincipal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $currentPrincipal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host "[INFO] 非管理员权限，正在请求提权..." -ForegroundColor Yellow
    try {
        Start-Process powershell -Verb RunAs -Wait -ArgumentList "-NoProfile", "-ExecutionPolicy", "Bypass", "-File", "`"$PSCommandPath`"", "-InstallDir", "`"$InstallDir`""
        exit $LASTEXITCODE
    } catch {
        Write-Host "[ERROR] 提权失败，请右键以管理员身份运行此脚本！" -ForegroundColor Red
        exit 1
    }
}

# 检查是否已安装
if ((Test-Path "$InstallDir\rdpwrap.dll") -and (-not $ForceReinstall)) {
    Write-Host "[INFO] RDP Wrapper 已安装在: $InstallDir" -ForegroundColor Green
    Write-Host "[INFO] 如需重新安装，请使用 -ForceReinstall 参数" -ForegroundColor Yellow
    exit 0
}

# 临时下载目录
$tempDir = Join-Path $env:TEMP "rdpwrap_install"
if (Test-Path $tempDir) {
    Remove-Item -Recurse -Force $tempDir
}
New-Item -ItemType Directory -Path $tempDir | Out-Null

try {
    # ========== 步骤1：下载 RDP Wrapper ==========
    Write-Host "[1/5] 正在从 GitHub 获取最新版本..." -ForegroundColor Yellow

    $releaseUrl = "https://api.github.com/repos/sebaxakerhtc/rdpwrap/releases/latest"

    try {
        [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
        $release = Invoke-RestMethod -Uri $releaseUrl -Method Get -TimeoutSec 30
        $downloadUrl = ($release.assets | Where-Object { $_.name -like "*.zip" } | Select-Object -First 1).browser_download_url

        if (-not $downloadUrl) {
            Write-Host "[WARN] 无法从 API 获取下载链接，尝试使用固定链接..." -ForegroundColor Yellow
            $downloadUrl = "https://github.com/sebaxakerhtc/rdpwrap/releases/download/v1.8.9.9/RDPWrap-v1.8.9.9.zip"
        }
    } catch {
        Write-Host "[WARN] 无法访问 GitHub API，使用固定下载链接..." -ForegroundColor Yellow
        $downloadUrl = "https://github.com/sebaxakerhtc/rdpwrap/releases/download/v1.8.9.9/RDPWrap-v1.8.9.9.zip"
    }

    $zipFile = Join-Path $tempDir "rdpwrap.zip"
    Write-Host "[2/5] 正在下载: $downloadUrl" -ForegroundColor Yellow

    try {
        Invoke-WebRequest -Uri $downloadUrl -OutFile $zipFile -TimeoutSec 120
    } catch {
        Write-Host "[ERROR] 下载失败: $_" -ForegroundColor Red
        Write-Host "[HINT] 如果网络受限，请手动下载并放置到: $InstallDir" -ForegroundColor Yellow
        Write-Host "[HINT] 下载地址: https://github.com/sebaxakerhtc/rdpwrap/releases" -ForegroundColor Yellow
        exit 1
    }

    # ========== 步骤2：解压安装 ==========
    Write-Host "[3/5] 正在解压安装..." -ForegroundColor Yellow

    $extractDir = Join-Path $tempDir "extracted"
    Expand-Archive -Path $zipFile -DestinationPath $extractDir -Force

    # 停止远程桌面服务（安装前需要）
    Write-Host "[INFO] 停止 TermService 服务..." -ForegroundColor Gray
    Stop-Service -Name TermService -Force -ErrorAction SilentlyContinue
    Start-Sleep -Seconds 2

    # 创建安装目录
    if (-not (Test-Path $InstallDir)) {
        New-Item -ItemType Directory -Path $InstallDir -Force | Out-Null
    }

    # 复制文件到安装目录
    $sourceFiles = Get-ChildItem -Path $extractDir -Recurse -File
    foreach ($file in $sourceFiles) {
        $destPath = Join-Path $InstallDir $file.Name
        Copy-Item -Path $file.FullName -Destination $destPath -Force
    }

    # ========== 步骤3：执行安装 ==========
    Write-Host "[4/5] 正在执行安装..." -ForegroundColor Yellow

    $installBat = Join-Path $InstallDir "install.bat"
    if (Test-Path $installBat) {
        $process = Start-Process -FilePath "cmd.exe" -ArgumentList "/c `"$installBat`"" -WorkingDirectory $InstallDir -Wait -PassThru -WindowStyle Hidden
        if ($process.ExitCode -ne 0) {
            Write-Host "[WARN] install.bat 返回非零退出码: $($process.ExitCode)" -ForegroundColor Yellow
        }
    } else {
        Write-Host "[WARN] 未找到 install.bat，尝试手动注册..." -ForegroundColor Yellow

        # 手动注册 rdpwrap.dll
        $rdpwrapDll = Join-Path $InstallDir "rdpwrap.dll"
        if (Test-Path $rdpwrapDll) {
            regsvr32 /s $rdpwrapDll
        }
    }

    # ========== 步骤4：更新配置文件 ==========
    Write-Host "[5/5] 正在更新配置文件..." -ForegroundColor Yellow

    $iniUrl = "https://raw.githubusercontent.com/sebaxakerhtc/rdpwrap/MOD/res/rdpwrap.ini"
    $iniFile = Join-Path $InstallDir "rdpwrap.ini"

    try {
        Invoke-WebRequest -Uri $iniUrl -OutFile $iniFile -TimeoutSec 30
        Write-Host "[INFO] 配置文件已更新" -ForegroundColor Green
    } catch {
        Write-Host "[WARN] 无法下载最新配置文件，使用安装包中的默认配置" -ForegroundColor Yellow
    }

    # 重启远程桌面服务
    Write-Host "[INFO] 启动 TermService 服务..." -ForegroundColor Gray
    Start-Service -Name TermService -ErrorAction SilentlyContinue
    Start-Sleep -Seconds 2

    # ========== 配置组策略（允许多用户） ==========
    Write-Host "[INFO] 配置多用户远程桌面策略..." -ForegroundColor Gray

    # 取消限制单个会话
    reg add "HKLM\SYSTEM\CurrentControlSet\Control\Terminal Server" /v fSingleSessionPerUser /t REG_DWORD /d 0 /f | Out-Null

    # 允许远程连接
    reg add "HKLM\SYSTEM\CurrentControlSet\Control\Terminal Server" /v fDenyTSConnections /t REG_DWORD /d 0 /f | Out-Null

    # 开启防火墙远程桌面规则
    netsh advfirewall firewall set rule group="remote desktop" new enable=yes 2>$null | Out-Null

    # ========== 完成 ==========
    Write-Host ""
    Write-Host "========================================" -ForegroundColor Green
    Write-Host " RDP Wrapper 安装完成！" -ForegroundColor Green
    Write-Host "========================================" -ForegroundColor Green
    Write-Host ""
    Write-Host " 安装路径: $InstallDir" -ForegroundColor White
    Write-Host " 验证工具: $InstallDir\RDPConf.exe" -ForegroundColor White
    Write-Host ""
    Write-Host " 提示：运行 RDPConf.exe 查看状态，全绿表示安装成功。" -ForegroundColor Cyan
    Write-Host ""

    exit 0

} catch {
    Write-Host "[ERROR] 安装过程出错: $_" -ForegroundColor Red
    exit 1

} finally {
    # 清理临时文件
    if (Test-Path $tempDir) {
        Remove-Item -Recurse -Force $tempDir -ErrorAction SilentlyContinue
    }
}
