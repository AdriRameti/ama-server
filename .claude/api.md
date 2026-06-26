# AMA Server — API Reference

> Complete endpoint contract, derived from `login.php`, `logout.php`, `collections.php`, `contact.php`. Every endpoint is a physical file; the path is the file path under the deployed base (e.g. `/api`).

---

## Conventions common to all endpoints

- **Base URL:** configured on the client (`REACT_APP_CONTENT_API`); typically `/api` in production. Examples below use `/api`.
- **CORS:** `Access-Control-Allow-Origin: <cors_origin>`, `Allow-Credentials: true`, `Allow-Headers: Content-Type`, `Allow-Methods: GET, POST, OPTIONS`. A preflight `OPTIONS` returns **204** with no body.
- **Auth:** PHP session cookie. Send credentials (`fetch(..., { credentials: 'include' })`). Admin-only endpoints return **401** `{"error":"Unauthorized"}` without a valid admin session.
- **Content-Type:** responses are `application/json` (except `login.php` `GET`, which returns a tiny HTML form).
- **Success envelope:** `{"ok": true, ...}` or a raw JSON document (scope reads). **Error envelope:** `{"error": "<message>"}`.

---

## 1. `POST /api/login.php` — admin login

**Auth:** none required (this is how you get auth).

**Request body (JSON):**
```json
{ "password": "your-admin-password" }
```
(Also accepts form-encoded `password` via `$_POST` fallback.)

**Responses:**
| Status | Body | When |
|---|---|---|
| 200 | `{"ok": true}` | password === `config.admin_password`; sets `$_SESSION['is_admin']` |
| 401 | `{"error": "Invalid credentials"}` | wrong/missing password |

**`GET /api/login.php`** → returns a minimal HTML `<form>` for manual testing (not JSON).

**Example:**
```bash
curl -X POST -c cookies.txt -H "Content-Type: application/json" \
  -d '{"password":"AMA2026!"}' http://localhost:8000/login.php
```

---

## 2. `POST /api/logout.php` (or `GET`) — logout

**Auth:** none required. Clears `$_SESSION`, expires the session cookie, `session_destroy()`.

**Response:** `200 {"ok": true}`.

```bash
curl -X POST -b cookies.txt http://localhost:8000/logout.php
```

---

## 3. `/api/collections.php` — content / images / collections + image files

The single data endpoint. Behaviour depends on `?scope=` and `?action=`.

### `?scope=` selects the file
| `scope` | File | Notes |
|---|---|---|
| `content` | `content.json` | editable texts |
| `images` | `images.json` | editable image URLs |
| `collections` *(default if omitted)* | `collections.json` | dynamic lists |

### 3a. `GET /api/collections.php?scope=<scope>` — read (public)
Returns the **raw JSON document** for that scope. No auth.

```bash
curl http://localhost:8000/collections.php?scope=content
```
Response: the file contents, e.g. `{"home.welcome.description":"...","admin.toolbar.save":"Guardar"}`. (Empty store returns `{}`.)

### 3b. `POST /api/collections.php?scope=<scope>` — save (admin)
Replaces the entire scope document with the JSON request body (atomic write).

| Status | Body | When |
|---|---|---|
| 200 | `{"ok": true}` | saved |
| 400 | `{"error": "Empty body"}` | no body |
| 400 | `{"error": "Invalid JSON"}` | body isn't valid JSON |
| 401 | `{"error": "Unauthorized"}` | not admin |
| 500 | `{"error": "Could not write file"}` | disk write failed |

```bash
curl -X POST -b cookies.txt -H "Content-Type: application/json" \
  -d '{"home.title":"Bienvenidos"}' \
  "http://localhost:8000/collections.php?scope=content"
```

### 3c. `POST /api/collections.php?action=upload` — upload image (admin)
Multipart form-data. Stores the file under `uploads/` and returns its URL.

**Form fields:** `file` (the image, required), `key` (optional logical name; sanitized; default `img`).

