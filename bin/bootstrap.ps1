# Windows 一键安装引导（无需 bash）。
# 推荐（与 Linux 相同，需 Git 自带 bash）：
#   curl.exe -fsSL https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.sh | bash -s -- -b dev -y
# 纯 PowerShell（尾部 -b -y）：
#   $f="$env:TEMP\weline-bootstrap.ps1"; irm 'https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.ps1' -OutFile $f; & $f -b dev -y
# 更短（参数写在环境变量，配合 irm | iex）：
#   $env:WELINE_INSTALL='-b dev -y'; irm 'https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.ps1' | iex

param(
    [Alias('b')]
    [string]$Branch = $(if ($env:WELINE_BRANCH) { $env:WELINE_BRANCH } else { 'master' }),
    [Alias('y')]
    [switch]$Yes,
    [Alias('f')]
    [switch]$Force,
    [switch]$PathOnly,
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]]$Remaining
)

function Import-WelineInstallEnvironmentArgs {
    if ($PSBoundParameters.Count -gt 0) {
        return
    }
    $raw = $env:WELINE_INSTALL
    if ([string]::IsNullOrWhiteSpace($raw)) {
        return
    }

    $tokens = @($raw -split '\s+' | Where-Object { -not [string]::IsNullOrWhiteSpace($_) })
    $extra = [System.Collections.Generic.List[string]]::new()
    $i = 0
    while ($i -lt $tokens.Count) {
        $token = $tokens[$i]
        switch ($token) {
            '-b' {
                $i++
                if ($i -lt $tokens.Count) { $script:Branch = $tokens[$i] }
            }
            { $_ -in '-y', '--yes' } { $script:Yes = $true }
            { $_ -in '-f', '--force' } { $script:Force = $true }
            '--path-only' { $script:PathOnly = $true }
            default { $extra.Add($token) }
        }
        $i++
    }
    if ($extra.Count -gt 0) {
        $script:Remaining = @($extra)
    }
}

Import-WelineInstallEnvironmentArgs

function Get-InstallBatArgumentList {
    $installArgs = @()
    if ($PathOnly) { $installArgs += '--path-only' }
    if ($Force) { $installArgs += '-f' }
    if ($Yes) { $installArgs += '-y' }
    if ($Remaining) { $installArgs += $Remaining }
    return $installArgs
}

$InstallBatArgs = Get-InstallBatArgumentList

$RepoUrl = if ($env:WELINE_REPO_URL) { $env:WELINE_REPO_URL } else { "https://gitee.com/aiweline/WelineFramework.git" }
$InstallDir = "weline"

$scriptPath = $null
try {
    $scriptPath = $MyInvocation.MyCommand.Path
} catch {
    $scriptPath = $null
}

if ([string]::IsNullOrWhiteSpace($scriptPath)) {
    try {
        $scriptPath = $PSCommandPath
    } catch {
        $scriptPath = $null
    }
}

if (-not [string]::IsNullOrWhiteSpace($scriptPath)) {
    $ScriptDir = Split-Path -Parent $scriptPath
    $Root = Split-Path -Parent $ScriptDir
} else {
    $Root = (Get-Location).Path
}
$RunPhp = Join-Path $Root "setup\server_installer\run.php"
$InstallBat = Join-Path $Root "bin\install.bat"

if ((Test-Path $RunPhp) -and (Test-Path $InstallBat)) {
    Write-Host "Already in WelineFramework. Running install..."
    Set-Location $Root
    & $InstallBat @InstallBatArgs
    exit $LASTEXITCODE
}

$TargetPath = Join-Path (Get-Location) $InstallDir
if (Test-Path (Join-Path $TargetPath ".git")) {
    Write-Host "Directory $InstallDir already exists. Updating..."
    Set-Location $TargetPath
    git fetch origin

    git show-ref --verify --quiet ("refs/heads/" + $Branch)
    if ($LASTEXITCODE -eq 0) {
        git checkout $Branch
    } else {
        git checkout -B $Branch ("origin/" + $Branch)
    }

    git pull --ff-only origin $Branch
    Set-Location (Get-Location)
} else {
    Write-Host "Cloning WelineFramework (branch: $Branch) into $InstallDir..."
    git clone -b $Branch $RepoUrl $InstallDir
}
Set-Location $TargetPath
& ".\bin\install.bat" @InstallBatArgs
exit $LASTEXITCODE
