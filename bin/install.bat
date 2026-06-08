@echo off
setlocal EnableDelayedExpansion
REM Install: PHP to extend\server\php + PATH; pgsql/mysql add to PATH when present. Then run.php handles extensions, php.ini, composer, setup.
REM All systems: after install, php and pgsql (if present) are written to User PATH (Windows) or shell config (Linux/Mac via install.sh).
REM From Git Bash: run ./bin/install (not install.bat) so that this .bat is executed by cmd.exe.

cd /d "%~dp0.."
goto :main

:main
set "ROOT=%CD%"
set "SERVER=%ROOT%\extend\server"
set "PHP_DIR=%SERVER%\php"
set "PHP_EXE=%PHP_DIR%\php.exe"
set "PHP_ZIP=%TEMP%\weline-php.zip"
set "PATH_ONLY="
set "DO_PHP=0"
set "DO_PGSQL=0"
set "DO_MYSQL=0"
set "BRANCH=main"
set "ENV_FILE_ARG="

REM Parse args (avoid for %%a in () when %* is empty - causes ") was unexpected" in cmd)
set "FORCE_INSTALL="
if "%~1"=="" (
  set "DO_PHP=1"
  set "DO_PGSQL=1"
) else (
  for %%a in (%*) do (
    if "%%a"=="--path-only" set "PATH_ONLY=1"
    if "%%a"=="-f" set "FORCE_INSTALL=1"
    if "%%a"=="--force" set "FORCE_INSTALL=1"
    if "%%a"=="php"   set "DO_PHP=1"
    if "%%a"=="pgsql" set "DO_PGSQL=1"
    if "%%a"=="mysql" set "DO_MYSQL=1"
  )
  if !DO_PHP!==0 if !DO_PGSQL!==0 if !DO_MYSQL!==0 set "DO_PHP=1" & set "DO_PGSQL=1"
)
REM Parse -b <branch> for code install when run.php is missing
set "PREV="
for %%a in (%*) do (
  if "!PREV!"=="-b" set "BRANCH=%%a"
  if "!PREV!"=="--env-file" set "ENV_FILE_ARG=%%a"
  set "PREV=%%a"
)
for %%a in (%*) do (
  set "ARG_VALUE=%%a"
  if "!ARG_VALUE:~0,11!"=="--env-file=" set "ENV_FILE_ARG=!ARG_VALUE:~11!"
)

REM Read env file (optional)
set "ENV_READ_FILE=%ROOT%\weline.env"
if defined ENV_FILE_ARG (
  if exist "%ENV_FILE_ARG%" (
    set "ENV_READ_FILE=%ENV_FILE_ARG%"
  ) else (
    set "ENV_READ_FILE=%ROOT%\%ENV_FILE_ARG%"
  )
  if not exist "!ENV_READ_FILE!" (
    set "CECHO_MSG=ERROR: env file not found: !ENV_READ_FILE!" & call :cecho Red ""
    exit /b 1
  )
)
if exist "%ENV_READ_FILE%" for /f "usebackq eol=# tokens=1* delims==" %%a in ("%ENV_READ_FILE%") do set "%%a=%%b"

REM Versions (weline.env or default)
if not defined INSTALL_PGSQL_VERSION set "INSTALL_PGSQL_VERSION=16"
if not defined INSTALL_MYSQL_VERSION set "INSTALL_MYSQL_VERSION=8.0"

REM PHP version can be major.minor or exact major.minor.patch
set "PHP_VER=8.4"
if defined INSTALL_PHP_VERSION set "PHP_VER=!INSTALL_PHP_VERSION!"
set "PHP_BASE_VER="
set "PHP_PATCH_HINT="
for /f "tokens=1,2,3 delims=." %%a in ("!PHP_VER!") do (
  set "PHP_BASE_VER=%%a.%%b"
  set "PHP_PATCH_HINT=%%c"
)
if not defined PHP_BASE_VER set "PHP_BASE_VER=!PHP_VER!"
set "PHP_PATCH_CANDIDATES=16 15 14 13 12 11 10 9 8 7 6 5 4 3 2 1 0"
if defined PHP_PATCH_HINT set "PHP_PATCH_CANDIDATES=!PHP_PATCH_HINT!"

