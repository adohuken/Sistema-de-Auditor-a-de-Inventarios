<?php
/**
 * Módulo de Informes Avanzados y Análisis por Evento de Auditoría
 * Sistema de Auditoría de Inventarios
 */

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/auth.php';

// Exigir permiso al módulo de informes
exigirPermisoModulo('informes');

$eventoActual = obtenerEventoActivo();
$eventoId = $eventoActual ? $eventoActual['id'] : 1;
$tipoInforme = $_GET['tipo'] ?? 'general';
$exportarCSV = isset($_GET['export']) && $_GET['export'] === 'csv';

try {
    $pdo = getPDOConnection();

    // =========================================================================
    // EXPORTACIÓN A EXCEL / CSV SEGÚN EL INFORME Y EVENTO SELECCIONADO
    // =========================================================================
    if ($exportarCSV) {
        $nombreLimpio = preg_replace('/[^a-zA-Z0-9_]/', '_', $eventoActual['nombre_evento'] ?? 'auditoria');
        $filename = "informe_" . $tipoInforme . "_" . $nombreLimpio . ".csv";
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

        if ($tipoInforme === 'faltantes') {
            fputcsv($output, ['Bodega', 'SKU', 'Producto', 'Stock Teórico', 'Stock Físico', 'Diferencia Faltante']);
            $stmt = $pdo->prepare("
                SELECT COALESCE(p.bodega, 'Bodega Principal') AS bodega, p.codigo_producto, p.producto, p.existencias_sistema AS stock_teorico,
                       COALESCE(SUM(c.cantidad_fisica), 0) AS stock_fisico,
                       (COALESCE(SUM(c.cantidad_fisica), 0) - p.existencias_sistema) AS diferencia
                FROM productos_sistema p
                LEFT JOIN conteos_fisicos c ON (p.codigo_producto = c.codigo_producto AND p.bodega = c.bodega AND p.evento_id = c.evento_id)
                WHERE p.evento_id = :ev
                GROUP BY p.id, p.bodega, p.codigo_producto, p.producto, p.existencias_sistema
                HAVING COUNT(c.id) > 0 AND diferencia < 0
                ORDER BY diferencia ASC
            ");
            $stmt->execute([':ev' => $eventoId]);
            foreach ($stmt->fetchAll() as $r) {
                fputcsv($output, [$r['bodega'], $r['codigo_producto'], $r['producto'], $r['stock_teorico'], $r['stock_fisico'], $r['diferencia']]);
            }
        } elseif ($tipoInforme === 'sobrantes') {
            fputcsv($output, ['Bodega', 'SKU', 'Producto', 'Stock Teórico', 'Stock Físico', 'Diferencia Sobrante']);
            $stmt = $pdo->prepare("
                SELECT COALESCE(p.bodega, 'Bodega Principal') AS bodega, p.codigo_producto, p.producto, p.existencias_sistema AS stock_teorico,
                       COALESCE(SUM(c.cantidad_fisica), 0) AS stock_fisico,
                       (COALESCE(SUM(c.cantidad_fisica), 0) - p.existencias_sistema) AS diferencia
                FROM productos_sistema p
                LEFT JOIN conteos_fisicos c ON (p.codigo_producto = c.codigo_producto AND p.bodega = c.bodega AND p.evento_id = c.evento_id)
                WHERE p.evento_id = :ev
                GROUP BY p.id, p.bodega, p.codigo_producto, p.producto, p.existencias_sistema
                HAVING COUNT(c.id) > 0 AND diferencia > 0
                ORDER BY diferencia DESC
            ");
            $stmt->execute([':ev' => $eventoId]);
            foreach ($stmt->fetchAll() as $r) {
                fputcsv($output, [$r['bodega'], $r['codigo_producto'], $r['producto'], $r['stock_teorico'], $r['stock_fisico'], $r['diferencia']]);
            }
        } elseif ($tipoInforme === 'categorias') {
            fputcsv($output, ['Bodega', 'Total SKUs', 'Stock Teórico Total', 'Stock Físico Total', 'Diferencia Global']);
            $stmt = $pdo->prepare("
                SELECT 
                    COALESCE(p.bodega, 'Bodega Principal') AS bodega,
                    COUNT(DISTINCT p.id) AS total_skus,
                    SUM(p.existencias_sistema) AS stock_teorico_total,
                    SUM(COALESCE(c.cantidad_fisica, 0)) AS stock_fisico_total,
                    (SUM(COALESCE(c.cantidad_fisica, 0)) - SUM(p.existencias_sistema)) AS diferencia_global
                FROM productos_sistema p
                LEFT JOIN (
                    SELECT codigo_producto, bodega, SUM(cantidad_fisica) AS cantidad_fisica 
                    FROM conteos_fisicos WHERE evento_id = :ev GROUP BY codigo_producto, bodega
                ) c ON (p.codigo_producto = c.codigo_producto AND p.bodega = c.bodega)
                WHERE p.evento_id = :ev
                GROUP BY p.bodega
                ORDER BY bodega ASC
            ");
            $stmt->execute([':ev' => $eventoId]);
            foreach ($stmt->fetchAll() as $r) {
                fputcsv($output, [$r['bodega'], $r['total_skus'], $r['stock_teorico_total'], $r['stock_fisico_total'], $r['diferencia_global']]);
            }
        } elseif ($tipoInforme === 'usuarios') {
            fputcsv($output, ['Usuario / Auditor', 'Rol en Evento', 'Bodega Asignada', 'Lecturas Registradas', 'Unidades Totales Contadas', 'Primer Conteo', 'Último Conteo']);
            $sqlUsuariosCsv = "
                SELECT 
                    u.nombre AS usuario,
                    a.rol_evento,
                    a.bodega_asignada,
                    COUNT(c.id) AS lecturas,
                    COALESCE(SUM(c.cantidad_fisica), 0) AS unidades_contadas,
                    MIN(c.fecha_conteo) AS primer_conteo,
                    MAX(c.fecha_conteo) AS ultimo_conteo
                FROM asignaciones_auditoria a
                INNER JOIN usuarios u ON a.usuario_id = u.id
                LEFT JOIN conteos_fisicos c ON (c.evento_id = a.evento_id AND (c.usuario_id = u.id OR c.usuario = u.nombre))
                WHERE a.evento_id = :ev
                GROUP BY u.id, u.nombre, a.rol_evento, a.bodega_asignada
                
                UNION ALL
                
                SELECT 
                    c.usuario AS usuario,
                    'sin_asignar' AS rol_evento,
                    'General' AS bodega_asignada,
                    COUNT(c.id) AS lecturas,
                    COALESCE(SUM(c.cantidad_fisica), 0) AS unidades_contadas,
                    MIN(c.fecha_conteo) AS primer_conteo,
                    MAX(c.fecha_conteo) AS ultimo_conteo
                FROM conteos_fisicos c
                WHERE c.evento_id = :ev 
                  AND (c.usuario_id IS NULL OR c.usuario_id NOT IN (SELECT usuario_id FROM asignaciones_auditoria WHERE evento_id = :ev))
                  AND c.usuario NOT IN (SELECT u2.nombre FROM asignaciones_auditoria a2 JOIN usuarios u2 ON a2.usuario_id = u2.id WHERE a2.evento_id = :ev)
                GROUP BY c.usuario
                ORDER BY unidades_contadas DESC, lecturas DESC
            ";
            $stmt = $pdo->prepare($sqlUsuariosCsv);
            $stmt->execute([':ev' => $eventoId]);
            foreach ($stmt->fetchAll() as $r) {
                $rolLabel = 'Sin Asignación';
                if ($r['rol_evento'] === 'contador') $rolLabel = 'Contador Físico';
                elseif ($r['rol_evento'] === 'supervisor') $rolLabel = 'Supervisor de Zona';
                elseif ($r['rol_evento'] === 'auditor_lider') $rolLabel = 'Auditor Líder';

                fputcsv($output, [
                    $r['usuario'],
                    $rolLabel,
                    $r['bodega_asignada'] ?: 'Todas / General',
                    $r['lecturas'],
                    $r['unidades_contadas'],
                    $r['primer_conteo'] ? date('d/m/Y H:i', strtotime($r['primer_conteo'])) : 'Sin lecturas',
                    $r['ultimo_conteo'] ? date('d/m/Y H:i', strtotime($r['ultimo_conteo'])) : 'Sin lecturas'
                ]);
            }
        } else {
            fputcsv($output, ['Bodega', 'SKU', 'Producto', 'Stock Teórico', 'Stock Físico', 'Diferencia', 'Estado']);
            $stmt = $pdo->prepare("
                SELECT COALESCE(p.bodega, 'Bodega Principal') AS bodega, p.codigo_producto, p.producto, p.existencias_sistema AS stock_teorico,
                       COALESCE(SUM(c.cantidad_fisica), 0) AS stock_fisico,
                       (COALESCE(SUM(c.cantidad_fisica), 0) - p.existencias_sistema) AS diferencia,
                       CASE 
                           WHEN COUNT(c.id) = 0 THEN 'Pendiente'
                           WHEN (COALESCE(SUM(c.cantidad_fisica), 0) - p.existencias_sistema) = 0 THEN 'Coincide'
                           WHEN (COALESCE(SUM(c.cantidad_fisica), 0) - p.existencias_sistema) < 0 THEN 'Faltante'
                           ELSE 'Sobrante'
                       END AS estado
                FROM productos_sistema p
                LEFT JOIN conteos_fisicos c ON (p.codigo_producto = c.codigo_producto AND p.bodega = c.bodega AND p.evento_id = c.evento_id)
                WHERE p.evento_id = :ev
                GROUP BY p.id, p.bodega, p.codigo_producto, p.producto, p.existencias_sistema
                ORDER BY p.producto ASC
            ");
            $stmt->execute([':ev' => $eventoId]);
            foreach ($stmt->fetchAll() as $r) {
                fputcsv($output, [$r['bodega'], $r['codigo_producto'], $r['producto'], $r['stock_teorico'], $r['stock_fisico'], $r['diferencia'], $r['estado']]);
            }
        }
        fclose($output);
        exit;
    }

    // =========================================================================
    // CONSULTAS PARA CADA VISTA DE INFORME
    // =========================================================================
    $datosInforme = [];

    if ($tipoInforme === 'faltantes') {
        $stmt = $pdo->prepare("
            SELECT COALESCE(p.bodega, 'Bodega Principal') AS bodega, p.codigo_producto, p.producto, p.existencias_sistema AS stock_teorico,
                   COALESCE(SUM(c.cantidad_fisica), 0) AS stock_fisico,
                   (COALESCE(SUM(c.cantidad_fisica), 0) - p.existencias_sistema) AS diferencia
            FROM productos_sistema p
            LEFT JOIN conteos_fisicos c ON (p.codigo_producto = c.codigo_producto AND p.bodega = c.bodega AND p.evento_id = c.evento_id)
            WHERE p.evento_id = :ev
            GROUP BY p.id, p.bodega, p.codigo_producto, p.producto, p.existencias_sistema
            HAVING COUNT(c.id) > 0 AND diferencia < 0
            ORDER BY diferencia ASC
        ");
        $stmt->execute([':ev' => $eventoId]);
        $datosInforme = $stmt->fetchAll();
    } elseif ($tipoInforme === 'sobrantes') {
        $stmt = $pdo->prepare("
            SELECT COALESCE(p.bodega, 'Bodega Principal') AS bodega, p.codigo_producto, p.producto, p.existencias_sistema AS stock_teorico,
                   COALESCE(SUM(c.cantidad_fisica), 0) AS stock_fisico,
                   (COALESCE(SUM(c.cantidad_fisica), 0) - p.existencias_sistema) AS diferencia
            FROM productos_sistema p
            LEFT JOIN conteos_fisicos c ON (p.codigo_producto = c.codigo_producto AND p.bodega = c.bodega AND p.evento_id = c.evento_id)
            WHERE p.evento_id = :ev
            GROUP BY p.id, p.bodega, p.codigo_producto, p.producto, p.existencias_sistema
            HAVING COUNT(c.id) > 0 AND diferencia > 0
            ORDER BY diferencia DESC
        ");
        $stmt->execute([':ev' => $eventoId]);
        $datosInforme = $stmt->fetchAll();
    } elseif ($tipoInforme === 'categorias') {
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(p.bodega, 'Bodega Principal') AS bodega,
                COUNT(DISTINCT p.id) AS total_skus,
                SUM(p.existencias_sistema) AS stock_teorico_total,
                SUM(COALESCE(c.cantidad_fisica, 0)) AS stock_fisico_total,
                (SUM(COALESCE(c.cantidad_fisica, 0)) - SUM(p.existencias_sistema)) AS diferencia_global
            FROM productos_sistema p
            LEFT JOIN (
                SELECT codigo_producto, SUM(cantidad_fisica) AS cantidad_fisica 
                FROM conteos_fisicos WHERE evento_id = :ev GROUP BY codigo_producto
            ) c ON p.codigo_producto = c.codigo_producto
            WHERE p.evento_id = :ev
            GROUP BY p.bodega
            ORDER BY bodega ASC
        ");
        $stmt->execute([':ev' => $eventoId]);
        $datosInforme = $stmt->fetchAll();
    } elseif ($tipoInforme === 'usuarios') {
        $sqlUsuarios = "
            SELECT 
                u.nombre AS usuario,
                a.rol_evento,
                a.bodega_asignada,
                COUNT(c.id) AS lecturas,
                COALESCE(SUM(c.cantidad_fisica), 0) AS unidades_contadas,
                MIN(c.fecha_conteo) AS primer_conteo,
                MAX(c.fecha_conteo) AS ultimo_conteo
            FROM asignaciones_auditoria a
            INNER JOIN usuarios u ON a.usuario_id = u.id
            LEFT JOIN conteos_fisicos c ON (c.evento_id = a.evento_id AND (c.usuario_id = u.id OR c.usuario = u.nombre))
            WHERE a.evento_id = :ev
            GROUP BY u.id, u.nombre, a.rol_evento, a.bodega_asignada
            
            UNION ALL
            
            SELECT 
                c.usuario AS usuario,
                'sin_asignar' AS rol_evento,
                'General' AS bodega_asignada,
                COUNT(c.id) AS lecturas,
                COALESCE(SUM(c.cantidad_fisica), 0) AS unidades_contadas,
                MIN(c.fecha_conteo) AS primer_conteo,
                MAX(c.fecha_conteo) AS ultimo_conteo
            FROM conteos_fisicos c
            WHERE c.evento_id = :ev 
              AND (c.usuario_id IS NULL OR c.usuario_id NOT IN (SELECT usuario_id FROM asignaciones_auditoria WHERE evento_id = :ev))
              AND c.usuario NOT IN (SELECT u2.nombre FROM asignaciones_auditoria a2 JOIN usuarios u2 ON a2.usuario_id = u2.id WHERE a2.evento_id = :ev)
            GROUP BY c.usuario
            ORDER BY unidades_contadas DESC, lecturas DESC
        ";
        $stmt = $pdo->prepare($sqlUsuarios);
        $stmt->execute([':ev' => $eventoId]);
        $datosInforme = $stmt->fetchAll();
    } else {
        // General
        $stmt = $pdo->prepare("
            SELECT COALESCE(p.bodega, 'Bodega Principal') AS bodega, p.codigo_producto, p.producto, p.existencias_sistema AS stock_teorico,
                   COALESCE(SUM(c.cantidad_fisica), 0) AS stock_fisico,
                   (COALESCE(SUM(c.cantidad_fisica), 0) - p.existencias_sistema) AS diferencia,
                   CASE 
                       WHEN COUNT(c.id) = 0 THEN 'Pendiente'
                       WHEN (COALESCE(SUM(c.cantidad_fisica), 0) - p.existencias_sistema) = 0 THEN 'Coincide'
                       WHEN (COALESCE(SUM(c.cantidad_fisica), 0) - p.existencias_sistema) < 0 THEN 'Faltante'
                       ELSE 'Sobrante'
                   END AS estado
            FROM productos_sistema p
            LEFT JOIN conteos_fisicos c ON (p.codigo_producto = c.codigo_producto AND p.bodega = c.bodega AND p.evento_id = c.evento_id)
            WHERE p.evento_id = :ev
            GROUP BY p.id, p.bodega, p.codigo_producto, p.producto, p.existencias_sistema
            ORDER BY p.producto ASC
        ");
        $stmt->execute([':ev' => $eventoId]);
        $datosInforme = $stmt->fetchAll();
    }

} catch (Exception $e) {
    $error = "Error al obtener datos del informe: " . $e->getMessage();
}

include __DIR__ . '/includes/header.php';
?>

<!-- Encabezado Visible en Impresión -->
<div class="print-header">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h2 class="fw-bold m-0">ACTA OFICIAL DE AUDITORÍA DE INVENTARIO</h2>
            <div class="fs-6"><b>Auditoría:</b> <?= htmlspecialchars($eventoActual['nombre_evento'] ?? '') ?> (<?= htmlspecialchars($eventoActual['bodega_sucursal'] ?? '') ?>)</div>
        </div>
        <div class="text-end small">
            <div><b>Fecha de Emisión:</b> <?= date('d/m/Y H:i') ?></div>
            <div><b>Emitido por:</b> <?= htmlspecialchars($usuarioActual['nombre']) ?> (<?= strtoupper($usuarioActual['perfil']) ?>)</div>
            <div><b>Estado:</b> <?= strtoupper($eventoActual['estado'] ?? 'ACTIVA') ?></div>
        </div>
    </div>
</div>

<div class="row mb-4 align-items-center no-print">
    <div class="col-md-7">
        <h3 class="fw-bold text-dark mb-1"><i class="bi bi-file-earmark-bar-graph-fill text-primary me-2"></i>Centro de Informes & Analítica</h3>
        <p class="text-muted mb-0">Informe de la auditoría: <b><?= htmlspecialchars($eventoActual['nombre_evento'] ?? 'Ninguna') ?></b></p>
    </div>
    <div class="col-md-5 text-md-end mt-3 mt-md-0">
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm rounded-pill me-2 fw-semibold">
            <i class="bi bi-printer-fill me-1"></i> Imprimir Acta / PDF
        </button>
        <a href="informes.php?tipo=<?= $tipoInforme ?>&export=csv" class="btn btn-outline-success btn-sm rounded-pill fw-semibold">
            <i class="bi bi-file-earmark-spreadsheet me-1"></i> Exportar a Excel (CSV)
        </a>
    </div>
</div>

<!-- Selector de Pestañas / Tipo de Informe Segmentado -->
<ul class="nav-segmented no-print mb-4">
    <li class="nav-item">
        <a class="nav-link <?= $tipoInforme === 'general' ? 'active' : '' ?>" href="informes.php?tipo=general">
            <i class="bi bi-grid-3x3-gap-fill"></i> <span>Resumen General</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tipoInforme === 'faltantes' ? 'active text-danger' : '' ?>" href="informes.php?tipo=faltantes">
            <i class="bi bi-arrow-down-circle-fill"></i> <span>Informe de Mermas</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tipoInforme === 'sobrantes' ? 'active text-warning-emphasis' : '' ?>" href="informes.php?tipo=sobrantes">
            <i class="bi bi-arrow-up-circle-fill"></i> <span>Informe de Excedentes</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tipoInforme === 'categorias' ? 'active' : '' ?>" href="informes.php?tipo=categorias">
            <i class="bi bi-building"></i> <span>Resumen por Bodega</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tipoInforme === 'usuarios' ? 'active' : '' ?>" href="informes.php?tipo=usuarios">
            <i class="bi bi-person-check-fill"></i> <span>Productividad Auditores</span>
        </a>
    </li>
</ul>

<!-- VISTAS DE INFORMES -->

<?php if ($tipoInforme === 'faltantes'): ?>
    <!-- 1. INFORME DE FALTANTES Y MERMAS -->
    <div class="card card-custom">
        <div class="card-header-custom d-flex justify-content-between align-items-center bg-danger-subtle">
            <span class="text-danger fw-bold"><i class="bi bi-exclamation-triangle-fill me-2"></i>Informe de Productos Faltantes (Mermas) - <?= htmlspecialchars($eventoActual['nombre_evento'] ?? '') ?></span>
            <span class="badge bg-danger text-white"><?= count($datosInforme) ?> artículos con faltante</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-custom align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Bodega</th>
                            <th>SKU / Código</th>
                            <th>Producto</th>
                            <th class="text-end">Stock Teórico</th>
                            <th class="text-end">Stock Físico</th>
                            <th class="text-end">Unidades Faltantes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $totalUnidadesFaltantes = 0;
                        ?>
                        <?php if (empty($datosInforme)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-4 text-success fw-semibold">
                                    <i class="bi bi-check-circle-fill fs-3 d-block mb-2"></i>
                                    ¡Excelente! No se registran artículos con faltantes o mermas en esta auditoría.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($datosInforme as $r): ?>
                                <?php 
                                $totalUnidadesFaltantes += abs($r['diferencia']);
                                ?>
                                <tr>
                                    <td><span class="bodega-badge"><i class="bi bi-building"></i><?= htmlspecialchars($r['bodega']) ?></span></td>
                                    <td><span class="sku-badge"><?= htmlspecialchars($r['codigo_producto']) ?></span></td>
                                    <td class="fw-semibold text-dark"><?= htmlspecialchars($r['producto']) ?></td>
                                    <td class="text-end text-secondary"><?= number_format($r['stock_teorico'], 2) ?></td>
                                    <td class="text-end fw-bold"><?= number_format($r['stock_fisico'], 2) ?></td>
                                    <td class="text-end fw-bold text-danger"><?= number_format($r['diferencia'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="table-danger fw-bold">
                                <td colspan="5" class="text-end">TOTAL DE UNIDADES FALTANTES:</td>
                                <td class="text-end text-danger fs-6"><?= number_format(-$totalUnidadesFaltantes, 2) ?> uds</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php elseif ($tipoInforme === 'sobrantes'): ?>
    <!-- 2. INFORME DE SOBRANTES Y EXCEDENTES -->
    <div class="card card-custom">
        <div class="card-header-custom d-flex justify-content-between align-items-center bg-warning-subtle">
            <span class="text-warning-emphasis fw-bold"><i class="bi bi-plus-circle-fill me-2"></i>Informe de Productos Sobrantes (Excedentes) - <?= htmlspecialchars($eventoActual['nombre_evento'] ?? '') ?></span>
            <span class="badge bg-warning text-dark"><?= count($datosInforme) ?> artículos con sobrante</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-custom align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Bodega</th>
                            <th>SKU / Código</th>
                            <th>Producto</th>
                            <th class="text-end">Stock Teórico</th>
                            <th class="text-end">Stock Físico</th>
                            <th class="text-end">Unidades Sobrantes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $totalUnidadesSobrantes = 0;
                        ?>
                        <?php if (empty($datosInforme)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">
                                    <i class="bi bi-info-circle fs-3 d-block mb-2"></i>
                                    No se registran productos con stock sobrante en esta auditoría.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($datosInforme as $r): ?>
                                <?php 
                                $totalUnidadesSobrantes += $r['diferencia'];
                                ?>
                                <tr>
                                    <td><span class="bodega-badge"><i class="bi bi-building"></i><?= htmlspecialchars($r['bodega']) ?></span></td>
                                    <td><span class="sku-badge"><?= htmlspecialchars($r['codigo_producto']) ?></span></td>
                                    <td class="fw-semibold text-dark"><?= htmlspecialchars($r['producto']) ?></td>
                                    <td class="text-end text-secondary"><?= number_format($r['stock_teorico'], 2) ?></td>
                                    <td class="text-end fw-bold"><?= number_format($r['stock_fisico'], 2) ?></td>
                                    <td class="text-end fw-bold text-success">+<?= number_format($r['diferencia'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="table-warning fw-bold">
                                <td colspan="5" class="text-end">TOTAL DE UNIDADES SOBRANTES:</td>
                                <td class="text-end text-success fs-6">+<?= number_format($totalUnidadesSobrantes, 2) ?> uds</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php elseif ($tipoInforme === 'categorias'): ?>
    <!-- 3. INFORME RESUMEN POR BODEGA -->
    <div class="card card-custom">
        <div class="card-header-custom d-flex justify-content-between align-items-center">
            <span><i class="bi bi-building me-2 text-primary"></i>Resumen de Auditoría Agrupado por Bodega - <?= htmlspecialchars($eventoActual['nombre_evento'] ?? '') ?></span>
            <span class="badge bg-light text-muted border"><?= count($datosInforme) ?> bodegas</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-custom align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Bodega / Sucursal</th>
                            <th class="text-center">Total SKUs</th>
                            <th class="text-end">Teórico Total</th>
                            <th class="text-end">Físico Total</th>
                            <th class="text-end">Diferencia Global</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($datosInforme as $r): ?>
                            <?php $difCat = $r['diferencia_global']; ?>
                            <tr>
                                <td class="fw-bold text-dark"><i class="bi bi-building me-2 text-primary"></i><?= htmlspecialchars($r['bodega']) ?></td>
                                <td class="text-center"><span class="badge bg-secondary-subtle text-secondary px-2"><?= $r['total_skus'] ?></span></td>
                                <td class="text-end text-secondary"><?= number_format($r['stock_teorico_total'], 2) ?></td>
                                <td class="text-end fw-bold text-dark"><?= number_format($r['stock_fisico_total'], 2) ?></td>
                                <td class="text-end fw-bold <?= $difCat < 0 ? 'text-danger' : ($difCat > 0 ? 'text-success' : 'text-muted') ?>">
                                    <?= ($difCat > 0 ? '+' : '') . number_format($difCat, 2) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php elseif ($tipoInforme === 'usuarios'): ?>
    <!-- 4. AUDITORÍA DE PRODUCTIVIDAD Y ROL DE USUARIOS -->
    <div class="card card-custom">
        <div class="card-header-custom d-flex justify-content-between align-items-center">
            <span><i class="bi bi-people-fill me-2 text-primary"></i>Desempeño y Asignación de Personal - <?= htmlspecialchars($eventoActual['nombre_evento'] ?? '') ?></span>
            <span class="badge bg-light text-muted border"><?= count($datosInforme) ?> personas en el equipo</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-custom align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Auditor / Usuario</th>
                            <th>Rol en Auditoría</th>
                            <th>Bodega Asignada</th>
                            <th class="text-center">Nº de Escaneos</th>
                            <th class="text-end">Total Unidades Contadas</th>
                            <th>Primer Registro</th>
                            <th>Última Actividad</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($datosInforme)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted">
                                    No hay personal asignado ni lecturas registradas en esta auditoría aún.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($datosInforme as $r): ?>
                                <tr>
                                    <td class="fw-bold text-dark"><i class="bi bi-person-badge me-2 text-primary"></i><?= htmlspecialchars($r['usuario']) ?></td>
                                    <td>
                                        <?php if ($r['rol_evento'] === 'contador'): ?>
                                            <span class="badge bg-primary-subtle text-primary border border-primary border-opacity-25 rounded-pill"><i class="bi bi-barcode me-1"></i>Contador Físico</span>
                                        <?php elseif ($r['rol_evento'] === 'supervisor'): ?>
                                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning border-opacity-25 rounded-pill"><i class="bi bi-shield-check me-1"></i>Supervisor Zona</span>
                                        <?php elseif ($r['rol_evento'] === 'auditor_lider'): ?>
                                            <span class="badge bg-danger-subtle text-danger-emphasis border border-danger border-opacity-25 rounded-pill"><i class="bi bi-award me-1"></i>Auditor Líder</span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-muted border rounded-pill">Sin Rol Específico</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small text-secondary fw-semibold">
                                        <i class="bi bi-building me-1 text-muted"></i><?= htmlspecialchars($r['bodega_asignada'] ?: 'Todas') ?>
                                    </td>
                                    <td class="text-center"><span class="badge bg-primary-subtle text-primary fw-bold px-3 py-1"><?= number_format($r['lecturas']) ?></span></td>
                                    <td class="text-end fw-bold fs-6 text-dark"><?= number_format($r['unidades_contadas'], 2) ?> uds</td>
                                    <td class="small text-muted"><?= $r['primer_conteo'] ? date('d/m/Y H:i', strtotime($r['primer_conteo'])) : '<span class="fst-italic opacity-50">Sin registros</span>' ?></td>
                                    <td class="small text-muted"><?= $r['ultimo_conteo'] ? date('d/m/Y H:i', strtotime($r['ultimo_conteo'])) : '<span class="fst-italic opacity-50">Sin registros</span>' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php else: ?>
    <!-- 5. INFORME GENERAL DE CONCILIACIÓN -->
    <div class="card card-custom">
        <div class="card-header-custom d-flex justify-content-between align-items-center">
            <span><i class="bi bi-table me-2 text-primary"></i>Resumen General - <?= htmlspecialchars($eventoActual['nombre_evento'] ?? '') ?></span>
            <span class="badge bg-light text-muted border"><?= count($datosInforme) ?> artículos</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-custom align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Bodega</th>
                            <th>SKU / Código</th>
                            <th>Descripción del Producto</th>
                            <th class="text-end">Stock Teórico ERP</th>
                            <th class="text-end">Stock Físico Real</th>
                            <th class="text-end">Diferencia</th>
                            <th class="text-center">Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($datosInforme as $r): ?>
                            <?php
                            $dif = $r['diferencia'];
                            $esPendiente = ($r['estado'] === 'Pendiente');
                            $badgeClass = 'badge-coincide';
                            if ($r['estado'] === 'Pendiente') $badgeClass = 'badge-pendiente';
                            if ($r['estado'] === 'Faltante') $badgeClass = 'badge-faltante';
                            if ($r['estado'] === 'Sobrante') $badgeClass = 'badge-sobrante';
                            ?>
                            <tr>
                                <td><span class="bodega-badge"><i class="bi bi-building"></i><?= htmlspecialchars($r['bodega']) ?></span></td>
                                <td><span class="sku-badge"><?= htmlspecialchars($r['codigo_producto']) ?></span></td>
                                <td class="fw-semibold text-dark"><?= htmlspecialchars($r['producto']) ?></td>
                                <td class="text-end text-secondary"><?= number_format($r['stock_teorico'], 2) ?></td>
                                <?php if ($esPendiente): ?>
                                    <td class="text-end text-muted">—</td>
                                    <td class="text-end text-muted">—</td>
                                <?php else: ?>
                                    <td class="text-end fw-bold"><?= number_format($r['stock_fisico'], 2) ?></td>
                                    <td class="text-end fw-bold <?= $dif < 0 ? 'text-danger' : ($dif > 0 ? 'text-success' : 'text-muted') ?>">
                                        <?= ($dif > 0 ? '+' : '') . number_format($dif, 2) ?>
                                    </td>
                                <?php endif; ?>
                                <td class="text-center"><span class="badge-state <?= $badgeClass ?>"><?= $r['estado'] ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Pie de firmas para impresión oficial -->
<div class="print-signatures">
    <div class="text-center" style="width: 250px;">
        <div style="border-top: 1px solid #000; padding-top: 5px;" class="fw-bold small">Firma Auditor / Contador</div>
        <div class="small text-muted">Nombre y Registro</div>
    </div>
    <div class="text-center" style="width: 250px;">
        <div style="border-top: 1px solid #000; padding-top: 5px;" class="fw-bold small">Firma Jefe de Almacén / Tienda</div>
        <div class="small text-muted">Aprobación de Inventario</div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
