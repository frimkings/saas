#Requires -Version 5.1
<#
.SYNOPSIS
    Eye Clinic Management System - Automated Installer
.DESCRIPTION
    Run this file by right-clicking it and choosing "Run with PowerShell".
    It will set up the entire Eye Clinic system automatically.
    Requires XAMPP and Composer to be installed first.
#>

Set-StrictMode -Off
$ErrorActionPreference = 'Stop'

# ── Self-elevate to Administrator ─────────────────────────────────────────────
if (-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]'Administrator')) {
    Write-Host "Requesting Administrator privileges..." -ForegroundColor Yellow
    Start-Process powershell.exe "-NoProfile -ExecutionPolicy Bypass -File `"$PSCommandPath`"" -Verb RunAs
    exit
}

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Definition

# ── Helpers ───────────────────────────────────────────────────────────────────
function Write-Section { param($text) Write-Host "`n  $text" -ForegroundColor Cyan }
function Write-OK      { param($text) Write-Host "  [OK]  $text" -ForegroundColor Green }
function Write-Info    { param($text) Write-Host "  [..] $text"  -ForegroundColor Yellow }
function Write-Err     { param($text) Write-Host "  [!!] $text"  -ForegroundColor Red }

function Stop-WithError {
    param([string]$Message)
    Write-Host ""
    Write-Host "  ════════════════════════════════════════" -ForegroundColor Red
    Write-Err  "INSTALLATION FAILED"
    Write-Err  $Message
    Write-Host "  ════════════════════════════════════════" -ForegroundColor Red
    Write-Host "`n  Press any key to exit..."
    try { $null = $Host.UI.RawUI.ReadKey('NoEcho,IncludeKeyDown') } catch {}
    exit 1
}

function Run-Artisan {
    param([string[]]$Arguments, [string]$Label)
    $output = & $PHP "$ScriptDir\artisan" @Arguments 2>&1
    if ($LASTEXITCODE -ne 0) {
        Stop-WithError "$Label failed.`n`n$($output | Out-String)"
    }
    Write-OK $Label
}

function Restart-Apache {
    $stopBat  = "C:\xampp\apache_stop.bat"
    $startBat = "C:\xampp\apache_start.bat"
    if (Test-Path $stopBat) {
        & cmd.exe /c "`"$stopBat`"" 2>$null | Out-Null
    } else {
        net stop Apache2.4 2>$null | Out-Null
    }
    Start-Sleep -Seconds 2
    if (Test-Path $startBat) {
        & cmd.exe /c "`"$startBat`"" 2>$null | Out-Null
    } else {
        net start Apache2.4 2>$null | Out-Null
    }
    Start-Sleep -Seconds 3
}

# ── Banner ────────────────────────────────────────────────────────────────────
Clear-Host
Write-Host ""
Write-Host "  +----------------------------------------------------------+" -ForegroundColor Cyan
Write-Host "  |      EYE CLINIC MANAGEMENT SYSTEM  -  INSTALLER          |" -ForegroundColor Cyan
Write-Host "  +----------------------------------------------------------+" -ForegroundColor Cyan
Write-Host ""

# ── Step 1: Check XAMPP ───────────────────────────────────────────────────────
Write-Section "Step 1 of 9 - Checking prerequisites"

$XamppRoot = "C:\xampp"
if (-not (Test-Path $XamppRoot)) {
    Stop-WithError @"
XAMPP is not installed at C:\xampp.

Please install XAMPP first, then run this installer again:
  1. Go to:  https://www.apachefriends.org/download.html
  2. Download the PHP 8.2 version for Windows
  3. Run the installer, keep the default install path (C:\xampp)
  4. When done, come back and run this installer again
"@
}

$PHP   = "$XamppRoot\php\php.exe"
$MySQL = "$XamppRoot\mysql\bin\mysql.exe"

if (-not (Test-Path $PHP))   { Stop-WithError "PHP not found at $PHP — please reinstall XAMPP." }
if (-not (Test-Path $MySQL)) { Stop-WithError "MySQL not found at $MySQL — please reinstall XAMPP." }
Write-OK "XAMPP is installed"

# Make sure MySQL is running before we try to use it
Write-Info "Starting MySQL..."
$mysqlStartBat = "C:\xampp\mysql_start.bat"
if (Test-Path $mysqlStartBat) {
    & cmd.exe /c "`"$mysqlStartBat`"" 2>$null | Out-Null
} else {
    net start MySQL 2>$null | Out-Null
}
Start-Sleep -Seconds 3

# ── Step 2: Check Composer ────────────────────────────────────────────────────
$ComposerCmd = $null
$composerCandidates = @(
    'C:\ProgramData\ComposerSetup\bin\composer.bat',
    "$env:APPDATA\Composer\vendor\bin\composer.bat",
    "$env:USERPROFILE\AppData\Roaming\Composer\vendor\bin\composer.bat"
)
foreach ($c in $composerCandidates) {
    if (Test-Path $c) { $ComposerCmd = $c; break }
}
if (-not $ComposerCmd) {
    $found = Get-Command composer -ErrorAction SilentlyContinue
    if ($found) { $ComposerCmd = $found.Source }
}
if (-not $ComposerCmd) {
    Stop-WithError @"
Composer is not installed.

Please install Composer first, then run this installer again:
  1. Go to:  https://getcomposer.org/Composer-Setup.exe
  2. Run the Composer-Setup.exe installer
  3. When it asks for PHP, point it to:  C:\xampp\php\php.exe
  4. Restart your computer, then run this installer again
"@
}
Write-OK "Composer is installed"

# ── Step 3: Read existing .env settings ───────────────────────────────────────
$EnvFile = "$ScriptDir\.env"
$AppName = "Eye Clinic"
$DbName  = "eyeclinicproject"
$DbPass  = ""
$Domain  = "eyeclinicproject.test"
$AppUrl  = "http://eyeclinicproject.test"
$AppKey  = ""

if (Test-Path $EnvFile) {
    $envRaw = Get-Content $EnvFile -Raw -ErrorAction SilentlyContinue
    if ($envRaw -match 'APP_NAME=["]?([^"\r\n]+)["]?')  { $AppName = $Matches[1].Trim().Trim('"') }
    if ($envRaw -match 'DB_DATABASE=([^\r\n]+)')         { $DbName  = $Matches[1].Trim() }
    if ($envRaw -match 'DB_PASSWORD=([^\r\n]*)')         { $DbPass  = $Matches[1].Trim() }
    if ($envRaw -match 'APP_URL=([^\r\n]+)') {
        $AppUrl = $Matches[1].Trim()
        $Domain = $AppUrl -replace 'https?://', ''
    }
    if ($envRaw -match 'APP_KEY=(base64:[^\r\n]+)') { $AppKey = $Matches[1].Trim() }
}

# ── Step 4: Show settings summary — one keypress ──────────────────────────────
Write-Host ""
Write-Host "  Settings that will be used:" -ForegroundColor White
Write-Host "  --------------------------------------------------" -ForegroundColor DarkGray
Write-Host ("  Clinic name    : " + $AppName) -ForegroundColor White
Write-Host ("  Database       : " + $DbName)  -ForegroundColor White
Write-Host ("  MySQL password : " + $(if ($DbPass) { "(already set)" } else { "(none - XAMPP default)" })) -ForegroundColor White
Write-Host ("  Website URL    : " + $AppUrl)  -ForegroundColor White
Write-Host "  Email alerts   : configure after install in Settings" -ForegroundColor DarkGray
Write-Host "  --------------------------------------------------" -ForegroundColor DarkGray
Write-Host ""
Write-Host "  Press Enter to begin installation, or Ctrl+C to cancel." -ForegroundColor Yellow
Read-Host | Out-Null

# ── Step 5: Enable PHP extensions ─────────────────────────────────────────────
Write-Section "Step 2 of 9 - Enabling PHP extensions"

$phpIni = "$XamppRoot\php\php.ini"
if (-not (Test-Path $phpIni)) { Stop-WithError "php.ini not found at $phpIni" }

$ini = Get-Content $phpIni -Raw
foreach ($ext in @('pdo_mysql','mysqli','fileinfo','openssl','mbstring','zip','gd','sodium')) {
    $ini = $ini -replace "(?m)^;(extension=$ext\b)", '$1'
}
[System.IO.File]::WriteAllText($phpIni, $ini, [System.Text.Encoding]::UTF8)
Write-OK "Extensions enabled: pdo_mysql, mysqli, fileinfo, openssl, mbstring, zip, gd, sodium"

# ── Step 6: Configure Apache ──────────────────────────────────────────────────
Write-Section "Step 3 of 9 - Configuring Apache web server"

# Enable mod_rewrite and include vhosts file
$httpdConf    = "$XamppRoot\apache\conf\httpd.conf"
$httpdContent = Get-Content $httpdConf -Raw
$httpdContent = $httpdContent -replace '(?m)^#(LoadModule rewrite_module\b.*)', '$1'
$httpdContent = $httpdContent -replace '(?m)^#(Include conf/extra/httpd-vhosts\.conf)', '$1'
[System.IO.File]::WriteAllText($httpdConf, $httpdContent, [System.Text.Encoding]::UTF8)
Write-OK "mod_rewrite enabled"

# Add virtual host block if not already present
$vhostConf = "$XamppRoot\apache\conf\extra\httpd-vhosts.conf"
$vhostRaw  = if (Test-Path $vhostConf) { Get-Content $vhostConf -Raw } else { "" }
$docRoot   = ($ScriptDir + "\public") -replace '\\', '/'

if ($vhostRaw -notmatch [regex]::Escape("ServerName $Domain")) {
    $block = @"

<VirtualHost *:80>
    ServerName $Domain
    DocumentRoot "$docRoot"
    <Directory "$docRoot">
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    <FilesMatch "(^\.env|^\.git|composer\.(json|lock)|artisan)$">
        Require all denied
    </FilesMatch>
</VirtualHost>
"@
    Add-Content $vhostConf $block -Encoding UTF8
    Write-OK "Virtual host created for $Domain (with .env protection)"
} else {
    Write-OK "Virtual host already exists for $Domain"
}

# Add hosts file entry if not already present
$hostsFile    = "C:\Windows\System32\drivers\etc\hosts"
$hostsContent = Get-Content $hostsFile -Raw -ErrorAction SilentlyContinue
if ($hostsContent -notmatch [regex]::Escape($Domain)) {
    $hostsLine = "`r`n127.0.0.1`t$Domain"
    [System.IO.File]::AppendAllText($hostsFile, $hostsLine, [System.Text.Encoding]::ASCII)
    Write-OK "Added $Domain to hosts file"
} else {
    Write-OK "hosts file already has $Domain"
}

# Restrict phpMyAdmin to localhost only (blocks access from other devices on the network)
$xamppConf = "$XamppRoot\apache\conf\extra\httpd-xampp.conf"
if (Test-Path $xamppConf) {
    $xc = Get-Content $xamppConf -Raw
    if ($xc -match 'phpmyadmin' -and $xc -notmatch 'Require local') {
        $xc = $xc -replace '(?si)(<Directory[^>]*phpmyadmin[^>]*>.*?)(Require all granted)', '$1Require local'
        [System.IO.File]::WriteAllText($xamppConf, $xc, [System.Text.Encoding]::UTF8)
        Write-OK "phpMyAdmin restricted to localhost only"
    } else {
        Write-OK "phpMyAdmin already restricted (or not found in config)"
    }
}

Write-Info "Restarting Apache..."
Restart-Apache
Write-OK "Apache restarted"

# ── Step 7: Create database ────────────────────────────────────────────────────
Write-Section "Step 4 of 9 - Setting up the database"

# Test connection; if it fails prompt once for password
$testArgs = @("-u", "root", "--connect-timeout=5", "-e", "SELECT 1;")
if ($DbPass) { $testArgs = @("-u", "root", "--password=$DbPass", "--connect-timeout=5", "-e", "SELECT 1;") }
$testOut = & $MySQL @testArgs 2>&1
if ($LASTEXITCODE -ne 0) {
    Write-Info "MySQL connection failed with current password."
    $DbPass = Read-Host "  Enter MySQL root password (press Enter if none)"
}

$createArgs = @("-u", "root", "-e", "CREATE DATABASE IF NOT EXISTS ``$DbName`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;")
if ($DbPass) { $createArgs = @("-u", "root", "--password=$DbPass", "-e", "CREATE DATABASE IF NOT EXISTS ``$DbName`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;") }
$createOut  = & $MySQL @createArgs 2>&1
if ($LASTEXITCODE -ne 0) { Stop-WithError "Could not create database '$DbName'.`n$($createOut | Out-String)" }
Write-OK "Database '$DbName' is ready"

# Create dedicated app user with limited privileges (not root)
$DbAppUser = "eyeclinic_user"
$DbAppPass = -join ((65..90) + (97..122) + (48..57) | Get-Random -Count 24 | ForEach-Object { [char]$_ })
$grantSql  = "CREATE USER IF NOT EXISTS '${DbAppUser}'@'localhost' IDENTIFIED BY '${DbAppPass}'; GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, ALTER ON ``${DbName}``.* TO '${DbAppUser}'@'localhost'; FLUSH PRIVILEGES;"
$grantArgs = @("-u", "root", "-e", $grantSql)
if ($DbPass) { $grantArgs = @("-u", "root", "--password=$DbPass", "-e", $grantSql) }
$grantOut  = & $MySQL @grantArgs 2>&1
if ($LASTEXITCODE -ne 0) {
    Write-Info "Could not create dedicated DB user — app will use root instead."
    $DbAppUser = "root"
    $DbAppPass = $DbPass
} else {
    Write-OK "Dedicated database user '$DbAppUser' created (app uses this, not root)"
}

# Set MySQL root password if it is currently blank (XAMPP default)
$rootPassCheck = & $MySQL @("-u", "root", "--connect-timeout=3", "-e", "SELECT 1;") 2>&1
if ($LASTEXITCODE -eq 0 -and -not $DbPass) {
    $NewRootPass = -join ((65..90) + (97..122) + (48..57) | Get-Random -Count 20 | ForEach-Object { [char]$_ })
    $setRootSql  = "ALTER USER 'root'@'localhost' IDENTIFIED BY '${NewRootPass}'; FLUSH PRIVILEGES;"
    & $MySQL @("-u", "root", "-e", $setRootSql) 2>&1 | Out-Null
    if ($LASTEXITCODE -eq 0) {
        $DbPass = $NewRootPass
        $rootPassFile = "$env:USERPROFILE\Desktop\MYSQL_ROOT_PASSWORD.txt"
        "MySQL root password set by Eye Clinic installer.`nPassword: $NewRootPass`n`nStore this somewhere safe and delete this file." |
            Set-Content $rootPassFile -Encoding UTF8
        Write-OK "MySQL root password set and saved to your Desktop (MYSQL_ROOT_PASSWORD.txt)"
    }
}

# ── Step 8: Write .env file ────────────────────────────────────────────────────
Write-Section "Step 5 of 9 - Writing configuration"

$envText = "APP_NAME=`"$AppName`"
APP_ENV=production
APP_KEY=$AppKey
APP_DEBUG=false
APP_URL=$AppUrl

LOG_CHANNEL=stack
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=$DbName
DB_USERNAME=$DbAppUser
DB_PASSWORD=$DbAppPass

BROADCAST_DRIVER=log
CACHE_DRIVER=file
FILESYSTEM_DRIVER=local
QUEUE_CONNECTION=sync
SESSION_DRIVER=database
SESSION_LIFETIME=120

MEMCACHED_HOST=127.0.0.1

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@eyeclinic.local
MAIL_FROM_NAME=`"$AppName`"

AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=
AWS_USE_PATH_STYLE_ENDPOINT=false
"
[System.IO.File]::WriteAllText($EnvFile, $envText, [System.Text.Encoding]::UTF8)
Write-OK ".env configuration file written"

# ── Step 9: Install Composer packages ─────────────────────────────────────────
Write-Section "Step 6 of 9 - Installing PHP packages (may take a few minutes)"

Set-Location $ScriptDir
Write-Info "Running composer install..."
& $ComposerCmd install --no-dev --optimize-autoloader
if ($LASTEXITCODE -ne 0) { Stop-WithError "Composer install failed. Check your internet connection and try again." }
Write-OK "PHP packages installed"

# ── Step 10: Run Laravel artisan setup commands ────────────────────────────────
Write-Section "Step 7 of 9 - Setting up the application"

# Generate app key only if one is not already set
$currentEnv = Get-Content $EnvFile -Raw
if ($currentEnv -notmatch 'APP_KEY=base64:') {
    Run-Artisan @('key:generate', '--force') "Application security key generated"
} else {
    Write-OK "Application security key already set - preserved"
}

Run-Artisan @('migrate',      '--force') "Database tables created"
Run-Artisan @('db:seed',      '--force') "Default users and data loaded"
Run-Artisan @('storage:link', '--force') "File storage linked"
Run-Artisan @('config:cache')            "Configuration cached"
Run-Artisan @('route:cache')             "Routes cached"
Run-Artisan @('view:cache')              "Views cached"

# ── Step 10b: Security hardening — folder permissions ─────────────────────────
Write-Info "Securing project folder permissions..."
try {
    $projPath = $ScriptDir
    $acl = Get-Acl $projPath
    $acl.SetAccessRuleProtection($true, $false)   # break inheritance, remove inherited

    $adminRule  = New-Object System.Security.AccessControl.FileSystemAccessRule(
        "Administrators", "FullControl", "ContainerInherit,ObjectInherit", "None", "Allow")
    $systemRule = New-Object System.Security.AccessControl.FileSystemAccessRule(
        "SYSTEM", "FullControl", "ContainerInherit,ObjectInherit", "None", "Allow")

    # Apache service runs as SYSTEM on XAMPP; add Network Service as fallback
    $netSvcRule = New-Object System.Security.AccessControl.FileSystemAccessRule(
        "NETWORK SERVICE", "ReadAndExecute", "ContainerInherit,ObjectInherit", "None", "Allow")

    $acl.AddAccessRule($adminRule)
    $acl.AddAccessRule($systemRule)
    $acl.AddAccessRule($netSvcRule)
    Set-Acl $projPath $acl
    Write-OK "Project folder locked — standard user accounts cannot read .env"
} catch {
    Write-Info "Could not set folder permissions (non-fatal): $($_.Exception.Message)"
}

# ── Step 11: Set up automatic backup scheduler ────────────────────────────────
Write-Section "Step 8 of 9 - Setting up automatic backups"
Run-Artisan @('setup:scheduler') "Backup scheduler registered with Windows"

# ── Step 12: Final Apache restart ─────────────────────────────────────────────
Write-Section "Step 9 of 9 - Final restart"
Restart-Apache
Write-OK "Web server restarted and ready"

# ── Success ───────────────────────────────────────────────────────────────────
Write-Host ""
Write-Host "  +----------------------------------------------------------+" -ForegroundColor Green
Write-Host "  |            INSTALLATION COMPLETE!                        |" -ForegroundColor Green
Write-Host "  +----------------------------------------------------------+" -ForegroundColor Green
Write-Host ""
Write-Host ("  Open your browser and go to:") -ForegroundColor White
Write-Host ("    " + $AppUrl) -ForegroundColor Cyan
Write-Host ""
Write-Host "  Default login accounts:" -ForegroundColor White
Write-Host "  +------------------+----------------------------+-----------+" -ForegroundColor DarkGray
Write-Host "  | Role             | Email                      | Password  |" -ForegroundColor DarkGray
Write-Host "  +------------------+----------------------------+-----------+" -ForegroundColor DarkGray
Write-Host "  | Super Admin      | admin@eyeclinic.com        | password  |" -ForegroundColor White
Write-Host "  | Doctor           | frimkings@gmail.com        | password  |" -ForegroundColor White
Write-Host "  | Secretary        | secretary@gmail.com        | password  |" -ForegroundColor White
Write-Host "  | Front Desk       | staff@eyeclinic.com        | staff123  |" -ForegroundColor White
Write-Host "  +------------------+----------------------------+-----------+" -ForegroundColor DarkGray
Write-Host ""
Write-Host "  !! CHANGE ALL PASSWORDS IMMEDIATELY AFTER FIRST LOGIN !!" -ForegroundColor Yellow
Write-Host ""
Write-Host "  Next steps:" -ForegroundColor White
Write-Host "   1. Log in as Super Admin and go to Settings" -ForegroundColor DarkGray
Write-Host "   2. Enter your clinic name, phone number, and email address" -ForegroundColor DarkGray
Write-Host "   3. Use start-clinic.bat to start the system each day" -ForegroundColor DarkGray
Write-Host ""
Write-Host "  Press any key to close..."
try { $null = $Host.UI.RawUI.ReadKey('NoEcho,IncludeKeyDown') } catch { Read-Host | Out-Null }
