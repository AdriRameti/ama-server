# AMA Server — "Database" (JSON File Storage)

> **There is no SQL/NoSQL database.** Persistence is three flat JSON files + an uploads directory. This document is the equivalent of a schema reference: it describes the document shapes, keys, relationships, and rules.

---

## 1. The storage model

| Logical store | File | Config key | Created by | In git? |
|---|---|---|---|---|
| Editable texts | `content.json` | `content_file` | app (`ensure_json_file`) | a sample is committed |
| Editable images | `images.json` | `images_file` | app at first write | **no** (`.gitignore`) |
| Dynamic collections | `collections.json` | `collections_file` | app at first write | **no** (`.gitignore`) |
| Uploaded images | `uploads/` | `uploads_dir` | app at first upload | **no** (only `.gitkeep`) |

All three JSON files are **single JSON documents**, pretty-printed (`JSON_PRETTY_PRINT`), UTF-8 with `\u`-escaped non-ASCII (default `json_encode` behaviour — see the committed `content.json`).

There are **no tables, foreign keys, indexes, migrations, seeders, factories, soft-deletes, or audit columns.** The "schema" is whatever the React client writes.

## 2. `content.json` — editable text overrides

**Shape:** flat object, `string → string`.

```json
{
  "home.welcome.description": "A l'Agrupació Musical Agullent creiem en formar...",
  "admin.toolbar.save": "Guardar"
}
```

- **Key** = a `contentKey` from the client (dot-namespaced, mirrors the i18n key structure, e.g. `home.welcome.description`, `footer.title`, `band.concerts.title`).
- **Value** = the admin-overridden text. If a key is absent, the client falls back to its bundled i18n default (`getText(key, default)`).
- This is an **override layer**, not the full content set — only keys the admin actually edited appear here.

## 3. `images.json` — editable image overrides

**Shape:** flat object, `string → url`.

```json
{
  "header.logo": "/api/uploads/header.logo_1730000000_a1b2c3d4.jpg",
  "home.carousel.slide1.image": "/api/uploads/slide1_..._.png"
}
```

- **Key** = a `contentKey` for an image slot (e.g. `header.logo`, `home.carousel.slide1.image`, `band.concerts.modal.<id>.imagen`).
- **Value** = a URL returned by the upload endpoint (points into `uploads/`). Client falls back to the bundled asset when a key is absent (`getImage(key, default)`).

## 4. `collections.json` — dynamic lists

**Shape:** object, `string → array<object>`.

```json
{
  "band.concerts.home.single.items": [
    {
      "id": 1,
      "titulo": "Concert Musica Festera",
      "fecha": "8 de marzo de 2026",
      "hora": "18 de la tarde",
      "lugar": "Auditori Josep Maria Bru, Agullent",
      "descripcion": "Un repertori variat de marches i passdobles...",
      "precio": "Gratuita",
      "imagen": "<asset-or-uploaded-url>",
      "estado": "proximo"
    }
  ]
}
```

- **Key** = a `collectionKey` (e.g. `band.concerts.home.single.items`).
- **Value** = array of item objects. Every item has an **`id`** (number, or a client-generated string like `"<key>-<timestamp>-<rand>"`). The client uses `id` for update/remove.
- The **concert item shape** above is the most important one (drives the home/news concert cards). Fields: `id, titulo, fecha, hora, lugar, descripcion, precio, imagen, estado`. Optional extended fields the modal renders if present: `programa` (array of `{titulo, compositor, duracion?}`), `participantes`, `informacionAdicional`.

### Relationship note (client-side, not enforced here)
The concerts collection `band.concerts.home.single.items` is the source for the client's in-memory `newsStore`, which derives "noticias" (news) items from concerts. The server stores only the raw array; the derivation/relationship lives entirely in the React client (`src/data/newsStore.js`). The backend enforces **no** referential integrity.

## 5. `uploads/` — binary store

- Files named `{sanitizedKey}_{unixTime}_{8hexChars}.{ext}`, e.g. `carousel_1730000000_a1b2c3d4.jpg`.
- Allowed extensions map from MIME: `jpg, png, gif, webp`.
- Public URL = `script_base_path()` + `/uploads/<filename>` (e.g. `/api/uploads/...`).
- Orphan cleanup: deleting an image only `unlink`s the file when the client calls `?action=delete-image`. Removing a key from `images.json` does **not** delete the underlying file automatically. Expect orphaned files over time.

## 6. Integrity / consistency rules

- **No locking.** Concurrent writes to the same scope file race; temp+rename makes each individual write atomic, but last-writer-wins. Single-admin usage makes this acceptable.
- **Whole-document writes.** A save replaces the entire scope file with the client-provided object. The server never merges. (The client *does* send the full object it holds in state.)
- **Validation on write is JSON-validity only** for the data scopes — the server does not validate the *shape* of `content`/`images`/`collections`. Shape correctness is the client's responsibility.
- **No schema versioning / migrations.** Changing the item shape is a client concern; old `collections.json` data simply coexists.

## 7. If you are ever asked to migrate to a real DB

`docs` (client `OVH_SHARED_DEPLOY.md` §9) explicitly suggests MySQL/MariaDB as the future step **while keeping the same PHP endpoints**. The migration target would be: one table per scope (or a generic `kv(scope, key, value_json)` table) + a `collections`/`concerts` table, exposed behind the identical `collections.php` contract. Do not undertake this unless explicitly requested.