mkdir "%SERVER%" 2>nul

call :cecho Cyan "========== PHP =========="
if %DO_PHP% neq 1 goto :skip_php
set "PHP_ALREADY="
dir /b "%PHP_DIR%\php.exe" 2>nul | findstr . >nul && set "PHP_ALREADY=1"
if defined PHP_ALREADY goto :php_do_present
if defined PATH_ONLY goto :php_path_only_msg
goto :php_do_download
:php_do_present
set "CECHO_MSG=PHP already present at %PHP_DIR%. Adding to PATH." & call :cecho Green ""
call :add_path "%PHP_DIR%"
goto :skip_php
:php_path_only_msg
set "CECHO_MSG=--path-only: PHP not found at %PHP_DIR%." & call :cecho Yellow ""
goto :skip_php
:php_do_download
set "VS=vs17"
REM Only use official downloads.php.net windows release sources:
REM active releases + archived releases.
set "FOUND_PATCH="
set "FOUND_URL="
set "PHP_BASE_URL_PRIMARY=https://downloads.php.net/~windows/releases/"
set "PHP_BASE_URL_ARCHIVE=https://downloads.php.net/~windows/releases/archives/"
for %%p in (!PHP_PATCH_CANDIDATES!) do (
  if not defined FOUND_PATCH (
    set "URL_PRIMARY=!PHP_BASE_URL_PRIMARY!php-!PHP_BASE_VER!.%%p-Win32-!VS!-x64.zip"
    set "URL_ARCHIVE=!PHP_BASE_URL_ARCHIVE!php-!PHP_BASE_VER!.%%p-Win32-!VS!-x64.zip"
    set "CECHO_MSG=Checking PHP !PHP_BASE_VER!.%%p ..." & call :cecho Gray ""
    set "CECHO_MSG=Probe URL(primary): !URL_PRIMARY!" & call :cecho DarkGray ""
    set "CECHO_MSG=Probe URL(archive): !URL_ARCHIVE!" & call :cecho DarkGray ""
    set "PROBE_OK="
    call :probe_url_exists "!URL_PRIMARY!"
    if defined PROBE_OK (
      set "FOUND_PATCH=%%p"
      set "FOUND_URL=!URL_PRIMARY!"
    )
    if not defined FOUND_PATCH (
      set "PROBE_OK="
      call :probe_url_exists "!URL_ARCHIVE!"
      if defined PROBE_OK (
        set "FOUND_PATCH=%%p"
        set "FOUND_URL=!URL_ARCHIVE!"
      )
    )
  )
)
if not defined FOUND_PATCH goto :php_download_failed
if not defined FOUND_URL set "FOUND_URL=!PHP_BASE_URL_PRIMARY!php-!PHP_BASE_VER!.!FOUND_PATCH!-Win32-!VS!-x64.zip"
set "CECHO_MSG=PHP package URL: !FOUND_URL!" & call :cecho DarkGray ""
set "CECHO_MSG=Downloading PHP !PHP_BASE_VER!.!FOUND_PATCH! from: !FOUND_URL!" & call :cecho Gray ""
set "DOWNLOAD_OK="
call :try_download_url "!FOUND_URL!"
if not defined DOWNLOAD_OK goto :php_download_transport_failed
mkdir "%PHP_DIR%" 2>nul
call :validate_zip_file "%PHP_ZIP%"
if errorlevel 1 goto :php_invalid_archive
powershell -NoProfile -ExecutionPolicy Bypass -Command "Expand-Archive -Path '%PHP_ZIP%' -DestinationPath '%PHP_DIR%' -Force"
if errorlevel 1 goto :php_extract_failed
del "%PHP_ZIP%" 2>nul
for /d %%d in ("%PHP_DIR%\php-*") do (
  move "%%d\*" "%PHP_DIR%\" >nul 2>&1
  rmdir "%%d" 2>nul
)
set "PHP_EXTRACT_OK="
dir /b "%PHP_DIR%\php.exe" 2>nul | findstr . >nul && set "PHP_EXTRACT_OK=1"
if not defined PHP_EXTRACT_OK goto :php_extract_failed
call :add_path "%PHP_DIR%"
set "CECHO_MSG=PHP installed to %PHP_DIR%." & call :cecho Green ""
"%PHP_EXE%" -v >nul 2>&1
if errorlevel 1 goto :php_vc_redist
goto :skip_php
:php_vc_redist
set "CECHO_MSG=PHP may need VC++ Redist. Downloading..." & call :cecho Yellow ""
curl -L -s -o "%TEMP%\vc_redist.x64.exe" "https://aka.ms/vs/17/release/vc_redist.x64.exe" 2>nul
dir /b "%TEMP%\vc_redist.x64.exe" 2>nul | findstr . >nul && start /wait "" "%TEMP%\vc_redist.x64.exe" /install /quiet /norestart
del "%TEMP%\vc_redist.x64.exe" 2>nul
goto :skip_php
:php_download_failed
if defined FOUND_URL (
  set "CECHO_MSG=Download failed from URL: !FOUND_URL!" & call :cecho Yellow ""
) else (
  set "CECHO_MSG=Download failed before a valid URL was selected." & call :cecho Yellow ""
)
set "CECHO_MSG=Manual source indexes: https://downloads.php.net/~windows/releases/ and https://downloads.php.net/~windows/releases/archives/ (extract to %PHP_DIR%)." & call :cecho Yellow ""
goto :skip_php
:php_download_transport_failed
set "CECHO_MSG=PHP package URL exists but download failed for: !FOUND_URL!" & call :cecho Yellow ""
set "CECHO_MSG=The installer will not fall back to an older PHP patch after a transport failure. Retry the same version or download it manually into %PHP_DIR%." & call :cecho Yellow ""
goto :skip_php
:php_invalid_archive
set "CECHO_MSG=Downloaded PHP package is not a valid ZIP archive. A proxy/CDN error page may have been saved instead. Check %PHP_ZIP% and retry." & call :cecho Yellow ""
goto :skip_php
:php_extract_failed
set "CECHO_MSG=Extract may have different structure. Check %PHP_DIR%." & call :cecho Yellow ""
:skip_php

