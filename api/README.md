# Head of AI — `api/` directory

PHP endpoints used by the frontend tracking + form stack.

## Files

| File | Purpose |
|---|---|
| `meta-capi.php` | Server-side Meta CAPI relay for the form Lead event. Dedup uses `event_id` shared with the browser Pixel. |
| `meta-capi-relay.php` | Thin pass-through wrapper for non-Lead browser events (PageView, ViewContent, InitiateCheckout). |
| `contact.php` | Server-side form endpoint. Validates → file log → e-mail notify → Meta CAPI → MailerLite → **MySQL `leads` insert**. |
| `lib/db.php` | PDO wrapper. `helm_db_connect()` + `helm_db_insert_lead()`. Best-effort; failures land in `logs/db.log` and never block the user. |
| `sql/001_create_leads.sql` | One-shot schema migration for the `leads` table. Run once in phpMyAdmin after creating the DB. |
| `capi-config.example.php` | Template config file. Copy to `capi-config.php` ON THE SERVER ONLY. |
| `capi-config.php` | **Server-only, gitignored.** Holds Pixel ID + CAPI access token + notify e-mail. |
| `db-config.example.php` | Template MySQL config. Copy to `db-config.php` ON THE SERVER ONLY. |
| `db-config.php` | **Server-only, gitignored.** Holds DB host / name / user / password. |
| `.htaccess` | Denies access to config/logs and forces no-store cache. |
| `logs/` | Auto-created at first write. `chmod 700`. Stores `capi.log`, `submissions.log`, `db.log`. |

## Going live

1. Upload everything in this folder to `headofai.eu/api/` via SFTP/SSH.
2. SSH in, then:
   ```
   cp capi-config.example.php capi-config.php
   chmod 600 capi-config.php
   nano capi-config.php   # fill in real values
   ```
3. Replace these placeholders:
   - `meta_pixel_id`     → your real Meta Pixel ID
   - `meta_access_token` → from Events Manager → Pixel → Settings → "Generate Access Token"
   - `notify_email`      → e.g. `info@headofai.eu`
   - `notify_from`       → e.g. `no-reply@headofai.eu`
   - `allowed_origins`   → match production + staging hostnames
4. Test via Events Manager → Test Events. Set `meta_test_event_code` temporarily, submit a form, confirm dedup ratio ≥ 90%.
5. Clear `meta_test_event_code` and you're live.

## What never goes into git

```
api/capi-config.php
api/db-config.php
api/smtp-config.php
api/mailerlite-config.php
api/logs/*
```

These are already covered by the project `.gitignore`. The repo is **public** — never commit the real `*-config.php` files.

On the server the canonical home for these files is `/home/u758116828/secrets/headofai/` (outside the web document root, chmod 700) so the Hostinger Git Deploy never touches them. The in-tree fallback path still works for local development if you put a gitignored `db-config.php` next to `contact.php`.

## MySQL lead storage (Hostinger)

A `contact.php` minden sikeres leadet **a fájl-log mellett MySQL-be is bemásol**, így lekérdezhető marad (phpMyAdmin / export) és nem vész el ha a file-system valamiért újrarendeződik. Ez best-effort: ha a DB elérhetetlen, a user akkor is sikeres redirectet kap, és a hiba a `logs/db.log`-ban jelenik meg.

### 1) DB létrehozása hPanel-en (egyszer)

A Hostinger MCP nem támogatja MySQL DB létrehozást API-ból — kézzel kell:

1. hPanel → **Hosting** → `headofai.eu` → **Databases** → **MySQL Databases**
2. **Create New**:
   - **Database name**: `headofai` (a Hostinger automatikusan elétűzi a `u758116828_` prefixet → végleges név: `u758116828_headofai`)
   - **Username**: `headofai` (Hostinger megengedi a DB-vel azonos nevet → végleges: `u758116828_headofai`)
   - **Password**: generálj erőset, mentsd Bitwardenbe
   - **All privileges**: bekapcsolva
3. **Create**

### 2) Tábla migráció

1. ugyanazon az oldalon kattints a DB-hez tartozó **phpMyAdmin** linkre
2. válaszd ki a bal oldalt a `u758116828_headofai` DB-t
3. felül **SQL** fül → illeszd be a `site/api/sql/001_create_leads.sql` teljes tartalmát → **Go**
4. ha minden zöld, a bal oldali fában megjelenik a `leads` tábla

### 3) Config feltöltése a perzisztens secrets-mappába

**FONTOS:** A Hostinger Git Deploy minden push-nál újragenerálja a `public_html/`-et és **törli az untracked fájlokat** (így a régi `db-config.php`-t is, ha az api-ban lenne). Ezért a configok és logok a **document rooton kívül**, `/home/u758116828/secrets/headofai/` alatt élnek. A `lib/secrets.php` helper ezt automatikusan megtalálja (CLI és web kontextusban is) — lásd a fájl tetején lévő dokumentációt.

Egyszeri bootstrap SSH-n:

```bash
mkdir -p ~/secrets/headofai/logs
chmod 700 ~/secrets ~/secrets/headofai ~/secrets/headofai/logs

# upload db-config.php innen SCP-vel a ~/secrets/headofai/ mappába
# (NEM az api/ alá!), majd:
chmod 600 ~/secrets/headofai/db-config.php
```

Layout a szerveren:

```
/home/u758116828/
├── domains/headofai.eu/public_html/   ← deploy target (recreated)
│   └── api/                           ← contact.php, lib/, sql/ — git-managed
└── secrets/headofai/                  ← persistent, chmod 700
    ├── db-config.php                  ← chmod 600
    ├── (smtp-config.php, capi-config.php, mailerlite-config.php — opcionális)
    └── logs/
        ├── submissions.log
        ├── db.log
        ├── capi.log
        └── mailerlite.log
```

A `dbname` és `user` mezők a `db-config.example.php` alapján (`u758116828_headofai` mindkettő — a Hostinger megengedi az azonos nevet) — ha más prefixet ad a Hostinger, igazítsd hozzá.

### 4) Smoke test

A CLI smoke test maga is verify-eli a layout-ot:

```bash
php /home/u758116828/domains/headofai.eu/public_html/api/db-test-cli.php
```

Várt kimenet:
```
Secrets resolved via: self-path-cli (/home/u758116828/secrets/headofai)
[1] Connecting…           ok
[2] SELECT VERSION()…     MySQL version: 11.8.6-MariaDB-log
[3] Checking `leads` table…  table exists, current row count: …
[4] Round-trip insert / select / delete…  ok
✅ All checks passed. The lead pipeline is live.
```

Aztán élesben:
1. töltsd ki a landingen a kapcsolatfelvételi formot egy teszt e-maillel
2. phpMyAdmin → `leads` → **Browse** → meg kell jelennie az új sornak
3. `~/secrets/headofai/logs/db.log` üresnek kell maradnia (ha van benne `connect_failed` vagy `insert_failed`, ott a hibaüzenet)
4. file-redundancia: `~/secrets/headofai/logs/submissions.log` is megkapja ugyanazt a rekordot

### Lekérdezések

```sql
-- Utolsó 20 lead
SELECT id, created_at, name, company, email, phone
FROM leads
ORDER BY created_at DESC
LIMIT 20;

-- Napi konverziók
SELECT DATE(created_at) AS day, COUNT(*) AS leads
FROM leads
GROUP BY day
ORDER BY day DESC;

-- UTM forrás szerint
SELECT JSON_UNQUOTE(JSON_EXTRACT(attribution, '$.utm_source')) AS source,
       COUNT(*) AS leads
FROM leads
GROUP BY source
ORDER BY leads DESC;
```
