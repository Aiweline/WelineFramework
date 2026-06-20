[CmdletBinding(PositionalBinding = $false)]
param(
    [Parameter(Position = 0)]
    [ValidateNotNullOrEmpty()]
    [string]$Branch = 'dev',
    [string]$CoreRepo = 'E:\WelineFramework\DEV-workspace',
    [string]$CommitMessage = '',
    [string[]]$IncludePaths = @(),
    [string[]]$Sites = @(
        'E:\WelineFramework\Framework-Official\A2A',
        'E:\WelineFramework\Framework-Official\App',
        'E:\WelineFramework\Framework-Official\Bbs',
        'E:\WelineFramework\Framework-Official\Official',
        'E:\WelineFramework\Framework-Official\Skill',
        'E:\WelineFramework\Framework-Official\Tools',
        'E:\WelineFramework\Framework-Official\WeShop'
    ),
    [switch]$SkipCommit,
    [switch]$SkipPush,
    [switch]$SkipSiteUpdate,
    [switch]$SkipWlsReload,
    [switch]$DryRun
)

$ErrorActionPreference = 'Stop'

function Set-FenxiangCommandPath {
    param([Parameter(Mandatory = $true)][string]$RepoRoot)

    $systemRoot = if ([string]::IsNullOrWhiteSpace($env:SystemRoot)) { 'C:\Windows' } else { $env:SystemRoot }
    $phpCommand = Get-Command php -ErrorAction SilentlyContinue
    $gitCommand = Get-Command git -ErrorAction SilentlyContinue
    $candidates = @(
        (Join-Path $systemRoot 'System32'),
        $systemRoot,
        (Join-Path $systemRoot 'System32\Wbem'),
        (Join-Path $systemRoot 'System32\WindowsPowerShell\v1.0'),
        (Join-Path $systemRoot 'System32\OpenSSH'),
        'C:\Program Files\Git\cmd',
        'C:\Program Files\Git\bin',
        'C:\Program Files\Git\usr\bin',
        (Join-Path $RepoRoot 'extend\server\php'),
        (Join-Path $RepoRoot 'bin')
    )

    if ($phpCommand -and $phpCommand.Source) {
        $candidates += Split-Path -Parent $phpCommand.Source
    }
    if ($gitCommand -and $gitCommand.Source) {
        $candidates += Split-Path -Parent $gitCommand.Source
    }

    $seen = @{}
    $normalized = @()
    foreach ($candidate in $candidates) {
        if ([string]::IsNullOrWhiteSpace($candidate)) {
            continue
        }
        $full = [System.IO.Path]::GetFullPath($candidate).TrimEnd('\')
        if (-not (Test-Path -LiteralPath $full -PathType Container)) {
            continue
        }
        $key = $full.ToLowerInvariant()
        if ($seen.ContainsKey($key)) {
            continue
        }
        $seen[$key] = $true
        $normalized += $full
    }

    if ($normalized.Count -eq 0) {
        throw 'Unable to build a usable PATH for fenxiang commands.'
    }

    $env:Path = $normalized -join ';'
    Write-Host "Fenxiang command PATH normalized with $($normalized.Count) entries."
}

function Invoke-Checked {
    param(
        [Parameter(Mandatory = $true)][string]$WorkingDirectory,
        [Parameter(Mandatory = $true)][string]$FilePath,
        [Parameter(Mandatory = $true)][string[]]$Arguments,
        [switch]$AllowFailure
    )

    Write-Host "[$WorkingDirectory] $FilePath $($Arguments -join ' ')"
    if ($DryRun) {
        return @{ ExitCode = 0; Output = @() }
    }

    Push-Location -LiteralPath $WorkingDirectory
    $oldPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $output = & $FilePath @Arguments 2>&1
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $oldPreference
        Pop-Location
    }

    if ($output) {
        $output | ForEach-Object { Write-Host $_ }
    }

    if ($exitCode -ne 0 -and -not $AllowFailure) {
        throw "Command failed with exit code $exitCode`: $FilePath $($Arguments -join ' ')"
    }

    return @{ ExitCode = $exitCode; Output = $output }
}

function Invoke-Git {
    param(
        [Parameter(Mandatory = $true)][string]$Repo,
        [Parameter(Mandatory = $true)][string[]]$Arguments,
        [switch]$AllowFailure
    )

    return Invoke-Checked -WorkingDirectory $Repo -FilePath 'git' -Arguments $Arguments -AllowFailure:$AllowFailure
}

function Get-GitOutput {
    param(
        [Parameter(Mandatory = $true)][string]$Repo,
        [Parameter(Mandatory = $true)][string[]]$Arguments
    )

    Push-Location -LiteralPath $Repo
    $oldPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $output = & git @Arguments 2>&1
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $oldPreference
        Pop-Location
    }

    if ($exitCode -ne 0) {
        throw "Git command failed with exit code $exitCode`: git $($Arguments -join ' ')"
    }

    return @($output) -join "`n"
}

function Resolve-SiteProjectRoot {
    param([Parameter(Mandatory = $true)][string]$SiteBase)

    $rootBin = Join-Path $SiteBase 'bin\w'
    if (Test-Path -LiteralPath $rootBin) {
        return $SiteBase
    }

    $nested = Join-Path $SiteBase 'weline'
    $nestedBin = Join-Path $nested 'bin\w'
    if (Test-Path -LiteralPath $nestedBin) {
        return $nested
    }

    return $null
}

