# DEVELOPMENT / TRIAL ONLY: PHP built-in web server bound to this PC only (127.0.0.1).
# It is single-threaded and not a production server — see docs\DEPLOY_WINDOWS.md for daily use.
param([int]$Port = 8000)
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root
Write-Host "Textile POS (DEV server) on http://127.0.0.1:$Port  — press Ctrl+C to stop" -ForegroundColor Yellow
Start-Process "http://127.0.0.1:$Port" | Out-Null
php -S "127.0.0.1:$Port" -t public public/router.php
