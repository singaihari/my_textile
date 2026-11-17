# Textile POS - Windows setup check (no XAMPP required; does NOT change system settings).
# Usage (PowerShell, in the project folder):  powershell -ExecutionPolicy Bypass -File scripts\setup-windows.ps1
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

$php = Get-Command php -ErrorAction SilentlyContinue
if (-not $php) {
    Write-Host "PHP was not found on PATH." -ForegroundColor Red
    Write-Host "Install a supported PHP 8.2+ (x64 Thread Safe zip from https://windows.php.net/download/), extract to C:\php,"
    Write-Host "copy php.ini-production to php.ini, enable: extension=pdo_sqlite, extension=sqlite3, extension=curl, extension=mbstring, extension=openssl"
    Write-Host "then add C:\php to your user PATH and open a new PowerShell window."
    exit 1
}
Write-Host "PHP: $($php.Source)"
& php -v | Select-Object -First 1
$mods = & php -m
foreach ($m in @('pdo_sqlite', 'sqlite3', 'mbstring', 'curl', 'openssl')) {
    if ($mods -contains $m) { Write-Host "  [ok] $m" -ForegroundColor Green } else { Write-Host "  [missing] $m - enable it in php.ini" -ForegroundColor Yellow }
}
if (-not (Test-Path "$root\.env")) {
    Copy-Item "$root\.env.example" "$root\.env"
    Write-Host "Created .env from .env.example (review it)."
}
foreach ($d in @('storage\sqlite', 'storage\backups', 'storage\logs', 'storage\sessions', 'storage\tmp', 'storage\attachments')) {
    New-Item -ItemType Directory -Force "$root\$d" | Out-Null
}
& php scripts\run-migrations.php
Write-Host ""
Write-Host "Next steps:" -ForegroundColor Cyan
Write-Host "  1. Create the shop and admin account:  php scripts\create-admin.php   (add --demo for training data)"
Write-Host "  2. Development / trial:  powershell -ExecutionPolicy Bypass -File scripts\start-dev.ps1"
Write-Host "  3. Open http://127.0.0.1:8000 and sign in on the login page."
Write-Host "  4. For daily shop use, follow docs\DEPLOY_WINDOWS.md (Apache/Nginx + PHP as a service)."
