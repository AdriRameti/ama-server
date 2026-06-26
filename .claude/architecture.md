# AMA Server — Architecture

> Complete architecture of the PHP backend, derived from the source.

---

## 1. Architectural style

This is a **flat, script-per-endpoint PHP API** — the simplest possible server architecture:

- **No MVC.** There are no controllers, services, repositories, models, or entities. Each `.php` file is simultaneously the router entry, the controller, the validation layer, and the persistence layer for its endpoint.
- **No layering / DI / autoloading of app code.** The only autoloaded code is PHPMailer via `vendor/autoload.php` (required only inside `contact.php`).
- **No central bootstrap.** Each endpoint independently `require`s `config.php` and sets its own headers. There is no shared `index.php` front controller.
- **Stateless except for the PHP session** used for admin auth.

This means: when reasoning about an endpoint, **the file you are reading is the whole story**. There is no hidden middleware, no service container, no global router.

## 2. Component / file responsibilities

```
┌─────────────────────────────────────────────────────────────┐
│ ama-client (React, separate repo)                            │
│   fetch(`${API}/...`, { credentials: 'include' })            │
└───────────────┬─────────────────────────────────────────────┘
                │ HTTP + JSON + session cookie (CORS)
                ▼
┌─────────────────────────────────────────────────────────────┐
│ ama-server (this repo) — flat PHP scripts                    │
│                                                              │
│  login.php  ──► sets $_SESSION['is_admin'] = true            │
│  logout.php ──► clears session + cookie                      │
│                                                              │
│  collections.php (the workhorse)                             │
│    ?scope=content     ◄──► content.json                      │
│    ?scope=images      ◄──► images.json                       │
│    ?scope=collections ◄──► collections.json   (default)      │
│    ?action=upload     ──►  uploads/<file>                    │
│    ?action=delete-image ─► unlink uploads/<file>             │
│                                                              │
│  contact.php ──► PHPMailer ──► SMTP (Brevo) ──► 2 emails     │
│                                                              │
│  config.php (secrets, git-ignored)                          │
└─────────────────────────────────────────────────────────────┘
                │
                ▼
        Filesystem: *.json + uploads/
```

| File | Single responsibility |
|---|---|
| `config.php` | Return one associative array of settings/secrets. No logic. |
| `login.php` | Authenticate admin: compare posted password to `admin_password`, set session flag. |
| `logout.php` | Tear down the session + cookie. |
| `collections.php` | CRUD for the three JSON "scopes" + image upload/delete. The only stateful data endpoint. |
| `contact.php` | Validate a contact form and send notification + confirmation emails. Writes nothing to disk. |

## 3. Request lifecycle (every endpoint follows this shape)

From `collections.php` / `login.php` / `logout.php`:

```php
<?php
session_start();                                   // 1. start session (auth-aware endpoints)
$cfg = require __DIR__ . '/config.php';            // 2. load config/secrets

header('Access-Control-Allow-Origin: ' . $cfg['cors_origin']);  // 3. CORS preamble
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }  // 4. preflight

header('Content-Type: application/json');           // 5. JSON response type

// 6. dispatch by method (+ ?action / ?scope), echo json, exit after each branch
// 7. fall through to 405 for unsupported methods
```

`contact.php` is the same except it `require`s `vendor/autoload.php` first (for PHPMailer) and only allows `POST`/`OPTIONS`.

## 4. Authentication & authorization flow

**Session-based, single admin role.** There is exactly one privileged identity: "admin".

1. Client `POST`s `{ "password": "..." }` to `login.php`.
2. `login.php` compares it to `$cfg['admin_password']` with strict `===`.
3. On match: `$_SESSION['is_admin'] = true; echo {"ok":true}`. On mismatch: `401 {"error":"Invalid credentials"}`.
4. The PHP session cookie (default `PHPSESSID`) is returned to the browser. Because the client uses `credentials: 'include'` and the server sends `Allow-Credentials: true`, the cookie rides along on every subsequent request.
5. Protected operations call `is_admin()` → `!empty($_SESSION['is_admin'])`. No match → `401`.
6. `logout.php` empties `$_SESSION`, expires the cookie, and `session_destroy()`s.

