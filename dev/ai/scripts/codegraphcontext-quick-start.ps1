<#
.SYNOPSIS
Bootstraps CodeGraphContext for this repository and registers the Codex MCP server.

.EXAMPLES
powershell -ExecutionPolicy Bypass -File dev/ai/scripts/codegraphcontext-quick-start.ps1
powershell -ExecutionPolicy Bypass -File dev/ai/scripts/codegraphcontext-quick-start.ps1 -IndexProfile none
powershell -ExecutionPolicy Bypass -File dev/ai/scripts/codegraphcontext-quick-start.ps1 -IndexPaths app/code/Weline/Framework/Router
#>

param(
    [ValidateSet("minimal", "framework", "none")]
    [string] $IndexProfile = "minimal",

    [string[]] $IndexPaths = @(),

    [switch] $SkipInstall,
    [switch] $SkipCodexMcp,
    [switch] $SkipIndex,
    [switch] $ForceIndex,

    [int] $IndexTimeoutSec = 900
)

$ErrorActionPreference = "Stop"

function Write-Step {
    param([string] $Message)
    Write-Host "[cgc-quick-start] $Message"
}

function Invoke-Native {
    param(
        [Parameter(Mandatory = $true)][string] $FilePath,
        [string[]] $Arguments = @(),
        [string] $WorkingDirectory = $RepoRoot,
        [switch] $AllowFailure
    )

    Write-Step ("run: {0} {1}" -f $FilePath, ($Arguments -join " "))
    & $FilePath @Arguments
    $exitCode = $LASTEXITCODE
    if ($exitCode -ne 0 -and -not $AllowFailure) {
        throw "Command failed with exit code ${exitCode}: $FilePath $($Arguments -join ' ')"
    }
}

function Get-RepoRoot {
    $candidate = Resolve-Path (Join-Path $PSScriptRoot "..\..\..")
    return $candidate.Path
}

function Get-PythonExe {
    $python = Get-Command python -ErrorAction SilentlyContinue
    if ($python) {
        return $python.Source
    }

    $py = Get-Command py -ErrorAction SilentlyContinue
    if ($py) {
        return $py.Source
    }

    throw "Python was not found. Install Python 3 first, then rerun this script."
}

function Get-CodeGraphContextExe {
    $cmd = Get-Command codegraphcontext -ErrorAction SilentlyContinue
    if ($cmd) {
        return $cmd.Source
    }

    $candidates = Get-ChildItem -Path (Join-Path $env:APPDATA "Python") -Recurse -Filter "codegraphcontext.exe" -ErrorAction SilentlyContinue |
        Sort-Object LastWriteTime -Descending

    if ($candidates) {
        return $candidates[0].FullName
    }

    throw "codegraphcontext.exe was not found after install."
}

function Get-CodexCli {
    $localBin = Join-Path $env:LOCALAPPDATA "OpenAI\Codex\bin"
    if (Test-Path -LiteralPath $localBin) {
        $candidate = Get-ChildItem -LiteralPath $localBin -Recurse -Filter "codex.exe" -ErrorAction SilentlyContinue |
            Sort-Object LastWriteTime -Descending |
            Select-Object -First 1
        if ($candidate) {
            return $candidate.FullName
        }
    }

    $cmd = Get-Command codex -ErrorAction SilentlyContinue
    if ($cmd) {
        return $cmd.Source
    }

    return $null
}

function Add-UserPath {
    param([string] $Directory)

    if (-not (Test-Path -LiteralPath $Directory)) {
        return
    }

    $userPath = [Environment]::GetEnvironmentVariable("Path", "User")
    if (-not $userPath) {
        $userPath = ""
    }

    $parts = $userPath -split ";" | Where-Object { $_ }
    if ($parts -contains $Directory) {
        return
    }

    $newPath = if ($userPath.Trim()) { "$userPath;$Directory" } else { $Directory }
    [Environment]::SetEnvironmentVariable("Path", $newPath, "User")
    Write-Step "added to user PATH: $Directory"
}

function Repair-CgcEnvEncoding {
    param([string[]] $Paths)

    foreach ($path in $Paths) {
        if (-not (Test-Path -LiteralPath $path)) {
            continue
        }

        $bytes = [IO.File]::ReadAllBytes($path)
        $text = [Text.Encoding]::Default.GetString($bytes)
        $text = [regex]::Replace($text, "[^\x00-\x7F]", "-")
        [IO.File]::WriteAllText($path, $text, [Text.ASCIIEncoding]::new())
    }
}

