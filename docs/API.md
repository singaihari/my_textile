# Online sync API (server-api)

Base URL: your HTTPS host, document root = `server-api/public`. All bodies are JSON (UTF-8). Max body 5 MB. Rate limit 120 requests/minute/device.

## Authentication

Every endpoint except `/health` requires:

```
Authorization: Bearer tpd_<48 hex>       (device token; the server stores only its SHA-256)
X-Device-Id: <device UUID>               (must match the token's device)
```

Tokens are created on the server with `php scripts/server-cli.php register-device …` and shown once.

## Endpoints

| Method & path | Purpose |
|---|---|
| `GET /api/v1/health` | Liveness, no data |
| `POST /api/v1/sync/handshake` | Validates credentials; returns device, store id, server time, `max_batch`, supported schema versions |
| `POST /api/v1/sync/push` | `{batch_id, events:[{id, seq, store_id, device_id, entity_type, entity_id, op, schema_version, payload, depends_on, created_at}]}` → `{results:[{event_id, status, message, conflicts}]}` |
| `GET /api/v1/sync/pull?cursor=N&limit=M` | `{changes:[{id, entity_type, entity_id, payload, created_at}], next_cursor, has_more}` (server-approved changes; excludes changes originating from the caller) |
| `GET /api/v1/sales/{invoice_no}` | One synced bill with items and payments (verification) |
| `POST /api/v1/admin/master` | Admin devices only: `{changes:[{type:"variant_price", variant_id, mrp_paise, price_paise} \| {type:"variant_status", variant_id, status} \| {type:"tax_rule", code, name, rate_bp, threshold_paise?, above_rate_bp?}]}` |

Push result statuses: `applied`, `duplicate`, `conflict` (stored; review), `deferred` (dependency missing; retry later), `rejected` (invalid; not stored), `error` (server problem; retry).

Errors: `{ok:false, error:{code, message}, request_id}` with HTTP 400/401/403/404/413/422/429/500. Logs contain the request id and error class, not payloads or personal data.
Plain HTTP is refused (403) except on localhost unless `ALLOW_HTTP=1` (not for production).