function Test-WelineCommandOutputFailure {
    param([object[]]$Output)

    if ($null -eq $Output) {
        return $false
    }

    $failureRegexes = @(
        '\u6ca1\u6709\u627e\u5230\u5339\u914d\u7684\u547d\u4ee4',
        '\u8bf7\u5148\u66f4\u65b0\u6a21\u5757',
        'Command registry update failed',
        'Fatal error',
        'Parse error',
        'Uncaught '
    )

    foreach ($line in $Output) {
        $text = [string]$line
        foreach ($pattern in $failureRegexes) {
            if ($text -match $pattern) {
                return $true
            }
        }
    }

    return $false
}

function Assert-NoSensitiveCoreChanges {
    param(
        [Parameter(Mandatory = $true)][string]$Repo,
        [string[]]$Paths = @()
    )

    $statusArgs = @('status', '--porcelain')
    if ($Paths.Count -gt 0) {
        $statusArgs += '--'
        $statusArgs += $Paths
    }
    $status = Get-GitOutput -Repo $Repo -Arguments $statusArgs
    if ([string]::IsNullOrWhiteSpace($status)) {
        return
    }

    $blocked = @()
    foreach ($line in ($status -split "`n")) {
        if ([string]::IsNullOrWhiteSpace($line) -or $line.Length -lt 4) {
            continue
        }

        $path = $line.Substring(3).Trim().Trim('"')
        $normalized = $path -replace '\\', '/'
        if (
            $normalized -in @('.env', 'app/.env', 'app/etc/env.php', 'dev/deploy/.config') -or
            $normalized -match '(^|/)(id_rsa|id_dsa|id_ecdsa|id_ed25519)$' -or
            $normalized -match '\.(pem|key|pfx|p12)$'
        ) {
            $blocked += $path
        }
    }

    if ($blocked.Count -gt 0) {
        throw "Refusing to commit sensitive/protected files: $($blocked -join ', ')"
    }
}

if (-not (Test-Path -LiteralPath (Join-Path $CoreRepo '.git'))) {
    throw "Core repo is not a git repository: $CoreRepo"
}

Set-FenxiangCommandPath -RepoRoot $CoreRepo

$currentBranch = (Get-GitOutput -Repo $CoreRepo -Arguments @('branch', '--show-current')).Trim()
if ($currentBranch -ne $Branch) {
    throw "Current core branch is '$currentBranch', but fenxiang target branch is '$Branch'. Switch branch or pass -Branch explicitly."
}

Write-Host "Fenxiang core repo: $CoreRepo"
Write-Host "Fenxiang branch: $Branch"
Write-Host "Fenxiang dry-run: $DryRun"

$remotes = (Get-GitOutput -Repo $CoreRepo -Arguments @('remote')).Split("`n") | ForEach-Object { $_.Trim() } | Where-Object { $_ -ne '' }
if (-not ($remotes -contains 'origin')) {
    throw "Core repo must have remote 'origin'."
}

if (-not $SkipCommit) {
    Assert-NoSensitiveCoreChanges -Repo $CoreRepo -Paths $IncludePaths
    $statusArgs = @('status', '--porcelain')
    if ($IncludePaths.Count -gt 0) {
        $statusArgs += '--'
        $statusArgs += $IncludePaths
    }
    $status = Get-GitOutput -Repo $CoreRepo -Arguments $statusArgs
    if (-not [string]::IsNullOrWhiteSpace($status)) {
        if ($IncludePaths.Count -gt 0) {
            Invoke-Git -Repo $CoreRepo -Arguments (@('add', '-A', '--') + $IncludePaths) | Out-Null
        } else {
            Invoke-Git -Repo $CoreRepo -Arguments @('add', '-A') | Out-Null
        }
        Invoke-Git -Repo $CoreRepo -Arguments @('diff', '--cached', '--check') | Out-Null
        if ([string]::IsNullOrWhiteSpace($CommitMessage)) {
            $CommitMessage = 'core: 分项同步核心更新'
        }
        Invoke-Git -Repo $CoreRepo -Arguments @('commit', '-m', $CommitMessage) | Out-Null
    } else {
        Write-Host 'Core repo has no local changes; commit skipped.'
    }
}

if (-not $SkipPush) {
    Invoke-Git -Repo $CoreRepo -Arguments @('push', 'origin', "HEAD:$Branch") | Out-Null
    if ($remotes -contains 'github') {
        Invoke-Git -Repo $CoreRepo -Arguments @('push', 'github', "HEAD:$Branch") | Out-Null
    } else {
        Write-Warning "Remote 'github' is not configured; pushed origin only."
    }
}

if ($SkipSiteUpdate) {
    Write-Host 'Site update skipped by -SkipSiteUpdate.'
    exit 0
}

$failures = @()
foreach ($site in $Sites) {
    $projectRoot = Resolve-SiteProjectRoot -SiteBase $site
    if ($null -eq $projectRoot) {
        $failures += "$site => bin\w not found"
        Write-Warning "Skipping $site because bin\w was not found."
        continue
    }

    $result = Invoke-Checked -WorkingDirectory $projectRoot -FilePath 'php' -Arguments @('bin/w', 'core:update', '-b', $Branch) -AllowFailure
    if (($result.ExitCode -ne 0) -or (Test-WelineCommandOutputFailure -Output $result.Output)) {
        $failures += "$projectRoot => core:update failed"
        continue
    }

    if (-not $SkipWlsReload) {
        $reload = Invoke-Checked -WorkingDirectory $projectRoot -FilePath 'php' -Arguments @('bin/w', 'server:reload', '-n') -AllowFailure
        if (($reload.ExitCode -ne 0) -or (Test-WelineCommandOutputFailure -Output $reload.Output)) {
            $failures += "$projectRoot => WLS reload failed"
        }
    }
}

if ($failures.Count -gt 0) {
    Write-Error "Fenxiang completed with site update failures: $($failures -join '; ')"
    exit 1
}

Write-Host 'Fenxiang completed successfully.'
