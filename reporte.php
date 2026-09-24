<?php
/**
 * Módulo 3: Reporte de Conciliación y Diferencias por Evento de Auditoría
 * Sistema de Auditoría de Inventarios
 */

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/exporter.php';

// Exigir permiso al módulo de reporte/conciliación
exigirPermisoModulo('reporte');

$eventoActual = obtenerEventoActivo();
$eventoId = $eventoActual ? $eventoActual['id'] : 1;

// Exportación a Excel / CSV formateado según solicitud
if (isset($_GET['export']) && in_array($_GET['export'], ['excel', 'csv'])) {
    try {
        $pdo = getPDOConnection();
        $sqlExport = "
            SELECT 
                p.codigo_producto,
                p.producto,
                p.categoria,
                p.marca,
                p.existencias_sistema AS stock_teorico,
                COALESCE(SUM(c.cantidad_fisica), 0) AS stock_fisico,
                (COALESCE(SUM(c.cantidad_fisica), 0) - p.existencias_sistema) AS diferencia,
                CASE 
                    WHEN COUNT(c.id) = 0 THEN 'Pendiente'
                    WHEN (COALESCE(SUM(c.cantidad_fisica), 0) - p.existencias_sistema) = 0 THEN 'Coincide'
                    WHEN (COALESCE(SUM(c.cantidad_fisica), 0) - p.existencias_sistema) < 0 THEN 'Faltante'
                    ELSE 'Sobrante'
                END AS estado,
                p.costo_promedio,
                ((COALESCE(SUM(c.cantidad_fisica), 0) - p.existencias_sistema) * p.costo_promedio) AS valor_diferencia
            FROM productos_sistema p
            LEFT JOIN conteos_fisicos c ON (p.codigo_producto = c.codigo_producto AND p.bodega = c.bodega AND p.evento_id = c.evento_id)
            WHERE p.evento_id = :evento_id
            GROUP BY p.id, p.codigo_producto, p.producto, p.categoria, p.marca, p.existencias_sistema, p.costo_promedio
            ORDER BY p.producto ASC";
        
        $stmtExp = $pdo->prepare($sqlExport);
        $stmtExp->execute([':evento_id' => $eventoId]);
        $rowsExp = $stmtExp->fetchAll();

        $nombreLimpio = preg_replace('/[^a-zA-Z0-9_]/', '_', $eventoActual['nombre_evento'] ?? 'auditoria');

        $columnas = [
            'Código SKU',
            'Producto',
            'Categoría',
            'Marca',
            'Stock Teórico',
            'Stock Físico',
            'Diferencia',
            'Estado',
            'Costo Unit. ($)',
            'Impacto Económico ($)'
        ];

        $filas = [];
        $totTeorico = 0;
        $totFisico = 0;
        $totDiferencia = 0;
        $totImpacto = 0;

        foreach ($rowsExp as $r) {
            $totTeorico += $r['stock_teorico'];
            $totFisico += $r['stock_fisico'];
            $totDiferencia += $r['diferencia'];
            $totImpacto += $r['valor_diferencia'];

            $filas[] = [
                ['val' => $r['codigo_producto'], 'type' => 'sku'],
                ['val' => $r['producto'], 'type' => 'text'],
                ['val' => $r['categoria'], 'type' => 'text'],
                ['val' => $r['marca'], 'type' => 'text'],
                ['val' => number_format($r['stock_teorico'], 2), 'type' => 'num'],
                ['val' => number_format($r['stock_fisico'], 2), 'type' => 'num'],
                ['val' => ($r['diferencia'] > 0 ? '+' : '') . number_format($r['diferencia'], 2), 'type' => 'num'],
                ['val' => $r['estado'], 'type' => 'status'],
                ['val' => '$' . number_format($r['costo_promedio'], 2), 'type' => 'currency'],
                ['val' => ($r['valor_diferencia'] < 0 ? '-$' : '$') . number_format(abs($r['valor_diferencia']), 2), 'type' => 'currency']
            ];
        }

        $totales = [
            ['val' => 'TOTALES', 'type' => 'text'],
            ['val' => count($rowsExp) . ' SKUs', 'type' => 'center'],
            ['val' => '', 'type' => 'text'],
            ['val' => '', 'type' => 'text'],
            ['val' => number_format($totTeorico, 2), 'type' => 'num'],
            ['val' => number_format($totFisico, 2), 'type' => 'num'],
            ['val' => ($totDiferencia > 0 ? '+' : '') . number_format($totDiferencia, 2), 'type' => 'num'],
            ['val' => '', 'type' => 'text'],
            ['val' => '', 'type' => 'text'],
            ['val' => ($totImpacto < 0 ? '-$' : '$') . number_format(abs($totImpacto), 2), 'type' => 'currency']
        ];

        $titulo = "REPORTE DE CONCILIACIÓN DE INVENTARIO";
        $subtitulo = "Auditoría: " . ($eventoActual['nombre_evento'] ?? 'General') . " | Sucursal/Bodega: " . ($eventoActual['bodega_sucursal'] ?? 'Todas');

        if ($_GET['export'] === 'excel') {
            exportarExcelFormateado("conciliacion_{$nombreLimpio}.xls", $titulo, $subtitulo, $columnas, $filas, $totales);
        } else {
            exportarCSVEstandar("conciliacion_{$nombreLimpio}.csv", $columnas, $filas, $totales);
        }
    } catch (Exception $e) {
        die("Error al exportar reporte: " . $e->getMessage());
    }
}

