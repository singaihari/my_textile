# Local MOCK of the online sync API (SQLite backend) for testing Sync Now without a real host.
# Usage: powershell -ExecutionPolicy Bypass -File scripts\start-mock-server.ps1
# Then register this shop's device (IDs are shown in Sync Center → Server settings):
#   $env:DB_DRIVER='sqlite'; php server-api\scripts\server-cli.php register-device --store-id=... --device-id=... --code=C1
# and in Sync Center set Server URL = http://127.0.0.1:8100 and paste the printed token.
param([int]$Port = 8100)
$root = Split-Path -Parent $PSScriptRoot
Set-Location "$root\server-api"
$env:DB_DRIVER = 'sqlite'
$env:DB_SQLITE_PATH = "$root\server-api\storage\mock-server.sqlite"
New-Item -ItemType Directory -Force "$root\server-api\storage" | Out-Null
php scripts\server-cli.php migrate
Write-Host "Mock sync API on http://127.0.0.1:$Port (Ctrl+C to stop). Data: $env:DB_SQLITE_PATH" -ForegroundColor Yellow
php -S "127.0.0.1:$Port" -t public