There are **no users table, no roles, no JWT, no password hashing** server-side. Authorization is binary: admin or not.

> Note: the React client has its own *mock* notion of "músico" (member) users and an admin email, but those never reach this backend. The only thing the backend authenticates is the admin password via `login.php`. See client `state-management.md`.

## 5. Data flow & persistence model

**Three JSON "scopes", selected by `?scope=`:**

| `scope` | File (config key) | Shape | Used by client for |
|---|---|---|---|
| `content` | `content.json` (`content_file`) | flat object: `{ "contentKey": "text" }` | editable text overrides keyed by `contentKey` |
| `images` | `images.json` (`images_file`) | flat object: `{ "contentKey": "url" }` | editable image overrides |
| `collections` (default) | `collections.json` (`collections_file`) | object: `{ "collectionKey": [ {...}, ... ] }` | dynamic lists (e.g. concerts) |

**Read path (`GET`, public):** `collections.php` resolves the scope file, ensures it exists (`ensure_json_file` writes `{}` if missing), and streams the **raw file contents** back verbatim (`echo file_get_contents($dataFile)`). It does not re-encode on read.

**Write path (`POST` JSON body, admin-only):** validate JSON → `json_encode(..., JSON_PRETTY_PRINT)` to `<file>.tmp` → `rename()` over the live file (atomic). Replaces the entire scope document each time (no partial/merge updates server-side; the client sends the full object).

**Image upload (`?action=upload`, admin-only):** multipart `file` (+ optional `key`) → MIME-checked via `finfo` → stored as `uploads/<key>_<time>_<8 hex>.<ext>` → returns `{"ok":true,"url":"<base>/uploads/<file>"}`. The URL base is derived from `SCRIPT_NAME` via `script_base_path()`.

**Image delete (`?action=delete-image`, admin-only):** body `{ "url": "..." }` → `basename(parse_url(... PHP_URL_PATH))` to prevent traversal → `unlink` if present.

See [database.md](database.md) for the JSON document "schemas" and [api.md](api.md) for full request/response examples.

## 6. Frontend ↔ backend contract

- **Base URL** is configured on the *client* via `REACT_APP_CONTENT_API` (e.g. `/api` in production on OVH, where the PHP files live under a sibling `api/` directory). In local dev the client may proxy to `http://localhost:8000` (CRA `proxy` in `package.json`) or hit a configured base.
- **All requests that need admin rights send the session cookie** (`credentials: 'include'`). The server must therefore set an exact-origin CORS header (`cors_origin`), never `*`.
- **Response envelope:** success returns either a domain JSON document (GET on a scope) or `{"ok":true, ...}`; errors return `{"error":"..."}` with a non-2xx status. The client treats any non-`ok` response on writes as "unauthorized or failed".

## 7. Deployment topology (OVH shared hosting)

There is **no Docker, Node, or CI in production**. (A `docker-compose.yml` exists only in the *client* repo and references a non-existent Node API — it is stale; ignore it for the PHP backend.)

Production layout (from `docs/user_guide.md` + client `docs/OVH_SHARED_DEPLOY.md`):

```
/www  (or /public_html)        ← OVH web root
├── index.html, static/, ...   ← React build output (from ama-client `npm run build`)
└── api/                        ← contents of ama-server
    ├── collections.php, login.php, logout.php, contact.php
    ├── config.php              ← production secrets (created on server, not from git)
    ├── content.json, images.json, collections.json
    ├── uploads/                ← writable (perms ~775)
    └── vendor/                 ← uploaded manually (NOT in git; run `composer install --no-dev` locally first)
```

Deploy = build React locally, upload `build/` to web root and the PHP files to `api/` via FTP/SFTP. No process restart needed (no long-running server). Permissions: PHP must be able to write the three `*.json` files (~664) and `uploads/` (~775).

## 8. Lifecycle summary

- **Process model:** classic PHP request-per-script. No long-lived process, no in-memory state between requests except the on-disk session store.
- **Concurrency:** JSON writes are atomic at the file level (temp+rename) but there is **no locking** across concurrent writers — last writer wins. Acceptable for a single-admin CMS; noted as a robustness limitation in the OVH deploy doc.
- **Caching:** none server-side. No cron jobs, no queues, no background workers.
