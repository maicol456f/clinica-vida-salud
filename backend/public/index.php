<?php
declare(strict_types=1);
require __DIR__ . '/../src/core.php';
load_env(__DIR__ . '/../.env');
foreach (['auth', 'admin', 'pacientes', 'citas', 'bitacora'] as $c) require __DIR__ . "/../src/controllers/$c.php";

cors();

$routes = [];
function route(string $method, string $pattern, callable|string $handler): void {
    global $routes;
    $routes[] = [$method, '#^' . preg_replace('#\{(\w+)\}#', '(?<$1>\d+)', $pattern) . '$#', $handler];
}
route('GET', '/', fn() => json_out(['servicio' => 'API Clínica Vida Salud', 'ok' => true]));
route('GET', '/api/health', fn() => json_out(['ok' => true]));
route('POST', '/api/auth/login', 'auth_login');
route('POST', '/api/auth/register', 'auth_register');
route('POST', '/api/auth/forgot', 'auth_forgot');
route('POST', '/api/auth/reset', 'auth_reset');
route('POST', '/api/auth/logout', 'auth_logout');
route('GET', '/api/me', 'auth_me');
route('GET', '/api/roles', 'roles_list');
route('GET', '/api/usuarios', 'usuarios_list');
route('POST', '/api/usuarios', fn() => usuarios_save());
route('PUT', '/api/usuarios/{id}', 'usuarios_save');
route('POST', '/api/usuarios/{id}/desbloquear', 'usuarios_desbloquear');
route('GET', '/api/bitacora', 'bitacora_list');
route('GET', '/api/bitacora/exportar', 'bitacora_exportar');
route('GET', '/api/especialidades', 'especialidades_list');
route('POST', '/api/especialidades', fn() => especialidades_save());
route('PUT', '/api/especialidades/{id}', 'especialidades_save');
route('GET', '/api/medicos', 'medicos_list');
route('POST', '/api/medicos', fn() => medicos_save());
route('PUT', '/api/medicos/{id}', 'medicos_save');
route('GET', '/api/medicos/{id}/horarios', 'horarios_list');
route('POST', '/api/horarios', 'horarios_create');
route('PATCH', '/api/horarios/{id}', 'horarios_estado');
route('GET', '/api/pacientes', 'pacientes_list');
route('POST', '/api/pacientes', fn() => pacientes_save());
route('PUT', '/api/pacientes/{id}', 'pacientes_save');
route('PATCH', '/api/pacientes/{id}/estado', 'pacientes_estado');
route('GET', '/api/disponibilidad', 'disponibilidad');
route('GET', '/api/citas', 'citas_list');
route('POST', '/api/citas', 'citas_create');
route('GET', '/api/citas/{id}/historial', 'citas_historial');
route('POST', '/api/citas/{id}/reprogramar', 'citas_reprogramar');
route('POST', '/api/citas/{id}/cancelar', 'citas_cancelar');

try {
    $path = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/') ?: '/';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $pathMatched = false;
    foreach ($routes as [$m, $rx, $h]) {
        if (!preg_match($rx, $path, $mm)) continue;
        $pathMatched = true;
        if ($m !== $method) continue;
        $args = array_map('intval', array_filter($mm, 'is_string', ARRAY_FILTER_USE_KEY));
        $h(...array_values($args));
        exit;
    }
    fail($pathMatched ? 'Método no permitido' : 'Ruta no encontrada', $pathMatched ? 405 : 404);
} catch (HttpError $e) {
    json_out(['error' => $e->getMessage()] + $e->extra, $e->getCode());
} catch (PDOException $e) {
    $s = (string)$e->getCode(); $msg = $e->getMessage();
    if ($s === '23P01') json_out(['error' => 'Ese horario ya fue ocupado por otra cita del médico. Elija otro.'], 409);
    if ($s === '23505') json_out(['error' => 'Ya existe un registro con esos datos.'], 409);
    if ($s === '23503') json_out(['error' => 'Referencia inválida o registro relacionado.'], 409);
    if ($s === 'P0001') json_out(['error' => preg_match('/ERROR:\s+(.+)/u', $msg, $mm) ? trim($mm[1]) : 'Operación no permitida.'], 422);
    error_log($msg); json_out(['error' => 'Error interno del servidor.'], 500);
} catch (Throwable $e) {
    error_log((string)$e); json_out(['error' => 'Error interno del servidor.'], 500);
}
