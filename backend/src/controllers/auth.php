<?php
require_once __DIR__ . '/../mail.php';
/* CU-01 Iniciar sesión, CU-02 Recuperar contraseña, CU-07 Autorregistro de paciente */

function auth_login(): void {
    $b = body(); $id = str($b, 'identificador', 120); $pw = (string)($b['password'] ?? '');
    if ($id === '' || $pw === '') fail('Ingrese usuario o correo y contraseña', 422);
    $pdo = db();
    $st = $pdo->prepare('SELECT u.id_usuario, u.username, u.password_hash, u.estado, u.bloqueado, u.intentos_fallidos, r.nombre AS rol FROM usuarios u
        JOIN roles r ON r.id_rol = u.id_rol WHERE lower(u.username) = lower(?) OR lower(u.correo) = lower(?) LIMIT 1');
    $st->execute([$id, $id]); $u = $st->fetch();
    if (!$u) {
        bitacora('Sesión', 'LOGIN_FALLIDO', "Usuario o correo inexistente: $id", ['username' => $id], 'FALLIDO');
        fail('Usuario o contraseña incorrectos', 401);
    }
    $actor = ['id_usuario' => (int)$u['id_usuario'], 'username' => $u['username'], 'rol' => $u['rol']];
    $max = max_intentos(); $min = minutos_bloqueo();
    $msgBloqueo = fn(int $restan) => $min > 0
        ? "La cuenta está bloqueada por exceso de intentos fallidos. Intente de nuevo en $restan minuto(s) o recupere su contraseña por correo."
        : 'La cuenta está bloqueada por exceso de intentos fallidos. Recupere su contraseña por correo o comuníquese con el administrador.';

    /* 1) Cuenta ya bloqueada: ni siquiera se verifica la contraseña */
    if ($u['bloqueado']) {
        $restan = 0;
        if ($min > 0) {   // bloqueo temporal: puede haber vencido
            $q = $pdo->prepare('SELECT GREATEST(0, ceil(extract(epoch FROM (bloqueado_en + make_interval(mins => ?::int) - now())) / 60))::int FROM usuarios WHERE id_usuario = ?');
            $q->execute([$min, $u['id_usuario']]); $restan = (int)$q->fetchColumn();
            if ($restan === 0) {
                desbloquear_cuenta((int)$u['id_usuario']);
                bitacora('Sesión', 'CUENTA_DESBLOQUEADA', 'Venció el bloqueo temporal (desbloqueo automático)', $actor);
                $u['bloqueado'] = false; $u['intentos_fallidos'] = 0;
            }
        }
        if ($u['bloqueado']) {
            bitacora('Sesión', 'LOGIN_BLOQUEADO', 'Intento de ingreso con la cuenta bloqueada', $actor, 'FALLIDO');
            fail($msgBloqueo($restan), 423);
        }
    }

    /* 2) Contraseña incorrecta: suma un intento (atómico) y bloquea al llegar al máximo */
    if (!password_verify($pw, $u['password_hash'])) {
        $q = $pdo->prepare('UPDATE usuarios SET intentos_fallidos = intentos_fallidos + 1,
            bloqueado = (intentos_fallidos + 1 >= ?::int),
            bloqueado_en = CASE WHEN intentos_fallidos + 1 >= ?::int THEN now() ELSE bloqueado_en END
            WHERE id_usuario = ? RETURNING intentos_fallidos, bloqueado');
        $q->execute([$max, $max, $u['id_usuario']]); $r = $q->fetch();
        $n = (int)$r['intentos_fallidos'];
        bitacora('Sesión', 'LOGIN_FALLIDO', "Contraseña incorrecta (intento $n de $max)", $actor, 'FALLIDO');
        if ($r['bloqueado']) {
            bitacora('Sesión', 'CUENTA_BLOQUEADA', "Cuenta bloqueada tras $max intentos fallidos consecutivos", $actor, 'FALLIDO');
            fail($msgBloqueo($min), 423);
        }
        $quedan = $max - $n;
        fail("Usuario o contraseña incorrectos. Le quedan $quedan intento(s) antes de bloquear la cuenta.", 401);
    }

    /* 3) Contraseña correcta */
    if (!$u['estado']) {
        bitacora('Sesión', 'LOGIN_FALLIDO', 'Cuenta inactiva', $actor, 'FALLIDO');
        fail('La cuenta está inactiva. Comuníquese con la clínica.', 403);
    }
    if ((int)$u['intentos_fallidos'] > 0) $pdo->prepare('UPDATE usuarios SET intentos_fallidos = 0 WHERE id_usuario = ?')->execute([$u['id_usuario']]);
    bitacora('Sesión', 'LOGIN_OK', 'Inicio de sesión exitoso', $actor);
    json_out(['token' => make_token((int)$u['id_usuario'], $u['rol']), 'user' => load_user((int)$u['id_usuario'])]);
}

function auth_logout(): void {
    $u = require_login();
    bitacora('Sesión', 'LOGOUT', 'Cierre de sesión', $u);
    json_out(['ok' => true]);
}

function auth_me(): void { json_out(['user' => require_login()]); }

/** Valida y limpia los datos de un paciente (usado por autorregistro, recepción y edición). */
function validar_paciente(array $b): array {
    need($b, ['ci' => 'CI', 'nombres' => 'Nombres', 'apellidos' => 'Apellidos', 'fecha_nacimiento' => 'Fecha de nacimiento', 'sexo' => 'Sexo', 'telefono' => 'Teléfono']);
    $d = ['ci' => str($b, 'ci', 20), 'nombres' => str($b, 'nombres', 80), 'apellidos' => str($b, 'apellidos', 80),
          'fecha_nacimiento' => str($b, 'fecha_nacimiento', 10), 'sexo' => strtoupper(str($b, 'sexo', 1)),
          'telefono' => str($b, 'telefono', 20), 'correo' => strtolower(str($b, 'correo', 120)), 'direccion' => str($b, 'direccion', 200)];
    if (!preg_match('/^[0-9A-Za-z\-]{5,20}$/', $d['ci'])) fail('El CI debe tener entre 5 y 20 caracteres (letras, números o guion)', 422);
    if (!valid_date($d['fecha_nacimiento']) || $d['fecha_nacimiento'] > date('Y-m-d') || $d['fecha_nacimiento'] < date('Y-m-d', strtotime('-120 years')))
        fail('La fecha de nacimiento no es válida', 422);
    if (!in_array($d['sexo'], ['M', 'F'], true)) fail('Seleccione el sexo', 422);
    if (!preg_match('/^[0-9+\-\s]{7,20}$/', $d['telefono'])) fail('El teléfono no es válido', 422);
    if ($d['correo'] !== '' && !valid_email($d['correo'])) fail('El correo no es válido', 422);
    return $d;
}

function auth_register(): void {
    $b = body(); $d = validar_paciente($b);
    if ($d['correo'] === '') fail('El correo es obligatorio para crear una cuenta', 422);
    $pw = (string)($b['password'] ?? '');
    if (!valid_password($pw)) fail('La contraseña debe tener al menos 8 caracteres, con letras y números', 422);
    $uid = tx(function (PDO $pdo) use ($d, $pw) {
        $c = $pdo->prepare('SELECT 1 FROM pacientes WHERE ci = ?'); $c->execute([$d['ci']]);
        if ($c->fetch()) fail('Ya existe un paciente registrado con ese CI', 409);
        $c = $pdo->prepare('SELECT 1 FROM usuarios WHERE lower(correo) = ? OR lower(username) = lower(?)'); $c->execute([$d['correo'], $d['ci']]);
        if ($c->fetch()) fail('El correo o el CI ya están asociados a otra cuenta', 409);
        $st = $pdo->prepare("INSERT INTO usuarios(id_rol, nombre_completo, username, correo, password_hash)
            VALUES ((SELECT id_rol FROM roles WHERE nombre = 'Paciente'), ?, ?, ?, ?) RETURNING id_usuario");
        $st->execute([$d['nombres'] . ' ' . $d['apellidos'], $d['ci'], $d['correo'], password_hash($pw, PASSWORD_DEFAULT)]);
        $uid = (int)$st->fetchColumn();
        $pdo->prepare('INSERT INTO pacientes(ci, nombres, apellidos, fecha_nacimiento, sexo, telefono, correo, direccion, id_usuario)
            VALUES (?,?,?,?,?,?,?,?,?)')->execute([$d['ci'], $d['nombres'], $d['apellidos'], $d['fecha_nacimiento'], $d['sexo'],
            $d['telefono'], $d['correo'], $d['direccion'] ?: null, $uid]);
        return $uid;
    });
    bitacora('Sesión', 'REGISTRO_PACIENTE', "Autorregistro de paciente (CI {$d['ci']})", ['id_usuario' => $uid, 'username' => $d['ci'], 'rol' => 'Paciente']);
    json_out(['token' => make_token($uid, 'Paciente'), 'user' => load_user($uid)], 201);
}

function auth_forgot(): void {
    $mail = strtolower(str(body(), 'correo', 120));
    if (!valid_email($mail)) fail('Ingrese un correo válido', 422);
    // Respuesta neutra: no revela si el correo existe
    $out = ['mensaje' => 'Si el correo está registrado, recibirá un enlace para restablecer su contraseña.'];
    $st = db()->prepare('SELECT id_usuario, username, nombre_completo FROM usuarios WHERE lower(correo) = ? AND estado'); $st->execute([$mail]);
    $u = $st->fetch();
    if (!$u) bitacora('Sesión', 'RECUPERACION_SOLICITADA', "Correo no registrado o cuenta inactiva: $mail", ['username' => $mail], 'FALLIDO');
    if ($u) {
        $token = bin2hex(random_bytes(32));
        db()->prepare("INSERT INTO tokens_recuperacion(id_usuario, token_hash, expira_en) VALUES (?, ?, now() + interval '30 minutes')")
            ->execute([$u['id_usuario'], hash('sha256', $token)]);
        $front = rtrim(env('FRONTEND_URL') ?: explode(',', env('ALLOWED_ORIGINS', 'http://localhost:5173'))[0], '/');
        $link = "$front/#/reset?token=$token";
        [$html, $txt] = correo_recuperacion($u['nombre_completo'], $link);
        $enviado = enviar_correo($mail, $u['nombre_completo'], 'Restablecer su contraseña — Clínica Vida Salud', $html, $txt);
        // Sólo en modo demostración y si el correo NO pudo enviarse, se muestra el enlace en pantalla
        bitacora('Sesión', 'RECUPERACION_SOLICITADA', 'Solicitud de enlace de recuperación (correo ' . ($enviado ? 'enviado' : 'NO enviado') . ')',
            ['id_usuario' => (int)$u['id_usuario'], 'username' => $u['username']], $enviado ? 'OK' : 'FALLIDO');
        if (!$enviado && env('APP_DEBUG') === '1') $out['enlace_demo'] = $link;
    }
    json_out($out);
}

function auth_reset(): void {
    $b = body(); $token = str($b, 'token', 100); $pw = (string)($b['password'] ?? '');
    if (!valid_password($pw)) fail('La contraseña debe tener al menos 8 caracteres, con letras y números', 422);
    try {
        $uid = tx(function (PDO $pdo) use ($token, $pw) {
            $st = $pdo->prepare('SELECT id_token, id_usuario FROM tokens_recuperacion WHERE token_hash = ? AND NOT usado AND expira_en > now() FOR UPDATE');
            $st->execute([hash('sha256', $token)]); $t = $st->fetch();
            if (!$t) fail('El enlace no es válido, expiró o ya fue utilizado', 422);
            // Restablecer la contraseña por correo también levanta el bloqueo por intentos fallidos
            $pdo->prepare('UPDATE usuarios SET password_hash = ?, bloqueado = false, intentos_fallidos = 0, bloqueado_en = NULL WHERE id_usuario = ?')
                ->execute([password_hash($pw, PASSWORD_DEFAULT), $t['id_usuario']]);
            $pdo->prepare('UPDATE tokens_recuperacion SET usado = true WHERE id_token = ?')->execute([$t['id_token']]);
            return (int)$t['id_usuario'];
        });
    } catch (HttpError $e) {
        bitacora('Sesión', 'RECUPERACION_FALLIDA', $e->getMessage(), null, 'FALLIDO');
        throw $e;
    }
    $u = load_user($uid);
    bitacora('Sesión', 'RECUPERACION_COMPLETADA', 'Contraseña restablecida con enlace de correo (cuenta desbloqueada)', ['id_usuario' => $uid, 'username' => $u['username'] ?? null, 'rol' => $u['rol'] ?? null]);
    json_out(['mensaje' => 'Contraseña actualizada. Ya puede iniciar sesión.']);
}
