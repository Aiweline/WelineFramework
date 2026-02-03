@echo off
REM Weline Server Windows 多进程启动脚本
REM 用法: start-multi.bat [基础端口] [进程数]

setlocal

set BASE_PORT=%1
set COUNT=%2

if "%BASE_PORT%"=="" set BASE_PORT=8080
if "%COUNT%"=="" set COUNT=4

echo ===================================
echo  Weline Server Multi-Process Mode
echo ===================================
echo Base Port: %BASE_PORT%
echo Workers: %COUNT%
echo.

REM 获取当前脚本所在目录
set SCRIPT_DIR=%~dp0

REM 获取 PHP 路径
for %%I in (php.exe) do set PHP_PATH=%%~$PATH:I
if "%PHP_PATH%"=="" (
    echo ERROR: PHP not found in PATH
    exit /b 1
)

echo PHP: %PHP_PATH%
echo.
echo Starting workers...
echo.

REM 启动多个 Worker 进程
set /a END_COUNT=%COUNT%-1
for /L %%i in (0,1,%END_COUNT%) do (
    set /a PORT=%BASE_PORT%+%%i
    set /a WORKER_ID=%%i+1
    call :START_WORKER %%i
)

echo.
echo ===================================
echo All workers started!
echo.
echo Ports: %BASE_PORT% - 
set /a LAST_PORT=%BASE_PORT%+%COUNT%-1
echo Ports: %BASE_PORT% to %LAST_PORT%
echo.
echo Press any key to stop all workers...
pause > nul

REM 停止所有 Worker
echo.
echo Stopping workers...
taskkill /FI "WindowTitle eq Weline-Worker-*" /T > nul 2>&1

echo Done.
exit /b 0

:START_WORKER
set /a WPORT=%BASE_PORT%+%1
set /a WID=%1+1
echo Starting Worker #%WID% on port %WPORT%
start "Weline-Worker-%WID%" /MIN "%PHP_PATH%" "%SCRIPT_DIR%worker.php" 127.0.0.1 %WPORT% %WID%
exit /b 0
