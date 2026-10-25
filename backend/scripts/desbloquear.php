<?php
// Desbloquea una cuenta por consola (por si queda bloqueado el único administrador).
// Uso:  php scripts/desbloquear.php admin
require __DIR__ . '/../src/core.php';
load_env(__DIR__ . '/../.env');
$user = $argv[1] ?? '';
if ($user === '') { fwrite(STDERR, "Uso: php scripts/desbloquear.php <usuario>\n"); exit(1); }
$st = db()->prepare('SELECT id_usuario FROM usuarios WHERE lower(username) = lower(?)');
$st->execute([$user]); $id = $st->fetchColumn();
if ($id === false) { fwrite(STDERR, "No existe el usuario «{$user}».\n"); exit(1); }
$nombre = desbloquear_cuenta((int)$id);
bitacora('Usuarios', 'USUARIO_DESBLOQUEADO', "Desbloqueó la cuenta «{$nombre}» por consola", ['username' => 'consola']);
echo "Cuenta «{$nombre}» desbloqueada.\n";
