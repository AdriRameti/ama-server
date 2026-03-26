# AMA Server — API Backend

Backend PHP de la **Agrupació Musical Agullent (AMA)**. API REST ligera con almacenamiento en ficheros JSON (sin base de datos) y gestión de imágenes por upload.

## Requisitos

- PHP 7.4 o superior
- Servidor web con soporte PHP (Apache, Nginx, XAMPP, etc.)
- Módulos PHP: `json`, `fileinfo`, `session`

## Instalación

```bash
# 1. Clonar el repositorio
git clone <url-del-repo> ama-server
cd ama-server

# 2. Crear el fichero de configuración a partir de la plantilla
cp config.example.php config.php

# 3. Editar config.php:
#    - Cambiar 'CHANGE_ME' por una contraseña segura
#    - Cambiar 'cors_origin' al dominio del frontend en producción
```

> **⚠️ Importante:** `config.php` contiene la contraseña de administración y está excluido del control de versiones mediante `.gitignore`. Nunca subas este fichero al repositorio.

## Estructura de ficheros

```
ama-server/
├── config.php              # Configuración local (ignorado por git)
├── config.example.php      # Plantilla de configuración
├── collections.php         # Endpoint principal de la API
├── login.php               # Autenticación (login)
├── logout.php              # Cierre de sesión (logout)
├── content.json            # Contenido editable (textos, traducciones)
├── collections.json        # Datos de colecciones (runtime, ignorado por git)
├── images.json             # Datos de imágenes (runtime, ignorado por git)
└── uploads/                # Imágenes subidas (contenido ignorado por git)
```

## API — Endpoints

### `login.php` — Autenticación

| Método | Descripción |
|--------|-------------|
| `POST` | Inicia sesión. Body JSON: `{ "password": "..." }`. Devuelve `{ "ok": true }` si es correcta. |
| `GET`  | Formulario HTML simple de login (para test manual). |

La autenticación se gestiona mediante **sesiones PHP** (`$_SESSION['is_admin']`).

### `logout.php` — Cierre de sesión

| Método | Descripción |
|--------|-------------|
| `POST` / `GET` | Destruye la sesión y la cookie. Devuelve `{ "ok": true }`. |

### `collections.php` — Endpoint principal

Gestiona contenido, colecciones e imágenes según el parámetro `scope`.

#### Parámetro `?scope=`

| Valor | Fichero | Descripción |
|-------|---------|-------------|
| `content` | `content.json` | Textos editables de la web |
| `images` | `images.json` | Referencias a imágenes |
| _(por defecto)_ | `collections.json` | Colecciones genéricas |

#### Operaciones

| Método | Acción (`?action=`) | Auth | Descripción |
|--------|---------------------|------|-------------|
| `GET` | — | No | Devuelve el JSON del scope solicitado |
| `POST` | — | Sí | Guarda el body JSON en el fichero del scope (escritura atómica) |
| `POST` | `upload` | Sí | Sube una imagen. Form-data: `file` (imagen), `key` (nombre opcional). Máx. 5 MB. Tipos: JPEG, PNG, GIF, WebP |
| `POST` | `delete-image` | Sí | Elimina una imagen subida. Body JSON: `{ "url": "..." }` |

#### Ejemplos de uso

```bash
# Obtener contenido
curl http://localhost/ama-server/collections.php?scope=content

# Guardar contenido (requiere sesión de admin)
curl -X POST -b cookies.txt \
  -H "Content-Type: application/json" \
  -d '{"home.title": "Bienvenidos"}' \
  http://localhost/ama-server/collections.php?scope=content

# Subir imagen (requiere sesión de admin)
curl -X POST -b cookies.txt \
  -F "file=@foto.jpg" \
  -F "key=carousel" \
  "http://localhost/ama-server/collections.php?action=upload"

# Eliminar imagen (requiere sesión de admin)
curl -X POST -b cookies.txt \
  -H "Content-Type: application/json" \
  -d '{"url": "/ama-server/uploads/carousel_1234_abcd.jpg"}' \
  "http://localhost/ama-server/collections.php?action=delete-image"
```

## CORS

El origen permitido para CORS se configura en `config.php` mediante el parámetro `cors_origin`:

```php
'cors_origin' => 'http://localhost:3000',   // desarrollo
'cors_origin' => 'https://www.amaagullent.com', // producción (ejemplo)
```

Por defecto apunta a `http://localhost:3000` (el cliente React en desarrollo). Para producción, cambia este valor al dominio real en `config.php`.

## Seguridad

- La contraseña de admin se almacena en `config.php` (texto plano). **Cambia la contraseña por defecto antes de desplegar.**
- La autenticación usa sesiones PHP estándar.
- Las imágenes subidas se validan por MIME type real (no por extensión) mediante `finfo`.
- El tamaño máximo de upload es 5 MB.
- Los nombres de fichero se sanitizan para evitar path traversal.
- La escritura de datos JSON es atómica (fichero temporal + rename).

## Licencia

Proyecto privado — Agrupació Musical Agullent.
