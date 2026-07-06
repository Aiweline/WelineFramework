param(
    [string[]]$Modules = @(),
    [switch]$All,
    [switch]$DryRun,
    [string]$DevRoot = '',
    [string]$WelineRoot = '',
    [switch]$NoAutoClone
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'bump-tag.ps1')

$MirrorExcludeDirs = @('.git', 'vendor', '.idea', 'node_modules')
$MirrorExcludeFiles = @('.DS_Store')
$DefaultSplitRootName = -join ([char[]](0x6846, 0x67B6, 0x002D, 0x5206, 0x4ED3))
$GiteeOwner = 'aiweline'
$GithubOwner = 'Aiweline'

$ModuleRepoMap = @{
    'Framework'              = 'weline-framework'
    'Acl'                    = 'weline-module-acl'
    'Admin'                  = 'weline-module-admin'
    'Backend'                = 'weline-module-backend'
    'BackendActivity'        = 'weline-module-backend-activity'
    'CacheManager'           = 'weline-module-cache-manager'
    'CKEditorEditorManager'  = 'weline-module-ck-editor-editor-manager'
    'Component'              = 'weline-module-component'
    'Cron'                   = 'weline-module-cron'
    'DeveloperWorkspace'     = 'weline-module-developer-workspace'
    'Eav'                    = 'weline-module-eav'
    'EditorManager'          = 'weline-module-editor-manager'
    'ElFinderFileManager'    = 'weline-module-el-finder-file-manager'
    'FileManager'            = 'weline-module-file-manager'
    'Frontend'               = 'weline-module-frontend'
    'I18n'                   = 'weline-module-i18n'
    'Indexer'                = 'weline-module-indexer'
    'Installer'              = 'weline-module-installer'
    'Maintenance'            = 'weline-module-maintenance'
    'MediaManager'           = 'weline-module-media-manager'
    'ModuleManager'          = 'weline-module-module-manager'
    'ModuleRouter'           = 'weline-module-module-router'
    'Parts'                  = 'weline-module-parts'
    'Queue'                  = 'weline-module-queue'
    'Smtp'                   = 'weline-module-smtp'
    'SystemConfig'           = 'weline-module-system-config'
    'Taglib'                 = 'weline-module-taglib'
    'Theme'                  = 'weline-module-theme'
    'ThemeFancy'             = 'weline-module-theme-francy'
    'UrlManager'             = 'weline-module-url-manager'
    'WarmCache'              = 'weline-module-warm-cache'
    'WebsiteMonitoring'      = 'weline-module-website-monitoring'
}

function Join-FencangPath {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Base,
        [Parameter(ValueFromRemainingArguments = $true)]
        [string[]]$Children
    )

    $path = $Base
    if (-not $Children) {
        return $path
    }

    foreach ($child in $Children) {
        $path = Join-Path $path $child
    }
    return $path
}

function Get-FullPathSafe {
    param([string]$Path)
    return [System.IO.Path]::GetFullPath($Path)
}

function Get-PathComparison {
    if ($PSVersionTable.PSEdition -eq 'Desktop' -or ($null -ne (Get-Variable IsWindows -ErrorAction SilentlyContinue) -and $IsWindows)) {
        return [System.StringComparison]::OrdinalIgnoreCase
    }
    return [System.StringComparison]::Ordinal
}

$PathComparison = Get-PathComparison

function Test-PathInsideRoot {
    param(
        [string]$Root,
        [string]$Path
    )

    $rootFull = (Get-FullPathSafe -Path $Root).TrimEnd([System.IO.Path]::DirectorySeparatorChar, [System.IO.Path]::AltDirectorySeparatorChar)
    $pathFull = Get-FullPathSafe -Path $Path
    if ($pathFull.Equals($rootFull, $PathComparison)) {
        return $true
    }

    $rootPrefix = $rootFull + [System.IO.Path]::DirectorySeparatorChar
    return $pathFull.StartsWith($rootPrefix, $PathComparison)
}

