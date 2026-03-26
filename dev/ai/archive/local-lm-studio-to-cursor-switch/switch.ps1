# Cursor API Switch - Auto Cloudflare Detect

$CURSOR_PROCESS = "Cursor"
$SCRIPT_DIR     = Split-Path -Parent $MyInvocation.MyCommand.Path
$CLOUDFLARED    = Join-Path $SCRIPT_DIR "cloudflared.exe"
$CONFIG_FILE    = Join-Path $SCRIPT_DIR "cursor-path.txt"
$SQLITE         = Join-Path $SCRIPT_DIR "sqlite3.exe"
$DB_PATH        = "$env:APPDATA\Cursor\User\globalStorage\state.vscdb"
$DB_KEY         = "src.vs.platform.reactivestorage.browser.reactiveStorageServiceImpl.persistentStorage.applicationUser"

# ---------------------------------------------------------------
# Find Cursor.exe
# ---------------------------------------------------------------
function Find-CursorExe {
    $appPath = (Get-ItemProperty `
        "HKLM:\Software\Microsoft\Windows\CurrentVersion\App Paths\cursor.exe" `
        -ErrorAction SilentlyContinue).'(Default)'
    if ($appPath -and (Test-Path $appPath)) { return $appPath }

    $roots = @(
        "HKCU:\Software\Microsoft\Windows\CurrentVersion\Uninstall",
        "HKLM:\Software\Microsoft\Windows\CurrentVersion\Uninstall",
        "HKLM:\Software\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall"
    )
    foreach ($root in $roots) {
        if (-not (Test-Path $root)) { continue }
        foreach ($key in (Get-ChildItem $root -ErrorAction SilentlyContinue)) {
            if ($key.GetValue("DisplayName") -notlike "*Cursor*") { continue }
            $loc = $key.GetValue("InstallLocation")
            if ($loc) { $c = Join-Path $loc "Cursor.exe"; if (Test-Path $c) { return $c } }
            $icon = $key.GetValue("DisplayIcon")
            if ($icon) {
                $p = ($icon -split ',')[0].Trim('"').Trim("'")
                if ($p -match '\.exe$' -and (Test-Path $p)) { return $p }
            }
        }
    }

    $w = where.exe cursor 2>$null | Select-Object -First 1
    if ($w -and (Test-Path $w)) { return $w }

    $drives = (Get-PSDrive -PSProvider FileSystem | Where-Object { $_.Root -match '^[A-Z]:\\$' }).Root
    foreach ($drive in $drives) {
        foreach ($dir in @("cursor","Cursor","apps\cursor","tools\cursor")) {
            $c = Join-Path $drive "$dir\Cursor.exe"
            if (Test-Path $c) { return $c }
        }
    }

    if (Test-Path $CONFIG_FILE) {
        $cached = (Get-Content $CONFIG_FILE -Raw).Trim()
        if ($cached -and (Test-Path $cached)) { return $cached }
    }

    Write-Host "[WARN] Cursor.exe not found automatically." -ForegroundColor Yellow
    $manual = (Read-Host "Enter full path to Cursor.exe").Trim().Trim('"')
    if ($manual -and (Test-Path $manual)) {
        $manual | Set-Content $CONFIG_FILE
        return $manual
    }

    Write-Host "[ERROR] Invalid path." -ForegroundColor Red
    return $null
}

# ---------------------------------------------------------------
# Init
# ---------------------------------------------------------------
$CURSOR_EXE = Find-CursorExe
if (-not $CURSOR_EXE) { exit 1 }

if (!(Test-Path $SQLITE)) {
    Write-Host "[ERROR] sqlite3.exe not found: $SQLITE" -ForegroundColor Red
    exit 1
}

Write-Host "[INFO] Cursor exe  : $CURSOR_EXE"  -ForegroundColor DarkGray
Write-Host "[INFO] Database    : $DB_PATH"      -ForegroundColor DarkGray

# ---------------------------------------------------------------
# Process control
# ---------------------------------------------------------------
function Stop-Cursor {
    $procs = Get-Process -Name $CURSOR_PROCESS -ErrorAction SilentlyContinue
    if ($procs) {
        Write-Host "Stopping Cursor..."
        $procs | Stop-Process -Force
        Start-Sleep -Milliseconds 800
    } else {
        Write-Host "Cursor is not running."
    }
}

function Start-Cursor {
    if (Test-Path $CURSOR_EXE) {
        Write-Host "Starting Cursor: $CURSOR_EXE"
        Start-Process $CURSOR_EXE -RedirectStandardError "$env:TEMP\cursor-err.log"
    }
}

