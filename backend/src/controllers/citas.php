<?php
/* CU-09 Disponibilidad, CU-10 Programar, CU-11 Reprogramar, CU-12 Cancelar */

const CITA_SQL = "SELECT c.id_cita, c.codigo, c.fecha, c.hora_inicio, c.hora_fin, c.motivo, c.estado, c.motivo_cancelacion, c.creado_en,
    c.id_paciente, p.codigo AS paciente_codigo, p.nombres || ' ' || p.apellidos AS paciente, p.ci AS paciente_ci,
    c.id_medico, m.nombres || ' ' || m.apellidos AS medico, c.id_especialidad, e.nombre AS especialidad
    FROM citas c JOIN pacientes p ON p.id_paciente = c.id_paciente JOIN medicos m ON m.id_medico = c.id_medico
    JOIN especialidades e ON e.id_especialidad = c.id_especialidad";

function cupos(int $medico, string $fecha): array {
    $st = db()->prepare('SELECT hora_inicio, hora_fin FROM fn_cupos_disponibles(?::int, ?::date)');
    $st->execute([$medico, $fecha]); return $st->fetchAll();
}
function cita_row(int $id): ?array { $st = db()->prepare(CITA_SQL . ' WHERE c.id_cita = ?'); $st->execute([$id]); return $st->fetch() ?: null; }
function puede_ver_cita(array $u, array $c): bool {
    return match ($u['rol']) {
        'Paciente' => (int)$c['id_paciente'] === (int)$u['id_paciente'],
        'Médico' => (int)$c['id_medico'] === (int)$u['id_medico'],
        default => true,
    };
}
function fecha_futura(string $f): void {
    if (!valid_date($f)) fail('La fecha no es válida', 422);
    if ($f < date('Y-m-d')) fail('No se pueden registrar citas en fechas pasadas', 422);
}

/* CU-09 */
function disponibilidad(): void {
    require_login();
    $m = (int)($_GET['medico'] ?? 0); $f = str($_GET, 'fecha', 10);
    fecha_futura($f);
    $st = db()->prepare('SELECT estado FROM medicos WHERE id_medico = ?'); $st->execute([$m]); $med = $st->fetch();
    if (!$med || !$med['estado']) fail('El médico no existe o está inactivo', 422);
    $st = db()->prepare('SELECT 1 FROM horarios_atencion WHERE id_medico = ? AND estado AND dia_semana = extract(isodow FROM ?::date)'); $st->execute([$m, $f]);
    if (!$st->fetch()) json_out(['cupos' => [], 'mensaje' => 'El médico no atiende ese día. Elija otra fecha.']);
    $c = cupos($m, $f);
    json_out(['cupos' => $c, 'mensaje' => $c ? null : 'No hay cupos disponibles para esa fecha. Pruebe con otra.']);
}

function citas_list(): void {
    $u = require_login(); $w = []; $p = [];
    if ($u['rol'] === 'Paciente') { $w[] = 'c.id_paciente = ?'; $p[] = (int)$u['id_paciente']; }
    if ($u['rol'] === 'Médico') { $w[] = 'c.id_medico = ?'; $p[] = (int)$u['id_medico']; }
    if (($f = str($_GET, 'fecha', 10)) !== '' && valid_date($f)) { $w[] = 'c.fecha = ?::date'; $p[] = $f; }
    if (in_array($e = str($_GET, 'estado', 15), ['Programada', 'Confirmada', 'Atendida', 'Cancelada', 'Inasistencia'], true)) { $w[] = 'c.estado = ?'; $p[] = $e; }
    if ((int)($_GET['medico'] ?? 0) > 0) { $w[] = 'c.id_medico = ?::int'; $p[] = (int)$_GET['medico']; }
    if (($q = str($_GET, 'q', 80)) !== '') { $w[] = "(p.ci ILIKE ? OR p.nombres || ' ' || p.apellidos ILIKE ? OR c.codigo ILIKE ?)"; array_push($p, "%$q%", "%$q%", "%$q%"); }
    $prox = ($_GET['proximas'] ?? '') === '1';
    if ($prox) $w[] = "c.estado IN ('Programada','Confirmada') AND (c.fecha + c.hora_inicio) >= now()";
    $st = db()->prepare(CITA_SQL . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ($prox ? ' ORDER BY c.fecha, c.hora_inicio' : ' ORDER BY c.fecha DESC, c.hora_inicio DESC') . ' LIMIT 300');
    $st->execute($p); json_out($st->fetchAll());
}

/* CU-10 */
function citas_create(): void {
    $u = require_role('Administrador', 'Recepcionista', 'Paciente'); $b = body();
    need($b, ['id_medico' => 'Médico', 'id_especialidad' => 'Especialidad', 'fecha' => 'Fecha', 'hora_inicio' => 'Hora']);
    $pac = $u['rol'] === 'Paciente' ? (int)$u['id_paciente'] : (int)($b['id_paciente'] ?? 0);
    if ($pac <= 0) fail('Seleccione un paciente', 422);
    $m = (int)$b['id_medico']; $esp = (int)$b['id_especialidad']; $f = str($b, 'fecha', 10); $h = substr(str($b, 'hora_inicio', 8), 0, 5);
    fecha_futura($f);
    if (!valid_time($h)) fail('La hora no es válida', 422);
    $motivo = str($b, 'motivo', 200);
    $codigo = tx(function (PDO $pdo) use ($pac, $m, $esp, $f, $h, $motivo, $u) {
        $ok = false; foreach (cupos($m, $f) as $s) if (substr($s['hora_inicio'], 0, 5) === $h) { $ok = true; break; }
        if (!$ok) fail('El horario seleccionado ya no está disponible. Elija otro.', 409);
        $st = $pdo->prepare('SELECT fn_programar_cita(?::int, ?::int, ?::int, ?::date, ?::time, ?::varchar, ?::int)');
        $st->execute([$pac, $m, $esp, $f, $h, $motivo ?: null, $u['id_usuario']]);
        return $st->fetchColumn();
    });
    $st = db()->prepare(CITA_SQL . ' WHERE c.codigo = ?'); $st->execute([$codigo]);
    $cita = $st->fetch();
    bitacora('Citas', 'CITA_CREADA', "Cita $codigo programada para $f $h con {$cita['medico']} (paciente {$cita['paciente_codigo']})", $u);
    json_out($cita, 201);
}

