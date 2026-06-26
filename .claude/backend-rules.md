# AMA Server — Backend Rules

> How the backend is organized and the responsibilities/expectations for each concern. Because there is **no MVC/service/repository layering**, these rules describe how those concerns are handled *inside the flat endpoint files*.

---

## 1. "Controllers" = the endpoint files themselves

Each `.php` file plays every role at once. When adding behaviour, keep these concerns ordered **within the single file** in this sequence (matches existing code):

1. Bootstrap: `session_start()` (if needed) + `require config.php`.
2. CORS + content-type headers + OPTIONS short-circuit.
3. Method/action dispatch.
4. **Authorization** (`is_admin()`) for any mutation.
5. **Input parsing** (`php://input` JSON / `$_FILES` / `$_GET`).
6. **Validation** (required, format, length, MIME, size).
7. **Sanitization** (HTML escaping, filename cleaning).
8. **Action** (read/write JSON file, move upload, send mail).
9. **Response** (`json_encode` + status) and `exit`.

Do not split these into separate layers/files unless explicitly asked — the project's value is its simplicity.

## 2. There are no services / repositories / entities / models

- Persistence helpers are plain functions co-located in `collections.php` (`get_scope_file`, `ensure_json_file`, `ensure_upload_dir`).
- There is no domain model. JSON documents are passed around as PHP arrays decoded from the request body and re-encoded to disk.
- If you need reusable persistence logic, prefer a **small local function** in the same file over introducing a class/layer.

## 3. Validation rules (canonical examples)

From `contact.php` (the most validated endpoint) — reuse this style:

- Required fields → accumulate into `$errors[]`, return all at once: `400 {"error": "a, b, c"}`.
- Email format → `filter_var(..., FILTER_VALIDATE_EMAIL)` → `400 'Formato de email inválido'`.
- Length caps → `mb_strlen()` vs explicit max (255 / 50 / 5000) → `400`.
- Trim all incoming strings (`trim()`).

From `collections.php`:

- Body present and valid JSON before saving: `json_decode`; if `null` and `json_last_error() !== JSON_ERROR_NONE` → `400 'Invalid JSON'`.
- Upload: presence + `UPLOAD_ERR_OK` + size ≤ 5 MB + MIME allow-list → otherwise `400`.

## 4. Authentication & authorization

- **Single mechanism:** PHP session flag `$_SESSION['is_admin']` set by `login.php`.
- **Single check:** `is_admin()` → `!empty($_SESSION['is_admin'])`. Inline the equivalent if a helper isn't already in the file.
- **Rule:** `GET` reads are public; **all writes/uploads/deletes require admin** and must return `401 {"error":"Unauthorized"}` when not.
- Password comparison is strict equality against `$cfg['admin_password']` (plaintext). Do not change to hashing/JWT/etc. without being asked — it's a deliberate scope decision.
- No CSRF token exists. The CORS exact-origin + credentialed-cookie model is the only cross-site protection. Don't loosen CORS to `*`.

## 5. Database access

There is **no database**. "DB access" = reading/writing the three JSON scope files. Rules:

- Always go through config-resolved paths (`$cfg['*_file']`).
- Always `ensure_json_file()` first.
- Always write atomically (temp + `rename`).
- The client sends the **entire** scope document on save; the server overwrites wholesale. There is no server-side merge or per-key patch. If you add partial-update behaviour, do it explicitly and document it.
- See [database.md](database.md) for the document shapes.

## 6. Transactions

- None. The only atomicity guarantee is the single-file temp+rename swap. Multi-file consistency is not provided. Avoid designing flows that require multiple files to update atomically.

## 7. File uploads

- Endpoint: `collections.php?action=upload`, admin-only, multipart `file` (+ optional `key`).
- Constraints: ≤ 5 MB, MIME ∈ {jpeg, png, gif, webp} (checked via `finfo`, not extension).
- Stored under `$cfg['uploads_dir']` as `{sanitizedKey}_{time}_{rand}.{ext}` via `move_uploaded_file()`.
- Returns a **public URL** built from `script_base_path()` + `/uploads/<file>`.
- Deletion via `?action=delete-image` with `{"url":...}`; only the `basename` is used to locate the file (path-traversal safe).
- `uploads/` must be writable in prod; it is git-ignored (except `.gitkeep`).

## 8. Emails (`contact.php` only)

- Transport: **PHPMailer over SMTP**, STARTTLS, port from config (`smtp_port`, default 587), credentials from `config.php` (`smtp_host/user/pass/from/from_name`). Default provider is **Brevo**.
- Two messages per successful submission:
  1. **Notification** to the association inbox (currently `$cfg['smtp_from']` — see debt note below), `Reply-To` set to the visitor.
  2. **Confirmation** to the visitor.
- Failure policy: if the **notification** send throws → `500` and stop. If only the **confirmation** send throws → swallow it and still return `{"ok":true}` (the message was delivered).
- Always set `CharSet = 'UTF-8'`, provide both `Body` (HTML) and `AltBody` (plaintext).

> **Debt to be aware of:** docs describe a `contact_to` config key for the recipient, but the code calls `$mail->addAddress($cfg['smtp_from'])`, and `config.example.php` does not define `contact_to`. Confirm the intended recipient before "fixing".

## 9. Security expectations (preserve these)

- Exact-origin CORS with credentials — never `*` while `Allow-Credentials: true`.
- Admin gate on every mutation.
- MIME-based (not extension-based) upload validation + size cap.
- Filename sanitization for upload keys and delete targets.
- HTML-escape all user input that lands in email HTML.
- Atomic JSON writes.

Known gaps (do not silently change; raise if relevant): plaintext admin password, no rate limiting / brute-force protection on `login.php`, no CSRF token, `contact.php` leaks `$mail->ErrorInfo` in its 500 body.

## 10. Caching / performance / cron / queues

- None of these exist and none are needed at current scale. No cron jobs, no queues, no background workers, no caching layer. Don't add them speculatively.

## 11. Logging

- There is **no application logging**. Errors surface only via HTTP status + JSON `error` (and, on OVH, the platform's PHP error logs). If you must debug, see [debugging.md](debugging.md) — use temporary `SMTPDebug`/`error_log()` and remove before shipping.
