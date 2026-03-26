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
set "PATH_ONLY="
set "DO_PHP=0"
set "DO_PGSQL=0"
set "DO_MYSQL=0"
set "BRANCH=master"

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
  set "PREV=%%a"
)

REM Read weline.env (optional)
if exist "%ROOT%\weline.env" for /f "usebackq eol=# tokens=1* delims==" %%a in ("%ROOT%\weline.env") do set "%%a=%%b"

REM Versions (weline.env or default)
if not defined INSTALL_PGSQL_VERSION set "INSTALL_PGSQL_VERSION=16"
if not defined INSTALL_MYSQL_VERSION set "INSTALL_MYSQL_VERSION=8.0"

REM PHP version major.minor (weline.env INSTALL_PHP_VERSION or default 8.4)
set "PHP_VER=8.4"
if defined INSTALL_PHP_VERSION set "PHP_VER=!INSTALL_PHP_VERSION!"
for /f "tokens=1,2 delims=." %%a in ("!PHP_VER!") do set "PHP_VER=%%a.%%b"

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
REM Speed-up: first use HEAD checks to find a valid PHP release zip,
REM so we only download the zip once (avoid downloading many failed candidates).
set "FOUND_PATCH="
set "FOUND_URL="
set "PHP_BASE_URL_PRIMARY=https://windows.php.net/downloads/releases/"
set "PHP_BASE_URL_ARCHIVE=https://downloads.php.net/~windows/releases/archives/"
for %%p in (16 15 14 13 12 11 10 9 8 7 6 5 4 3 2 1 0) do (
  if not defined FOUND_PATCH (
    set "URL_PRIMARY=!PHP_BASE_URL_PRIMARY!php-!PHP_VER!.%%p-Win32-!VS!-x64.zip"
    set "URL_ARCHIVE=!PHP_BASE_URL_ARCHIVE!php-!PHP_VER!.%%p-Win32-!VS!-x64.zip"
    set "CECHO_MSG=Checking PHP !PHP_VER!.%%p ..." & call :cecho Gray ""
    REM Some servers may not respond well to HEAD; a tiny ranged GET is more robust.
    curl -L -s --retry 3 --retry-all-errors --retry-delay 2 --connect-timeout 10 --max-time 60 -f -r 0-0 "!URL_PRIMARY!" -o NUL >nul 2>&1 && (
      set "FOUND_PATCH=%%p"
      set "FOUND_URL=!URL_PRIMARY!"
    )
    if not defined FOUND_PATCH (
      curl -L -s --retry 3 --retry-all-errors --retry-delay 2 --connect-timeout 10 --max-time 60 -f -r 0-0 "!URL_ARCHIVE!" -o NUL >nul 2>&1 && (
        set "FOUND_PATCH=%%p"
        set "FOUND_URL=!URL_ARCHIVE!"
      )
    )
  )
)
if not defined FOUND_PATCH goto :php_download_failed
if not defined FOUND_URL set "FOUND_URL=!PHP_BASE_URL_PRIMARY!php-!PHP_VER!.!FOUND_PATCH!-Win32-!VS!-x64.zip"
set "CECHO_MSG=Downloading PHP !PHP_VER!.!FOUND_PATCH! ..." & call :cecho Gray ""
curl -L -s --retry 5 --retry-all-errors --retry-delay 2 --connect-timeout 10 --max-time 300 -f -o "%TEMP%\weline-php.zip" "!FOUND_URL!" 2>nul >nul && set "FOUND=1"
if not defined FOUND goto :php_download_failed
mkdir "%PHP_DIR%" 2>nul
powershell -NoProfile -ExecutionPolicy Bypass -Command "Expand-Archive -Path '%TEMP%\weline-php.zip' -DestinationPath '%PHP_DIR%' -Force"
del "%TEMP%\weline-php.zip" 2>nul
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
set "CECHO_MSG=Download failed. Check network. Manual: https://windows.php.net/downloads/releases/ extract to %PHP_DIR%." & call :cecho Yellow ""
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
  set "CECHO_MSG=run.php not found. Installing framework code from gitee (branch: %BRANCH%)..." & call :cecho Yellow ""
  set "REPO_URL=https://gitee.com/aiweline/WelineFramework.git"
  if exist "%ROOT%\.git" (
    git -C "%ROOT%" fetch origin 2>nul
    git -C "%ROOT%" checkout %BRANCH% 2>nul || git -C "%ROOT%" pull origin %BRANCH% 2>nul
  ) else (
    git clone -b %BRANCH% %REPO_URL% "%ROOT%\temp_clone_weline" 2>nul
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
  icacls "%ROOT%" /grant:r "%USERNAME%":(OI)(CI)F /T /Q >nul 2>&1
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
set "RUN_ARGS="
if defined FORCE_INSTALL set "RUN_ARGS=-f"
call :cecho Gray "Running: php setup\server_installer\run.php %RUN_ARGS%"
"!USE_PHP!" "%ROOT%\setup\server_installer\run.php" %RUN_ARGS%
if errorlevel 1 exit /b 1
echo.
cd /d "%ROOT%"
call :cecho Green "Done. php, pgsql and bin (w command) have been added to User PATH. Current directory: project root. Reopen the terminal for PATH, then you can run: w setup:upgrade"
endlocal
exit /b 0

REM ---- Subroutines ----
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