call :cecho Cyan "========== PostgreSQL =========="
if %DO_PGSQL% neq 1 goto :skip_pgsql
set "PGSQL_OK="
dir /b "%SERVER%\pgsql\bin\psql.exe" 2>nul | findstr . >nul && set "PGSQL_OK=1"
if defined PGSQL_OK (
  call :add_path "%SERVER%\pgsql\bin"
  call :cecho Green "PostgreSQL bin added to PATH."
) else (
  if not defined PATH_ONLY set "CECHO_MSG=PostgreSQL not at %SERVER%\pgsql. Install manually, then run: install.bat --path-only pgsql" & call :cecho Yellow ""
)
:skip_pgsql

REM Default DB: PostgreSQL. MySQL block shown only if mysql bin exists.
if %DO_MYSQL% neq 1 goto :skip_mysql
set "MYSQL_OK="
dir /b "%SERVER%\mysql\bin\mysql.exe" 2>nul | findstr . >nul && set "MYSQL_OK=1"
if defined MYSQL_OK (
  call :cecho Cyan "========== MySQL =========="
  call :add_path "%SERVER%\mysql\bin"
  call :cecho Green "MySQL bin added to PATH."
)
:skip_mysql

REM 安装后：将 php、pgsql、项目 bin（w 命令）写入用户 PATH（与 Linux/Mac 一致，所有系统都处理好）
if exist "%PHP_DIR%\php.exe" call :add_path "%PHP_DIR%"
if exist "%SERVER%\pgsql\bin\psql.exe" call :add_path "%SERVER%\pgsql\bin"
call :add_path "%ROOT%\bin"

