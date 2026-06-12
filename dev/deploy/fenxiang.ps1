param(
    [string]$RepoRoot = "E:\WelineFramework\DEV-workspace",
    [string]$SitesRoot = "E:\WelineFramework",
    [string]$UpdateBranch = "dev",
    [string[]]$Remotes = @("origin", "github"),
    [string]$CommitMessage = "",
    [string]$Php = "php",
    [switch]$DryRun,
    [switch]$AllowSensitivePaths
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function Write-Step {
    param([string]$Message)
    Write-Host ""
    Write-Host "==> $Message"
}

function Invoke-Checked {
    param(
        [string]$FilePath,
        [string[]]$Arguments,
        [string]$WorkingDirectory
    )

    $display = "$FilePath $($Arguments -join ' ')"
    if ($DryRun) {
        Write-Host "[dry-run] $WorkingDirectory> $display"
        return
    }

    Push-Location -LiteralPath $WorkingDirectory
    try {
        & $FilePath @Arguments
        if ($LASTEXITCODE -ne 0) {
            throw "Command failed with exit code ${LASTEXITCODE}: $display"
        }
    }
    finally {
        Pop-Location
    }
}

function Resolve-SafeChildPath {
    param(
        [string]$Root,
        [string]$Child
    )

    $rootFull = [System.IO.Path]::GetFullPath($Root).TrimEnd('\')
    $pathFull = [System.IO.Path]::GetFullPath((Join-Path $Root $Child))
    if (-not $pathFull.StartsWith($rootFull + '\', [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Refusing path outside root: $pathFull"
    }
    return $pathFull
}

function Resolve-WelineSiteRoot {
    param([string]$SitePath)

    $directEntry = Join-Path $SitePath "bin\w"
    if (Test-Path -LiteralPath $directEntry -PathType Leaf) {
        return $SitePath
    }

    $nestedRoot = Join-Path $SitePath "weline"
    $nestedEntry = Join-Path $nestedRoot "bin\w"
    if (Test-Path -LiteralPath $nestedEntry -PathType Leaf) {
        return $nestedRoot
    }

    throw "Missing Weline entry file in site: $SitePath or $nestedRoot"
}

$repoFull = [System.IO.Path]::GetFullPath($RepoRoot)
if (-not (Test-Path -LiteralPath (Join-Path $repoFull ".git") -PathType Container)) {
    throw "RepoRoot is not a git repository: $repoFull"
}

$siteNames = @(
    "Framework-Office-A2a-Site",
    "Framework-Office-App-Site",
    "Framework-Office-Bbs-Site",
    "Framework-Office-Site",
    "Framework-Office-Skill-Site",
    "Framework-Office-WeShop-Site"
)

$sites = foreach ($name in $siteNames) {
    $path = Resolve-SafeChildPath -Root $SitesRoot -Child $name
    if (-not (Test-Path -LiteralPath $path -PathType Container)) {
        throw "Missing site directory: $path"
    }
    $workRoot = Resolve-WelineSiteRoot -SitePath $path
    [PSCustomObject]@{
        Name = $name
        SitePath = $path
        WorkRoot = $workRoot
    }
}

Write-Step "Checking git repository"
$branch = (& git -C $repoFull rev-parse --abbrev-ref HEAD).Trim()
if ($LASTEXITCODE -ne 0) {
    throw "Unable to resolve current branch in $repoFull"
}
if ($branch -eq "HEAD") {
    throw "Refusing to run on detached HEAD in $repoFull"
}
Write-Host "Repo: $repoFull"
Write-Host "Branch: $branch"

$remoteList = (& git -C $repoFull remote)
if ($LASTEXITCODE -ne 0) {
    throw "Unable to list git remotes in $repoFull"
}
$missingRemotes = @($Remotes | Where-Object { $remoteList -notcontains $_ })
if ($missingRemotes.Count -gt 0) {
    throw "Missing required git remote(s): $($missingRemotes -join ', ')"
}

$status = @(& git -C $repoFull status --porcelain)
if ($LASTEXITCODE -ne 0) {
    throw "Unable to read git status in $repoFull"
}

if ($status.Count -gt 0) {
    Write-Step "Staging and committing current worktree"
    Invoke-Checked -FilePath "git" -Arguments @("add", "-A") -WorkingDirectory $repoFull

    $stagedNames = @(& git -C $repoFull diff --cached --name-only)
    if ($LASTEXITCODE -ne 0) {
        throw "Unable to inspect staged files in $repoFull"
    }

    $sensitivePattern = '(^|/)(\.env|id_rsa|id_ed25519|[^/]+\.(pem|key|p12|pfx))$'
    $sensitiveHits = @($stagedNames | Where-Object { $_ -match $sensitivePattern })
    if (($sensitiveHits.Count -gt 0) -and (-not $AllowSensitivePaths)) {
        throw "Refusing to commit sensitive-looking path(s): $($sensitiveHits -join ', ')"
    }

    if ([string]::IsNullOrWhiteSpace($CommitMessage)) {
        $CommitMessage = "chore: fenxiang release $(Get-Date -Format 'yyyyMMdd-HHmm')"
    }

    Invoke-Checked -FilePath "git" -Arguments @("commit", "-m", $CommitMessage) -WorkingDirectory $repoFull
}
else {
    Write-Host "Worktree is clean; skipping commit."
}

Write-Step "Pushing current branch"
foreach ($remote in $Remotes) {
    Invoke-Checked -FilePath "git" -Arguments @("push", $remote, "HEAD") -WorkingDirectory $repoFull
}

Write-Step "Updating local framework sites"
$failedSites = @()
foreach ($site in $sites) {
    Write-Host ""
    Write-Host "Site: $($site.SitePath)"
    Write-Host "Work root: $($site.WorkRoot)"
    if ($DryRun) {
        Write-Host "[dry-run] $($site.WorkRoot)> $Php bin/w core:update -b $UpdateBranch"
        continue
    }

    Push-Location -LiteralPath $site.WorkRoot
    try {
        & $Php "bin/w" "core:update" "-b" $UpdateBranch
        if ($LASTEXITCODE -ne 0) {
            $failedSites += $site.SitePath
        }
    }
    finally {
        Pop-Location
    }
}

if ($failedSites.Count -gt 0) {
    throw "Site update failed for: $($failedSites -join ', ')"
}

Write-Step "Done"
Write-Host "Pushed $branch to: $($Remotes -join ', ')"
Write-Host "Updated branch '$UpdateBranch' in $($sites.Count) site(s)."