**Constraints:** ≤ **5 MB**; MIME (via `finfo`, not extension) ∈ `image/jpeg|png|gif|webp`.

| Status | Body | When |
|---|---|---|
| 200 | `{"ok": true, "url": "/api/uploads/<key>_<time>_<hex>.<ext>"}` | success |
| 400 | `{"error":"Missing file" \| "Upload error" \| "File too large" \| "Invalid file type"}` | validation failure |
| 401 | `{"error": "Unauthorized"}` | not admin |
| 500 | `{"error": "Could not move uploaded file"}` | move failed |

```bash
curl -X POST -b cookies.txt -F "file=@foto.jpg" -F "key=carousel" \
  "http://localhost:8000/collections.php?action=upload"
```

### 3d. `POST /api/collections.php?action=delete-image` — delete image (admin)
Deletes an uploaded file by URL (only the `basename` is used → traversal-safe).

**Body (JSON):** `{ "url": "/api/uploads/carousel_1730000000_a1b2c3d4.jpg" }`

| Status | Body | When |
|---|---|---|
| 200 | `{"ok": true}` | deleted (or already absent) |
| 400 | `{"error": "Invalid image URL"}` | URL yields no basename |
| 401 | `{"error": "Unauthorized"}` | not admin |

> Note: this only removes the file. It does **not** remove the key from `images.json` (the client does that separately by re-saving the images scope).

### Method fallback
Any other method on `collections.php` → `405 {"error":"Method not allowed"}`.

---

## 4. `POST /api/contact.php` — contact form → email

**Auth:** none (public form). Sends a notification email to the association and a confirmation email to the visitor. Writes nothing to disk.

**Request body (JSON):**
| Field | Required | Max len | Notes |
|---|---|---|---|
| `nombre` | ✅ | 255 | first name |
| `apellido` | ❌ | 255 | last name |
| `email` | ✅ | 255 | must pass `FILTER_VALIDATE_EMAIL`; used as `Reply-To` |
| `telefono` | ❌ | 50 | phone |
| `asunto` | ✅ | 255 | subject |
| `body` | ✅ | 5000 | message text (note the field is `body`, not `mensaje`) |

**Responses:**
| Status | Body | When |
|---|---|---|
| 200 | `{"ok": true}` | notification email sent (confirmation may have silently failed) |
| 400 | `{"error":"Invalid JSON body"}` | unparseable body |
| 400 | `{"error":"<field> es obligatorio, ..."}` | missing required fields (comma-joined) |
| 400 | `{"error":"Formato de email inválido"}` | bad email |
| 400 | `{"error":"Uno o más campos exceden la longitud máxima"}` | length cap exceeded |
| 405 | `{"error":"Method not allowed"}` | non-POST |
| 500 | `{"error":"No se pudo enviar el mensaje. Inténtalo más tarde.", "debug":"<SMTP error>"}` | notification send threw |

```bash
curl -X POST -H "Content-Type: application/json" \
  -d '{"nombre":"María","apellido":"García","email":"maria@ejemplo.com","telefono":"612345678","asunto":"Consulta","body":"Me gustaría más información."}' \
  http://localhost:8000/contact.php
```

> **Field-name gotcha for the client:** the form sends `body` for the message and `apellido` (singular) for last name. The React `ContactForm` maps its local `mensaje`→`body` and `apellidos`→`apellido`. Keep that mapping in mind when changing either side.

> **Debt:** the 500 response leaks `$mail->ErrorInfo` in `debug`. Don't expose SMTP internals in new code; see [debugging.md](debugging.md).

---

## Quick reference matrix

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/login.php` | – | set admin session |
| GET | `/login.php` | – | HTML test form |
| POST/GET | `/logout.php` | – | clear session |
| GET | `/collections.php?scope=content\|images\|collections` | – | read scope JSON |
| POST | `/collections.php?scope=...` | admin | overwrite scope JSON |
| POST | `/collections.php?action=upload` | admin | upload image |
| POST | `/collections.php?action=delete-image` | admin | delete image file |
| POST | `/contact.php` | – | send contact emails |