function cita_autorizada(int $id): array {
    $u = require_login(); $c = cita_row($id);
    if (!$c) fail('La cita no existe', 404);
    if (!puede_ver_cita($u, $c)) fail('No tiene permiso para esta operación', 403);
    return $c;
}
function citas_historial(int $id): void {
    cita_autorizada($id);
    $st = db()->prepare('SELECT h.estado_anterior, h.estado_nuevo, h.fecha_hora, h.observacion, u.nombre_completo AS usuario
        FROM cita_historial h LEFT JOIN usuarios u ON u.id_usuario = h.id_usuario WHERE h.id_cita = ? ORDER BY h.id_historial');
    $st->execute([$id]); json_out($st->fetchAll());
}

/* CU-11 */
function citas_reprogramar(int $id): void {
    $u = require_role('Administrador', 'Recepcionista', 'Paciente'); $b = body();
    need($b, ['fecha' => 'Nueva fecha', 'hora_inicio' => 'Nueva hora']);
    $f = str($b, 'fecha', 10); $h = substr(str($b, 'hora_inicio', 8), 0, 5); $motivo = str($b, 'motivo', 100);
    fecha_futura($f); if (!valid_time($h)) fail('La hora no es válida', 422);
    $res = tx(function (PDO $pdo) use ($id, $f, $h, $motivo, $u) {
        $st = $pdo->prepare('SELECT * FROM citas WHERE id_cita = ? FOR UPDATE'); $st->execute([$id]); $c = $st->fetch();
        if (!$c) fail('La cita no existe', 404);
        if (!puede_ver_cita($u, $c)) fail('No tiene permiso para esta operación', 403);
        if (!in_array($c['estado'], ['Programada', 'Confirmada'], true)) fail("Una cita {$c['estado']} no puede reprogramarse", 422);
        $slot = null; foreach (cupos((int)$c['id_medico'], $f) as $s) if (substr($s['hora_inicio'], 0, 5) === $h) { $slot = $s; break; }
        if (!$slot) fail('El nuevo horario no está disponible. Elija otro.', 409);
        $pdo->prepare('UPDATE citas SET fecha = ?::date, hora_inicio = ?::time, hora_fin = ?::time WHERE id_cita = ?')->execute([$f, $slot['hora_inicio'], $slot['hora_fin'], $id]);
        $obs = mb_substr("Reprogramada de {$c['fecha']} " . substr($c['hora_inicio'], 0, 5) . " a $f $h" . ($motivo ? ": $motivo" : ''), 0, 200);
        $pdo->prepare('INSERT INTO cita_historial(id_cita, estado_anterior, estado_nuevo, id_usuario, observacion) VALUES (?,?,?,?,?)')
            ->execute([$id, $c['estado'], $c['estado'], $u['id_usuario'], $obs]);
        return cita_row($id);
    });
    bitacora('Citas', 'CITA_REPROGRAMADA', "Cita {$res['codigo']} reprogramada a $f $h" . ($motivo ? ": $motivo" : ''), $u);
    json_out($res);
}

/* CU-12 */
function citas_cancelar(int $id): void {
    $u = require_role('Administrador', 'Recepcionista', 'Paciente');
    $motivo = str(body(), 'motivo', 200);
    if ($motivo === '') fail('Indique el motivo de la cancelación', 422);
    $res = tx(function (PDO $pdo) use ($id, $motivo, $u) {
        $st = $pdo->prepare('SELECT * FROM citas WHERE id_cita = ? FOR UPDATE'); $st->execute([$id]); $c = $st->fetch();
        if (!$c) fail('La cita no existe', 404);
        if (!puede_ver_cita($u, $c)) fail('No tiene permiso para esta operación', 403);
        if ($c['estado'] === 'Cancelada') fail('La cita ya estaba cancelada', 422);
        if (!in_array($c['estado'], ['Programada', 'Confirmada'], true)) fail("Una cita {$c['estado']} no puede cancelarse", 422);
        $pdo->prepare("UPDATE citas SET estado = 'Cancelada', motivo_cancelacion = ? WHERE id_cita = ?")->execute([$motivo, $id]);
        $pdo->prepare('UPDATE cita_historial SET id_usuario = ? WHERE id_historial = (SELECT max(id_historial) FROM cita_historial WHERE id_cita = ?)')->execute([$u['id_usuario'], $id]);
        return cita_row($id);
    });
    bitacora('Citas', 'CITA_CANCELADA', "Cita {$res['codigo']} cancelada. Motivo: $motivo", $u);
    json_out($res);
}