REM 若 setup\server_installer\run.php 不存在，说明代码未安装：按 -b 指定分支拉取，未指定则 master
if not exist "%ROOT%\setup\server_installer\run.php" (
  where git >nul 2>&1
  if errorlevel 1 (
    set "CECHO_MSG=Git not found. Installing Git..." & call :cecho Yellow ""
    call :install_git
    set "PATH=%PATH%;%ProgramFiles%\Git\cmd;%ProgramFiles(x86)%\Git\cmd"
    where git >nul 2>&1
    if errorlevel 1 (
      set "CECHO_MSG=Git install failed. Install from https://git-scm.com/download/win then re-run." & call :cecho Red ""
      exit /b 1
    )
  )
  set "CECHO_MSG=run.php not found. Installing framework code from GitHub (branch: %BRANCH%)..." & call :cecho Yellow ""
  if defined WELINE_REPO_URL (
    set "REPO_URL=%WELINE_REPO_URL%"
  ) else (
    set "REPO_URL=https://github.com/Aiweline/PageBuilder.git"
  )
  set "CLONE_URL=%REPO_URL%"
  if defined WELINE_GITHUB_TOKEN call :build_clone_url "%REPO_URL%"
  if not defined WELINE_GITHUB_TOKEN if defined GITHUB_TOKEN call :build_clone_url "%REPO_URL%"
  if exist "%ROOT%\.git" (
    git -C "%ROOT%" fetch origin 2>nul
    git -C "%ROOT%" checkout %BRANCH% 2>nul || git -C "%ROOT%" pull origin %BRANCH% 2>nul
  ) else (
    git clone -b %BRANCH% %CLONE_URL% "%ROOT%\temp_clone_weline" 2>nul
    if not exist "%ROOT%\temp_clone_weline\setup\server_installer\run.php" (
      set "CECHO_MSG=Clone failed or branch invalid. Manual: git clone -b %BRANCH% %REPO_URL% ." & call :cecho Red ""
      exit /b 1
    )
    robocopy "%ROOT%\temp_clone_weline" "%ROOT%" /E /NFL /NDL /NJH /NJS /NC /NS >nul 2>&1
    rd /s /q "%ROOT%\temp_clone_weline" 2>nul
  )
  if not exist "%ROOT%\setup\server_installer\run.php" (
    set "CECHO_MSG=Code install failed. Ensure run.php exists at setup\server_installer\run.php" & call :cecho Red ""
    exit /b 1
  )
  REM 确保当前用户对项目目录有完全控制权限（避免后续操作权限问题）
  set "CECHO_MSG=Setting project directory permissions for current user..." & call :cecho Gray ""
  call :grant_current_user_full_control "%ROOT%"
  set "CECHO_MSG=Code installed (branch: %BRANCH%)." & call :cecho Green ""
)

