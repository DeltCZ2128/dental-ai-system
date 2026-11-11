@echo off
title Abalos Dental Clinic - 1-Click Starter
color 0A

echo.
echo  ================================================================
echo    ABALOS DENTAL CLINIC SYSTEM - 1-CLICK LAUNCHER
echo  ================================================================
echo.

set "XAMPP_DIR=C:\xampp"

if not exist "%XAMPP_DIR%" (
    echo  [ERROR] XAMPP was not found at %XAMPP_DIR%!
    echo  Please ensure XAMPP is installed at C:\xampp.
    echo.
    pause
    exit /b 1
)

REM ----------------------------------------------------------------
REM 1. Start MySQL if not already running
REM ----------------------------------------------------------------
echo  [1/3] Checking MySQL database...
tasklist /FI "IMAGENAME eq mysqld.exe" 2>NUL | find /I /N "mysqld.exe" >NUL
if "%ERRORLEVEL%"=="0" (
    echo        [OK] MySQL is already running.
) else (
    REM Clean up stale pid or lock if computer restarted abruptly
    if exist "%XAMPP_DIR%\mysql\data\mysql.pid" del /f /q "%XAMPP_DIR%\mysql\data\mysql.pid" >nul 2>&1
    echo        [*] Starting MySQL in background...
    powershell -NoProfile -WindowStyle Hidden -Command "Start-Process '%XAMPP_DIR%\mysql\bin\mysqld.exe' -ArgumentList '--defaults-file=%XAMPP_DIR%\mysql\bin\my.ini --standalone' -WindowStyle Hidden"
    ping 127.0.0.1 -n 4 >nul
    tasklist /FI "IMAGENAME eq mysqld.exe" 2>NUL | find /I /N "mysqld.exe" >NUL
    if "%ERRORLEVEL%"=="0" (
        echo        [OK] MySQL started successfully.
    ) else (
        echo        [*] Performing auto-recovery on MariaDB tables...
        del /f /q "%XAMPP_DIR%\mysql\data\aria_log*" >nul 2>&1
        if exist "%XAMPP_DIR%\mysql\bin\aria_chk.exe" (
            "%XAMPP_DIR%\mysql\bin\aria_chk.exe" -o "%XAMPP_DIR%\mysql\data\mysql\*.MAI" >nul 2>&1
        )
        powershell -NoProfile -WindowStyle Hidden -Command "Start-Process '%XAMPP_DIR%\mysql\bin\mysqld.exe' -ArgumentList '--defaults-file=%XAMPP_DIR%\mysql\bin\my.ini --standalone' -WindowStyle Hidden"
        ping 127.0.0.1 -n 4 >nul
        tasklist /FI "IMAGENAME eq mysqld.exe" 2>NUL | find /I /N "mysqld.exe" >NUL
        if "%ERRORLEVEL%"=="0" (
            echo        [OK] MySQL auto-recovered and started successfully.
        ) else (
            echo        [*] Trying fallback start...
            start "" /min "%XAMPP_DIR%\mysql_start.bat"
            ping 127.0.0.1 -n 3 >nul
        )
    )
)

REM ----------------------------------------------------------------
REM Check & ensure dental_ai_system database exists
REM ----------------------------------------------------------------
"%XAMPP_DIR%\mysql\bin\mysql.exe" -u root -e "USE dental_ai_system;" >nul 2>&1
if errorlevel 1 (
    echo        [*] Initializing database dental_ai_system...
    "%XAMPP_DIR%\mysql\bin\mysql.exe" -u root -e "CREATE DATABASE IF NOT EXISTS dental_ai_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" >nul 2>&1
    if exist "%XAMPP_DIR%\htdocs\dental-ai-system-fixed\dental_ai.sql" (
        "%XAMPP_DIR%\mysql\bin\mysql.exe" -u root dental_ai_system < "%XAMPP_DIR%\htdocs\dental-ai-system-fixed\dental_ai.sql" >nul 2>&1
    ) else if exist "%~dp0dental_ai.sql" (
        "%XAMPP_DIR%\mysql\bin\mysql.exe" -u root dental_ai_system < "%~dp0dental_ai.sql" >nul 2>&1
    )
    echo        [OK] Database imported.
)

REM ----------------------------------------------------------------
REM 2. Start Apache if not already running
REM ----------------------------------------------------------------
echo.
echo  [2/3] Checking Apache web server...
tasklist /FI "IMAGENAME eq httpd.exe" 2>NUL | find /I /N "httpd.exe" >NUL
if "%ERRORLEVEL%"=="0" (
    echo        [OK] Apache is already running.
) else (
    echo        [*] Starting Apache in background...
    powershell -NoProfile -WindowStyle Hidden -Command "Start-Process '%XAMPP_DIR%\apache\bin\httpd.exe' -WindowStyle Hidden"
    ping 127.0.0.1 -n 3 >nul
    tasklist /FI "IMAGENAME eq httpd.exe" 2>NUL | find /I /N "httpd.exe" >NUL
    if "%ERRORLEVEL%"=="0" (
        echo        [OK] Apache started successfully.
    ) else (
        echo        [*] Trying fallback start...
        start "" /min "%XAMPP_DIR%\apache_start.bat"
        ping 127.0.0.1 -n 3 >nul
    )
)

REM ----------------------------------------------------------------
REM 2b. Start Permanent QR Tunnel in background (no window)
REM ----------------------------------------------------------------
if exist "%~dp0start_qr_tunnel_silent.vbs" (
    wscript.exe //B "%~dp0start_qr_tunnel_silent.vbs"
)

REM ----------------------------------------------------------------
REM 3. Open Dental AI System in default browser
REM ----------------------------------------------------------------
echo.
echo  [3/3] Opening Dental AI System in browser...
ping 127.0.0.1 -n 2 >nul
start "" "http://localhost/dental-ai-system-fixed/login.php"


echo.
echo  ================================================================
echo    [SUCCESS] System is live and running!
echo  ================================================================
echo.
echo    System URL:
echo      http://localhost/dental-ai-system-fixed/login.php
echo.
echo    Login Accounts:
echo      - Admin:   admin@dental.com   /  admin123
echo      - Dentist: dentist@gmail.com  /  dentist123
echo.
echo    Notes:
echo      - Apache and MySQL will stay running in the background.
echo      - You can close this console window anytime.
echo      - To stop all services later, run STOP_DENTAL_SYSTEM.bat.
echo  ================================================================
echo.
echo  Press any key to close this launcher...
pause >nul
