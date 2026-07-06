param(
    [Parameter(Mandatory = $true)]
    [string]$RepoPath,
    [string]$PackageName = '',
    [int]$MaxWaitSeconds = 120,
    [int]$PollIntervalSeconds = 10
)

$ErrorActionPreference = 'Stop'

function Get-PackagistCredentials {
    $username = $env:WELINE_PACKAGIST_USERNAME
    $token = $env:WELINE_PACKAGIST_API_TOKEN

    if (-not $username) {
        $username = $env:PACKAGIST_USERNAME
    }
    if (-not $token) {
        $token = $env:PACKAGIST_API_TOKEN
    }

    $localConfig = Join-Path $PSScriptRoot 'packagist.local.json'
    if ((-not $username -or -not $token) -and (Test-Path -LiteralPath $localConfig)) {
        $config = Get-Content -LiteralPath $localConfig -Raw -Encoding UTF8 | ConvertFrom-Json
        if (-not $username) {
            $username = $config.username
        }
        if (-not $token) {
            $token = $config.token
        }
    }

    if (-not $username -or -not $token) {
        throw 'Packagist credentials are missing. Set WELINE_PACKAGIST_USERNAME/WELINE_PACKAGIST_API_TOKEN, or configure username/token in dev/tools/fencang/packagist.local.json.'
    }

    return @{
        Username = $username
        Token    = $token
    }
}
function Get-ComposerPackageName {
    param([string]$Path)

    $composerJson = Join-Path $Path 'composer.json'
    if (-not (Test-Path $composerJson)) {
        throw "composer.json is missing: $composerJson"
    }

    $raw = Get-Content $composerJson -Raw -Encoding UTF8
    if ($raw -match '"name"\s*:\s*"([^"]+)"') {
        return $Matches[1]
    }

    throw "Unable to parse package name from composer.json: $composerJson"
}

function Get-GithubRepositoryUrl {
    param([string]$Path)

    Push-Location $Path
    try {
        $remoteNames = @(git remote)
        if ($remoteNames -notcontains 'github') {
            throw 'github remote is missing'
        }

        $url = git remote get-url github
        $url = $url.Trim()
        if ($url -match '^git@github\.com:(.+?)(?:\.git)?$') {
            return "https://github.com/$($Matches[1])"
        }
        if ($url -match '^https://(?:[^/@]+@)?github\.com/(.+?)(?:\.git)?$') {
            return "https://github.com/$($Matches[1])"
        }

        return ($url -replace '^(https://)[^/@]+@', '$1') -replace '\.git$', ''
    }
    finally {
        Pop-Location
    }
}

function Invoke-PackagistUpdate {
    param(
        [string]$RepositoryUrl,
        [hashtable]$Credentials
    )

    $headers = @{
        Authorization  = "Bearer $($Credentials.Username):$($Credentials.Token)"
        'Content-Type' = 'application/json'
    }
    $body = @{ repository = @{ url = $RepositoryUrl } } | ConvertTo-Json -Compress

    $response = Invoke-RestMethod -Method Post -Uri 'https://packagist.org/api/update-package' -Headers $headers -Body $body
    return $response
}

function Test-PackagistTagVisible {
    param(
        [string]$PackageName,
        [string]$Tag
    )

    $encoded = [uri]::EscapeDataString($PackageName)
    $json = Invoke-RestMethod -Method Get -Uri "https://packagist.org/packages/$encoded.json"
    return $null -ne $json.package.versions.$Tag
}

$credentials = Get-PackagistCredentials
if (-not $PackageName) {
    $PackageName = Get-ComposerPackageName -Path $RepoPath
}
$repositoryUrl = Get-GithubRepositoryUrl -Path $RepoPath

$update = Invoke-PackagistUpdate -RepositoryUrl $repositoryUrl -Credentials $credentials

return [ordered]@{
    PackageName    = $PackageName
    RepositoryUrl  = $repositoryUrl
    PackagistJobs  = $update.jobs
    PackagistStatus = $update.status
}