// Filtros de búsqueda
$filtroEstado    = $_GET['estado']    ?? 'todos';
$filtroCategoria = $_GET['categoria'] ?? 'todas';
$filtroBodega    = $_GET['bodega']    ?? 'todas';
$busqueda        = trim($_GET['busqueda'] ?? '');

$filas    = [];
$categorias = [];
$bodegas    = [];

$metricas = [
    'total_skus'        => 0,
    'coinciden_count'   => 0,
    'faltantes_count'   => 0,
    'sobrantes_count'   => 0,
    'sin_conteo_count'  => 0,
    'total_teorico'     => 0,
    'total_fisico'      => 0,
    'valor_faltantes'   => 0,
    'valor_sobrantes'   => 0,
];

try {
    $pdo = getPDOConnection();

    // Categorías del evento
    $stmtCat = $pdo->prepare("SELECT DISTINCT categoria FROM productos_sistema WHERE evento_id = :ev AND categoria IS NOT NULL ORDER BY categoria ASC");
    $stmtCat->execute([':ev' => $eventoId]);
    $categorias = $stmtCat->fetchAll(PDO::FETCH_COLUMN);

    // Bodegas del evento
    $stmtBod = $pdo->prepare("SELECT DISTINCT COALESCE(bodega, 'Bodega Principal') AS bodega FROM productos_sistema WHERE evento_id = :ev ORDER BY bodega ASC");
    $stmtBod->execute([':ev' => $eventoId]);
    $bodegas = $stmtBod->fetchAll(PDO::FETCH_COLUMN);

    // Consulta SQL Base de Conciliación por Evento
    $sql = "
        SELECT 
            p.id,
            COALESCE(p.bodega, 'Bodega Principal') AS bodega,
            p.codigo_producto,
            p.producto,
            p.categoria,
            p.tipo,
            p.marca,
            p.existencias_sistema AS stock_teorico,
            p.costo_promedio,
            p.precio_producto,
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
        WHERE p.evento_id = :evento_id
        GROUP BY p.id, p.bodega, p.codigo_producto, p.producto, p.categoria, p.tipo, p.marca, p.existencias_sistema, p.costo_promedio, p.precio_producto
    ";

    $stmtAll = $pdo->prepare($sql);
    $stmtAll->execute([':evento_id' => $eventoId]);
    $todasLasFilas = $stmtAll->fetchAll();

    // Calcular Métricas Globales
    foreach ($todasLasFilas as $r) {
        $metricas['total_skus']++;
        $metricas['total_teorico'] += $r['stock_teorico'];
        $metricas['total_fisico']  += $r['stock_fisico'];

        $difVal = $r['diferencia'] * $r['costo_promedio'];

        if ($r['estado'] === 'Pendiente') {
            $metricas['sin_conteo_count']++;
        } elseif ($r['estado'] === 'Coincide') {
            $metricas['coinciden_count']++;
        } elseif ($r['estado'] === 'Faltante') {
            $metricas['faltantes_count']++;
            $metricas['valor_faltantes'] += abs($difVal);
        } elseif ($r['estado'] === 'Sobrante') {
            $metricas['sobrantes_count']++;
            $metricas['valor_sobrantes'] += abs($difVal);
        }
    }

    // Filtrar filas para la vista
    foreach ($todasLasFilas as $r) {
        if ($filtroEstado !== 'todos' && strtolower($r['estado']) !== strtolower($filtroEstado)) {
            continue;
        }
        if ($filtroCategoria !== 'todas' && $r['categoria'] !== $filtroCategoria) {
            continue;
        }
        $bodegaFila = $r['bodega'] ?? 'Bodega Principal';
        if ($filtroBodega !== 'todas' && $bodegaFila !== $filtroBodega) {
            continue;
        }
        if (!empty($busqueda)) {
            $matchSku  = strpos(strtolower($r['codigo_producto']), strtolower($busqueda)) !== false;
            $matchName = strpos(strtolower($r['producto']),        strtolower($busqueda)) !== false;
            if (!$matchSku && !$matchName) continue;
        }
        $filas[] = $r;
    }

} catch (Exception $e) {
    $error = "Error al generar reporte: " . $e->getMessage();
}