call :cecho Cyan "========== Run installer =========="
set "USE_PHP=php"
set "HAVE_PHP="
dir /b "%PHP_DIR%\php.exe" 2>nul | findstr . >nul && set "HAVE_PHP=1"
if defined HAVE_PHP set "USE_PHP=%PHP_DIR%\php.exe"
if "!USE_PHP!"=="php" where php >nul 2>&1
if "!USE_PHP!"=="php" if errorlevel 1 goto :err_php_not_found
goto :do_run_installer
:err_php_not_found
set "CECHO_MSG=ERROR: PHP not found. Install to %PHP_DIR% or add php to PATH." & call :cecho Red ""
exit /b 1
:do_run_installer
if exist "%PHP_DIR%\php.exe" if exist "%ROOT%\setup\server_installer\bootstrap_php_ini.php" (
  call :cecho Gray "Pre-configuring php.ini (opcache file_cache for Windows ASLR)..."
  "%PHP_EXE%" -d opcache.enable=0 -d opcache.enable_cli=0 "%ROOT%\setup\server_installer\bootstrap_php_ini.php"
  if errorlevel 1 (
    set "CECHO_MSG=WARNING: bootstrap_php_ini failed; run.php may hit Opcache ASLR fatal on Windows." & call :cecho Yellow ""
  )
)
set "RUN_ARGS="
if defined FORCE_INSTALL set "RUN_ARGS=-f"
call :cecho Gray "Running: php setup\server_installer\run.php %RUN_ARGS%"
if defined ENV_FILE_ARG (
  "!USE_PHP!" "%ROOT%\setup\server_installer\run.php" %RUN_ARGS% --env-file "%ENV_FILE_ARG%"
) else (
  "!USE_PHP!" "%ROOT%\setup\server_installer\run.php" %RUN_ARGS%
)
if errorlevel 1 exit /b 1
echo.
cd /d "%ROOT%"
call :cecho Green "Done. php, pgsql and bin (w command) have been added to User PATH. Current directory: project root. Reopen the terminal for PATH, then you can run: w setup:upgrade"
endlocal
exit /b 0

REM ---- Subroutines ----
:try_download_url
set "TRY_URL=%~1"
if not defined TRY_URL exit /b 1
if exist "%PHP_ZIP%" del "%PHP_ZIP%" 2>nul

REM Engine 1: PowerShell (more reliable than curl on some Windows networks/CDNs)
set "CECHO_MSG=Attempting download via PowerShell: %TRY_URL%" & call :cecho DarkGray ""
powershell -NoProfile -ExecutionPolicy Bypass -Command "try { Invoke-WebRequest -Uri '%TRY_URL%' -OutFile '%PHP_ZIP%' -UseBasicParsing -TimeoutSec 180; exit 0 } catch { exit 1 }" >nul 2>&1
call :validate_zip_file "%PHP_ZIP%"
if not errorlevel 1 (
  set "DOWNLOAD_OK=1"
  exit /b 0
)

REM Engine 2: curl fallback
if exist "%PHP_ZIP%" del "%PHP_ZIP%" 2>nul
set "CECHO_MSG=PowerShell download did not produce a valid ZIP. Trying curl fallback..." & call :cecho DarkGray ""
curl.exe -L --fail --retry 2 --retry-delay 2 --connect-timeout 10 --max-time 60 --progress-bar -o "%PHP_ZIP%" "%TRY_URL%" 2>nul
call :validate_zip_file "%PHP_ZIP%"
if not errorlevel 1 (
  set "DOWNLOAD_OK=1"
  exit /b 0
)

if exist "%PHP_ZIP%" del "%PHP_ZIP%" 2>nul
set "CECHO_MSG=Probe failed: %TRY_URL%" & call :cecho DarkGray ""
exit /b 1

:probe_url_exists
set "PROBE_URL=%~1"
set "PROBE_OK="
if not defined PROBE_URL exit /b 1
powershell -NoProfile -ExecutionPolicy Bypass -Command "try { $r=Invoke-WebRequest -Uri '%PROBE_URL%' -Method Head -UseBasicParsing -TimeoutSec 30; if ($r.StatusCode -ge 200 -and $r.StatusCode -lt 400) { exit 0 } exit 1 } catch { exit 1 }" >nul 2>&1
if not errorlevel 1 (
  set "PROBE_OK=1"
  exit /b 0
)
exit /b 1

