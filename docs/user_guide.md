# Documentación — Endpoint de Contacto por Email

## Índice

1. [Descripción general](#1-descripción-general)
2. [Requisitos previos](#2-requisitos-previos)
3. [Instalación de PHP en Windows](#3-instalación-de-php-en-windows)
4. [Instalación de Composer](#4-instalación-de-composer)
5. [Instalación de dependencias del proyecto](#5-instalación-de-dependencias-del-proyecto)
6. [Configuración del servicio de email (SMTP)](#6-configuración-del-servicio-de-email-smtp)
7. [Configuración del proyecto](#7-configuración-del-proyecto)
8. [Ejecución en local (desarrollo)](#8-ejecución-en-local-desarrollo)
9. [Tests y verificación](#9-tests-y-verificación)
10. [Despliegue en OVH](#10-despliegue-en-ovh)
11. [Referencia del API — contact.php](#11-referencia-del-api--contactphp)
12. [Solución de problemas](#12-solución-de-problemas)

---

## 1. Descripción general

El endpoint `contact.php` permite recibir mensajes desde un formulario de contacto web. Al recibir una petición:

1. **Valida** los datos del formulario (nombre, email, asunto, mensaje, etc.)
2. **Envía un email** a `info@amagullent.org` con los datos del mensaje
3. **Envía un email de confirmación** al visitante indicándole que su mensaje fue recibido

El envío se realiza mediante **PHPMailer** a través de un servidor SMTP externo gratuito (Brevo).

### Archivos involucrados

| Archivo | Descripción |
|---|---|
| `contact.php` | Endpoint de contacto |
| `config.php` | Configuración con credenciales SMTP (no versionado) |
| `config.example.php` | Plantilla de configuración (versionado) |
| `composer.json` | Dependencias PHP (PHPMailer) |
| `vendor/` | Carpeta de dependencias instaladas (no versionada) |

---

## 2. Requisitos previos

- **PHP 7.4+** (recomendado 8.0+) con las extensiones:
  - `openssl` (necesaria para SMTP con TLS)
  - `mbstring` (validación de longitud de campos)
  - `fileinfo` (ya requerida por el proyecto)
- **Composer** (gestor de dependencias PHP)
- Cuenta en un servicio SMTP gratuito (ver sección 6)

---

## 3. Instalación de PHP en Windows

### Opción A — PHP standalone (recomendado)

1. Descargar PHP desde: https://windows.php.net/download/
   - Elegir la versión **VS16 x64 Thread Safe** (zip)
2. Extraer el contenido en `C:\php`
3. Configurar `php.ini`:
   ```
   cd C:\php
   copy php.ini-development php.ini
   ```
4. Editar `C:\php\php.ini` y descomentar estas líneas (quitar el `;` del inicio):
   ```ini
   extension=fileinfo
   extension=openssl
   extension=mbstring
   ```
5. Añadir `C:\php` al **PATH** del sistema:
   - Pulsar `Win + R` → escribir `sysdm.cpl` → Enter
   - Pestaña **Opciones avanzadas** → botón **Variables de entorno**
   - En la sección **Variables del sistema**, seleccionar `Path` → **Editar**
   - Clic en **Nuevo** → escribir `C:\php`
   - Aceptar todo
6. **Cerrar y reabrir** la terminal (PowerShell/CMD)
7. Verificar:
   ```powershell
   php -v
   ```
   Debe mostrar la versión de PHP instalada.

### Opción B — XAMPP

1. Descargar XAMPP desde: https://www.apachefriends.org
2. Instalar en `C:\xampp`
3. Añadir `C:\xampp\php` al PATH del sistema (mismo procedimiento que arriba)
4. Verificar con `php -v`

---

## 4. Instalación de Composer

Composer es el gestor de paquetes de PHP. Es necesario para instalar PHPMailer.

### Paso a paso

1. Abrir PowerShell y ejecutar:
   ```powershell
   cd C:\php
   php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
   php composer-setup.php
   php -r "unlink('composer-setup.php');"
   ```
   Esto crea el archivo `composer.phar` en `C:\php`.

2. Crear el archivo `C:\php\composer.bat` con este contenido:
   ```bat
   @echo off
   php "%~dp0composer.phar" %*
   ```
   Esto permite ejecutar `composer` como comando global.

3. Verificar (reabrir terminal):
   ```powershell
   composer --version
   ```
   Debe mostrar algo como: `Composer version 2.x.x`.

### Alternativa: instalador gráfico

Descargar el instalador de Windows desde https://getcomposer.org/download/ y seguir el asistente. Detecta PHP automáticamente.

---

## 5. Instalación de dependencias del proyecto

Navegar a la carpeta del proyecto e instalar:

```powershell
cd c:\Users\aramos\Development\ama\ama-server
composer install
```

Esto crea la carpeta `vendor/` con PHPMailer y su autoloader. La carpeta `vendor/` está en `.gitignore` y **no debe subirse al repositorio Git**, pero **sí debe subirse al servidor de producción**.

### Verificar la instalación

```powershell
# Debe existir el autoloader
dir vendor\autoload.php

# Debe existir PHPMailer
dir vendor\phpmailer\phpmailer\src\PHPMailer.php
```

---

## 6. Configuración del servicio de email (SMTP)

### ¿Por qué un servicio SMTP externo?

La función `mail()` nativa de PHP depende de un servidor de correo local y los emails suelen caer en **spam**. Un servicio SMTP externo garantiza mejor entregabilidad.

### Opción recomendada: Brevo (gratis)

**Brevo** (antes Sendinblue) ofrece **300 emails gratis al día**, suficiente para un formulario de contacto.

#### Crear cuenta y obtener credenciales

1. Ir a https://www.brevo.com y crear una cuenta gratuita
2. Confirmar el email de registro
3. En el panel de Brevo, ir a:
   **Settings** (icono de engranaje, esquina superior derecha) → **SMTP & API**
4. En la pestaña **SMTP**, clic en **Generate a new SMTP key**
5. Copiar los datos:
   - **Servidor SMTP:** `smtp-relay.brevo.com`
   - **Puerto:** `587`
   - **Login/usuario:** tu email de la cuenta Brevo
   - **Contraseña:** la clave SMTP generada (formato `xsmtpsib-xxxxxxxxx...`)

#### Verificar el remitente (importante)

Brevo requiere que el email remitente esté verificado:
1. Ir a **Settings** → **Senders, Domains & Dedicated IPs** → **Senders**
2. Añadir `noreply@amagullent.org` (o el email que vayas a usar como From)
3. Confirmar la verificación que llegará a ese buzón

> **Si no tienes control del dominio** `amagullent.org`: usa como `smtp_from` el mismo email con el que te registraste en Brevo (que ya está verificado automáticamente).

### Opción alternativa: Gmail SMTP

Si prefieres usar una cuenta de Gmail:

| Parámetro | Valor |
|---|---|
| `smtp_host` | `smtp.gmail.com` |
| `smtp_port` | `587` |
| `smtp_user` | `tucuenta@gmail.com` |
| `smtp_pass` | Tu **App Password** (ver abajo) |

#### Generar App Password de Gmail

1. Ir a https://myaccount.google.com/security
2. Activar **Verificación en 2 pasos** (si no está activa)
3. Ir a **Contraseñas de aplicaciones** (App Passwords)
4. Crear una nueva para "Correo" + "Otro (nombre personalizado)" → "AMA Contacto"
5. Copiar la contraseña de 16 caracteres generada

---

## 7. Configuración del proyecto

Editar el archivo `config.php` con las credenciales obtenidas en el paso anterior:

```php
<?php
return [
    'admin_password' => 'TU_PASSWORD_SEGURA',
    'cors_origin'    => 'http://localhost:3000',        // Desarrollo
    // 'cors_origin' => 'https://www.amagullent.org',   // Producción
    'content_file'   => __DIR__ . '/content.json',
    'images_file'    => __DIR__ . '/images.json',
    'collections_file' => __DIR__ . '/collections.json',
    'uploads_dir'    => __DIR__ . '/uploads',

    // SMTP — Brevo
    'smtp_host'      => 'smtp-relay.brevo.com',
    'smtp_port'      => 587,
    'smtp_user'      => 'tu-email@ejemplo.com',         // ← Email de tu cuenta Brevo
    'smtp_pass'      => 'xsmtpsib-xxxxxxxxxxxxxxxxx',   // ← Clave SMTP de Brevo
    'smtp_from'      => 'noreply@amagullent.org',       // ← Email verificado en Brevo
    'smtp_from_name' => 'AMA Agullent',
    'contact_to'     => 'info@amagullent.org'            // ← Destinatario de los mensajes
];
```

> **Seguridad:** `config.php` contiene credenciales y está en `.gitignore`. Nunca lo subas al repositorio Git.

---

## 8. Ejecución en local (desarrollo)

### Con el servidor integrado de PHP

```powershell
cd c:\Users\aramos\Development\ama\ama-server
php -S localhost:8000
```

El endpoint estará disponible en: `http://localhost:8000/contact.php`

### Con XAMPP

1. Copiar o crear un enlace simbólico de la carpeta del proyecto en `C:\xampp\htdocs\ama-server`
2. Arrancar Apache desde el panel de XAMPP
3. El endpoint estará en: `http://localhost/ama-server/contact.php`

---

## 9. Tests y verificación

Ejecutar estos tests desde una **segunda terminal** (mantener el servidor PHP corriendo).

### Test 1 — Envío correcto ✅

```powershell
curl -X POST http://localhost:8000/contact.php `
  -H "Content-Type: application/json" `
  -d '{\"nombre\":\"Alejandro\",\"apellido\":\"Ramos\",\"email\":\"tu-email@gmail.com\",\"telefono\":\"612345678\",\"asunto\":\"Prueba de contacto\",\"body\":\"Este es un mensaje de prueba.\"}'
```

**Respuesta esperada:** `{"ok":true}`

**Verificar:**
- Llega un email a `info@amagullent.org` con los datos del formulario
- Llega un email de confirmación a `tu-email@gmail.com`

### Test 2 — Campos obligatorios vacíos ❌

```powershell
curl -X POST http://localhost:8000/contact.php `
  -H "Content-Type: application/json" `
  -d '{\"nombre\":\"\",\"email\":\"\",\"asunto\":\"\",\"body\":\"\"}'
```

**Respuesta esperada (HTTP 400):**
```json
{"error":"nombre es obligatorio, email es obligatorio, asunto es obligatorio, body es obligatorio"}
```

### Test 3 — Email con formato inválido ❌

```powershell
curl -X POST http://localhost:8000/contact.php `
  -H "Content-Type: application/json" `
  -d '{\"nombre\":\"Test\",\"email\":\"esto-no-es-un-email\",\"asunto\":\"Test\",\"body\":\"Hola\"}'
```

**Respuesta esperada (HTTP 400):**
```json
{"error":"Formato de email inválido"}
```

### Test 4 — Preflight CORS (OPTIONS)

```powershell
curl -X OPTIONS http://localhost:8000/contact.php -v
```

**Respuesta esperada:** HTTP **204**, sin body, con cabeceras `Access-Control-Allow-*`.

### Test 5 — Método no permitido (GET)

```powershell
curl http://localhost:8000/contact.php
```

**Respuesta esperada (HTTP 405):**
```json
{"error":"Method not allowed"}
```

### Test 6 — Body vacío / JSON inválido ❌

```powershell
curl -X POST http://localhost:8000/contact.php `
  -H "Content-Type: application/json" `
  -d 'esto no es json'
```

**Respuesta esperada (HTTP 400):**
```json
{"error":"Invalid JSON body"}
```

### Resumen de tests

| # | Caso | Método | HTTP esperado | Respuesta |
|---|---|---|---|---|
| 1 | Envío correcto | POST | 200 | `{"ok":true}` |
| 2 | Campos vacíos | POST | 400 | Listado de errores |
| 3 | Email inválido | POST | 400 | `Formato de email inválido` |
| 4 | Preflight CORS | OPTIONS | 204 | Sin body |
| 5 | Método GET | GET | 405 | `Method not allowed` |
| 6 | JSON inválido | POST | 400 | `Invalid JSON body` |

---

## 10. Despliegue en OVH

### 10.1 Preparar los archivos en local

```powershell
cd c:\Users\aramos\Development\ama\ama-server
composer install --no-dev
```

La opción `--no-dev` excluye dependencias de desarrollo y reduce el tamaño.

### 10.2 Crear el config.php de producción

Crear un `config.php` con los valores de producción:

```php
<?php
return [
    'admin_password' => 'UNA_PASSWORD_MUY_SEGURA',
    'cors_origin'    => 'https://www.amagullent.org',   // ← Dominio real del frontend
    'content_file'   => __DIR__ . '/content.json',
    'images_file'    => __DIR__ . '/images.json',
    'collections_file' => __DIR__ . '/collections.json',
    'uploads_dir'    => __DIR__ . '/uploads',

    'smtp_host'      => 'smtp-relay.brevo.com',
    'smtp_port'      => 587,
    'smtp_user'      => 'tu-email@brevo.com',
    'smtp_pass'      => 'xsmtpsib-tu-clave-real',
    'smtp_from'      => 'noreply@amagullent.org',
    'smtp_from_name' => 'AMA Agullent',
    'contact_to'     => 'info@amagullent.org'
];
```

### 10.3 Conectar al servidor OVH por FTP

Usar **FileZilla**, **WinSCP**, o el **File Manager** del panel de OVH.

| Dato | Valor |
|---|---|
| **Protocolo** | FTP (puerto 21) o SFTP (puerto 22) |
| **Host** | El que aparece en tu panel OVH (Web Cloud → Hosting → FTP-SSH) |
| **Usuario** | Tu usuario FTP de OVH |
| **Contraseña** | La que configuraste en OVH |

### 10.4 Subir archivos

La carpeta raíz web en OVH suele ser `/www/` o `/public_html/`.

Subir todo a una subcarpeta, por ejemplo `/www/ama-server/`:

```
/www/ama-server/
├── vendor/                  ← IMPORTANTE: subir esta carpeta completa
│   ├── autoload.php
│   ├── composer/
│   └── phpmailer/
├── uploads/
│   └── .gitkeep
├── collections.php
├── contact.php
├── login.php
├── logout.php
├── config.php               ← Con credenciales de producción
├── config.example.php
├── composer.json
├── content.json
├── images.json
└── collections.json
```

**NO subir:**
- `.git/`
- `.gitignore`
- `composer.lock` (no es necesario en el servidor)
- `README.md` (opcional)

### 10.5 Configurar permisos

Desde el File Manager de OVH o por SSH:

```bash
cd ~/www/ama-server
chmod 755 uploads
chmod 644 config.php *.json
```

| Carpeta/archivo | Permisos | Motivo |
|---|---|---|
| `uploads/` | 755 | PHP necesita escribir aquí |
| `config.php` | 644 | Solo lectura para PHP |
| `*.php` | 644 | Solo lectura/ejecución |
| `content.json`, `images.json`, `collections.json` | 644 | Lectura/escritura por PHP |

### 10.6 Verificar versión de PHP en OVH

1. Panel de OVH: **Web Cloud** → seleccionar tu hosting → **Configuración general**
2. Verificar que la versión de PHP sea **7.4 o superior** (idealmente 8.0+)
3. Si no lo es, cambiarla desde el panel

#### Verificación rápida (opcional)

Crear temporalmente `/www/ama-server/info.php`:
```php
<?php phpinfo();
```
Acceder a `https://tudominio.com/ama-server/info.php`, comprobar la versión y extensiones, y **borrar el archivo inmediatamente** (riesgo de seguridad).

### 10.7 Probar en producción

```bash
# Test rápido (debe devolver 405)
curl https://www.amagullent.org/ama-server/contact.php

# Test de envío real
curl -X POST https://www.amagullent.org/ama-server/contact.php \
  -H "Content-Type: application/json" \
  -d '{"nombre":"Test","apellido":"Producción","email":"tu@email.com","telefono":"600000000","asunto":"Prueba desde OVH","body":"El formulario de contacto funciona en producción."}'
```

**Respuesta esperada:** `{"ok":true}`

---

## 11. Referencia del API — contact.php

### Endpoint

```
POST /ama-server/contact.php
Content-Type: application/json
```

### Body (JSON)

| Campo | Tipo | Obligatorio | Longitud máx. | Descripción |
|---|---|---|---|---|
| `nombre` | string | ✅ Sí | 255 | Nombre del visitante |
| `apellido` | string | ❌ No | 255 | Apellido del visitante |
| `email` | string | ✅ Sí | 255 | Email del visitante (formato válido) |
| `telefono` | string | ❌ No | 50 | Teléfono del visitante |
| `asunto` | string | ✅ Sí | 255 | Asunto del mensaje |
| `body` | string | ✅ Sí | 5000 | Cuerpo del mensaje |

### Ejemplo de petición

```json
{
  "nombre": "María",
  "apellido": "García",
  "email": "maria@ejemplo.com",
  "telefono": "612345678",
  "asunto": "Información sobre actividades",
  "body": "Me gustaría saber más sobre las actividades de la asociación."
}
```

### Respuestas

| HTTP | Body | Significado |
|---|---|---|
| 200 | `{"ok":true}` | Mensaje enviado correctamente |
| 400 | `{"error":"..."}` | Error de validación (campos faltantes, email inválido, etc.) |
| 405 | `{"error":"Method not allowed"}` | Se usó un método distinto de POST |
| 500 | `{"error":"No se pudo enviar el mensaje..."}` | Error del servidor SMTP |

### Comportamiento de emails

Al recibir un POST válido, se envían **2 emails**:

1. **A `info@amagullent.org`:** Email HTML con todos los datos del formulario. El email del visitante se incluye como **Reply-To** para poder responder directamente.

2. **Al visitante:** Email de confirmación automático con el texto: _"Hemos recibido tu mensaje con el asunto '[asunto]' y te responderemos lo antes posible."_

> Si el email de confirmación falla pero el principal se envió correctamente, el endpoint devuelve `{"ok":true}` igualmente.

### Ejemplo de integración con JavaScript (frontend)

```javascript
const response = await fetch('https://www.amagullent.org/ama-server/contact.php', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    nombre: 'María',
    apellido: 'García',
    email: 'maria@ejemplo.com',
    telefono: '612345678',
    asunto: 'Consulta',
    body: 'Me gustaría más información.'
  })
});

const data = await response.json();
if (data.ok) {
  alert('Mensaje enviado correctamente');
} else {
  alert('Error: ' + data.error);
}
```

---

## 12. Solución de problemas

### El email no llega

1. **Verificar credenciales SMTP:** Asegurarse de que `smtp_user` y `smtp_pass` en `config.php` son correctos
2. **Verificar remitente:** El email en `smtp_from` debe estar verificado en Brevo
3. **Revisar carpeta de spam:** Los primeros envíos pueden llegar a spam
4. **Probar con debug SMTP:** Añadir temporalmente en `contact.php` antes de `$mail->send()`:
   ```php
   $mail->SMTPDebug = 2; // Mostrar log detallado de SMTP
   ```
   Esto imprimirá la conversación SMTP en la respuesta (quitar después de depurar)

### Error 500 al llamar al endpoint

1. **¿Existe `vendor/`?** Verificar que se ejecutó `composer install` y que la carpeta `vendor/` está en el servidor
2. **¿PHP tiene openssl?** Ejecutar `php -m | grep openssl` (o revisar `phpinfo()`)
3. **Revisar logs de PHP:** En OVH, los logs están en el panel: **Web Cloud** → Hosting → **Logs y estadísticas**

### Error CORS en el frontend

Verificar que `cors_origin` en `config.php` coincide **exactamente** con el origen del frontend:
- `https://www.amagullent.org` (con https, sin barra final)
- No confundir `www.amagullent.org` con `amagullent.org` — deben ser iguales

### Los emails llegan a spam

1. **Configurar SPF en el DNS** del dominio `amagullent.org`:
   - Añadir un registro TXT: `v=spf1 include:sendinblue.com ~all` (para Brevo)
2. **Configurar DKIM:** Seguir las instrucciones de Brevo en Settings → Senders → Domains
3. **Usar un remitente del mismo dominio:** `noreply@amagullent.org` es mejor que un gmail como From
