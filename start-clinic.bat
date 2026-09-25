@echo off
title Eye Clinic - Starting System
color 0A

echo.
echo  +------------------------------------------+
echo  ^|    Eye Clinic Management System           ^|
echo  ^|    Starting...                            ^|
echo  +------------------------------------------+
echo.

:: Start Apache
echo  Starting Apache web server...
net start Apache2.4 >nul 2>&1
if %errorlevel% neq 0 (
    call "C:\xampp\apache_start.bat" >nul 2>&1
)

:: Start MySQL
echo  Starting MySQL database...
net start MySQL >nul 2>&1
if %errorlevel% neq 0 (
    call "C:\xampp\mysql_start.bat" >nul 2>&1
)

:: Wait for services to be ready
timeout /t 4 /nobreak >nul

echo.
echo  +------------------------------------------+
echo  ^|  System is ready!                        ^|
echo  ^|                                          ^|
echo  ^|  Open your browser and go to:            ^|
echo  ^|    http://eyeclinicproject.test          ^|
echo  +------------------------------------------+
echo.
echo  Press any key to close this window...
pause >nul
