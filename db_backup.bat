@echo off
:: --- CONFIGURATION ---
SET PROJECT_DIR=C:\laragon\www\eyeclinicproject
SET BACKUP_ROOT=C:\laragon\www\eyeclinicproject\backups

SET MYSQL_BIN=C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqldump.exe
SET DB_NAME=eyeclinicproject
SET DB_USER=root

:: --- GET TIMESTAMP (UNIVERSAL METHOD) ---
for /f "tokens=2-4 delims=/ " %%a in ('echo %date%') do (set DATE_STAMP=%%c_%%a_%%b)
if "%DATE_STAMP%"=="" set DATE_STAMP=%date:~10,4%_%date:~4,2%_%date:~7,2%
set DATE_STAMP=%DATE_STAMP:/=-%
set DATE_STAMP=%DATE_STAMP: =%

:: --- DEFINE SPECIFIC BACKUP DESTINATIONS ---
SET MIGRATION_DIR=%BACKUP_ROOT%\%DATE_STAMP%
SET DB_BACKUP_FILE=%MIGRATION_DIR%\%DB_NAME%_%DATE_STAMP%.sql
SET UPLOADS_SOURCE=%PROJECT_DIR%\storage\app\public
SET UPLOADS_DEST=%MIGRATION_DIR%\uploaded_files

:: --- CREATE TODAY'S BACKUP DIRECTORY ---
if not exist "%MIGRATION_DIR%" mkdir "%MIGRATION_DIR%"

echo ===================================================
echo STARTING CLINIC SYSTEM BACKUP: %DATE_STAMP%
echo ===================================================

:: 1. BACK UP THE DATABASE
echo Running Database Export...
"%MYSQL_BIN%" -u %DB_USER% --max_allowed_packet=512M %DB_NAME% > "%DB_BACKUP_FILE%" 2> "%MIGRATION_DIR%\backup_error.log"

:: 2. BACK UP UPLOADED FILES (PDFs, Images, etc.)
echo Copying User Uploaded Files...
if exist "%UPLOADS_SOURCE%" (
    :: Robocopy syntax: robocopy [source] [destination] [options]
    :: /E = Copies subdirectories, including empty ones.
    :: /Z = Copies files in restartable mode (survives network/hardware glitches).
    :: /R:5 = Retry 5 times if a file is locked by a user.
    :: /W:5 = Wait 5 seconds between retries.
    robocopy "%UPLOADS_SOURCE%" "%UPLOADS_DEST%" /E /Z /R:5 /W:5 > nul
    echo Files copied successfully!
) else (
    echo WARNING: Uploads folder not found at %UPLOADS_SOURCE%
)

echo ===================================================
echo BACKUP COMPLETE!
echo Saved to: %MIGRATION_DIR%
echo ===================================================

:: --- DELETE BACKUP FOLDERS OLDER THAN 30 DAYS ---
:: Looks for date-stamped root directories to clean up space
for /f "tokens=*" %%d in ('dir "%BACKUP_ROOT%" /ad /b') do (
    ForFiles /p "%BACKUP_ROOT%" /m "%%d" /d -30 /c "cmd /c rmdir /s /q @path" 2>nul
)

pause