# Online server (sync API) deployment

The API is optional — the shop works fully without it. It needs: PHP 8.1+ with `pdo_mysql`, `mbstring`; MySQL 8 or MariaDB 10.5+ (verified on MariaDB 10.4 too); HTTPS.

## Steps (VPS or a PHP host that allows a custom document root)

1. Upload `server-api/` (not the shop app) to e.g. `/var/www/textile-sync/`.
2. Point the domain's **document root** at `/var/www/textile-sync/public`. Keep `app/`, `database/`, `scripts/`, `.env` outside it.
3. Create the database and a dedicated user:
   ```sql
   CREATE DATABASE textile_pos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'textile_api'@'localhost' IDENTIFIED BY '<strong random password>';
   GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES ON textile_pos.* TO 'textile_api'@'localhost';
   ```
4. `cp .env.example .env`, fill DB settings (keep `ALLOW_HTTP=0`), `chmod 600 .env`.
5. `php scripts/server-cli.php migrate`
6. Enable HTTPS (Let's Encrypt or the host's certificate). The API refuses plain HTTP outside localhost.
7. Apache: `public/.htaccess` routes requests and passes the `Authorization` header. Nginx: `try_files $uri /index.php?$query_string;` and `fastcgi_param HTTP_AUTHORIZATION $http_authorization;`.

## Register the shop's counter

In the shop app: Sync Center → *Server settings* shows **Store ID** and **Device ID**. On the server:

```bash
php scripts/server-cli.php register-device --store-id=<STORE_ID> --store-name="My Shop" --device-id=<DEVICE_ID> --code=C1 --name="Counter 1"
```

Copy the printed `tpd_…` token into Sync Center (Server URL `https://your-domain`), click *Test connection* (sends no data), then **Sync Now**.
Re-running `register-device` rotates the token.

## Operating

```bash
php scripts/server-cli.php find-sale --store-id=<STORE_ID> --invoice=TX-C1-2627-00001   # shows the bill and how many copies exist (should be 1)
php scripts/server-cli.php conflicts --store-id=<STORE_ID>
php scripts/server-cli.php set-price --store-id=<STORE_ID> --variant-id=<UUID> --mrp=1299 --price=1199
php scripts/server-cli.php set-tax --store-id=<STORE_ID> --code=APPAREL --name="Apparel" --rate=5 --threshold=2500 --above=18   # verify with your accountant
```

Price/tax changes reach the shop only when someone there clicks Sync Now.
Back up MySQL daily (`mysqldump --single-transaction textile_pos`).

## Shared hosting / cPanel

Works if the host lets you set the domain or subdomain document root to `public/`, run PHP 8.1+, and use SSH or a cron-free CLI once for `migrate`/`register-device`
(otherwise import the migration SQL with phpMyAdmin and register the device on another machine pointing at the same DB). Some free hosts block the `Authorization` header,
inject ads into responses, or put requests behind JavaScript challenges — those break the API. No particular free host is guaranteed to work.

## Local mock for testing

`scripts\start-mock-server.ps1` runs the same API on http://127.0.0.1:8100 with a SQLite file (test mode only).
