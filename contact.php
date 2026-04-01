<?php
require __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$cfg = require __DIR__ . '/config.php';

header('Access-Control-Allow-Origin: ' . $cfg['cors_origin']);
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// --- Read and validate input ---
$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
}

$nombre   = isset($body['nombre'])   ? trim($body['nombre'])   : '';
$apellido = isset($body['apellido']) ? trim($body['apellido']) : '';
$email    = isset($body['email'])    ? trim($body['email'])    : '';
$telefono = isset($body['telefono']) ? trim($body['telefono']) : '';
$asunto   = isset($body['asunto'])   ? trim($body['asunto'])   : '';
$mensaje  = isset($body['body'])     ? trim($body['body'])     : '';

// Required fields
$errors = [];
if ($nombre === '')  $errors[] = 'nombre es obligatorio';
if ($email === '')   $errors[] = 'email es obligatorio';
if ($asunto === '')  $errors[] = 'asunto es obligatorio';
if ($mensaje === '') $errors[] = 'body es obligatorio';

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['error' => implode(', ', $errors)]);
    exit;
}

// Format validation
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Formato de email inválido']);
    exit;
}

// Length limits
if (mb_strlen($nombre) > 255 || mb_strlen($apellido) > 255 || mb_strlen($email) > 255 ||
    mb_strlen($telefono) > 50 || mb_strlen($asunto) > 255 || mb_strlen($mensaje) > 5000) {
    http_response_code(400);
    echo json_encode(['error' => 'Uno o más campos exceden la longitud máxima']);
    exit;
}

// --- Sanitize for HTML output ---
$s_nombre   = htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8');
$s_apellido = htmlspecialchars($apellido, ENT_QUOTES, 'UTF-8');
$s_email    = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
$s_telefono = htmlspecialchars($telefono, ENT_QUOTES, 'UTF-8');
$s_asunto   = htmlspecialchars($asunto, ENT_QUOTES, 'UTF-8');
$s_mensaje  = nl2br(htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8'));

// --- Build notification email to info@ ---
$htmlBody = <<<HTML
<html>
<body style="font-family: Arial, sans-serif; color: #333; line-height: 1.6;">
    <h2 style="color: #2c3e50;">Nuevo mensaje de contacto</h2>
    <table style="border-collapse: collapse; width: 100%; max-width: 600px;">
        <tr><td style="padding: 8px; font-weight: bold; border-bottom: 1px solid #eee;">Nombre:</td>
            <td style="padding: 8px; border-bottom: 1px solid #eee;">{$s_nombre} {$s_apellido}</td></tr>
        <tr><td style="padding: 8px; font-weight: bold; border-bottom: 1px solid #eee;">Email:</td>
            <td style="padding: 8px; border-bottom: 1px solid #eee;"><a href="mailto:{$s_email}">{$s_email}</a></td></tr>
        <tr><td style="padding: 8px; font-weight: bold; border-bottom: 1px solid #eee;">Teléfono:</td>
            <td style="padding: 8px; border-bottom: 1px solid #eee;">{$s_telefono}</td></tr>
        <tr><td style="padding: 8px; font-weight: bold; border-bottom: 1px solid #eee;">Asunto:</td>
            <td style="padding: 8px; border-bottom: 1px solid #eee;">{$s_asunto}</td></tr>
    </table>
    <div style="margin-top: 20px; padding: 15px; background: #f9f9f9; border-left: 4px solid #3498db;">
        <strong>Mensaje:</strong><br><br>
        {$s_mensaje}
    </div>
</body>
</html>
HTML;

// --- Send notification email to info@ ---
$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host       = $cfg['smtp_host'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $cfg['smtp_user'];
    $mail->Password   = $cfg['smtp_pass'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = $cfg['smtp_port'];
    $mail->CharSet    = 'UTF-8';

    $mail->setFrom($cfg['smtp_from'], $cfg['smtp_from_name']);
    $mail->addAddress($cfg['contact_to']);
    $mail->addReplyTo($email, "$nombre $apellido");

    $mail->isHTML(true);
    $mail->Subject = "Contacto web: $asunto";
    $mail->Body    = $htmlBody;
    $mail->AltBody = "Nombre: $nombre $apellido\nEmail: $email\nTeléfono: $telefono\nAsunto: $asunto\n\n$mensaje";

    $mail->send();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'No se pudo enviar el mensaje. Inténtalo más tarde.', 'debug' => $mail->ErrorInfo]);
    exit;
}

// --- Send confirmation email to the visitor ---
$confirmHtml = <<<HTML
<html>
<body style="font-family: Arial, sans-serif; color: #333; line-height: 1.6;">
    <h2 style="color: #2c3e50;">Hemos recibido tu mensaje</h2>
    <p>Hola {$s_nombre},</p>
    <p>Gracias por ponerte en contacto con nosotros. Hemos recibido tu mensaje con el asunto
       <strong>"{$s_asunto}"</strong> y te responderemos lo antes posible.</p>
    <hr style="border: none; border-top: 1px solid #eee; margin: 20px 0;">
    <p style="color: #777; font-size: 13px;">
        Este es un mensaje automático, por favor no respondas a este correo.<br>
        AMA Agullent &mdash; <a href="https://www.amagullent.org">www.amagullent.org</a>
    </p>
</body>
</html>
HTML;

try {
    $confirm = new PHPMailer(true);
    $confirm->isSMTP();
    $confirm->Host       = $cfg['smtp_host'];
    $confirm->SMTPAuth   = true;
    $confirm->Username   = $cfg['smtp_user'];
    $confirm->Password   = $cfg['smtp_pass'];
    $confirm->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $confirm->Port       = $cfg['smtp_port'];
    $confirm->CharSet    = 'UTF-8';

    $confirm->setFrom($cfg['smtp_from'], $cfg['smtp_from_name']);
    $confirm->addAddress($email, "$nombre $apellido");

    $confirm->isHTML(true);
    $confirm->Subject = 'Hemos recibido tu mensaje — AMA Agullent';
    $confirm->Body    = $confirmHtml;
    $confirm->AltBody = "Hola $nombre,\n\nGracias por contactar con AMA Agullent. Hemos recibido tu mensaje con el asunto \"$asunto\" y te responderemos lo antes posible.\n\nAMA Agullent — www.amagullent.org";

    $confirm->send();
} catch (Exception $e) {
    // Confirmation email failed but the main email was sent successfully.
    // We still return ok since the contact message was delivered.
}

echo json_encode(['ok' => true]);
