[CmdletBinding()]
param(
    [ValidateSet("install", "status", "uninstall")]
    [string]$Action = "install",
    [ValidateSet("github", "gitee")]
    [string]$Source = "github",
    [string]$Branch = "main",
    [string]$InstallDir = $(if ($env:WELINE_MCP_INSTALL_DIR) {
        $env:WELINE_MCP_INSTALL_DIR
    } elseif ($env:LOCALAPPDATA) {
        Join-Path $env:LOCALAPPDATA "Weline\weline-codex-mcp"
    } else {
        Join-Path $HOME ".local\share\weline-codex-mcp"
    }),
    [switch]$PurgeData
)

$ErrorActionPreference = "Stop"
$markerName = ".weline-mcp-managed"
$tempRoot = $null

function Get-WelineSource {
    param([string]$Provider, [string]$Ref)
    $script:tempRoot = Join-Path ([System.IO.Path]::GetTempPath()) ("weline-mcp-" + [guid]::NewGuid())
    $archive = Join-Path $script:tempRoot "source.zip"
    $extract = Join-Path $script:tempRoot "extract"
    New-Item -ItemType Directory -Force -Path $extract | Out-Null
    $url = if ($Provider -eq "gitee") {
        "https://gitee.com/aiweline/weline-codex-mcp/repository/archive/$Ref.zip"
    } else {
        "https://github.com/Aiweline/Weline-Codex-Mcp/archive/refs/heads/$Ref.zip"
    }
    Invoke-WebRequest -UseBasicParsing -Uri $url -OutFile $archive
    Expand-Archive -Path $archive -DestinationPath $extract -Force
    $entry = Get-ChildItem -Path $extract -Recurse -File -Filter "learning-mcp" |
        Where-Object { $_.FullName -match "[\\/]bin[\\/]learning-mcp$" } |
        Select-Object -First 1
    if (-not $entry) {
        throw "Downloaded archive does not contain bin/learning-mcp."
    }
    return $entry.Directory.Parent.FullName
}

function Invoke-WelineInstaller {
    param([string]$Root, [string]$Mode, [switch]$Purge)
    $php = (Get-Command php -ErrorAction Stop).Source
    $args = @((Join-Path $Root "scripts\install.php"), $Mode)
    if ($Purge) {
        $args += "--purge-data"
    }
    & $php @args
    if ($LASTEXITCODE -ne 0) {
        throw "Weline MCP installer exited with code $LASTEXITCODE."
    }
}

try {
    $InstallDir = [System.IO.Path]::GetFullPath([Environment]::ExpandEnvironmentVariables($InstallDir))
    $marker = Join-Path $InstallDir $markerName

    if ($Action -eq "install") {
        $sourceRoot = Get-WelineSource -Provider $Source -Ref $Branch
        if ((Test-Path $InstallDir) -and -not (Test-Path $marker)) {
            throw "Refusing to replace unowned directory: $InstallDir"
        }

        $parent = Split-Path -Parent $InstallDir
        New-Item -ItemType Directory -Force -Path $parent | Out-Null
        $backup = "$InstallDir.backup.$PID"
        if (Test-Path $backup) {
            Remove-Item -Recurse -Force $backup
        }
        if (Test-Path $InstallDir) {
            Move-Item -Path $InstallDir -Destination $backup
        }

        try {
            Move-Item -Path $sourceRoot -Destination $InstallDir
            New-Item -ItemType File -Force -Path (Join-Path $InstallDir $markerName) | Out-Null
            Invoke-WelineInstaller -Root $InstallDir -Mode "install"
            if (Test-Path $backup) {
                Remove-Item -Recurse -Force $backup
            }
        } catch {
            if (Test-Path $InstallDir) {
                Remove-Item -Recurse -Force $InstallDir
            }
            if (Test-Path $backup) {
                Move-Item -Path $backup -Destination $InstallDir
            }
            throw
        }

        Write-Host "Managed installation: $InstallDir"
        Write-Host "Start a new Codex task to load Weline MCP 0.13.0."
        exit 0
    }

    $sourceRoot = if (Test-Path (Join-Path $InstallDir "scripts\install.php")) {
        $InstallDir
    } else {
        Get-WelineSource -Provider $Source -Ref $Branch
    }

    Invoke-WelineInstaller -Root $sourceRoot -Mode $Action -Purge:$PurgeData
    if ($Action -eq "uninstall" -and (Test-Path $marker)) {
        Remove-Item -Recurse -Force $InstallDir
        Write-Host "Removed managed installation: $InstallDir"
    }
    exit 0
} catch {
    Write-Error $_
    exit 1
} finally {
    if ($tempRoot -and (Test-Path $tempRoot)) {
        Remove-Item -Recurse -Force $tempRoot
    }
}
