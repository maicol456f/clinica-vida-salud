<?php
/* Envío de correo por la API HTTP de Brevo (https://developers.brevo.com). No usa SMTP porque Render bloquea esos puertos. */

function brevo_configurado(): bool { return env('BREVO_API_KEY') !== null && env('MAIL_FROM_EMAIL') !== null; }

function enviar_correo(string $para, string $nombre, string $asunto, string $html, string $texto): bool {
    if (!brevo_configurado()) return false;
    $payload = json_encode([
        'sender' => ['name' => env('MAIL_FROM_NAME', 'Clínica Vida Salud'), 'email' => env('MAIL_FROM_EMAIL')],
        'to' => [['email' => $para, 'name' => $nombre ?: $para]],
        'subject' => $asunto, 'htmlContent' => $html, 'textContent' => $texto,
    ], JSON_UNESCAPED_UNICODE);
    $url = env('BREVO_API_URL', 'https://api.brevo.com/v3/smtp/email');
    $headers = ['accept: application/json', 'content-type: application/json', 'api-key: ' . env('BREVO_API_KEY')];
    $resp = false; $code = 0; $err = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
        $resp = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $payload, 'timeout' => 15, 'ignore_errors' => true]]);
        $resp = @file_get_contents($url, false, $ctx);
        if (isset($http_response_header[0]) && preg_match('/ (\d{3})/', $http_response_header[0], $m)) $code = (int)$m[1];
        if ($resp === false) $err = 'sin respuesta del servidor de correo';
    }
    if ($code >= 200 && $code < 300) return true;
    error_log("Brevo: fallo al enviar correo (HTTP $code) " . ($resp ?: $err));   // la clave nunca se registra
    return false;
}

function correo_recuperacion(string $nombre, string $link): array {
    $n = htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8'); $l = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
    $html = "<div style=\"font-family:Arial,sans-serif;max-width:480px;margin:auto;color:#17313a\">
      <h2 style=\"color:#0f766e\">🩺 Clínica Vida Salud</h2><p>Hola $n,</p>
      <p>Recibimos una solicitud para restablecer su contraseña. Presione el botón para elegir una nueva (el enlace vence en 30 minutos y sólo puede usarse una vez):</p>
      <p style=\"text-align:center\"><a href=\"$l\" style=\"background:#0f766e;color:#fff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:bold\">Restablecer contraseña</a></p>
      <p style=\"font-size:13px;color:#5b7480\">Si el botón no funciona, copie este enlace en su navegador:<br>$l</p>
      <p style=\"font-size:13px;color:#5b7480\">Si usted no lo solicitó, ignore este mensaje: su contraseña no cambiará.</p></div>";
    $txt = "Hola $nombre,\n\nPara restablecer su contraseña abra este enlace (vence en 30 minutos):\n$link\n\nSi usted no lo solicitó, ignore este mensaje.";
    return [$html, $txt];
}
