@echo off
REM Y4APK foss-mirror helper for Windows.
REM Usage:  run_windows.bat stats      run_windows.bat list --games      run_windows.bat run --limit 20
REM Paths you type (--csv, --dest-dir, --config, --work-dir) are relative to the folder you are in.
setlocal
chcp 65001 >nul
set PYTHONUTF8=1
set "HERE=%~dp0"
set "VENV=%HERE%.venv"
if not exist "%VENV%\Scripts\python.exe" (
  echo Creating virtual environment .venv ...
  python -m venv "%VENV%" || goto :nopython
)
if not exist "%VENV%\deps-installed.txt" (
  "%VENV%\Scripts\python.exe" -m pip install --upgrade pip
  "%VENV%\Scripts\python.exe" -m pip install -r "%HERE%requirements.txt" || exit /b 1
  echo ok> "%VENV%\deps-installed.txt"
)
if "%~1"=="" (
  REM double-clicked: show help and keep the window open
  "%VENV%\Scripts\python.exe" "%HERE%foss_mirror.py" -h
  echo.
  echo Open Command Prompt and type for example:  run_windows.bat stats
  pause
  exit /b 0
)
"%VENV%\Scripts\python.exe" "%HERE%foss_mirror.py" %*
exit /b %errorlevel%

:nopython
echo [ERROR] Python 3.9+ not found. Install Python 3.12 from https://www.python.org/downloads/
echo         and tick "Add python.exe to PATH" during setup, then open a new Command Prompt.
pause
exit /b 1