function Get-RelativePathFromRoot {
    param(
        [string]$Root,
        [string]$Path
    )

    $rootFull = (Get-FullPathSafe -Path $Root).TrimEnd([System.IO.Path]::DirectorySeparatorChar, [System.IO.Path]::AltDirectorySeparatorChar)
    $pathFull = Get-FullPathSafe -Path $Path
    if (-not (Test-PathInsideRoot -Root $rootFull -Path $pathFull)) {
        throw "Path is outside root: root=$rootFull path=$pathFull"
    }
    return $pathFull.Substring($rootFull.Length).TrimStart([System.IO.Path]::DirectorySeparatorChar, [System.IO.Path]::AltDirectorySeparatorChar)
}

function Test-FencangPathExcluded {
    param(
        [string]$RelativePath,
        [switch]$Directory
    )

    $segments = $RelativePath -split '[\\/]+' | Where-Object { $_ }
    foreach ($segment in $segments) {
        if ($MirrorExcludeDirs -contains $segment) {
            return $true
        }
    }

    if (-not $Directory) {
        $leaf = Split-Path $RelativePath -Leaf
        if ($MirrorExcludeFiles -contains $leaf) {
            return $true
        }
    }

    return $false
}

function Get-FencangFiles {
    param([string]$Root)

    if (-not (Test-Path -LiteralPath $Root)) {
        return @()
    }

    $stack = New-Object 'System.Collections.Generic.Stack[string]'
    $stack.Push((Get-FullPathSafe -Path $Root))
    $files = New-Object 'System.Collections.Generic.List[object]'

    while ($stack.Count -gt 0) {
        $current = $stack.Pop()
        foreach ($item in (Get-ChildItem -LiteralPath $current -Force)) {
            $relative = Get-RelativePathFromRoot -Root $Root -Path $item.FullName
            if ($item.PSIsContainer) {
                if (-not (Test-FencangPathExcluded -RelativePath $relative -Directory)) {
                    $stack.Push($item.FullName)
                }
                continue
            }

            if (-not (Test-FencangPathExcluded -RelativePath $relative)) {
                $files.Add($item) | Out-Null
            }
        }
    }

    return $files
}

function Get-FencangDirectories {
    param([string]$Root)

    if (-not (Test-Path -LiteralPath $Root)) {
        return @()
    }

    $stack = New-Object 'System.Collections.Generic.Stack[string]'
    $stack.Push((Get-FullPathSafe -Path $Root))
    $dirs = New-Object 'System.Collections.Generic.List[object]'

    while ($stack.Count -gt 0) {
        $current = $stack.Pop()
        foreach ($item in (Get-ChildItem -LiteralPath $current -Force -Directory)) {
            $relative = Get-RelativePathFromRoot -Root $Root -Path $item.FullName
            if (Test-FencangPathExcluded -RelativePath $relative -Directory) {
                continue
            }
            $dirs.Add($item) | Out-Null
            $stack.Push($item.FullName)
        }
    }

    return $dirs
}

function Get-FencangFileIndex {
    param([string]$Root)

    $index = @{}
    foreach ($file in (Get-FencangFiles -Root $Root)) {
        $relative = Get-RelativePathFromRoot -Root $Root -Path $file.FullName
        $index[$relative] = $file
    }
    return $index
}

function Test-FencangFilesDiffer {
    param(
        [string]$SourceFile,
        [string]$DestinationFile
    )

    if (-not (Test-Path -LiteralPath $DestinationFile)) {
        return $true
    }

    $sourceInfo = Get-Item -LiteralPath $SourceFile
    $destinationInfo = Get-Item -LiteralPath $DestinationFile
    if ($sourceInfo.Length -ne $destinationInfo.Length) {
        return $true
    }

    $sourceHash = (Get-FileHash -LiteralPath $SourceFile -Algorithm SHA256).Hash
    $destinationHash = (Get-FileHash -LiteralPath $DestinationFile -Algorithm SHA256).Hash
    return ($sourceHash -ne $destinationHash)
}

