@echo off
setlocal EnableExtensions DisableDelayedExpansion
set "SCRIPT_DIR=%~dp0"
set "ACTION=%~1"
if not defined ACTION set "ACTION=install"

if /i "%ACTION%"=="serve" goto local_runtime
if defined WELINE_MCP_INTERNAL goto local_runtime
if exist "%SCRIPT_DIR%.weline-mcp-managed" goto bootstrap
if not exist "%SCRIPT_DIR%scripts\install.php" goto bootstrap
goto local_runtime

:bootstrap
set "SOURCE=%WELINE_MCP_SOURCE%"
if not defined SOURCE set "SOURCE=github"
set "PURGE_ARG="
if /i "%~2"=="github" set "SOURCE=github"
if /i "%~2"=="gitee" set "SOURCE=gitee"
if /i "%~2"=="--purge-data" set "PURGE_ARG=-PurgeData"
if /i "%~3"=="--purge-data" set "PURGE_ARG=-PurgeData"
if /i "%~2"=="--source=github" set "SOURCE=github"
if /i "%~2"=="--source=gitee" set "SOURCE=gitee"
if /i not "%ACTION%"=="install" if /i not "%ACTION%"=="status" if /i not "%ACTION%"=="uninstall" (
  1>&2 echo Usage: start.bat [install^|status^|uninstall] [github^|gitee] [--purge-data]
  exit /b 2
)
if /i not "%SOURCE%"=="github" if /i not "%SOURCE%"=="gitee" (1>&2 echo Source must be github or gitee. & exit /b 2)
set "BOOTSTRAP=%TEMP%\weline-mcp-bootstrap-%RANDOM%-%RANDOM%.ps1"
if exist "%SCRIPT_DIR%scripts\bootstrap-windows.ps1.txt" (
  copy /Y "%SCRIPT_DIR%scripts\bootstrap-windows.ps1.txt" "%BOOTSTRAP%" 1>nul
) else (
  if /i "%SOURCE%"=="gitee" (
    set "WELINE_BOOTSTRAP_URL=https://gitee.com/aiweline/weline-codex-mcp/raw/main/scripts/bootstrap-windows.ps1.txt"
  ) else (
    set "WELINE_BOOTSTRAP_URL=https://raw.githubusercontent.com/Aiweline/Weline-Codex-Mcp/main/scripts/bootstrap-windows.ps1.txt"
  )
  set "WELINE_BOOTSTRAP_FILE=%BOOTSTRAP%"
  powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "$ProgressPreference='SilentlyContinue'; Invoke-WebRequest -UseBasicParsing -Uri $env:WELINE_BOOTSTRAP_URL -OutFile $env:WELINE_BOOTSTRAP_FILE"
  if errorlevel 1 (1>&2 echo Unable to download the Windows bootstrap. & exit /b 1)
)
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%BOOTSTRAP%" -Action "%ACTION%" -Source "%SOURCE%" %PURGE_ARG%
set "STATUS=%ERRORLEVEL%"
del /Q "%BOOTSTRAP%" 2>nul
exit /b %STATUS%

:local_runtime
if not defined USERPROFILE (
  1>&2 echo USERPROFILE is required to create the default MCP configuration.
  exit /b 1
)
if not defined HOME set "HOME=%USERPROFILE%"
if not defined LEARNING_MCP_CONFIG set "LEARNING_MCP_CONFIG=%USERPROFILE%\.learning-mcp\config.yaml"
set "CONFIG_PATH=%LEARNING_MCP_CONFIG%"
set "PATH=%LOCALAPPDATA%\Microsoft\WinGet\Links;%ProgramFiles%\Git\cmd;%PATH%"
call :find_runtime
if errorlevel 1 call :install_runtime
call :find_runtime
if errorlevel 1 (1>&2 echo MCP runtime verification failed after installation. & exit /b 1)
call :prepare_php_extensions
if errorlevel 1 exit /b 1
if /i "%ACTION%"=="serve" goto serve
if /i "%ACTION%"=="install" goto installer
if /i "%ACTION%"=="status" goto installer
if /i "%ACTION%"=="uninstall" goto installer
1>&2 echo Usage: start.bat [install^|status^|uninstall^|serve] [options]
exit /b 2

:installer
"%PHP_BIN%" "%SCRIPT_DIR%scripts\install.php" %*
exit /b %errorlevel%

:serve
for %%I in ("%CONFIG_PATH%") do set "CONFIG_DIR=%%~dpI"
if not exist "%CONFIG_DIR%" mkdir "%CONFIG_DIR%" 1>&2
if not exist "%CONFIG_PATH%" copy /Y "%SCRIPT_DIR%config.example.yaml" "%CONFIG_PATH%" 1>nul
"%PHP_BIN%" "%SCRIPT_DIR%bin\learning-mcp" --config "%CONFIG_PATH%"
exit /b %errorlevel%