include __DIR__ . '/includes/header.php';
?>

<div class="row mb-4 align-items-center">
    <div class="col-md-7">
        <h3 class="fw-bold text-dark mb-1"><i class="bi bi-bar-chart-line-fill text-primary me-2"></i>Conciliación de Inventario</h3>
        <p class="text-muted mb-0">Auditoría: <b><?= htmlspecialchars($eventoActual['nombre_evento'] ?? 'Ninguna') ?></b> (<?= $eventoActual['estado'] === 'activa' ? 'En Curso' : 'Cerrada' ?>)</p>
    </div>
    <div class="col-md-5 text-md-end mt-3 mt-md-0 d-flex justify-content-md-end gap-2 flex-wrap">
        <a href="reporte.php?export=excel" class="btn btn-success btn-sm rounded-pill fw-semibold shadow-sm">
            <i class="bi bi-file-earmark-excel-fill me-1"></i> Exportar Excel (.xls)
        </a>
        <a href="reporte.php?export=csv" class="btn btn-outline-success btn-sm rounded-pill fw-semibold shadow-sm">
            <i class="bi bi-file-earmark-spreadsheet me-1"></i> CSV
        </a>
    </div>
</div>

<!-- Tarjetas de Métricas de Auditoría -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-icon primary">
                <i class="bi bi-boxes"></i>
            </div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($metricas['total_skus']) ?></div>
                <div class="stat-label">Total SKUs Auditados</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-icon success">
                <i class="bi bi-check-circle"></i>
            </div>
            <div class="stat-info">
                <div class="stat-value text-success"><?= number_format($metricas['coinciden_count']) ?></div>
                <div class="stat-label">Coinciden (100%)</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-icon danger">
                <i class="bi bi-exclamation-triangle"></i>
            </div>
            <div class="stat-info">
                <div class="stat-value text-danger"><?= number_format($metricas['faltantes_count']) ?></div>
                <div class="stat-label">Faltantes (-$<?= number_format($metricas['valor_faltantes'], 2) ?>)</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-icon warning">
                <i class="bi bi-plus-circle"></i>
            </div>
            <div class="stat-info">
                <div class="stat-value text-warning-emphasis"><?= number_format($metricas['sobrantes_count']) ?></div>
                <div class="stat-label">Sobrantes (+$<?= number_format($metricas['valor_sobrantes'], 2) ?>)</div>
            </div>
        </div>
    </div>
