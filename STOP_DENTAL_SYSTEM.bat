@echo off
title Abalos Dental Clinic - Stop Services
color 0C

echo.
echo  ================================================================
echo    ABALOS DENTAL CLINIC SYSTEM - STOP SERVICES
echo  ================================================================
echo.

echo  [*] Stopping Apache web server...
taskkill /F /IM httpd.exe >nul 2>&1
if "%ERRORLEVEL%"=="0" (
    echo      [OK] Apache stopped.
) else (
    echo      [i] Apache was not running.
)

echo.
echo  [*] Gracefully stopping MySQL database...
if exist "C:\xampp\mysql\bin\mysqladmin.exe" (
    "C:\xampp\mysql\bin\mysqladmin.exe" -u root shutdown >nul 2>&1
)
ping 127.0.0.1 -n 3 >nul
tasklist /FI "IMAGENAME eq mysqld.exe" 2>NUL | find /I /N "mysqld.exe" >NUL
if "%ERRORLEVEL%"=="0" (
    taskkill /IM mysqld.exe >nul 2>&1
    ping 127.0.0.1 -n 2 >nul
)
tasklist /FI "IMAGENAME eq mysqld.exe" 2>NUL | find /I /N "mysqld.exe" >NUL
if "%ERRORLEVEL%"=="0" (
    taskkill /F /IM mysqld.exe >nul 2>&1
)
echo      [OK] MySQL cleanly stopped.

echo.
echo  ================================================================
echo    [DONE] All Dental System services have been stopped.
echo  ================================================================
echo.
ping 127.0.0.1 -n 3 >nul
