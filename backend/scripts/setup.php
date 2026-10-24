<?php
// Crea el esquema (si no existe) y los datos iniciales. Es idempotente: se puede ejecutar en cada arranque.
require __DIR__ . '/../src/core.php';
load_env(__DIR__ . '/../.env');
$pdo = db();

if (!$pdo->query("SELECT to_regclass('public.roles')")->fetchColumn()) {
    echo "Creando esquema de base de datos...\n";
    $pdo->exec(file_get_contents(__DIR__ . '/../sql/schema.sql'));
}
if ((int)$pdo->query('SELECT count(*) FROM roles')->fetchColumn() > 0) { echo "Base de datos lista.\n"; exit(0); }

echo "Cargando datos iniciales...\n";
$pdo->beginTransaction();
foreach (['Administrador' => 'Configura y supervisa el sistema', 'Recepcionista' => 'Gestiona pacientes y citas',
          'Médico' => 'Consulta su agenda y atiende', 'Paciente' => 'Reserva y consulta sus citas'] as $n => $d)
    $pdo->prepare('INSERT INTO roles(nombre, descripcion) VALUES (?, ?)')->execute([$n, $d]);

function mk_user(PDO $pdo, string $rol, string $nombre, string $user, string $mail, string $pw): int {
    $st = $pdo->prepare('INSERT INTO usuarios(id_rol, nombre_completo, username, correo, password_hash)
        VALUES ((SELECT id_rol FROM roles WHERE nombre = ?), ?, ?, ?, ?) RETURNING id_usuario');
    $st->execute([$rol, $nombre, $user, $mail, password_hash($pw, PASSWORD_DEFAULT)]);
    return (int)$st->fetchColumn();
}
mk_user($pdo, 'Administrador', 'Administrador del sistema', 'admin', 'admin@vidasalud.test', env('ADMIN_PASSWORD', 'Admin123!'));

if (env('SEED_DEMO', '1') !== '0') {
    mk_user($pdo, 'Recepcionista', 'María Recepción', 'recepcion', 'recepcion@vidasalud.test', 'Recep123!');
    $esp = [];
    foreach ([['Medicina General', 'Consulta médica general'], ['Pediatría', 'Atención de niños y adolescentes'],
              ['Ginecología', 'Salud de la mujer'], ['Odontología', 'Salud bucal']] as [$n, $d]) {
        $st = $pdo->prepare('INSERT INTO especialidades(nombre, nombre_normalizado, descripcion) VALUES (?, ?, ?) RETURNING id_especialidad');
        $st->execute([$n, normalizar($n), $d]); $esp[$n] = (int)$st->fetchColumn();
    }
    $docs = [ // nombres, apellidos, registro, usuario, especialidad, [dias], inicio, fin, duración
        ['Carlos', 'Rojas', 'RM-1001', 'crojas', 'Medicina General', [1, 2, 3, 4, 5], '08:00', '12:00', 30],
        ['Lucía', 'Pereira', 'RM-1002', 'lpereira', 'Pediatría', [1, 3, 5], '14:00', '18:00', 20],
        ['Marco', 'Salvatierra', 'RM-1003', 'msalvatierra', 'Ginecología', [2, 4], '09:00', '13:00', 30],
        ['Ana', 'Vaca', 'RM-1004', 'avaca', 'Odontología', [1, 2, 3, 4, 5, 6], '08:00', '12:00', 30],
    ];
    foreach ($docs as [$no, $ap, $reg, $us, $es, $dias, $ini, $fin, $dur]) {
        $uid = mk_user($pdo, 'Médico', "$no $ap", $us, "$us@vidasalud.test", 'Medico123!');
        $st = $pdo->prepare('INSERT INTO medicos(nro_registro, nombres, apellidos, correo, id_usuario) VALUES (?,?,?,?,?) RETURNING id_medico');
        $st->execute([$reg, $no, $ap, "$us@vidasalud.test", $uid]); $mid = (int)$st->fetchColumn();
        $pdo->prepare('INSERT INTO medico_especialidad VALUES (?, ?)')->execute([$mid, $esp[$es]]);
        foreach ($dias as $d) $pdo->prepare('INSERT INTO horarios_atencion(id_medico, dia_semana, hora_inicio, hora_fin, duracion_min) VALUES (?,?,?,?,?)')
            ->execute([$mid, $d, $ini, $fin, $dur]);
    }
    $uid = mk_user($pdo, 'Paciente', 'Juan Pérez', 'paciente', 'paciente@vidasalud.test', 'Paciente123!');
    $pdo->prepare("INSERT INTO pacientes(ci, nombres, apellidos, fecha_nacimiento, sexo, telefono, correo, id_usuario)
        VALUES ('1234567','Juan','Pérez','1990-05-12','M','70000000','paciente@vidasalud.test',?)")->execute([$uid]);
}
$pdo->commit();
echo "Listo.\n";