function Remove-FencangItemSafely {
    param(
        [string]$Root,
        [string]$Path,
        [switch]$Recurse
    )

    if (-not (Test-PathInsideRoot -Root $Root -Path $Path)) {
        throw "Refuse to delete path outside root: root=$Root path=$Path"
    }

    if ($Recurse) {
        Remove-Item -LiteralPath $Path -Recurse -Force
        return
    }
    Remove-Item -LiteralPath $Path -Force
}

function Test-UseRobocopy {
    return $null -ne (Get-Command robocopy -ErrorAction SilentlyContinue)
}

function Invoke-ModuleRobocopy {
    param(
        [string]$Source,
        [string]$Destination,
        [switch]$ListOnly
    )

    $roboArgs = @(
        $Source,
        $Destination,
        '/MIR',
        '/XD'
    ) + $MirrorExcludeDirs + @('/XF') + $MirrorExcludeFiles + @('/NFL', '/NDL', '/NJH', '/NJS', '/NC', '/NS')

    if ($ListOnly) {
        $roboArgs = @('/L') + $roboArgs
    }

    & robocopy @roboArgs | Out-Null
    return $LASTEXITCODE
}

function Test-PortableMirrorNeedsSync {
    param(
        [string]$Source,
        [string]$Destination
    )

    $sourceIndex = Get-FencangFileIndex -Root $Source
    $destinationIndex = Get-FencangFileIndex -Root $Destination

    foreach ($relative in $sourceIndex.Keys) {
        if (-not $destinationIndex.ContainsKey($relative)) {
            return $true
        }
        if (Test-FencangFilesDiffer -SourceFile $sourceIndex[$relative].FullName -DestinationFile $destinationIndex[$relative].FullName) {
            return $true
        }
    }

    foreach ($relative in $destinationIndex.Keys) {
        if (-not $sourceIndex.ContainsKey($relative)) {
            return $true
        }
    }

    return $false
}

function Test-ModuleRepoNeedsSync {
    param(
        [string]$Source,
        [string]$Destination
    )

    if (Test-UseRobocopy) {
        $exitCode = Invoke-ModuleRobocopy -Source $Source -Destination $Destination -ListOnly
        if ($exitCode -ge 8) {
            throw "robocopy precheck failed, exit=$exitCode"
        }
        return ($exitCode -ne 0)
    }

    return (Test-PortableMirrorNeedsSync -Source $Source -Destination $Destination)
}

function Invoke-PortableModuleMirror {
    param(
        [string]$Source,
        [string]$Destination
    )

    if (-not (Test-Path -LiteralPath $Destination)) {
        New-Item -ItemType Directory -Path $Destination -Force | Out-Null
    }

    $sourceIndex = Get-FencangFileIndex -Root $Source
    $destinationIndex = Get-FencangFileIndex -Root $Destination

    foreach ($relative in $destinationIndex.Keys) {
        if (-not $sourceIndex.ContainsKey($relative)) {
            Remove-FencangItemSafely -Root $Destination -Path $destinationIndex[$relative].FullName
        }
    }

    foreach ($relative in $sourceIndex.Keys) {
        $target = Join-FencangPath $Destination $relative
        $targetDir = Split-Path $target -Parent
        if (-not (Test-Path -LiteralPath $targetDir)) {
            New-Item -ItemType Directory -Path $targetDir -Force | Out-Null
        }

        if (Test-FencangFilesDiffer -SourceFile $sourceIndex[$relative].FullName -DestinationFile $target) {
            Copy-Item -LiteralPath $sourceIndex[$relative].FullName -Destination $target -Force
        }
    }

    $sourceDirs = @{}
    foreach ($dir in (Get-FencangDirectories -Root $Source)) {
        $sourceDirs[(Get-RelativePathFromRoot -Root $Source -Path $dir.FullName)] = $true
    }

    $destinationDirs = Get-FencangDirectories -Root $Destination | Sort-Object { $_.FullName.Length } -Descending
    foreach ($dir in $destinationDirs) {
        $relative = Get-RelativePathFromRoot -Root $Destination -Path $dir.FullName
        if ($sourceDirs.ContainsKey($relative)) {
            continue
        }

        $children = @(Get-ChildItem -LiteralPath $dir.FullName -Force -ErrorAction SilentlyContinue)
        if ($children.Count -eq 0) {
            Remove-FencangItemSafely -Root $Destination -Path $dir.FullName -Recurse
        }
    }
}

