<?php
/* Bitácora general: consulta y exportación (sólo Administrador). Los registros son inmutables (trigger en la BD). */

function bitacora_filtros(): array {
    $w = []; $p = [];
    if (($q = str($_GET, 'q', 80)) !== '') {
        $w[] = '(b.usuario ILIKE ? OR b.accion ILIKE ? OR b.detalle ILIKE ? OR b.ip ILIKE ?)';
        array_push($p, "%$q%", "%$q%", "%$q%", "%$q%");
    }
    if (($m = str($_GET, 'modulo', 30)) !== '') { $w[] = 'b.modulo = ?'; $p[] = $m; }
    if (in_array($r = str($_GET, 'resultado', 10), ['OK', 'FALLIDO'], true)) { $w[] = 'b.resultado = ?'; $p[] = $r; }
    if (($d = str($_GET, 'desde', 10)) !== '' && valid_date($d)) { $w[] = 'b.fecha_hora >= ?::date'; $p[] = $d; }
    if (($h = str($_GET, 'hasta', 10)) !== '' && valid_date($h)) { $w[] = 'b.fecha_hora < (?::date + 1)'; $p[] = $h; }
    return [$w ? ' WHERE ' . implode(' AND ', $w) : '', $p];
}

function bitacora_list(): void {
    require_role('Administrador');
    [$where, $p] = bitacora_filtros();
    $limite = min(100, max(10, (int)($_GET['limite'] ?? 25))); $pagina = max(1, (int)($_GET['pagina'] ?? 1));
    $st = db()->prepare('SELECT count(*) FROM bitacora b' . $where); $st->execute($p); $total = (int)$st->fetchColumn();
    $paginas = max(1, (int)ceil($total / $limite)); $pagina = min($pagina, $paginas);
    $st = db()->prepare("SELECT b.id_bitacora, b.fecha_hora, b.usuario, b.rol, b.modulo, b.accion, b.detalle, b.resultado, b.ip
        FROM bitacora b $where ORDER BY b.id_bitacora DESC LIMIT $limite OFFSET " . (($pagina - 1) * $limite));
    $st->execute($p);
    json_out(['total' => $total, 'pagina' => $pagina, 'paginas' => $paginas, 'items' => $st->fetchAll()]);
}

/** CSV (separador «;» para que Excel en español lo abra en columnas). Máximo 5000 filas con los filtros actuales. */
function bitacora_exportar(): void {
    $adm = require_role('Administrador');
    [$where, $p] = bitacora_filtros();
    $st = db()->prepare("SELECT b.fecha_hora, b.usuario, b.rol, b.modulo, b.accion, b.detalle, b.resultado, b.ip
        FROM bitacora b $where ORDER BY b.id_bitacora DESC LIMIT 5000");
    $st->execute($p); $rows = $st->fetchAll();
    bitacora('Bitácora', 'BITACORA_EXPORTADA', 'Exportó ' . count($rows) . ' registros a CSV', $adm);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bitacora.csv"');
    $safe = fn($v) => (is_string($v) && $v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v;   // evita inyección de fórmulas en Excel
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Fecha y hora', 'Usuario', 'Rol', 'Módulo', 'Acción', 'Detalle', 'Resultado', 'IP'], ';', '"', '');
    foreach ($rows as $r) fputcsv($out, array_map($safe, array_values($r)), ';', '"', '');
    fclose($out);
}
