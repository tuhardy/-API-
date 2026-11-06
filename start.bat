@echo off
setlocal

rem Double-click this file to start the PHP project and ngrok.
set "PROJECT_DIR=%~dp0message-system"
set "PHP_EXTENSION_DIR=D:\php\php-8.5.9-Win32-vs17-x64\ext"
set "PORT=8765"

where php >nul 2>nul
if errorlevel 1 (
    echo [ERROR] PHP was not found. Install PHP or add it to PATH.
    pause
    exit /b 1
)

where ngrok >nul 2>nul
if errorlevel 1 (
    echo [ERROR] ngrok was not found. Install ngrok or add it to PATH.
    pause
    exit /b 1
)

if not exist "%PROJECT_DIR%\public" (
    echo [ERROR] Project directory not found: %PROJECT_DIR%\public
    pause
    exit /b 1
)

echo Starting PHP server on http://127.0.0.1:%PORT% ...
start "PHP Server" cmd /k "cd /d "%PROJECT_DIR%" && php -d extension_dir="%PHP_EXTENSION_DIR%" -d extension=pdo_sqlite -S 127.0.0.1:%PORT% -t public"

rem Give PHP a moment to start before creating the tunnel.
timeout /t 2 /nobreak >nul

echo Starting ngrok tunnel ...
start "ngrok" cmd /k "ngrok http %PORT%"

echo.
echo Both services have been started in separate windows.
echo Open the HTTPS address shown by ngrok to access the project.
echo Close the PHP Server and ngrok windows to stop the services.
timeout /t 5 >nul
