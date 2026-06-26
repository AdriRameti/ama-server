# AMA Server — Debugging Guide

> How to run, test, and troubleshoot the PHP backend. Distilled from `docs/user_guide.md` plus the actual code paths.

---

## 1. Running locally

```bash
cd ama-server
composer install                 # creates vendor/ (PHPMailer) — required for contact.php
cp config.example.php config.php # then edit secrets
php -S localhost:8000            # PHP built-in dev server
```

Endpoints are then at `http://localhost:8000/<file>.php`.

> The React client's CRA dev proxy (`ama-client/package.json` → `"proxy": "http://localhost:8000"`) points at exactly this port. If you run PHP on a different port, the client's relative `/api/...` calls won't reach it in dev.

**Required PHP extensions:** `json`, `fileinfo`, `session`, `openssl` (SMTP TLS), `mbstring` (length checks). Verify with `php -m`.

## 2. First things to check when something fails

1. **Does `config.php` exist?** Every endpoint does `require __DIR__ . '/config.php'`. On a fresh clone it does not exist → fatal error. Create it from `config.example.php`.
2. **Does `vendor/` exist?** Only `contact.php` needs it (PHPMailer). Missing `vendor/autoload.php` → 500 on contact only. Run `composer install`.
3. **Is the web server allowed to write?** `collections.php` writes `*.json` and `uploads/`. On OVH set JSON files ~664 and `uploads/` ~775.

## 3. Common issues → cause → fix

### 401 Unauthorized on save/upload/delete
- **Cause:** no admin session. The browser must have logged in via `login.php` *and* be sending the session cookie.
- **Check:** did the client use `credentials: 'include'`? Is the CORS origin exact (not `*`)? Browsers silently drop credentialed responses when `Allow-Origin` is `*` or mismatched.
- **Fix:** confirm `config.php` `cors_origin` exactly matches the frontend origin (scheme + host, no trailing slash; `www.` matters).

### CORS error in the browser console
- **Cause:** `cors_origin` ≠ the page's origin, or a proxy stripped headers.
- **Fix:** set `cors_origin` to the precise frontend origin. In dev that's usually `http://localhost:3000`. In prod it's `https://www.amagullent.org`. Remember every endpoint reads this independently.
- **Preflight:** `curl -X OPTIONS http://localhost:8000/collections.php -i` should return `204` with the `Access-Control-Allow-*` headers.

### Save returns 400
- `Empty body` → the POST had no body. `Invalid JSON` → body wasn't valid JSON. The client sends `JSON.stringify(obj)`; ensure `Content-Type: application/json`.

### Image upload fails
- `File too large` → > 5 MB cap. `Invalid file type` → real MIME not in {jpeg,png,gif,webp} (renaming a `.txt` to `.jpg` won't fool `finfo`). `Could not move uploaded file` → `uploads/` not writable or missing (the code `mkdir`s it `0775`, but parent perms can block this).

### Uploaded image URL is wrong / 404
- URL is built from `script_base_path()` (derived from `SCRIPT_NAME`) + `/uploads/`. If the PHP files live under `/api`, URLs are `/api/uploads/...`. If your deployment path differs, the returned URL reflects the script's directory — verify the file actually exists under that path.

### Contact email not arriving
1. Verify SMTP creds in `config.php` (`smtp_user`, `smtp_pass`). Default provider is **Brevo** (`smtp-relay.brevo.com:587`, STARTTLS).
2. The `smtp_from` sender must be **verified** in Brevo, else sends are rejected.
3. Check spam; configure SPF/DKIM for `amagullent.org` for deliverability.
4. Temporary SMTP trace — add before `$mail->send()` and **remove after**:
   ```php
   $mail->SMTPDebug = 2; // dumps SMTP conversation
   ```
5. The 500 response includes a `debug` field with `$mail->ErrorInfo` — read it (but note it should not ship to end users).

### 500 on contact.php with no email error
- Almost always missing `vendor/` (PHPMailer not installed) or `openssl` extension disabled. Run `composer install`; enable `extension=openssl` in `php.ini`.

## 4. Manual test suite (from `docs/user_guide.md`)

| Test | Command (PowerShell `curl`) | Expect |
|---|---|---|
| Login OK | `POST /login.php {"password":"<pwd>"}` | `200 {"ok":true}` + cookie |
| Read content | `GET /collections.php?scope=content` | `200` JSON document |
| Save w/o session | `POST /collections.php?scope=content` (no cookie) | `401 Unauthorized` |
| Contact valid | `POST /contact.php` with required fields | `200 {"ok":true}` + 2 emails |
| Contact empty | `POST /contact.php {}` | `400` field list |
| Contact bad email | `POST /contact.php` bad `email` | `400 Formato de email inválido` |
| Contact GET | `GET /contact.php` | `405 Method not allowed` |
| Preflight | `OPTIONS` any endpoint | `204`, no body |

## 5. Logs

- No application log files are written by this code.
- On OVH, PHP errors appear in **Web Cloud → Hosting → Logs y estadísticas**.
- Locally, the `php -S` console prints fatal errors and warnings to stdout.
- For ad-hoc tracing use `error_log('...')` (goes to the SErver/PHP error log) and remove it before deploying.

## 6. Deployment gotchas (OVH shared hosting)

- `vendor/` is git-ignored but **must be uploaded** to the server (run `composer install --no-dev` locally, then FTP the folder). A missing `vendor/` breaks `contact.php`.
- `config.php` is created **on the server** with production values; it is never in git.
- Ensure PHP version on OVH is **7.4+** (panel: Web Cloud → Configuración general). The code uses `??`-free, 7.4-compatible syntax (ternaries, `isset()` guards) deliberately — keep new code 7.4-compatible unless the prod PHP version is confirmed higher.
- No build, no restart: uploading the changed `.php` file is the deploy.

## 7. Things that look like bugs but are intentional

- `GET` on a scope echoes the raw file without re-encoding — by design (fast passthrough).
- Confirmation email failure is swallowed (still returns `ok`) — by design (the notification already succeeded).
- Admin password is plaintext and compared with `===` — current scope decision, not an oversight to "fix" unprompted.