function Invoke-ModuleMirror {
    param(
        [string]$Source,
        [string]$Destination
    )

    if (Test-UseRobocopy) {
        $exitCode = Invoke-ModuleRobocopy -Source $Source -Destination $Destination
        if ($exitCode -ge 8) {
            throw "robocopy failed, exit=$exitCode"
        }
        return
    }

    Invoke-PortableModuleMirror -Source $Source -Destination $Destination
}

function Get-FencangRepoRemotes {
    param([string]$RepoName)

    return [ordered]@{
        Origin = "https://gitee.com/$GiteeOwner/$RepoName.git"
        Github = "https://github.com/$GithubOwner/$RepoName.git"
    }
}

function Invoke-GitChecked {
    param(
        [Parameter(Mandatory = $true)]
        [string[]]$Arguments
    )

    $oldErrorActionPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $output = & git @Arguments 2>&1
        $exitCode = $LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $oldErrorActionPreference
    }
    if ($output) {
        foreach ($line in $output) {
            Write-Host $line
        }
    }
    if ($exitCode -ne 0) {
        throw "git $($Arguments -join ' ') failed, exit=$exitCode"
    }
}

function Invoke-GitAllowFailure {
    param(
        [Parameter(Mandatory = $true)]
        [string[]]$Arguments
    )

    $oldErrorActionPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $output = & git @Arguments 2>&1
        $exitCode = $LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $oldErrorActionPreference
    }
    if ($output) {
        foreach ($line in $output) {
            Write-Host $line
        }
    }
    return $exitCode
}

function Ensure-GitRemote {
    param(
        [string]$RepoPath,
        [string]$Name,
        [string]$Url
    )

    Push-Location $RepoPath
    try {
        $remoteNames = @(git remote)
        if ($remoteNames -notcontains $Name) {
            Invoke-GitChecked -Arguments @('remote', 'add', $Name, $Url) | Out-Null
            return "added:$Name"
        }

        $existing = git remote get-url $Name
        if ($existing.Trim() -ne $Url) {
            return "kept:$Name(existing-url)"
        }

        return "ok:$Name"
    }
    finally {
        Pop-Location
    }
}

function Ensure-FencangRepo {
    param(
        [string]$RepoName,
        [string]$RepoPath
    )

    $gitDir = Join-FencangPath $RepoPath '.git'
    $remotes = Get-FencangRepoRemotes -RepoName $RepoName

    if (Test-Path -LiteralPath $gitDir) {
        $originStatus = Ensure-GitRemote -RepoPath $RepoPath -Name 'origin' -Url $remotes.Origin
        $githubStatus = Ensure-GitRemote -RepoPath $RepoPath -Name 'github' -Url $remotes.Github
        return "repo exists, clone skipped; remote: $originStatus, $githubStatus"
    }

    if ($NoAutoClone) {
        throw "split repo is missing and auto-clone is disabled: $RepoPath"
    }

    if (Test-Path -LiteralPath $RepoPath) {
        $existingItems = @(Get-ChildItem -LiteralPath $RepoPath -Force -ErrorAction SilentlyContinue)
        if ($existingItems.Count -gt 0) {
            throw "target directory exists but is not a git repo; refusing to overwrite: $RepoPath"
        }
    }

    $parent = Split-Path $RepoPath -Parent
    if (-not (Test-Path -LiteralPath $parent)) {
        New-Item -ItemType Directory -Path $parent -Force | Out-Null
    }

    $tempPath = "$RepoPath.clone-$PID"
    if (Test-Path -LiteralPath $tempPath) {
        Remove-FencangItemSafely -Root $parent -Path $tempPath -Recurse
    }

    $cloneExitCode = Invoke-GitAllowFailure -Arguments @('clone', $remotes.Origin, $tempPath)
    if ($cloneExitCode -ne 0) {
        if (Test-Path -LiteralPath $tempPath) {
            Remove-FencangItemSafely -Root $parent -Path $tempPath -Recurse
        }

        $cloneExitCode = Invoke-GitAllowFailure -Arguments @('clone', '--origin', 'github', $remotes.Github, $tempPath)
        if ($cloneExitCode -ne 0) {
            if (Test-Path -LiteralPath $tempPath) {
                Remove-FencangItemSafely -Root $parent -Path $tempPath -Recurse
            }
            throw "auto clone failed: $($remotes.Origin) / $($remotes.Github)"
        }
    }

    if (Test-Path -LiteralPath $RepoPath) {
        Remove-FencangItemSafely -Root $parent -Path $RepoPath -Recurse
    }
    Move-Item -LiteralPath $tempPath -Destination $RepoPath
    $originStatus = Ensure-GitRemote -RepoPath $RepoPath -Name 'origin' -Url $remotes.Origin
    $githubStatus = Ensure-GitRemote -RepoPath $RepoPath -Name 'github' -Url $remotes.Github

    return "auto-cloned to $RepoPath; remote: $originStatus, $githubStatus"
}