function Add-MissingLines {
    param(
        [string] $Path,
        [string[]] $Lines
    )

    if (-not (Test-Path -LiteralPath $Path)) {
        New-Item -ItemType File -Path $Path -Force | Out-Null
    }

    $existing = Get-Content -LiteralPath $Path -ErrorAction SilentlyContinue
    $append = @()
    foreach ($line in $Lines) {
        if ($existing -notcontains $line) {
            $append += $line
        }
    }

    if ($append.Count -gt 0) {
        Add-Content -LiteralPath $Path -Value $append -Encoding UTF8
    }
}

function Set-LocalDatabaseConfig {
    param([string] $CodeGraphContextDir)

    New-Item -ItemType Directory -Path $CodeGraphContextDir -Force | Out-Null
    $configYaml = Join-Path $CodeGraphContextDir "config.yaml"
    [IO.File]::WriteAllText($configYaml, "database: kuzudb`n", [Text.UTF8Encoding]::new($false))

    $ignorePath = Join-Path $CodeGraphContextDir ".cgcignore"
    Add-MissingLines -Path $ignorePath -Lines @(
        "# Project-specific CodeGraphContext ignores",
        ".cursor/",
        ".claude/",
        ".codex/",
        ".playwright-mcp/",
        "node_modules/",
        "**/node_modules/",
        "vendor/",
        "var/",
        "tmp/",
        "dev/ai/codex/",
        "dev/phpunit/",
        "tests/phpunit/coverage-html/",
        "**/test/",
        "**/tests/",
        "**/Test/",
        "*.md",
        "*.json",
        "*.html",
        "*.css",
        "*.txt",
        "*.sh",
        "*.ps1",
        "*.bat",
        "*.yaml",
        "*.yml",
        "*.env",
        "app/code/Weline/AutoLeadAgent/wasm/",
        "app/code/Weline/AutoLeadAgent/weline-browser-mcp/",
        "app/code/Weline/AutoLeadAgent/browser-extension/",
        "app/code/Weline/AutoLeadAgent/browser-extension-backup/",
        "extend/server/",
        "pub/",
        "generated/",
        "memory/",
        "tests/e2e/"
    )

    $gitExclude = Join-Path $RepoRoot ".git\info\exclude"
    if (Test-Path -LiteralPath $gitExclude) {
        Add-MissingLines -Path $gitExclude -Lines @(".codegraphcontext/", ".cgcignore")
    }
}

function Configure-CodeGraphContext {
    param([string] $CgcExe)

    Invoke-Native $CgcExe @("config", "db", "kuzudb")
    Invoke-Native $CgcExe @("context", "mode", "per-repo")
    Invoke-Native $CgcExe @("config", "set", "INDEX_SOURCE", "false")
    Invoke-Native $CgcExe @("config", "set", "INDEX_VARIABLES", "false")
    Invoke-Native $CgcExe @("config", "set", "ENABLE_APP_LOGS", "CRITICAL")
    Invoke-Native $CgcExe @("config", "set", "IGNORE_DIRS", "node_modules,vendor,var,tmp,cache,venv,.venv,env,.env,dist,build,target,out,.git,.idea,.vscode,__pycache__")

    Repair-CgcEnvEncoding @(
        (Join-Path $env:USERPROFILE ".codegraphcontext\.env"),
        (Join-Path $RepoRoot ".codegraphcontext\.env")
    )
}

function Configure-CodexMcp {
    param(
        [string] $CodexCli,
        [string] $CgcExe
    )

    if (-not $CodexCli) {
        Write-Step "Codex CLI not found; skip MCP registration."
        return
    }

    $getOutput = & $CodexCli mcp get CodeGraphContext 2>$null
    $exists = ($LASTEXITCODE -eq 0)
    $needsUpdate = $true
    if ($exists -and ($getOutput -join "`n") -match [regex]::Escape($CgcExe)) {
        $needsUpdate = $false
    }

    if ($needsUpdate -and $exists) {
        Invoke-Native $CodexCli @("mcp", "remove", "CodeGraphContext")
    }

    if ($needsUpdate) {
        Invoke-Native $CodexCli @("mcp", "add", "CodeGraphContext", "--", $CgcExe, "mcp", "start")
    }
}

