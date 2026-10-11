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
echo  [*] Stopping MySQL database...
taskkill /F /IM mysqld.exe >nul 2>&1
if "%ERRORLEVEL%"=="0" (
    echo      [OK] MySQL stopped.
) else (
    echo      [i] MySQL was not running.
)

echo.
echo  ================================================================
echo    [DONE] All Dental System services have been stopped.
echo  ================================================================
echo.
ping 127.0.0.1 -n 3 >nul
