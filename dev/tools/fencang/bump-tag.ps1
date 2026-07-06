param(
    [string]$CurrentTag
)

function Get-NextWelineTag {
    param([string]$Tag)

    $normalized = $Tag.Trim()
    if ($normalized.StartsWith('v')) {
        $normalized = $normalized.Substring(1)
    }

    $parts = $normalized.Split('.')
    if ($parts.Count -ne 3) {
        throw "Tag must be vMAJOR.MINOR.PATCH (three segments), got: $Tag"
    }

    $major = [int]$parts[0]
    $minor = [int]$parts[1]
    $patch = [int]$parts[2]

    $patch += 1
    if ($patch -gt 9) {
        $patch = 0
        $minor += 1
    }
    if ($minor -gt 9) {
        $minor = 0
        $major += 1
    }

    return "v$major.$minor.$patch"
}

function Get-LatestRepoTag {
    param(
        [string]$RepoPath,
        [switch]$FetchRemotes
    )

    Push-Location $RepoPath
    try {
        if ($FetchRemotes) {
            git fetch --all --tags 2>$null | Out-Null
        }
        $tags = git tag --sort=-v:refname 2>$null
        if (-not $tags) {
            return $null
        }
        foreach ($t in $tags) {
            if ($t -match '^v\d+\.\d+\.\d+$') {
                return $t
            }
        }
        return $null
    }
    finally {
        Pop-Location
    }
}

if ($PSBoundParameters.ContainsKey('CurrentTag')) {
    Write-Output (Get-NextWelineTag -Tag $CurrentTag)
}