function Get-IndexTargets {
    if ($IndexPaths.Count -gt 0) {
        return $IndexPaths
    }

    if ($IndexProfile -eq "none") {
        return @()
    }

    if ($IndexProfile -eq "minimal") {
        return @(
            "app/code/Weline/Framework/Router",
            "app/code/Weline/Framework/Http",
            "app/code/Weline/Framework/App",
            "app/code/Weline/Framework/Module"
        )
    }

    return @(
        "app/code/Weline/Framework/Router",
        "app/code/Weline/Framework/Http",
        "app/code/Weline/Framework/App",
        "app/code/Weline/Framework/Module",
        "app/code/Weline/Framework/Event",
        "app/code/Weline/Framework/Hook",
        "app/code/Weline/Framework/Manager",
        "app/code/Weline/Framework/Database",
        "app/code/Weline/Server/Console",
        "app/code/Weline/Server/Service"
    )
}

function Invoke-IndexTarget {
    param(
        [string] $CgcExe,
        [string] $Target
    )

    $resolvedTarget = if ([IO.Path]::IsPathRooted($Target)) { $Target } else { Join-Path $RepoRoot $Target }
    if (-not (Test-Path -LiteralPath $resolvedTarget)) {
        Write-Step "skip missing index target: $Target"
        return
    }

    $logRoot = Join-Path $RepoRoot ".codegraphcontext\logs"
    New-Item -ItemType Directory -Path $logRoot -Force | Out-Null
    $safeName = ($Target -replace "[:\\/]+", "_").Trim("_")
    if (-not $safeName) {
        $safeName = "repo"
    }
    $stdout = Join-Path $logRoot "$safeName.out.log"
    $stderr = Join-Path $logRoot "$safeName.err.log"
    Remove-Item -LiteralPath $stdout, $stderr -ErrorAction SilentlyContinue

    $args = @("index")
    if ($ForceIndex) {
        $args += "--force"
    }
    $args += $resolvedTarget

    Write-Step "index target: $resolvedTarget"
    $process = Start-Process -FilePath $CgcExe -ArgumentList $args -WorkingDirectory $RepoRoot -RedirectStandardOutput $stdout -RedirectStandardError $stderr -PassThru -WindowStyle Hidden
    $completed = $process.WaitForExit($IndexTimeoutSec * 1000)
    if (-not $completed) {
        Stop-Process -Id $process.Id -Force -ErrorAction SilentlyContinue
        throw "Index timed out after ${IndexTimeoutSec}s for $resolvedTarget. Logs: $stdout, $stderr"
    }

    $process.Refresh()
    if ($process.ExitCode -ne 0) {
        throw "Index failed for $resolvedTarget with exit code $($process.ExitCode). Logs: $stdout, $stderr"
    }
}

$RepoRoot = Get-RepoRoot
Set-Location $RepoRoot

Write-Step "repo root: $RepoRoot"
$pythonExe = Get-PythonExe
Write-Step "python: $pythonExe"

if (-not $SkipInstall) {
    Invoke-Native $pythonExe @("-m", "pip", "install", "--user", "--upgrade", "codegraphcontext", "tree-sitter", "tree-sitter-language-pack")
}

$cgcExe = Get-CodeGraphContextExe
Write-Step "codegraphcontext: $cgcExe"
Add-UserPath (Split-Path -Parent $cgcExe)

$localCgcDir = Join-Path $RepoRoot ".codegraphcontext"
Set-LocalDatabaseConfig $localCgcDir
Configure-CodeGraphContext $cgcExe

if (-not $SkipCodexMcp) {
    $codexCli = Get-CodexCli
    if ($codexCli) {
        Write-Step "codex: $codexCli"
    }
    Configure-CodexMcp -CodexCli $codexCli -CgcExe $cgcExe
}

Invoke-Native $cgcExe @("doctor")

if (-not $SkipIndex) {
    foreach ($target in Get-IndexTargets) {
        Invoke-IndexTarget -CgcExe $cgcExe -Target $target
    }
}

Invoke-Native $cgcExe @("stats")
Write-Step "done. Restart Codex after the first run so the CodeGraphContext MCP server is loaded."
