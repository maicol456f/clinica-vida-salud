<?php
declare(strict_types=1);
date_default_timezone_set('America/La_Paz');
mb_internal_encoding('UTF-8');

/* ---------- Errores HTTP ---------- */
class HttpError extends Exception {
    public array $extra;
    public function __construct(string $m, int $code = 400, array $extra = []) { parent::__construct($m, $code); $this->extra = $extra; }
}
function fail(string $msg, int $code = 400, array $extra = []): never { throw new HttpError($msg, $code, $extra); }

/* ---------- Entorno ---------- */
function env(string $k, ?string $d = null): ?string { $v = getenv($k); return ($v === false || $v === '') ? $d : $v; }
function load_env(string $file): void {
    if (!is_file($file)) return;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        $l = trim($l);
        if ($l === '' || $l[0] === '#' || !str_contains($l, '=')) continue;
        [$k, $v] = explode('=', $l, 2);
        $k = trim($k);
        if (getenv($k) === false) putenv($k . '=' . trim($v, " \t\"'"));
    }
}

/* ---------- Base de datos (PDO + consultas parametrizadas) ---------- */
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $url = env('DATABASE_URL');
    if ($url) {
        $p = parse_url($url);
        $host = $p['host']; $port = (int)($p['port'] ?? 5432);
        $name = ltrim($p['path'] ?? '', '/'); $user = urldecode($p['user'] ?? ''); $pass = urldecode($p['pass'] ?? '');
    } else {
        $host = env('DB_HOST', 'localhost'); $port = (int)env('DB_PORT', '5432');
        $name = env('DB_NAME', 'vida_salud'); $user = env('DB_USER', 'postgres'); $pass = env('DB_PASSWORD', 'postgres');
    }
    $ssl = env('DB_SSLMODE', 'prefer');
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$name;sslmode=$ssl", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET TIME ZONE 'America/La_Paz'");
    return $pdo;
}
function tx(callable $fn) {
    $pdo = db(); $pdo->beginTransaction();
    try { $r = $fn($pdo); $pdo->commit(); return $r; }
    catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function pgb($v): string { return filter_var($v, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false'; }

/* ---------- Respuesta / entrada ---------- */
function json_out($data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}
function body(): array {
    static $b = null;
    if ($b === null) { $raw = file_get_contents('php://input'); $j = $raw ? json_decode($raw, true) : null; $b = is_array($j) ? $j : []; }
    return $b;
}
function str(array $a, string $k, int $max = 200): string {
    $v = $a[$k] ?? ''; if (!is_scalar($v)) return '';
    return mb_substr(trim((string)$v), 0, $max);
}
function need(array $a, array $labels): void {
    foreach ($labels as $k => $label) if (!isset($a[$k]) || (is_scalar($a[$k]) && trim((string)$a[$k]) === ''))
        fail("El campo «{$label}» es obligatorio", 422);
}

/* ---------- Validaciones (servidor) ---------- */
function valid_email(string $e): bool { return (bool)filter_var($e, FILTER_VALIDATE_EMAIL); }
function valid_password(string $p): bool { return strlen($p) >= 8 && preg_match('/[A-Za-z]/', $p) && preg_match('/\d/', $p); }
function valid_date(string $d): bool { $x = DateTime::createFromFormat('Y-m-d', $d); return $x && $x->format('Y-m-d') === $d; }
function valid_time(string $t): bool { return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $t); }
function normalizar(string $s): string {
    $s = mb_strtolower(trim($s));
    return preg_replace('/\s+/', ' ', strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u']));
}

/* ---------- Tokens firmados (HS256) ---------- */
function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64d(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/')); }
function secret(): string {
    $s = env('APP_SECRET');
    if (!$s) { if (env('APP_ENV') === 'production') throw new RuntimeException('APP_SECRET no configurado'); $s = 'clave-solo-para-desarrollo-local'; }
    return $s;
}
function make_token(int $uid, string $rol): string {
    $h = b64u('{"alg":"HS256","typ":"JWT"}');
    $p = b64u(json_encode(['uid' => $uid, 'rol' => $rol, 'exp' => time() + 8 * 3600]));
    return "$h.$p." . b64u(hash_hmac('sha256', "$h.$p", secret(), true));
}
function verify_token(string $t): ?array {
    $parts = explode('.', $t); if (count($parts) !== 3) return null;
    $calc = b64u(hash_hmac('sha256', "$parts[0].$parts[1]", secret(), true));
    if (!hash_equals($calc, $parts[2])) return null;
    $p = json_decode(b64d($parts[1]), true);
    return (is_array($p) && ($p['exp'] ?? 0) > time()) ? $p : null;
}
function bearer(): string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('getallheaders')) foreach (getallheaders() as $k => $v) if (strcasecmp($k, 'Authorization') === 0) $h = $v;
    return preg_match('/^Bearer\s+(.+)$/i', $h, $m) ? trim($m[1]) : '';
}
function load_user(int $id): ?array {
    $st = db()->prepare("SELECT u.id_usuario, u.nombre_completo, u.username, u.correo, u.estado, r.nombre AS rol,
        (SELECT id_paciente FROM pacientes WHERE id_usuario = u.id_usuario) AS id_paciente,
        (SELECT id_medico FROM medicos WHERE id_usuario = u.id_usuario) AS id_medico
        FROM usuarios u JOIN roles r ON r.id_rol = u.id_rol WHERE u.id_usuario = ?");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}
function require_login(): array {
    static $u = null; if ($u) return $u;
    $t = bearer(); if ($t === '') fail('Sesión requerida', 401);
    $p = verify_token($t); if (!$p) fail('Sesión inválida o expirada', 401);
    $user = load_user((int)$p['uid']);
    if (!$user || !$user['estado']) fail('Sesión inválida o cuenta inactiva', 401);
    return $u = $user;
}
function require_role(string ...$roles): array {
    $u = require_login();
    if (!in_array($u['rol'], $roles, true)) fail('No tiene permiso para esta operación', 403);
    return $u;
}

/* ---------- Bitácora general ---------- */
/** Registra un evento. $actor = ['id_usuario' =>, 'username' =>, 'rol' =>] (todo opcional). Nunca interrumpe la operación principal. */
function bitacora(string $modulo, string $accion, string $detalle = '', ?array $actor = null, string $resultado = 'OK'): void {
    try {
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';   // en Render la IP real llega en este encabezado
        $ip = $xff !== '' ? trim(explode(',', $xff)[0]) : ($_SERVER['REMOTE_ADDR'] ?? '');
        $nombre = $actor['username'] ?? null;
        db()->prepare('INSERT INTO bitacora(id_usuario, usuario, rol, modulo, accion, detalle, resultado, ip) VALUES (?,?,?,?,?,?,?,?)')->execute([
            $actor['id_usuario'] ?? null, $nombre !== null ? mb_substr((string)$nombre, 0, 120) : null, $actor['rol'] ?? null,
            $modulo, $accion, $detalle !== '' ? mb_substr($detalle, 0, 500) : null, $resultado, $ip !== '' ? mb_substr($ip, 0, 64) : null,
        ]);
    } catch (Throwable $e) { error_log('Bitácora: ' . $e->getMessage()); }
}

/* ---------- Bloqueo por intentos fallidos ---------- */
function max_intentos(): int { return max(1, (int)env('MAX_INTENTOS', '3')); }
/** 0 = el bloqueo dura hasta que un administrador (o la recuperación de contraseña) lo levante. */
function minutos_bloqueo(): int { return max(0, (int)env('LOCKOUT_MINUTES', '0')); }
/** Quita el bloqueo y reinicia el contador. Devuelve el username, o null si el usuario no existe. */
function desbloquear_cuenta(int $id): ?string {
    $st = db()->prepare('UPDATE usuarios SET bloqueado = false, intentos_fallidos = 0, bloqueado_en = NULL WHERE id_usuario = ? RETURNING username');
    $st->execute([$id]); $n = $st->fetchColumn();
    return $n === false ? null : (string)$n;
}

/* ---------- CORS ---------- */
function cors(): void {
    header('X-Content-Type-Options: nosniff');
    $origin = rtrim($_SERVER['HTTP_ORIGIN'] ?? '', '/');
    $allowed = array_map(fn($o) => rtrim(trim($o), '/'), explode(',', env('ALLOWED_ORIGINS', 'http://localhost:5173,http://127.0.0.1:5173')));
    if ($origin !== '' && in_array($origin, $allowed, true)) {
        header("Access-Control-Allow-Origin: $origin");
        header('Vary: Origin');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
}
