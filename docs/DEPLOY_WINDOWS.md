# Shop PC deployment (Windows 10/11) — no XAMPP required

## 1. Install PHP

1. Download a currently supported PHP 8.x **x64 Thread Safe** zip from https://windows.php.net/download/ (and the matching Visual C++ redistributable it lists).
2. Extract to `C:\php`. Copy `php.ini-production` to `php.ini` and enable:
   `extension=pdo_sqlite`, `extension=sqlite3`, `extension=mbstring`, `extension=openssl`, `extension=curl`, and set `date.timezone = Asia/Kolkata`.
3. Add `C:\php` to the user PATH; open a new PowerShell: `php -v`, `php -m`.

## 2. Install the app

```powershell
# copy the project to e.g. C:\TextilePOS (outside any public web folder)
cd C:\TextilePOS
powershell -ExecutionPolicy Bypass -File scripts\setup-windows.ps1
php scripts\create-admin.php        # shop name, counter code, admin name/username/password (no setup web page exists)
```

## 3. Run it

**Trial / development only:** `scripts\start-dev.ps1` (PHP built-in server on 127.0.0.1:8000). It is single-threaded and not hardened.

**Daily use (recommended): Apache + PHP, localhost only**

1. Install Apache 2.4 (e.g. the Apache Lounge Win64 build) to `C:\Apache24`.
2. In `httpd.conf`:
   ```apache
   Listen 127.0.0.1:8080
   LoadModule rewrite_module modules/mod_rewrite.so
   LoadModule php_module "C:/php/php8apache2_4.dll"
   AddHandler application/x-httpd-php .php
   PHPIniDir "C:/php"
   DocumentRoot "C:/TextilePOS/public"
   <Directory "C:/TextilePOS/public">
       AllowOverride All
       Require local
   </Directory>
   ```
3. Install as a service (Administrator PowerShell): `C:\Apache24\bin\httpd.exe -k install`, then `Start-Service Apache2.4` and set it to start automatically.
4. Browse to http://127.0.0.1:8080 and sign in with the admin created by `create-admin.php`.

Alternative: Nginx + `php-cgi.exe -b 127.0.0.1:9000` run as a service (e.g. with NSSM), `root C:/TextilePOS/public;` and `try_files $uri /index.php?$query_string;`.

Security notes: bind to `127.0.0.1` (the POS runs on the same PC). If another PC on the LAN must use it, bind to the LAN IP, restrict with `Require ip 192.168.1.0/24`,
allow only that subnet in Windows Firewall, and consider HTTPS. Never point the web root at the project folder — only at `public/`.
The SQLite DB lives in `storage/sqlite/` (denied by `.htaccess` and outside the web root). Give the web-server account write access to `storage/` only.

Browser: create a desktop shortcut `msedge.exe --app=http://127.0.0.1:8080/pos` (or Chrome) for a clean full-window POS. Set the 80 mm printer as the
Windows default and in the browser print dialog choose “More settings → Margins: None, Scale 100%, uncheck headers and footers” once; the browser remembers it.
Silent printing is not possible from a normal browser page; Chrome/Edge `--kiosk-printing` prints without a dialog (use only on a dedicated POS PC).

## 4. Printer and scanner

- Thermal printer: install the manufacturer's Windows driver, paper size 80 mm (72 mm printable). Settings → Printing → *Test 80 mm*.
- If a print fails after saving, the bill is already saved: press **R** (retry) or reprint from Invoices. No duplicate bill is ever created.
- USB barcode scanner: keyboard (HID) mode with **Enter** suffix (factory default on most models). Test by scanning into Notepad. The POS sends any typing to the scan box.
- Labels: Settings → Printing → label size/columns; Products → open product → *Labels*.

## 5. Backups

- Manual: Backup & restore → *Create backup now* (verified with an integrity check).
- Scheduled (Task Scheduler, daily 21:30 and at logon):
  `Program: C:\php\php.exe`, `Arguments: scripts\backup.php scheduled`, `Start in: C:\TextilePOS`.
- Copy `storage\backups\` to a USB drive or a synced cloud folder regularly. Keep at least one copy off the PC.
- Restore: Backup & restore → Restore… (type RESTORE + your password). A safety backup is taken first, everyone is signed out, and the swap happens on the next page load.
  If Apache holds the file open, the page says so — restart the service (`Restart-Service Apache2.4`) and reload. CLI alternative with the server stopped: `php scripts\restore.php <file>`.

## 6. Updates

Stop the web server → back up → replace the code (keep `.env` and `storage\`) → `php scripts\run-migrations.php` (backs up first) → start the server.

## 7. Offline simulation (acceptance test)

Disconnect the network cable / turn off Wi-Fi → make several bills → print → close the browser → reopen → bills and stock are there, Sync Center shows them pending →
reconnect → verify nothing uploads until **Sync Now** is clicked → click it → click again (nothing new uploads).

## Troubleshooting

| Symptom | Fix |
|---|---|
| “The local POS server is not responding” | Start the Apache service / dev script |
| Blank page or 500 | See `storage\logs\php-error.log` (error reference id is shown on screen) |
| “Database update required” | `php scripts\run-migrations.php` |
| Scanner types but nothing is added | Scanner must send Enter; press F3 to focus the scan box |
| F-key opens a browser feature | Use the Alt alternative or remap in Settings → Shortcuts |
| “database is locked” | Another program (antivirus, backup tool) is scanning the .sqlite file — exclude `storage\sqlite` |
| Forgot admin password | `php scripts\create-admin.php` on the shop PC |