:find_runtime
set "PHP_BIN="
for /f "delims=" %%I in ('where php.exe 2^>nul') do if not defined PHP_BIN set "PHP_BIN=%%I"
if not defined PHP_BIN for /f "delims=" %%I in ('dir /b /s "%LOCALAPPDATA%\Microsoft\WinGet\Packages\PHP.PHP.8.4_*\php.exe" 2^>nul') do if not defined PHP_BIN set "PHP_BIN=%%I"
if not defined PHP_BIN exit /b 1
set "PHP_MAJOR="
set "PHP_MINOR="
for /f "tokens=1,2 delims=." %%A in ('"%PHP_BIN%" -r "echo PHP_MAJOR_VERSION,'.',PHP_MINOR_VERSION;"') do (set "PHP_MAJOR=%%A" & set "PHP_MINOR=%%B")
if not defined PHP_MAJOR exit /b 1
if %PHP_MAJOR% LSS 8 exit /b 1
if %PHP_MAJOR% EQU 8 if %PHP_MINOR% LSS 2 exit /b 1
where git.exe 1>nul 2>nul
if errorlevel 1 exit /b 1
exit /b 0

:install_runtime
1>&2 echo Installing PHP 8.4 and Git...
where winget.exe 1>nul 2>nul
if not errorlevel 1 (
  winget install --id PHP.PHP.8.4 --exact --source winget --accept-package-agreements --accept-source-agreements 1>&2
  if errorlevel 1 exit /b 1
  winget install --id Git.Git --exact --source winget --accept-package-agreements --accept-source-agreements 1>&2
  if errorlevel 1 exit /b 1
  set "PATH=%LOCALAPPDATA%\Microsoft\WinGet\Links;%ProgramFiles%\Git\cmd;%PATH%"
  exit /b 0
)
where choco.exe 1>nul 2>nul
if not errorlevel 1 (choco install php git -y 1>&2 & if errorlevel 1 exit /b 1 & exit /b 0)
1>&2 echo Neither winget nor Chocolatey is available. Install PHP 8.2+ and Git, then retry.
exit /b 1

:prepare_php_extensions
for %%I in ("%PHP_BIN%") do set "PHP_DIR=%%~dpI"
set "NEED_PDO_SQLITE="
set "NEED_MBSTRING="
set "NEED_OPENSSL="
"%PHP_BIN%" -r "exit(extension_loaded('pdo_sqlite')?0:1);" 1>nul 2>nul
if errorlevel 1 set "NEED_PDO_SQLITE=1"
"%PHP_BIN%" -r "exit(extension_loaded('mbstring')?0:1);" 1>nul 2>nul
if errorlevel 1 set "NEED_MBSTRING=1"
"%PHP_BIN%" -r "exit(extension_loaded('openssl')?0:1);" 1>nul 2>nul
if errorlevel 1 set "NEED_OPENSSL=1"
if not defined NEED_PDO_SQLITE if not defined NEED_MBSTRING if not defined NEED_OPENSSL goto verify_extensions
set "MCP_PHP_INI_DIR=%USERPROFILE%\.learning-mcp\php-conf.d"
if not exist "%MCP_PHP_INI_DIR%" mkdir "%MCP_PHP_INI_DIR%" 1>&2
set "MCP_PHP_INI=%MCP_PHP_INI_DIR%\weline-mcp.ini"
> "%MCP_PHP_INI%" echo extension_dir="%PHP_DIR%ext"
if defined NEED_PDO_SQLITE >> "%MCP_PHP_INI%" echo extension=pdo_sqlite
if defined NEED_MBSTRING >> "%MCP_PHP_INI%" echo extension=mbstring
if defined NEED_OPENSSL >> "%MCP_PHP_INI%" echo extension=openssl
if defined PHP_INI_SCAN_DIR (set "PHP_INI_SCAN_DIR=%MCP_PHP_INI_DIR%;%PHP_INI_SCAN_DIR%") else (set "PHP_INI_SCAN_DIR=%MCP_PHP_INI_DIR%")
:verify_extensions
"%PHP_BIN%" -r "foreach(['pdo_sqlite','json','mbstring','openssl'] as $extension){if(!extension_loaded($extension)){fwrite(STDERR,$extension.PHP_EOL);exit(1);}}" 1>nul
if errorlevel 1 (1>&2 echo Required PHP extensions are unavailable. Check the PHP ext directory and retry. & exit /b 1)
exit /b 0
