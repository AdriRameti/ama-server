# AMA Server — Claude Instructions (Entry Point)

> This file is the permanent knowledge base entry point for the **AMA Server** repository.
> Read this first. Then consult the topic files referenced below.
> Everything here was derived by reading the actual source code, not assumed.

---

## 1. What this project is

`ama-server` is the **PHP backend** for the website of the **Agrupació Musical Agullent (AMA)** — a music association / music school in Agullent (Valencia, Spain). Production site: <https://www.amagullent.org/>.

It is a deliberately **minimal, flat PHP API**:

- **No framework.** No Laravel, no Symfony, no Slim. Just standalone `.php` scripts executed directly by the web server.
- **No database.** All persistence is **flat JSON files** on disk + uploaded images on the filesystem.
- **No router.** Each endpoint is a physical `.php` file; the URL path *is* the file path (e.g. `POST /api/login.php`).
- **One Composer dependency:** `phpmailer/phpmailer` (used only by `contact.php`).
- **Session-based admin auth** using native PHP sessions (`$_SESSION['is_admin']`).

The frontend is a separate repository, `ama-client` (React / Create React App). The two communicate over HTTP+JSON with **CORS + cookies** (`credentials: 'include'`).

## 2. Technology stack (from real files)

| Concern | Choice | Evidence |
|---|---|---|
| Language | PHP 7.4+ (8.0+ recommended) | `README.md`, `docs/user_guide.md` |
| Dependency manager | Composer | `composer.json`, `composer.lock` |
| Only library | PHPMailer `^6.9` | `composer.json` |
| Persistence | JSON files + filesystem uploads | `collections.php`, `*.json` |
| Auth | Native PHP sessions | `login.php`, `logout.php` |
| Mail | SMTP via PHPMailer (Brevo by default) | `contact.php`, `config.example.php` |
| Required PHP extensions | `json`, `fileinfo`, `session`, `openssl`, `mbstring` | `README.md`, `docs/user_guide.md` |
| Hosting | OVHCloud **shared hosting** (no Docker/Node in prod) | `docs/user_guide.md`, client `docs/OVH_SHARED_DEPLOY.md` |

## 3. File map (the entire backend)

```
ama-server/
├── collections.php       # MAIN API: read/write content/images/collections JSON + image upload/delete
├── login.php             # POST password → sets $_SESSION['is_admin']; GET → tiny HTML form
├── logout.php            # destroys session + cookie
├── contact.php           # POST contact form → sends 2 emails via PHPMailer
├── config.php            # SECRETS, git-ignored. Created from config.example.php (NOT in repo)
├── config.example.php    # template for config.php
├── content.json          # editable site texts (key → string)   [committed sample]
├── collections.json      # dynamic lists (e.g. concerts)         [runtime, git-ignored]
├── images.json           # editable image URL overrides          [runtime, git-ignored]
├── uploads/              # uploaded images (git-ignored except .gitkeep)
├── vendor/               # Composer deps (git-ignored; MUST be uploaded to prod)
├── composer.json / .lock
├── README.md
└── docs/user_guide.md    # step-by-step PHP/Composer/SMTP/OVH setup guide
```

See [architecture.md](architecture.md) for how these fit together and [api.md](api.md) for the full endpoint contract.

## 4. Golden rules (ALWAYS)

1. **Treat `config.php` as secret.** It is git-ignored and contains the admin password (plaintext) and SMTP credentials. Never commit it, never print its values, never hardcode them elsewhere. When config changes are needed, update `config.example.php` (the template) too.
2. **Every endpoint must emit the CORS preamble.** Copy the exact 5-line block used by `login.php`/`logout.php`/`collections.php`/`contact.php` (origin from `$cfg['cors_origin']`, `Allow-Credentials: true`, handle `OPTIONS` → 204). The frontend sends cookies, so `Access-Control-Allow-Credentials: true` is mandatory and the origin must be the exact frontend origin (a wildcard `*` would break credentialed requests).
3. **Gate every write on `is_admin()`.** Any state-changing operation (`POST` to save JSON, `?action=upload`, `?action=delete-image`) must check `is_admin()` and return `401 {"error":"Unauthorized"}` if false. Reads (`GET`) are public.
4. **Respond in JSON.** Set `header('Content-Type: application/json')` and `echo json_encode([...])`. Success ≈ `{"ok": true}` (plus payload), failure ≈ `{"error": "message"}` with an appropriate `http_response_code()`.
5. **Write JSON atomically.** Follow the `collections.php` pattern: write to `$file . '.tmp'`, then `rename()` into place. Never `file_put_contents` the live file directly.
6. **Validate uploads by real MIME, not extension.** Use `finfo` (as `collections.php` does), enforce the 5 MB cap, and sanitize filenames against path traversal.