# ---------------------------------------------------------------
# Cloudflare tunnel
# ---------------------------------------------------------------
function Start-Cloudflare-And-GetUrl {
    if (!(Test-Path $CLOUDFLARED)) {
        Write-Host "[ERROR] cloudflared.exe not found: $CLOUDFLARED" -ForegroundColor Red
        return $null
    }

    Get-Process cloudflared -ErrorAction SilentlyContinue | ForEach-Object {
        $_.Kill(); $_.WaitForExit()
    }

    $logFile = Join-Path $SCRIPT_DIR "cf.log"
    if (Test-Path $logFile) { Remove-Item $logFile -Force }

    Write-Host "Starting cloudflared tunnel..."
    Start-Process `
        -FilePath $CLOUDFLARED `
        -ArgumentList "tunnel --url http://localhost:1234 --no-autoupdate --logfile `"$logFile`"" `
        -WindowStyle Hidden

    $timeout   = 20
    $startTime = Get-Date
    $url       = $null

    while (-not $url -and ((Get-Date) - $startTime).TotalSeconds -lt $timeout) {
        if (Test-Path $logFile) {
            $content = Get-Content $logFile -Raw -ErrorAction SilentlyContinue
            if ($content -match "https://[a-z0-9\-]+\.trycloudflare\.com") {
                $url = $matches[0]; break
            }
        }
        Start-Sleep -Milliseconds 500
    }

    if (!$url) {
        Write-Host "[ERROR] Tunnel URL not detected within ${timeout}s." -ForegroundColor Red
        return $null
    }

    Write-Host "Cloudflare tunnel URL: $url" -ForegroundColor Green
    return $url
}

# ---------------------------------------------------------------
# Update openAIBaseUrl via temp SQL file (avoids cmdline length limit)
# ---------------------------------------------------------------
function Set-BaseUrl($newUrl) {
    if (-not $newUrl) {
        Write-Host "[ERROR] Invalid URL." -ForegroundColor Red
        return
    }

    if (!(Test-Path $DB_PATH)) {
        Write-Host "[ERROR] state.vscdb not found: $DB_PATH" -ForegroundColor Red
        return
    }

    # Backup
    Copy-Item $DB_PATH "$DB_PATH.bak" -Force
    Write-Host "Backup saved: $DB_PATH.bak"

    # Read current JSON blob from DB
    $raw = & $SQLITE $DB_PATH "SELECT value FROM ItemTable WHERE key='$DB_KEY';"
    if (-not $raw) {
        Write-Host "[ERROR] Key not found in database." -ForegroundColor Red
        return
    }

    # Parse and update
    $json    = $raw | ConvertFrom-Json
    $oldUrl  = $json.openAIBaseUrl
    $json.openAIBaseUrl = $newUrl

    # Serialize compressed
    $newValue = $json | ConvertTo-Json -Depth 20 -Compress

    # Escape single quotes for SQLite
    $escaped = $newValue -replace "'", "''"

    # Write SQL to temp file to bypass Windows cmdline length limit
    $tmpSql = Join-Path $env:TEMP "cursor_update.sql"
    "UPDATE ItemTable SET value='$escaped' WHERE key='$DB_KEY';" | Set-Content $tmpSql -Encoding UTF8

    & $SQLITE $DB_PATH ".read `"$tmpSql`""

    Remove-Item $tmpSql -Force -ErrorAction SilentlyContinue

    Write-Host "openAIBaseUrl: $oldUrl -> $newUrl" -ForegroundColor Green
}

# ---------------------------------------------------------------
# Main menu loop
# ---------------------------------------------------------------
while ($true) {
    Write-Host ""
    Write-Host "===============================" -ForegroundColor Cyan
    Write-Host " Cursor API Switch"             -ForegroundColor Cyan
    Write-Host "===============================" -ForegroundColor Cyan
    Write-Host "1. Start CF Tunnel + Apply"
    Write-Host "2. Set Official OpenAI"
    Write-Host "3. Stop Cursor"
    Write-Host "4. Start Cursor"
    Write-Host "0. Exit"
    Write-Host "===============================" -ForegroundColor Cyan
    Write-Host ""

    $choice = Read-Host "Select option"

    switch ($choice) {
        "1" {
            Stop-Cursor
            $url = Start-Cloudflare-And-GetUrl
            if ($url) {
                Set-BaseUrl "$url/v1"
                Start-Cursor
            } else {
                Write-Host "Tunnel failed, base URL not changed." -ForegroundColor Yellow
            }
        }
        "2" {
            Stop-Cursor
            Set-BaseUrl "https://api.openai.com/v1"
            Start-Cursor
        }
        "3" { Stop-Cursor }
        "4" { Start-Cursor }
        "0" { exit }
        default { Write-Host "Invalid option." -ForegroundColor Yellow }
    }
}