:validate_zip_file
set "VALIDATE_ZIP_FILE=%~1"
if not defined VALIDATE_ZIP_FILE exit /b 1
if not exist "%VALIDATE_ZIP_FILE%" exit /b 1
for %%z in ("%VALIDATE_ZIP_FILE%") do if %%~zz LEQ 100000 exit /b 1
powershell -NoProfile -ExecutionPolicy Bypass -Command "$path=$env:VALIDATE_ZIP_FILE; try { Add-Type -AssemblyName System.IO.Compression; Add-Type -AssemblyName System.IO.Compression.FileSystem; $stream=[System.IO.File]::OpenRead($path); $zip=$null; try { $zip=New-Object -TypeName System.IO.Compression.ZipArchive -ArgumentList @($stream, [System.IO.Compression.ZipArchiveMode]::Read, $false); if ($zip.Entries.Count -gt 0) { exit 0 } exit 1 } finally { if ($zip -ne $null) { $zip.Dispose() }; if ($stream -ne $null) { $stream.Dispose() } } } catch { exit 1 }" >nul 2>&1
if errorlevel 1 exit /b 1
exit /b 0

:grant_current_user_full_control
set "PERMISSION_TARGET=%~1"
if not defined PERMISSION_TARGET exit /b 0
icacls "%PERMISSION_TARGET%" /grant:r "%USERNAME%":(OI)(CI)F /T /Q >nul 2>&1
exit /b 0

:install_git
REM Windows: 优先 winget，其次 Chocolatey
where winget >nul 2>&1
if not errorlevel 1 (
  winget install --id Git.Git -e --source winget --accept-package-agreements --accept-source-agreements 2>nul
  if not errorlevel 1 (
    set "PATH=%PATH%;%ProgramFiles%\Git\cmd;%ProgramFiles(x86)%\Git\cmd"
    goto :eof
  )
)
where choco >nul 2>&1
if not errorlevel 1 (
  choco install git -y 2>nul
  if not errorlevel 1 (
    set "PATH=%PATH%;%ProgramFiles%\Git\cmd;%ProgramFiles(x86)%\Git\cmd"
    goto :eof
  )
)
exit /b 1

:cecho
set "CECHO_C=%~1"
if not "%~2"=="" set "CECHO_MSG=%~2"
if "!CECHO_C!"=="" exit /b 0
powershell -NoProfile -ExecutionPolicy Bypass -Command "Write-Host $env:CECHO_MSG -ForegroundColor $env:CECHO_C"
exit /b 0

:add_path
set "ADD_PATH_VALUE=%~1"
if not defined ADD_PATH_VALUE exit /b 0
REM 1) Persist: append to User PATH (no duplicate entries)
powershell -NoProfile -ExecutionPolicy Bypass -Command "$dir=$env:ADD_PATH_VALUE.TrimEnd('\'); $p=[Environment]::GetEnvironmentVariable('Path','User'); if ($p -eq $null) { $p='' }; $entries=($p -split ';' | ForEach-Object { $_.Trim().TrimEnd('\') } | Where-Object { $_ }); $exists=($entries | Where-Object { $_ -eq $dir }); if (-not $exists) { $new=if ($p) { $p.TrimEnd(';')+';'+$dir } else { $dir }; [Environment]::SetEnvironmentVariable('Path', $new, 'User'); Write-Host 'User PATH saved.' -ForegroundColor DarkGray }"
REM 2) Current session: prepend so PATH works without reopening terminal
set "PATH=%PATH%;%ADD_PATH_VALUE%"
exit /b 0

:build_clone_url
set "BUILD_REPO_URL=%~1"
set "TOKEN_VALUE=%WELINE_GITHUB_TOKEN%"
if not defined TOKEN_VALUE set "TOKEN_VALUE=%GITHUB_TOKEN%"
if not defined TOKEN_VALUE (
  set "CLONE_URL=%BUILD_REPO_URL%"
  exit /b 0
)
echo %BUILD_REPO_URL% | findstr /B /C:"https://github.com/" >nul
if errorlevel 1 (
  set "CLONE_URL=%BUILD_REPO_URL%"
  exit /b 0
)
set "CLONE_URL=%BUILD_REPO_URL:https://=https://x-access-token:%TOKEN_VALUE%@%"
exit /b 0