function Invoke-GitFetchBestEffort {
    param([string]$RepoPath)

    $warnings = New-Object 'System.Collections.Generic.List[string]'
    Push-Location $RepoPath
    try {
        $remotes = @(git remote)
        foreach ($remote in $remotes) {
            if (-not $remote) {
                continue
            }

            $exitCode = Invoke-GitAllowFailure -Arguments @('fetch', $remote, '--tags')
            if ($exitCode -ne 0) {
                $warnings.Add("fetch $remote failed with exit=$exitCode") | Out-Null
            }
        }
    }
    finally {
        Pop-Location
    }

    return ($warnings -join '; ')
}

function Get-NextTagForRepo {
    param([string]$RepoPath)

    $current = Get-LatestRepoTag -RepoPath $RepoPath
    if (-not $current) {
        return 'v1.0.0'
    }
    return Get-NextWelineTag -Tag $current
}

function Sync-ModuleRepo {
    param(
        [string]$Module,
        [string]$RepoName
    )

    $src = Join-FencangPath $DevRoot 'app' 'code' 'Weline' $Module
    $dst = Join-FencangPath $WelineRoot $RepoName

    $result = [ordered]@{
        Module          = $Module
        Repo            = $RepoName
        Status          = 'skipped'
        OldTag          = $null
        NewTag          = $null
        PackageName     = $null
        PackagistStatus = $null
        Message         = ''
    }

    if (-not (Test-Path -LiteralPath $src)) {
        $result.Message = "DEV source directory is missing: $src"
        return $result
    }

    try {
        $repoMessage = Ensure-FencangRepo -RepoName $RepoName -RepoPath $dst
        $result.Message = $repoMessage
    }
    catch {
        $result.Status = 'error'
        $result.Message = $_.Exception.Message
        return $result
    }

    $fetchWarning = Invoke-GitFetchBestEffort -RepoPath $dst

    Push-Location $dst
    try {
        $dirtyStatus = git status --porcelain
    }
    finally {
        Pop-Location
    }

    if ($fetchWarning) {
        $result.Message = "$($result.Message); $fetchWarning"
    }

    if ($dirtyStatus) {
        $result.Status = 'error'
        $result.Message = "$($result.Message); split repo worktree is dirty; refusing mirror overwrite: $dst"
        return $result
    }

    $result.OldTag = Get-LatestRepoTag -RepoPath $dst

    try {
        $needsSync = Test-ModuleRepoNeedsSync -Source $src -Destination $dst
    }
    catch {
        $result.Status = 'error'
        $result.Message = $_.Exception.Message
        return $result
    }

    if (-not $needsSync) {
        $result.Status = 'no-change'
        $result.Message = "$($result.Message); DEV and split repo match; skipping mirror/commit/tag/push/Packagist"
        return $result
    }

    if ($DryRun) {
        $result.Status = 'dry-run'
        $result.NewTag = Get-NextTagForRepo -RepoPath $dst
        $result.Message = "$($result.Message); diff detected; would mirror $src -> $dst; new tag: $($result.NewTag)"
        return $result
    }

    try {
        Invoke-ModuleMirror -Source $src -Destination $dst
    }
    catch {
        $result.Message = $_.Exception.Message
        $result.Status = 'error'
        return $result
    }

    Push-Location $dst
    try {
        Invoke-GitChecked -Arguments @('add', '-A') | Out-Null
        $porcelain = git status --porcelain
        if (-not $porcelain) {
            $result.Status = 'no-change'
            $result.Message = 'no git changes after mirror; skipping commit/tag/push'
            return $result
        }

        $newTag = Get-NextTagForRepo -RepoPath $dst
        $result.NewTag = $newTag

        Invoke-GitChecked -Arguments @('commit', '--trailer', 'Co-authored-by: Cursor <cursoragent@cursor.com>', '-m', "sync: mirror $Module from DEV-workspace") | Out-Null
        Invoke-GitChecked -Arguments @('tag', $newTag) | Out-Null

        $branch = git branch --show-current
        if (-not $branch) { $branch = 'master' }

        Invoke-GitChecked -Arguments @('push', 'origin', $branch) | Out-Null
        Invoke-GitChecked -Arguments @('push', 'origin', $newTag) | Out-Null
        Invoke-GitChecked -Arguments @('push', 'github', $branch) | Out-Null
        Invoke-GitChecked -Arguments @('push', 'github', $newTag) | Out-Null

        try {
            $refreshScript = Join-Path $PSScriptRoot 'refresh-packagist.ps1'
            $packagist = & $refreshScript -RepoPath $dst -PackageName ''
            $result.PackageName = $packagist.PackageName
            $result.PackagistStatus = $packagist.PackagistStatus
            $result.Status = 'ok'
            $result.Message = "pushed $branch/$newTag to origin/github and triggered Packagist update for $($packagist.PackageName)"
        }
        catch {
            $result.Status = 'ok-push-only'
            $result.PackagistStatus = 'failed'
            $result.Message = "pushed $branch/$newTag, but Packagist refresh failed: $($_.Exception.Message)"
        }
    }
    catch {
        $result.Status = 'error'
        $result.Message = $_.Exception.Message
    }
    finally {
        Pop-Location
    }

    return $result
}

