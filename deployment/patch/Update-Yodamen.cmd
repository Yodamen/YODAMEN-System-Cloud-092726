@echo off
setlocal
powershell.exe -NoProfile -ExecutionPolicy RemoteSigned -File "%~dp0Update-Windows.ps1" %*
set "UPDATE_RESULT=%ERRORLEVEL%"
echo.
pause
exit /b %UPDATE_RESULT%
