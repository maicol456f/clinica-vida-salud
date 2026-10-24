<?php
/* CU-03 Usuarios y roles, CU-04 Especialidades, CU-05 Médicos, CU-06 Horarios de atención */

function roles_list(): void { require_login(); json_out(db()->query('SELECT id_rol, nombre, descripcion FROM roles ORDER BY id_rol')->fetchAll()); }

/* ---------- CU-03 ---------- */
function usuarios_list(): void {
    require_role('Administrador');
    json_out(db()->query('SELECT u.id_usuario, u.nombre_completo, u.username, u.correo, u.estado, u.id_rol, r.nombre AS rol, u.creado_en
        FROM usuarios u JOIN roles r ON r.id_rol = u.id_rol ORDER BY u.id_usuario')->fetchAll());
}
function usuarios_save(int $id = 0): void {
    require_role('Administrador'); $b = body();
    need($b, ['nombre_completo' => 'Nombre completo', 'username' => 'Usuario', 'correo' => 'Correo', 'id_rol' => 'Rol']);
    $nombre = str($b, 'nombre_completo', 120); $user = str($b, 'username', 40); $mail = strtolower(str($b, 'correo', 120));
    $rol = (int)$b['id_rol']; $estado = pgb($b['estado'] ?? true); $pw = (string)($b['password'] ?? '');
    if (!preg_match('/^[A-Za-z0-9._-]{3,40}$/', $user)) fail('El usuario debe tener de 3 a 40 caracteres (letras, números, punto, guion)', 422);
    if (!valid_email($mail)) fail('El correo no es válido', 422);
    if ($id === 0 && !valid_password($pw)) fail('La contraseña inicial debe tener al menos 8 caracteres, con letras y números', 422);
    if ($id > 0 && $pw !== '' && !valid_password($pw)) fail('La contraseña debe tener al menos 8 caracteres, con letras y números', 422);
    $pdo = db();
    $c = $pdo->prepare('SELECT 1 FROM roles WHERE id_rol = ?'); $c->execute([$rol]); if (!$c->fetch()) fail('El rol no existe', 422);
    $c = $pdo->prepare('SELECT 1 FROM usuarios WHERE (lower(username) = lower(?) OR lower(correo) = ?) AND id_usuario <> ?'); $c->execute([$user, $mail, $id]);
    if ($c->fetch()) fail('El nombre de usuario o el correo ya existen', 409);
    if ($id === 0) {
        $pdo->prepare('INSERT INTO usuarios(id_rol, nombre_completo, username, correo, password_hash, estado) VALUES (?,?,?,?,?,?::boolean)')
            ->execute([$rol, $nombre, $user, $mail, password_hash($pw, PASSWORD_DEFAULT), $estado]);
        json_out(['mensaje' => 'Usuario creado'], 201);
    }
    $c = $pdo->prepare("SELECT u.estado, r.nombre AS rol FROM usuarios u JOIN roles r ON r.id_rol = u.id_rol WHERE u.id_usuario = ?"); $c->execute([$id]);
    $cur = $c->fetch(); if (!$cur) fail('El usuario no existe', 404);
    $nuevoRol = $pdo->prepare('SELECT nombre FROM roles WHERE id_rol = ?'); $nuevoRol->execute([$rol]); $nuevoRol = $nuevoRol->fetchColumn();
    if ($cur['rol'] === 'Administrador' && $cur['estado'] && ($nuevoRol !== 'Administrador' || $estado === 'false')) {
        $o = $pdo->prepare("SELECT count(*) FROM usuarios u JOIN roles r ON r.id_rol = u.id_rol WHERE r.nombre = 'Administrador' AND u.estado AND u.id_usuario <> ?");
        $o->execute([$id]); if ((int)$o->fetchColumn() === 0) fail('No se puede desactivar ni cambiar el rol de la única cuenta administradora', 422);
    }
    $pdo->prepare('UPDATE usuarios SET id_rol = ?, nombre_completo = ?, username = ?, correo = ?, estado = ?::boolean WHERE id_usuario = ?')
        ->execute([$rol, $nombre, $user, $mail, $estado, $id]);
    if ($pw !== '') $pdo->prepare('UPDATE usuarios SET password_hash = ? WHERE id_usuario = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $id]);
    json_out(['mensaje' => 'Usuario actualizado']);
}

/* ---------- CU-04 ---------- */
function especialidades_list(): void {
    require_login();
    $w = ($_GET['activas'] ?? '') === '1' ? 'WHERE e.estado' : '';
    json_out(db()->query("SELECT e.id_especialidad, e.nombre, e.descripcion, e.estado,
        (SELECT count(*) FROM medico_especialidad me JOIN medicos m ON m.id_medico = me.id_medico WHERE me.id_especialidad = e.id_especialidad AND m.estado)::int AS medicos_activos
        FROM especialidades e $w ORDER BY e.nombre")->fetchAll());
}
function especialidades_save(int $id = 0): void {
    require_role('Administrador'); $b = body();
    need($b, ['nombre' => 'Nombre']);
    $nombre = str($b, 'nombre', 80); $desc = str($b, 'descripcion', 200); $estado = pgb($b['estado'] ?? true); $norm = normalizar($nombre);
    $pdo = db();
    $c = $pdo->prepare('SELECT 1 FROM especialidades WHERE nombre_normalizado = ? AND id_especialidad <> ?'); $c->execute([$norm, $id]);
    if ($c->fetch()) fail('Ya existe una especialidad con ese nombre', 409);
    if ($id === 0) {
        $pdo->prepare('INSERT INTO especialidades(nombre, nombre_normalizado, descripcion, estado) VALUES (?,?,?,?::boolean)')->execute([$nombre, $norm, $desc ?: null, $estado]);
        json_out(['mensaje' => 'Especialidad creada'], 201);
    }
    if ($estado === 'false' && empty($b['confirmar'])) {
        $c = $pdo->prepare('SELECT count(*) FROM medico_especialidad me JOIN medicos m ON m.id_medico = me.id_medico WHERE me.id_especialidad = ? AND m.estado'); $c->execute([$id]);
        if ((int)$c->fetchColumn() > 0) fail('Esta especialidad tiene médicos activos asociados. ¿Desea desactivarla de todas formas?', 409, ['advertencia' => true]);
    }
    $u = $pdo->prepare('UPDATE especialidades SET nombre = ?, nombre_normalizado = ?, descripcion = ?, estado = ?::boolean WHERE id_especialidad = ?');
    $u->execute([$nombre, $norm, $desc ?: null, $estado, $id]);
    if (!$u->rowCount()) fail('La especialidad no existe', 404);
    json_out(['mensaje' => 'Especialidad actualizada']);
}

/* ---------- CU-05 ---------- */
function medicos_list(): void {
    $u = require_login(); $w = []; $p = [];
    if (($q = str($_GET, 'q', 80)) !== '') {
        $w[] = "(m.nombres ILIKE ? OR m.apellidos ILIKE ? OR (m.nombres || ' ' || m.apellidos) ILIKE ? OR EXISTS (SELECT 1 FROM medico_especialidad x JOIN especialidades ex ON ex.id_especialidad = x.id_especialidad WHERE x.id_medico = m.id_medico AND ex.nombre ILIKE ?))";
        array_push($p, "%$q%", "%$q%", "%$q%", "%$q%");
    }
    if ((int)($_GET['especialidad'] ?? 0) > 0) {
        $w[] = 'EXISTS (SELECT 1 FROM medico_especialidad x WHERE x.id_medico = m.id_medico AND x.id_especialidad = ?::int)'; $p[] = (int)$_GET['especialidad'];
    }
    if (($_GET['activos'] ?? '') === '1' || $u['rol'] === 'Paciente') $w[] = 'm.estado';
    $sql = "SELECT m.id_medico, m.codigo, m.nro_registro, m.nombres, m.apellidos, m.telefono, m.correo, m.estado, m.id_usuario,
        COALESCE(string_agg(e.nombre, ', ' ORDER BY e.nombre), '') AS especialidades,
        COALESCE(json_agg(e.id_especialidad) FILTER (WHERE e.id_especialidad IS NOT NULL), '[]'::json) AS ids_especialidad
        FROM medicos m LEFT JOIN medico_especialidad me ON me.id_medico = m.id_medico LEFT JOIN especialidades e ON e.id_especialidad = me.id_especialidad"
        . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' GROUP BY m.id_medico ORDER BY m.apellidos, m.nombres';
    $st = db()->prepare($sql); $st->execute($p); $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['ids_especialidad'] = json_decode($r['ids_especialidad'], true);
        if ($u['rol'] === 'Paciente') unset($r['telefono'], $r['correo'], $r['nro_registro'], $r['id_usuario']);
    }
    json_out($rows);
}
function medicos_save(int $id = 0): void {
    require_role('Administrador'); $b = body();
    need($b, ['nro_registro' => 'Nro. de registro profesional', 'nombres' => 'Nombres', 'apellidos' => 'Apellidos', 'correo' => 'Correo']);
    $reg = str($b, 'nro_registro', 30); $no = str($b, 'nombres', 80); $ap = str($b, 'apellidos', 80); $tel = str($b, 'telefono', 20);
    $mail = strtolower(str($b, 'correo', 120)); $estado = pgb($b['estado'] ?? true);
    $esps = array_values(array_unique(array_map('intval', is_array($b['especialidades'] ?? null) ? $b['especialidades'] : [])));
    if (!valid_email($mail)) fail('El correo no es válido', 422);
    if ($tel !== '' && !preg_match('/^[0-9+\-\s]{7,20}$/', $tel)) fail('El teléfono no es válido', 422);
    if (!$esps) fail('Seleccione al menos una especialidad', 422);
    $user = str($b, 'username', 40); $pw = (string)($b['password'] ?? '');
    if ($id === 0) {
        if (!preg_match('/^[A-Za-z0-9._-]{3,40}$/', $user)) fail('Defina un usuario de acceso (3 a 40 caracteres)', 422);
        if (!valid_password($pw)) fail('La contraseña inicial debe tener al menos 8 caracteres, con letras y números', 422);
    }
    tx(function (PDO $pdo) use ($id, $reg, $no, $ap, $tel, $mail, $estado, $esps, $user, $pw) {
        $c = $pdo->prepare('SELECT 1 FROM medicos WHERE nro_registro = ? AND id_medico <> ?'); $c->execute([$reg, $id]);
        if ($c->fetch()) fail('El número de registro profesional ya existe', 409);
        $c = $pdo->prepare('SELECT count(*) FROM especialidades WHERE id_especialidad = ANY(?::int[])'); $c->execute(['{' . implode(',', $esps) . '}']);
        if ((int)$c->fetchColumn() !== count($esps)) fail('Alguna especialidad no existe', 422);
        if ($id === 0) {
            $c = $pdo->prepare('SELECT 1 FROM usuarios WHERE lower(username) = lower(?) OR lower(correo) = ?'); $c->execute([$user, $mail]);
            if ($c->fetch()) fail('El usuario o el correo ya existen', 409);
            $s = $pdo->prepare("INSERT INTO usuarios(id_rol, nombre_completo, username, correo, password_hash) VALUES ((SELECT id_rol FROM roles WHERE nombre = 'Médico'), ?, ?, ?, ?) RETURNING id_usuario");
            $s->execute(["$no $ap", $user, $mail, password_hash($pw, PASSWORD_DEFAULT)]); $uid = (int)$s->fetchColumn();
            $s = $pdo->prepare('INSERT INTO medicos(nro_registro, nombres, apellidos, telefono, correo, estado, id_usuario) VALUES (?,?,?,?,?,?::boolean,?) RETURNING id_medico');
            $s->execute([$reg, $no, $ap, $tel ?: null, $mail, $estado, $uid]); $id = (int)$s->fetchColumn();
        } else {
            $s = $pdo->prepare('UPDATE medicos SET nro_registro=?, nombres=?, apellidos=?, telefono=?, correo=?, estado=?::boolean WHERE id_medico=? RETURNING id_usuario');
            $s->execute([$reg, $no, $ap, $tel ?: null, $mail, $estado, $id]); $row = $s->fetch();
            if (!$row) fail('El médico no existe', 404);
            if ($row['id_usuario']) {
                $c = $pdo->prepare('SELECT 1 FROM usuarios WHERE lower(correo) = ? AND id_usuario <> ?'); $c->execute([$mail, $row['id_usuario']]);
                if ($c->fetch()) fail('El correo ya pertenece a otro usuario', 409);
                $pdo->prepare('UPDATE usuarios SET nombre_completo = ?, correo = ? WHERE id_usuario = ?')->execute(["$no $ap", $mail, $row['id_usuario']]);
            }
            $pdo->prepare('DELETE FROM medico_especialidad WHERE id_medico = ?')->execute([$id]);
        }
        foreach ($esps as $e) $pdo->prepare('INSERT INTO medico_especialidad(id_medico, id_especialidad) VALUES (?,?)')->execute([$id, $e]);
    });
    json_out(['mensaje' => 'Médico guardado'], 201);
}

/* ---------- CU-06 ---------- */
function horarios_list(int $medico): void {
    require_role('Administrador', 'Recepcionista');
    $st = db()->prepare('SELECT id_horario, id_medico, dia_semana, hora_inicio, hora_fin, duracion_min, estado,
        floor(extract(epoch FROM (hora_fin - hora_inicio)) / 60 / duracion_min)::int AS cupos
        FROM horarios_atencion WHERE id_medico = ? ORDER BY dia_semana, hora_inicio');
    $st->execute([$medico]); json_out($st->fetchAll());
}
function horario_conflicto(int $medico, int $dia, string $ini, string $fin, int $excluir = 0): bool {
    $st = db()->prepare('SELECT 1 FROM horarios_atencion WHERE id_medico = ? AND dia_semana = ? AND estado AND hora_inicio < ?::time AND hora_fin > ?::time AND id_horario <> ?');
    $st->execute([$medico, $dia, $fin, $ini, $excluir]); return (bool)$st->fetch();
}
function horarios_create(): void {
    require_role('Administrador'); $b = body();
    need($b, ['id_medico' => 'Médico', 'dia_semana' => 'Día', 'hora_inicio' => 'Hora inicial', 'hora_fin' => 'Hora final', 'duracion_min' => 'Duración']);
    $m = (int)$b['id_medico']; $dia = (int)$b['dia_semana']; $ini = str($b, 'hora_inicio', 8); $fin = str($b, 'hora_fin', 8); $dur = (int)$b['duracion_min'];
    if ($dia < 1 || $dia > 7) fail('Día inválido', 422);
    if (!valid_time($ini) || !valid_time($fin)) fail('Las horas no son válidas', 422);
    if ($fin <= $ini) fail('La hora final debe ser posterior a la hora inicial', 422);
    if ($dur < 5 || $dur > 120 || $dur % 5 !== 0) fail('La duración debe ser múltiplo de 5 minutos (entre 5 y 120)', 422);
    $st = db()->prepare('SELECT estado FROM medicos WHERE id_medico = ?'); $st->execute([$m]); $med = $st->fetch();
    if (!$med) fail('El médico no existe', 404);
    if (!$med['estado']) fail('El médico está inactivo', 422);
    if (horario_conflicto($m, $dia, $ini, $fin)) fail('El horario se superpone con otro ya registrado para ese día', 409);
    db()->prepare('INSERT INTO horarios_atencion(id_medico, dia_semana, hora_inicio, hora_fin, duracion_min) VALUES (?,?,?::time,?::time,?)')->execute([$m, $dia, $ini, $fin, $dur]);
    $cupos = intdiv((strtotime($fin) - strtotime($ini)) / 60, $dur);
    json_out(['mensaje' => "Horario guardado: $cupos cupos por jornada"], 201);
}
function horarios_estado(int $id): void {
    require_role('Administrador'); $estado = pgb(body()['estado'] ?? false);
    $st = db()->prepare('SELECT * FROM horarios_atencion WHERE id_horario = ?'); $st->execute([$id]); $h = $st->fetch();
    if (!$h) fail('El horario no existe', 404);
    if ($estado === 'true' && horario_conflicto((int)$h['id_medico'], (int)$h['dia_semana'], $h['hora_inicio'], $h['hora_fin'], $id))
        fail('No se puede reactivar: se superpone con otro horario vigente', 409);
    db()->prepare('UPDATE horarios_atencion SET estado = ?::boolean WHERE id_horario = ?')->execute([$estado, $id]);
    json_out(['mensaje' => 'Horario actualizado']);
}