</div>

<!-- Barra de Filtros -->
<div class="card card-custom mb-4">
    <div class="card-body p-3">
        <form method="GET" action="reporte.php" class="row g-2 align-items-end">

            <!-- Estado -->
            <div class="col-md-2">
                <label class="form-label small fw-semibold text-muted mb-1"><i class="bi bi-funnel me-1"></i>Estado</label>
                <select name="estado" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="todos"     <?= $filtroEstado === 'todos'     ? 'selected' : '' ?>>Todos</option>
                    <option value="Pendiente" <?= $filtroEstado === 'Pendiente' ? 'selected' : '' ?>>&#9203; Pendiente</option>
                    <option value="Coincide"  <?= $filtroEstado === 'Coincide'  ? 'selected' : '' ?>>&#10003; Coincide</option>
                    <option value="Faltante"  <?= $filtroEstado === 'Faltante'  ? 'selected' : '' ?>>&#8595; Faltante</option>
                    <option value="Sobrante"  <?= $filtroEstado === 'Sobrante'  ? 'selected' : '' ?>>&#8593; Sobrante</option>
                </select>
            </div>

            <!-- Bodega -->
            <div class="col-md-2">
                <label class="form-label small fw-semibold text-muted mb-1"><i class="bi bi-building me-1"></i>Bodega</label>
                <select name="bodega" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="todas" <?= $filtroBodega === 'todas' ? 'selected' : '' ?>>Todas las bodegas</option>
                    <?php foreach ($bodegas as $bod): ?>
                        <option value="<?= htmlspecialchars($bod) ?>" <?= $filtroBodega === $bod ? 'selected' : '' ?>>
                            <?= htmlspecialchars($bod) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Categoría -->
            <div class="col-md-2">
                <label class="form-label small fw-semibold text-muted mb-1"><i class="bi bi-tag me-1"></i>Categoría</label>
                <select name="categoria" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="todas" <?= $filtroCategoria === 'todas' ? 'selected' : '' ?>>Todas</option>
                    <?php foreach ($categorias as $cat): ?>
                        <option value="<?= htmlspecialchars($cat) ?>" <?= $filtroCategoria === $cat ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Búsqueda texto -->
            <div class="col-md-4">
                <label class="form-label small fw-semibold text-muted mb-1"><i class="bi bi-search me-1"></i>Buscar SKU o Producto</label>
                <div class="input-group input-group-sm">
                    <input type="text" name="busqueda" class="form-control" placeholder="Código o descripción..." value="<?= htmlspecialchars($busqueda) ?>">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search"></i></button>
                </div>
            </div>

            <!-- Limpiar + contador -->
            <div class="col-md-2 d-flex align-items-end gap-2">
                <a href="reporte.php" class="btn btn-light btn-sm border text-secondary w-100"><i class="bi bi-x-circle me-1"></i>Limpiar</a>
            </div>

            <!-- Resumen de resultados -->
            <div class="col-12">
                <div class="d-flex gap-3 flex-wrap pt-1">
                    <span class="badge bg-secondary-subtle text-secondary px-3 py-2 rounded-pill">
                        <i class="bi bi-list-ul me-1"></i> <?= number_format(count($filas)) ?> resultado(s)
                    </span>
                    <?php if ($filtroEstado !== 'todos' || $filtroBodega !== 'todas' || $filtroCategoria !== 'todas' || $busqueda !== ''): ?>
                    <span class="badge bg-warning-subtle text-warning-emphasis px-3 py-2 rounded-pill">
                        <i class="bi bi-funnel-fill me-1"></i> Filtros activos
                    </span>
                    <?php endif; ?>
                </div>
            </div>

        </form>
    </div>
</div>

