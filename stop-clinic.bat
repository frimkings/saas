@echo off
title Eye Clinic - Stopping System
color 0C

echo.
echo  +------------------------------------------+
echo  ^|    Eye Clinic Management System           ^|
echo  ^|    Stopping...                            ^|
echo  +------------------------------------------+
echo.

:: Stop Apache
echo  Stopping Apache web server...
net stop Apache2.4 >nul 2>&1
if %errorlevel% neq 0 (
    call "C:\xampp\apache_stop.bat" >nul 2>&1
)

:: Stop MySQL
echo  Stopping MySQL database...
net stop MySQL >nul 2>&1
if %errorlevel% neq 0 (
    call "C:\xampp\mysql_stop.bat" >nul 2>&1
)

echo.
echo  +------------------------------------------+
echo  ^|  Eye Clinic has been stopped safely.     ^|
echo  ^|  It is now safe to shut down the PC.     ^|
echo  +------------------------------------------+
echo.
echo  Press any key to close this window...
pause >nul
