# Offline operation and manual synchronisation

## What works offline

Everything on the shop PC: billing, printing (local printer), returns, purchasing, stock, reports and backups. It only needs the PC,
the local PHP web server, the SQLite file and the printer. (A browser pointed at a *remote* website cannot run PHP offline — this app runs on the shop PC itself.)

## Local write path (transactional outbox)

Each mutation inserts a row into `sync_outbox` **in the same SQLite transaction** as the business data:
event UUID, monotonic `seq`, store & device ids, `entity_type`, `entity_id`, `op`, `schema_version`, full JSON snapshot payload,
`depends_on` (e.g. a return depends on its sale; a sale on its new customer), status, attempts, last error.
Payload, ids and order are immutable (trigger), and unacknowledged events cannot be deleted (trigger).

| Event | Payload |
|---|---|
| `sale.created` | sale + items + payments + stock movements + customer snapshot |
| `sale.voided` | sale id, reason, linked credit note |
| `return.created` | credit note + items + refunds + movements + exchange link |
| `purchase.posted`, `supplier_return.created`, `stock_adjustment.posted` | document + movements |
| `customer.upserted` / `customer.anonymized`, `product.upserted` | current snapshot |
| `expense.created`, `customer_receipt.created`, `cash_session.closed` | document |

## Sync Now (the only trigger)

There are **no** timers, reconnect hooks, service workers or startup syncs. The network badge only reads `navigator.onLine`.
Pending counts come from the local server. The only code paths that upload are the **Sync Now** button (POST `/api/sync/run` with `confirm: true`, permission `sync.run`)
and the explicit CLI `scripts/sync-now.php` (asks to type SYNC).

1. Confirm dialog shows the pending count.
2. `POST /api/v1/sync/handshake` — authenticates the device token, checks the token belongs to this store.
3. Upload pending/failed events in `seq` order in batches (default 50, max 200) to `POST /api/v1/sync/push`.
4. The server processes each event in its own MySQL transaction **and inserts `sync_receipts(event_id)` (primary key) in the same transaction**.
5. Per event the client marks the outbox row synced **only** for `applied`, `duplicate` or `conflict` acknowledgements; `deferred` stays pending; `rejected`/`error` become `failed` with the message.
6. Deferred events whose dependency was acknowledged later in the same run are retried.
7. Pull: `GET /api/v1/sync/pull?cursor=N` for server-approved master changes (prices, variant status, tax rules), applied in local transactions; cursor saved after each page.
8. Summary stored in `sync_runs` and shown in Sync Center.

**Interruption** at any point leaves unacknowledged events pending. Re-running is always safe: an event that reached the server
earlier is answered with `duplicate` and no business rows are touched. Event-id reuse with a different payload hash is rejected.

## Conflict policy

| Situation | Behaviour |
|---|---|
| Financial transactions (sales, payments, returns) | Never overwritten. Exact replays are duplicates. |
| Same invoice number from two devices (misconfigured counter codes) | Stored in `documents` + `conflicts`; acknowledged as conflict; shown in Sync Center |
| Price edited locally while the server published a different approved price | Server price kept (server is authoritative for master data); conflict shown locally with *Use server price* / *Keep local (send again)* |
| Server price change while local product has unsynced edits | Not applied; conflict recorded |
| Duplicate customer phone on server | Customer stored; conflict recorded for manual merge |
| Server stock mirror goes negative | Movement stored; `negative_stock` conflict recorded (stock count advised) |
| Tax rule changed locally after the last sync and on the server | Not applied; `stale_tax_config` conflict |
| Discontinued (archived) variant from server | Applied; variant no longer sellable |

## Multi-counter: current limits and migration path

Supported today: **one billing counter per store** (one device owns stock). The schema already carries `device_id`/`store_id`
everywhere, invoice numbers include the counter code, and IDs are UUIDs, so a second counter can be *registered* — but two counters
selling offline against one shared stock figure can oversell, and they do not see each other's sales until both sync. Before enabling
multiple counters: (1) allocate per-counter stock (a `transfer` between counter locations) or (2) make the server the stock authority with
pull of other devices' movements and periodic reconciliation; extend `pull` to deliver product/stock events from other devices; add tests for both.
