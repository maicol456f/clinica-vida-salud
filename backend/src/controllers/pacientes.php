<?php
/* CU-07 Registrar paciente (recepción), CU-08 Gestionar pacientes */

function pacientes_list(): void {
    require_role('Administrador', 'Recepcionista'); $w = []; $p = [];
    if (($q = str($_GET, 'q', 80)) !== '') {
        $w[] = "(ci ILIKE ? OR nombres ILIKE ? OR apellidos ILIKE ? OR codigo ILIKE ? OR (nombres || ' ' || apellidos) ILIKE ?)";
        array_push($p, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%");
    }
    if (($_GET['activos'] ?? '') === '1') $w[] = 'estado';
    $st = db()->prepare('SELECT id_paciente, codigo, ci, nombres, apellidos, fecha_nacimiento, sexo, telefono, correo, direccion, estado, creado_en FROM pacientes'
        . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY apellidos, nombres LIMIT 100');
    $st->execute($p); json_out($st->fetchAll());
}
function pacientes_save(int $id = 0): void {
    require_role('Administrador', 'Recepcionista'); $d = validar_paciente(body()); $pdo = db();
    $c = $pdo->prepare('SELECT 1 FROM pacientes WHERE ci = ? AND id_paciente <> ?'); $c->execute([$d['ci'], $id]);
    if ($c->fetch()) fail('Ya existe un paciente con ese CI', 409);
    $v = [$d['ci'], $d['nombres'], $d['apellidos'], $d['fecha_nacimiento'], $d['sexo'], $d['telefono'], $d['correo'] ?: null, $d['direccion'] ?: null];
    if ($id === 0) {
        $st = $pdo->prepare('INSERT INTO pacientes(ci, nombres, apellidos, fecha_nacimiento, sexo, telefono, correo, direccion) VALUES (?,?,?,?,?,?,?,?) RETURNING codigo');
        $st->execute($v); json_out(['mensaje' => 'Paciente registrado', 'codigo' => $st->fetchColumn()], 201);
    }
    $v[] = $id;
    $st = $pdo->prepare('UPDATE pacientes SET ci=?, nombres=?, apellidos=?, fecha_nacimiento=?::date, sexo=?, telefono=?, correo=?, direccion=? WHERE id_paciente=?');
    $st->execute($v);
    if (!$st->rowCount()) fail('El paciente no existe', 404);
    json_out(['mensaje' => 'Paciente actualizado']);
}
/** RN11: los pacientes no se eliminan, sólo se activan o desactivan. */
function pacientes_estado(int $id): void {
    require_role('Administrador', 'Recepcionista');
    $st = db()->prepare('UPDATE pacientes SET estado = ?::boolean WHERE id_paciente = ?'); $st->execute([pgb(body()['estado'] ?? false), $id]);
    if (!$st->rowCount()) fail('El paciente no existe', 404);
    json_out(['mensaje' => 'Estado actualizado']);
}