if (-not $DevRoot) {
    $DevRoot = (Resolve-Path (Join-FencangPath $PSScriptRoot '..' '..' '..')).Path
}
$DevRoot = Get-FullPathSafe -Path $DevRoot

if (-not $WelineRoot) {
    $WelineRoot = Join-FencangPath (Split-Path $DevRoot -Parent) $DefaultSplitRootName
}
$WelineRoot = Get-FullPathSafe -Path $WelineRoot

if (-not (Test-Path -LiteralPath $WelineRoot)) {
    New-Item -ItemType Directory -Path $WelineRoot -Force | Out-Null
}

$targets = @()
if ($All) {
    $targets = $ModuleRepoMap.Keys | Sort-Object
}
elseif ($Modules.Count -gt 0) {
    $targets = $Modules
}
else {
    throw 'Please specify -Modules or -All'
}

$report = @()
foreach ($module in $targets) {
    if (-not $ModuleRepoMap.ContainsKey($module)) {
        $report += [pscustomobject][ordered]@{
            Module          = $module
            Repo            = $null
            Status          = 'error'
            OldTag          = $null
            NewTag          = $null
            PackageName     = $null
            PackagistStatus = $null
            Message         = 'unknown module or missing split-repo mapping'
        }
        continue
    }
    $report += [pscustomobject](Sync-ModuleRepo -Module $module -RepoName $ModuleRepoMap[$module])
}

$report | Format-Table -AutoSize Module, Repo, Status, OldTag, NewTag, PackageName, PackagistStatus, Message
return $report