## 5. Things Claude must NEVER do

- **Never introduce a database, ORM, framework, or router** unless explicitly asked. The design is intentionally file-based and flat. (Migrating to MySQL is noted only as a *future* option in `docs/OVH_SHARED_DEPLOY.md`.)
- **Never `require` `config.php` without it existing** — on a fresh checkout it does not exist. Code assumes it was created from `config.example.php`. Don't add logic that crashes hard if missing without a clear error.
- **Never commit `config.php`, `vendor/`, `collections.json`, `images.json`, uploaded files, or `*.tmp`.** They are all git-ignored for a reason (see `.gitignore`).
- **Never trust `$_POST['key']` or request URLs as filesystem paths.** Always sanitize (`preg_replace`/`basename`) — the upload/delete code already does this; preserve it.
- **Never echo PHPMailer debug or stack traces to clients in production.** Note `contact.php` currently leaks `$mail->ErrorInfo` in a 500 response (`debug` field) — that is existing technical debt, do not copy it into new code (see [debugging.md](debugging.md)).
- **Never assume a build step.** There is none. The `.php` files are deployed as-is via FTP/SFTP to OVH. What you write is what runs.

## 6. Things Claude should ALWAYS do

- Mirror the **existing per-file structure**: `session_start()` (if auth/session needed) → `require config.php` → CORS headers → OPTIONS short-circuit → `Content-Type: application/json` → method dispatch → `exit` after each response branch.
- Keep helper functions **local to the file** (e.g. `is_admin()`, `ensure_json_file()` live inside `collections.php`). There is no shared `lib/` or autoloaded app code beyond PHPMailer.
- Keep user-facing strings in the language already used in that file. Validation/error messages in `contact.php` are **Spanish**; email bodies are **Catalan/Spanish**. Match the surrounding file.
- Use the config keys that already exist (`admin_password`, `cors_origin`, `content_file`, `images_file`, `collections_file`, `uploads_dir`, `smtp_*`). If you need a new setting, add it to **both** `config.php` and `config.example.php`.

## 7. How to safely add a new endpoint

1. Create a new top-level `<name>.php`.
2. Copy the header block (session if needed, config require, CORS, OPTIONS→204, JSON content-type) verbatim from an existing endpoint.
3. Dispatch on `$_SERVER['REQUEST_METHOD']`; `exit` after writing each response.
4. For writes, guard with `is_admin()` (copy the helper or the inline `!empty($_SESSION['is_admin'])` check) and return 401 when unauthorized.
5. Use atomic write + `json_encode(..., JSON_PRETTY_PRINT)` for any file persistence.
6. End with a `405` fallthrough for unsupported methods.
7. Update [api.md](api.md), `README.md`, and (if a new origin/secret is involved) `config.example.php`.

## 8. Where to look next

| You need to… | Read |
|---|---|
| Understand request lifecycle, data flow, storage model | [architecture.md](architecture.md) |
| Match code style / naming / error conventions | [coding-rules.md](coding-rules.md) |
| Know controller-vs-helper responsibilities, validation, security expectations | [backend-rules.md](backend-rules.md) |
| Understand the JSON "schema" (there is no SQL DB) | [database.md](database.md) |
| Get the exact endpoint contract + examples | [api.md](api.md) |
| Debug a 401 / CORS / upload / email problem | [debugging.md](debugging.md) |

## 9. Known constraints & debt (do not "fix" silently)

- Admin password is stored **in plaintext** in `config.php` and compared with `===` (`login.php`). This is intentional for the current scope; do not change the auth model without being asked.
- The frontend *also* contains a legacy client-side SHA-256 admin hash (`ama-client/src/services/AuthService.js`) that is **no longer the real gate** — the real gate is this server's `login.php`. See the client docs.
- `contact.php` references `$cfg['contact_to']` in the docs but the code actually sends to `$cfg['smtp_from']` (`$mail->addAddress($cfg['smtp_from'])`). `config.example.php` does not define `contact_to`. Documented in [debugging.md](debugging.md); confirm intent before changing.
