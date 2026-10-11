@echo off
REM ============================================================
REM  PERMANENT QR STARTER - Abalos Dental Clinic
REM  Hindi nag-e-expire, kahit anong WiFi/data, Android + iPhone
REM ------------------------------------------------------------
REM  ISANG BESES LANG NA SETUP (bago ito gumana):
REM   1. Signup sa https://ngrok.com (libre) > kunin ang Authtoken.
REM   2. Dashboard > Domains > New Domain (hal. abalos-dental.ngrok-free.app)
REM   3. I-run minsan:  ngrok config add-authtoken PASTE_TOKEN_MO
REM   4. Sa ibaba, palitan ang YOUR_STATIC_DOMAIN ng domain mo.
REM   5. Sa config.php, ilagay din ang domain sa PERMANENT_QR_DOMAIN.
REM ============================================================

set STATIC_DOMAIN=appendage-pastrami-untracked.ngrok-free.dev
set PORT=80

echo.
echo  === Abalos Dental Permanent QR ===
echo  Domain : %STATIC_DOMAIN%
echo  Port   : %PORT%
echo.

if "%STATIC_DOMAIN%"=="YOUR_STATIC_DOMAIN.ngrok-free.app" (
    echo  [KAILANGAN] Palitan muna ang YOUR_STATIC_DOMAIN sa loob ng
    echo  file na ito ^(right-click ^> Edit^) ng static domain mo mula ngrok.
    echo  Halimbawa: set STATIC_DOMAIN=abalos-dental.ngrok-free.app
    echo.
    pause
    exit /b 1
)

if exist "%~dp0ngrok.exe" (
    set NGROK=%~dp0ngrok.exe
) else (
    set NGROK=ngrok
)
%NGROK% version >nul 2>nul
if errorlevel 1 (
    echo  [KAILANGAN] Hindi nakita ang ngrok. Nasa folder na ito dapat ang ngrok.exe.
    echo.
    pause
    exit /b 1
)

echo  [1/2] Tinitingnan kung bukas ang XAMPP Apache sa port %PORT% ...
curl.exe -s -o NUL --max-time 5 http://localhost:%PORT%/dental-ai-system-fixed/walkin_qr.php
if errorlevel 1 (
    echo  [BABALA] Hindi maabot ang http://localhost:%PORT%/ - buksan ang XAMPP
    echo  Control Panel at i-Start ang Apache ^(+ MySQL^) bago ituloy.
    echo.
    pause
) else (
    echo  OK - Apache sumasagot.
)

echo.
echo  [2/2] Bumubukas ng PERMANENT tunnel (huwag isara ang window na ito) ...
echo  Permanenteng link: https://%STATIC_DOMAIN%/dental-ai-system-fixed/walkin_qr.php
echo.
"%NGROK%" http --url=%STATIC_DOMAIN% %PORT%
pause
