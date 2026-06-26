# AMA Server — Coding Rules

> Conventions **extracted from the existing PHP source**. These are descriptive (what the code already does), not aspirational. Match them.

---

## 1. File & endpoint conventions

- **One endpoint = one top-level `.php` file**, lowercase, descriptive noun/verb: `login.php`, `logout.php`, `contact.php`, `collections.php`. No subdirectories for code.
- Each file is fully self-contained: it loads its own config and sets its own headers. There is no shared include beyond `config.php` and `vendor/autoload.php`.
- Files **open with `<?php`** and have **no closing `?>`** tag (avoids accidental output). Preserve this.

## 2. Standard endpoint preamble (copy verbatim)

Every endpoint begins with this exact structure (auth-aware endpoints include `session_start()`):

```php
<?php
session_start();                          // only if the endpoint reads/writes the session
$cfg = require __DIR__ . '/config.php';

header('Access-Control-Allow-Origin: ' . $cfg['cors_origin']);
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');   // adjust verbs per endpoint
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

header('Content-Type: application/json');
```

`contact.php` additionally does `require __DIR__ . '/vendor/autoload.php';` at the very top.

## 3. Naming

| Thing | Convention | Examples (real) |
|---|---|---|
| Files | lowercase, `.php` | `collections.php`, `contact.php` |
| Functions | `snake_case` | `get_scope_file()`, `ensure_json_file()`, `ensure_upload_dir()`, `is_admin()`, `script_base_path()` |
| Local variables | `$camelCase` or short `$snake` | `$dataFile`, `$targetPath`, `$s_nombre`, `$cfg` |
| Config keys | `snake_case` strings | `admin_password`, `cors_origin`, `content_file`, `smtp_host` |
| Query params | lowercase | `?scope=`, `?action=` |
| JSON response keys | lowercase, `ok`/`error`/`url` | `{"ok":true}`, `{"error":"..."}`, `{"url":"..."}` |
| Sanitized form vars | `$s_` prefix for HTML-escaped copies | `$s_nombre`, `$s_email` (in `contact.php`) |

Helper functions are defined **inside the endpoint file that uses them** (e.g. all helpers live in `collections.php`). Do not create a shared utilities file unless asked.

## 4. Request handling

- Read query params defensively: `isset($_GET['scope']) ? $_GET['scope'] : 'collections'`.
- Read JSON bodies with: `json_decode(file_get_contents('php://input'), true)`. Fall back to `$_POST` where the code already does (`login.php`: `... ?: $_POST`).
- Dispatch on `$_SERVER['REQUEST_METHOD']` and `$action`/`$scope` with early `exit` after each branch.
- End the file with a method-not-allowed fallback:
  ```php
  http_response_code(405);
  echo json_encode(['error' => 'Method not allowed']);
  ```

## 5. Responses

- Always `echo json_encode(...)`. Success shapes seen in code:
  - `{"ok": true}` (login, logout, save, delete-image, contact)
  - `{"ok": true, "url": "..."}` (upload)
  - raw JSON document echoed verbatim (GET on a scope — *not* re-encoded)
- Error shape: `{"error": "<message>"}` paired with an explicit `http_response_code()`.
- Status codes actually used: `204` (OPTIONS), `400` (bad input), `401` (unauthorized), `405` (method), `500` (write/mail failure). Reuse these; don't invent new ones without reason.
- Persisted JSON is always written with `JSON_PRETTY_PRINT`.

## 6. Validation & sanitization (follow `contact.php` and `collections.php`)

- **Required fields:** collect errors into an array, then `implode(', ', $errors)` into one message (see `contact.php`).
- **Email:** `filter_var($email, FILTER_VALIDATE_EMAIL)`.
- **Lengths:** `mb_strlen()` against explicit caps (255 for short fields, 50 for phone, 5000 for message body).
- **HTML output safety:** `htmlspecialchars($x, ENT_QUOTES, 'UTF-8')` for any value interpolated into an HTML email; `nl2br()` for multi-line message bodies. Store the escaped copy in a `$s_`-prefixed variable.
- **Filesystem safety:** `preg_replace('/[^a-zA-Z0-9-_\.]/', '_', $key)` for upload keys; `basename(parse_url($url, PHP_URL_PATH))` for delete targets. Never pass raw user input to filesystem calls.
- **Upload validation order:** presence → `UPLOAD_ERR_OK` → size cap (5 MB) → real MIME via `finfo` against an allow-list (`image/jpeg|png|gif|webp`).

## 7. Persistence rules

- Resolve the target file through config (`$cfg['content_file']`, etc.), never hardcode a path.
- `ensure_json_file()` before reading/writing (creates dir + `{}` placeholder if absent).
- **Atomic writes only:** `file_put_contents($file.'.tmp', ...)` then `rename($file.'.tmp', $file)`.
- Generated upload filenames: `"{key}_{time()}_{bin2hex(random_bytes(4))}.{ext}"`.

## 8. Language / i18n in code

- Validation and client-facing error strings are written in **Spanish** in `contact.php` (e.g. `'nombre es obligatorio'`, `'Formato de email inválido'`). `collections.php`/`login.php` use short **English** tokens (`'Unauthorized'`, `'Invalid JSON'`, `'Method not allowed'`).
- Email subjects/bodies are **Catalan** (with some Spanish). Match whichever language the surrounding file already uses; do not "standardize" languages.

## 9. Comments

- Sparse, in Spanish, used to mark sections (e.g. `// Upload image file (admin only)`, `// --- Read and validate input ---`). Keep comments short and section-oriented. No PHPDoc blocks in the current code.

## 10. Error handling

- No global exception handler. The only `try/catch` is around PHPMailer `send()` in `contact.php` (PHPMailer is constructed with `new PHPMailer(true)` to throw on error).
- For ordinary failures, set the status code and `echo` an error JSON, then `exit`. Do not throw.

## 11. What NOT to add (stay consistent with the codebase)

- No Composer autoload-based app namespaces / PSR-4 app code (only PHPMailer is autoloaded).
- No frameworks, routers, templating engines, ORMs, or migrations.
- No `.env` parsing libs — configuration is a returned PHP array in `config.php`.
- No closing `?>` tags.
- No new top-level dependencies without updating `composer.json`/`composer.lock` and confirming the OVH PHP version supports them.
