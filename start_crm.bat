@echo off
setlocal
SET "PROJECT_DIR=%~dp0"
SET "PORT=8080"
SET "URL=http://localhost:%PORT%/login"

echo ========================================================
echo   BASE FARE CRM - AUTO STARTUP
echo ========================================================

cd /d "%PROJECT_DIR%"

echo [1/3] Checking if port %PORT% is already in use...
netstat -ano | findstr :%PORT% >nul 2>&1
if %ERRORLEVEL% equ 0 (
    echo [!] Port %PORT% is already in use. Attempting to restart...
    for /f "tokens=5" %%a in ('netstat -aon ^| findstr :%PORT%') do (
        echo [!] Killing process ID %%a...
        taskkill /F /PID %%a >nul 2>&1
    )
) else (
    echo [OK] Port %PORT% is free.
)

echo [2/3] Starting PHP Development Server...
start "Base Fare CRM Server" /min php -S localhost:%PORT% -t public

echo [3/3] Opening CRM in default browser...
timeout /t 2 /nobreak >nul
start "" "%URL%"

echo ========================================================
echo   CRM is now running at %URL%
echo   (Server window is minimized in taskbar)
echo ========================================================
pause