<!-- Tabla Principal de Conciliación -->
<div class="card card-custom">
    <div class="card-header-custom d-flex justify-content-between align-items-center">
        <span><i class="bi bi-table me-2 text-primary"></i>Matriz Cruzada - <?= htmlspecialchars($eventoActual['nombre_evento'] ?? '') ?></span>
        <span class="badge bg-light text-muted border"><?= count($filas) ?> resultados</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-custom align-middle mb-0">
                <thead>
                    <tr>
                        <th>Código SKU</th>
                        <th>Producto / Categoría</th>
                        <th class="text-end">Stock Teórico</th>
                        <th class="text-end">Stock Físico</th>
                        <th class="text-end">Diferencia</th>
                        <th class="text-center">Estado</th>
                        <th class="text-end">Costo Unit.</th>
                        <th class="text-end">Impacto ($)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($filas)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-5 text-muted">
                                <i class="bi bi-search fs-2 d-block mb-2 text-black-50"></i>
                                No se encontraron productos coincidentes con los filtros seleccionados.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($filas as $r): ?>
                            <?php
                            $dif = $r['diferencia'];
                            $costo = $r['costo_promedio'];
                            $impacto = $dif * $costo;

                            $badgeClass = 'badge-coincide';
                            $iconEstado = 'bi-check-circle-fill';
                            if ($r['estado'] === 'Pendiente') {
                                $badgeClass = 'badge-pendiente';
                                $iconEstado = 'bi-hourglass-split';
                            } elseif ($r['estado'] === 'Faltante') {
                                $badgeClass = 'badge-faltante';
                                $iconEstado = 'bi-dash-circle-fill';
                            } elseif ($r['estado'] === 'Sobrante') {
                                $badgeClass = 'badge-sobrante';
                                $iconEstado = 'bi-plus-circle-fill';
                            }
                            ?>
                            <tr>
                                <td><span class="sku-badge"><?= htmlspecialchars($r['codigo_producto']) ?></span></td>
                                <td>
                                    <div class="fw-semibold text-dark"><?= htmlspecialchars($r['producto']) ?></div>
                                    <div class="small text-muted"><?= htmlspecialchars($r['categoria']) ?> &bull; <span class="fw-normal"><?= htmlspecialchars($r['marca']) ?></span></div>
                                </td>
                                <td class="text-end fw-semibold text-secondary">
                                    <?= number_format($r['stock_teorico'], 2) ?>
                                </td>
                                <?php if ($r['estado'] === 'Pendiente'): ?>
                                    <td class="text-end text-muted">—</td>
                                    <td class="text-end text-muted">—</td>
                                    <td class="text-center">
                                        <span class="badge-state <?= $badgeClass ?>">
                                            <i class="bi <?= $iconEstado ?>"></i> <?= $r['estado'] ?>
                                        </span>
                                    </td>
                                    <td class="text-end small text-muted">$<?= number_format($costo, 2) ?></td>
                                    <td class="text-end text-muted">—</td>
                                <?php else: ?>
                                    <td class="text-end fw-bold text-dark">
                                        <?= number_format($r['stock_fisico'], 2) ?>
                                    </td>
                                    <td class="text-end fw-bold fs-6 <?= $dif < 0 ? 'text-danger' : ($dif > 0 ? 'text-warning-emphasis' : 'text-success') ?>">
                                        <?= ($dif > 0 ? '+' : '') . number_format($dif, 2) ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge-state <?= $badgeClass ?>">
                                            <i class="bi <?= $iconEstado ?>"></i> <?= $r['estado'] ?>
                                        </span>
                                    </td>
                                    <td class="text-end small text-muted">$<?= number_format($costo, 2) ?></td>
                                    <td class="text-end fw-semibold <?= $impacto < 0 ? 'text-danger' : ($impacto > 0 ? 'text-warning-emphasis' : 'text-muted') ?>">
                                        <?= ($impacto > 0 ? '+$' : ($impacto < 0 ? '-$' : '$')) . number_format(abs($impacto), 2) ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
