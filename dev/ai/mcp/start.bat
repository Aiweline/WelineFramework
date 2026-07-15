@echo off
setlocal EnableExtensions DisableDelayedExpansion
set "SCRIPT_DIR=%~dp0"
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
if errorlevel 1 (
  1>&2 echo MCP runtime verification failed after installation.
  exit /b 1
)
call :prepare_php_extensions
if errorlevel 1 exit /b 1

for %%I in ("%CONFIG_PATH%") do set "CONFIG_DIR=%%~dpI"
if not exist "%CONFIG_DIR%" mkdir "%CONFIG_DIR%" 1>&2
if not exist "%CONFIG_PATH%" (
  copy /Y "%SCRIPT_DIR%config.example.yaml" "%CONFIG_PATH%" 1>nul
  1>&2 echo Created MCP configuration: %CONFIG_PATH%
)

"%PHP_BIN%" "%SCRIPT_DIR%bin\learning-mcp" --config "%CONFIG_PATH%" %*
exit /b %errorlevel%

:find_runtime
set "PHP_BIN="
for /f "delims=" %%I in ('where php.exe 2^>nul') do if not defined PHP_BIN set "PHP_BIN=%%I"
if not defined PHP_BIN for /f "delims=" %%I in ('dir /b /s "%LOCALAPPDATA%\Microsoft\WinGet\Packages\PHP.PHP.8.4_*\php.exe" 2^>nul') do if not defined PHP_BIN set "PHP_BIN=%%I"
if not defined PHP_BIN exit /b 1
set "PHP_MAJOR="
set "PHP_MINOR="
for /f "tokens=1,2 delims=." %%A in ('"%PHP_BIN%" -r "echo PHP_MAJOR_VERSION,'.',PHP_MINOR_VERSION;"') do (
  set "PHP_MAJOR=%%A"
  set "PHP_MINOR=%%B"
)
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
if not errorlevel 1 (
  choco install php git -y 1>&2
  if errorlevel 1 exit /b 1
  exit /b 0
)
1>&2 echo Neither winget nor Chocolatey is available. Install PHP 8.2+ and Git, then run start.bat again.
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
if defined PHP_INI_SCAN_DIR (
  set "PHP_INI_SCAN_DIR=%MCP_PHP_INI_DIR%;%PHP_INI_SCAN_DIR%"
) else (
  set "PHP_INI_SCAN_DIR=%MCP_PHP_INI_DIR%"
)

:verify_extensions
"%PHP_BIN%" -r "foreach(['pdo_sqlite','json','mbstring','openssl'] as $extension){if(!extension_loaded($extension)){fwrite(STDERR,$extension.PHP_EOL);exit(1);}}" 1>nul
if errorlevel 1 (
  1>&2 echo Required PHP extensions are unavailable. Check the PHP ext directory and rerun start.bat.
  exit /b 1
)
exit /b 0
