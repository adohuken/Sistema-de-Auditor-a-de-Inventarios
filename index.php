<?php
/**
 * Dashboard Ejecutivo & Panel de Control de Auditoría
 * Sistema de Auditoría de Inventarios Físicos
 */

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/auth.php';

// Exigir autenticación
exigirAutenticacion();
$usuarioActual = obtenerUsuarioActual();
$eventoActual = obtenerEventoActivo();
$eventoId = $eventoActual ? $eventoActual['id'] : 0;

// Variables de métricas para el Dashboard
$metricas = [
    'total_skus'        => 0,
    'skus_contados'     => 0,
    'porcentaje_contado'=> 0,
    'coinciden_count'   => 0,
    'faltantes_count'   => 0,
    'sobrantes_count'   => 0,
    'sin_conteo_count'  => 0,
    'valor_faltantes'   => 0,
    'valor_sobrantes'   => 0,
    'valor_teorico'     => 0,
    'valor_fisico'      => 0
];

$topFaltantes = [];
$ultimosConteos = [];
$categoriaMermas = [];
$productividadAuditores = [];

try {
    $pdo = getPDOConnection();

    if ($eventoId > 0) {
        // 1. Consulta Principal de Conciliación por Evento
        $sqlConciliacion = "
            SELECT 
                p.id,
                COALESCE(p.bodega, 'Bodega Principal') AS bodega,
                p.codigo_producto,
                p.producto,
                p.categoria,
                p.existencias_sistema AS stock_teorico,
                p.costo_promedio,
                COALESCE(SUM(c.cantidad_fisica), 0) AS stock_fisico,
                COUNT(c.id) AS numero_lecturas,
                (COALESCE(SUM(c.cantidad_fisica), 0) - p.existencias_sistema) AS diferencia,
                ((COALESCE(SUM(c.cantidad_fisica), 0) - p.existencias_sistema) * p.costo_promedio) AS impacto_monetario
            FROM productos_sistema p
            LEFT JOIN conteos_fisicos c ON (p.codigo_producto = c.codigo_producto AND p.bodega = c.bodega AND p.evento_id = c.evento_id)
            WHERE p.evento_id = :ev
            GROUP BY p.id, p.bodega, p.codigo_producto, p.producto, p.categoria, p.existencias_sistema, p.costo_promedio
        ";
        
        $stmtAll = $pdo->prepare($sqlConciliacion);
        $stmtAll->execute([':ev' => $eventoId]);
        $productosAuditoria = $stmtAll->fetchAll();

        foreach ($productosAuditoria as $p) {
            $metricas['total_skus']++;
            $valTeorico = $p['stock_teorico'] * $p['costo_promedio'];
            $valFisico  = $p['stock_fisico']  * $p['costo_promedio'];

            $metricas['valor_teorico'] += $valTeorico;
            $metricas['valor_fisico']  += $valFisico;

            if ($p['numero_lecturas'] > 0 || $p['stock_fisico'] > 0) {
                $metricas['skus_contados']++;

                if ($p['diferencia'] == 0) {
                    $metricas['coinciden_count']++;
                } elseif ($p['diferencia'] < 0) {
                    $metricas['faltantes_count']++;
                    $metricas['valor_faltantes'] += abs($p['impacto_monetario']);

                    // Acumular merma por categoría
                    $cat = $p['categoria'] ?: 'General';
                    if (!isset($categoriaMermas[$cat])) {
                        $categoriaMermas[$cat] = 0;
                    }
                    $categoriaMermas[$cat] += abs($p['impacto_monetario']);
                } elseif ($p['diferencia'] > 0) {
                    $metricas['sobrantes_count']++;
                    $metricas['valor_sobrantes'] += abs($p['impacto_monetario']);
                }
            } else {
                $metricas['sin_conteo_count']++;
            }
        }

        if ($metricas['total_skus'] > 0) {
            $metricas['porcentaje_contado'] = round(($metricas['skus_contados'] / $metricas['total_skus']) * 100, 1);
        }

        // 2. Top 5 Mayores Faltantes ($)
        usort($productosAuditoria, function($a, $b) {
            return $a['impacto_monetario'] <=> $b['impacto_monetario']; // Los más negativos primero
        });
        $topFaltantes = array_slice(array_filter($productosAuditoria, function($i) { return ($i['numero_lecturas'] > 0 || $i['stock_fisico'] > 0) && $i['diferencia'] < 0; }), 0, 5);

        // 3. Últimos 5 Conteos Registrados
        $stmtUlt = $pdo->prepare("
            SELECT c.codigo_producto, c.cantidad_fisica, c.fecha_conteo, c.usuario, COALESCE(p.producto, 'Producto') AS producto
            FROM conteos_fisicos c
            LEFT JOIN productos_sistema p ON (c.codigo_producto = p.codigo_producto AND c.bodega = p.bodega AND c.evento_id = p.evento_id)
            WHERE c.evento_id = :ev
            ORDER BY c.fecha_conteo DESC
            LIMIT 5
        ");
        $stmtUlt->execute([':ev' => $eventoId]);
        $ultimosConteos = $stmtUlt->fetchAll();

        // 4. Productividad por Auditor
        $stmtAud = $pdo->prepare("
            SELECT usuario, COUNT(*) AS lecturas, SUM(cantidad_fisica) AS unidades
            FROM conteos_fisicos
            WHERE evento_id = :ev
            GROUP BY usuario
            ORDER BY lecturas DESC
        ");
        $stmtAud->execute([':ev' => $eventoId]);
        $productividadAuditores = $stmtAud->fetchAll();
    }

} catch (Exception $e) {
    $error = "Error al cargar datos del Dashboard: " . $e->getMessage();
}

include __DIR__ . '/includes/header.php';
?>

<!-- Script de Chart.js para gráficos -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="row mb-4 align-items-center">
    <div class="col-md-7">
        <h3 class="fw-bold text-dark mb-1"><i class="bi bi-speedometer2 text-primary me-2"></i>Dashboard Ejecutivo de Auditoría</h3>
        <p class="text-muted mb-0">Panel de control en tiempo real: <b><?= htmlspecialchars($eventoActual['nombre_evento'] ?? 'Ninguna Auditoría Activa') ?></b></p>
    </div>
    <div class="col-md-5 text-md-end mt-3 mt-md-0">
        <a href="conteo.php" class="btn btn-primary btn-sm rounded-pill fw-bold me-1 px-3" style="background-color: var(--primary-color);">
            <i class="bi bi-barcode me-1"></i> Ir a Toma Física
        </a>
        <a href="reporte.php" class="btn btn-outline-primary btn-sm rounded-pill fw-semibold me-1">
            <i class="bi bi-bar-chart-line me-1"></i> Conciliación
        </a>
        <a href="informes.php" class="btn btn-outline-success btn-sm rounded-pill fw-semibold">
            <i class="bi bi-printer me-1"></i> Actas / Informes
        </a>
    </div>
</div>

<!-- =========================================================================
     BARRA DE PROGRESO GLOBAL
     ========================================================================= -->
<?php if ($eventoId > 0 && $metricas['total_skus'] > 0): ?>
<div class="card card-custom mb-4" style="border-left: 4px solid var(--primary-color);">
    <div class="card-body py-3 px-4">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="fw-semibold text-dark"><i class="bi bi-clipboard2-check me-2 text-primary"></i>Avance del Conteo Físico</span>
            <span class="fw-bold text-primary"><?= $metricas['porcentaje_contado'] ?>% completado</span>
        </div>
        <div class="progress mb-2" style="height: 10px; border-radius: 8px;">
            <div class="progress-bar bg-primary" style="width: <?= $metricas['porcentaje_contado'] ?>%; border-radius: 8px;"></div>
        </div>
        <div class="d-flex gap-3 flex-wrap small text-muted">
            <span><i class="bi bi-check-circle-fill text-success me-1"></i><b><?= number_format($metricas['skus_contados']) ?></b> contados</span>
            <span><i class="bi bi-hourglass-split text-secondary me-1"></i><b><?= number_format($metricas['sin_conteo_count']) ?></b> pendientes</span>
            <span><i class="bi bi-boxes text-primary me-1"></i><b><?= number_format($metricas['total_skus']) ?></b> total SKUs</span>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- =========================================================================
     FILA DE TARJETAS DE INDICADORES CLAVE (KPIS)
     ========================================================================= -->
<div class="row g-3 mb-4">
    <!-- KPI 1: Pendientes de contar -->
    <div class="col-md-6 col-xl-3">
        <a href="reporte.php?estado=Pendiente" class="text-decoration-none text-reset">
            <div class="stat-card border-start border-4" style="border-color: #64748b !important;">
                <div class="stat-icon secondary">
                    <i class="bi bi-hourglass-split"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-value text-secondary"><?= number_format($metricas['sin_conteo_count']) ?></div>
                    <div class="stat-label">Sin Contar (Pendientes)</div>
                </div>
            </div>
        </a>
    </div>

    <!-- KPI 2: Coinciden -->
    <div class="col-md-6 col-xl-3">
        <a href="reporte.php?estado=Coincide" class="text-decoration-none text-reset">
            <div class="stat-card border-start border-4 border-success">
                <div class="stat-icon success">
                    <i class="bi bi-check-circle-fill"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-value text-success"><?= number_format($metricas['coinciden_count']) ?></div>
                    <div class="stat-label">Coinciden exacto</div>
                </div>
            </div>
        </a>
    </div>

    <!-- KPI 3: Pérdidas por Mermas ($) -->
    <div class="col-md-6 col-xl-3">
        <a href="reporte.php?estado=Faltante" class="text-decoration-none text-reset">
            <div class="stat-card border-start border-4 border-danger">
                <div class="stat-icon danger">
                    <i class="bi bi-graph-down-arrow"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-value text-danger"><?= number_format($metricas['faltantes_count']) ?></div>
                    <div class="stat-label">Faltantes <?= $metricas['valor_faltantes'] > 0 ? '(-$' . number_format($metricas['valor_faltantes'], 2) . ')' : '' ?></div>
                </div>
            </div>
        </a>
    </div>

    <!-- KPI 4: Sobrantes -->
    <div class="col-md-6 col-xl-3">
        <a href="reporte.php?estado=Sobrante" class="text-decoration-none text-reset">
            <div class="stat-card border-start border-4 border-warning">
                <div class="stat-icon warning">
                    <i class="bi bi-graph-up-arrow"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-value text-warning-emphasis"><?= number_format($metricas['sobrantes_count']) ?></div>
                    <div class="stat-label">Sobrantes <?= $metricas['valor_sobrantes'] > 0 ? '(+$' . number_format($metricas['valor_sobrantes'], 2) . ')' : '' ?></div>
                </div>
            </div>
        </a>
    </div>
</div>

<!-- =========================================================================
     FILA DE GRÁFICOS VISUALES INTERACTIVOS
     ========================================================================= -->
<div class="row g-4 mb-4">
    <!-- Gráfico 1: Estado de Productos -->
    <div class="col-lg-5">
        <div class="card card-custom h-100">
            <div class="card-header-custom d-flex justify-content-between align-items-center">
                <span><i class="bi bi-pie-chart me-2 text-primary"></i>Distribución de Conciliación</span>
                <span class="badge bg-light text-muted border">Estado del Inventario</span>
            </div>
            <div class="card-body d-flex align-items-center justify-content-center p-4">
                <div style="width: 100%; max-width: 320px;">
                    <canvas id="chartEstadoConciliacion"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Gráfico 2: Mermas por Categoría -->
    <div class="col-lg-7">
        <div class="card card-custom h-100">
            <div class="card-header-custom d-flex justify-content-between align-items-center">
                <span><i class="bi bi-bar-chart-fill me-2 text-primary"></i>Pérdidas Económicas por Categoría ($)</span>
                <span class="badge bg-danger-subtle text-danger fw-bold">Top Mermas</span>
            </div>
            <div class="card-body p-4 d-flex align-items-center justify-content-center" style="min-height: 290px;">
                <?php if (empty($categoriaMermas)): ?>
                    <div class="text-center py-4">
                        <div class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center p-3 mb-2 text-success" style="width: 54px; height: 54px;">
                            <i class="bi bi-shield-check fs-3"></i>
                        </div>
                        <h6 class="fw-bold text-dark mb-1">Sin mermas detectadas</h6>
                        <p class="text-muted small mb-0">Los faltantes monetarios por categoría aparecerán aquí conforme avance la toma física.</p>
                    </div>
                <?php else: ?>
                    <div style="width: 100%; height: 250px;">
                        <canvas id="chartMermasCategoria"></canvas>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- =========================================================================
     FILA DE TABLAS Y ALERTAS CRÍTICAS
     ========================================================================= -->
<div class="row g-4 mb-4">
    <!-- Top 5 Mayores Pérdidas -->
    <div class="col-lg-7">
        <div class="card card-custom h-100">
            <div class="card-header-custom d-flex justify-content-between align-items-center bg-danger-subtle">
                <span class="text-danger fw-bold"><i class="bi bi-exclamation-triangle-fill me-2"></i>Top 5 Mayores Faltantes de Inventario</span>
                <a href="reporte.php?estado=Faltante" class="small text-danger fw-semibold">Ver Todos &rarr;</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-custom align-middle mb-0 fs-7">
                        <thead>
                            <tr>
                                <th>SKU</th>
                                <th>Producto</th>
                                <th class="text-end">Teórico</th>
                                <th class="text-end">Físico</th>
                                <th class="text-end">Faltante</th>
                                <th class="text-end">Pérdida ($)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($topFaltantes)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-success fw-semibold">
                                        <i class="bi bi-check-circle me-1"></i> No se registran faltantes críticos en esta auditoría.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($topFaltantes as $f): ?>
                                    <tr>
                                        <td><code><?= htmlspecialchars($f['codigo_producto']) ?></code></td>
                                        <td class="fw-semibold text-dark"><?= htmlspecialchars($f['producto']) ?></td>
                                        <td class="text-end text-secondary"><?= number_format($f['stock_teorico'], 2) ?></td>
                                        <td class="text-end fw-bold"><?= number_format($f['stock_fisico'], 2) ?></td>
                                        <td class="text-end fw-bold text-danger"><?= number_format($f['diferencia'], 2) ?></td>
                                        <td class="text-end fw-bold text-danger">-$<?= number_format(abs($f['impacto_monetario']), 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Productividad y Últimos Escaneos -->
    <div class="col-lg-5">
        <div class="card card-custom h-100">
            <div class="card-header-custom d-flex justify-content-between align-items-center">
                <span><i class="bi bi-clock-history me-2 text-primary"></i>Últimas Lecturas Registradas</span>
                <span class="badge bg-light text-muted border">En Vivo</span>
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush border-0">
                    <?php if (empty($ultimosConteos)): ?>
                        <li class="list-group-item text-center py-5 text-muted">
                            <i class="bi bi-barcode fs-2 d-block mb-2 text-black-25"></i>
                            No hay escaneos recientes en esta auditoría.<br>
                            <a href="conteo.php" class="btn btn-primary btn-sm rounded-pill mt-3 px-4 fw-bold">
                                <i class="bi bi-barcode me-1"></i> Iniciar Conteo
                            </a>
                        </li>
                    <?php else: ?>
                        <?php foreach ($ultimosConteos as $c): ?>
                            <li class="list-group-item p-3 d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-bold text-dark small"><?= htmlspecialchars($c['codigo_producto']) ?> - <?= htmlspecialchars($c['producto']) ?></div>
                                    <div class="text-muted small" style="font-size: 0.75rem;">
                                        <i class="bi bi-person me-1"></i><?= htmlspecialchars($c['usuario']) ?> &bull; <?= date('H:i:s', strtotime($c['fecha_conteo'])) ?>
                                    </div>
                                </div>
                                <span class="badge bg-success-subtle text-success fs-6 fw-bold px-2.5 py-1">
                                    <?= number_format($c['cantidad_fisica'], 2) ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- =========================================================================
     INICIALIZACIÓN DE GRÁFICOS INTERACTIVOS (CHART.JS)
     ========================================================================= -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Datos del servidor
    const coinciden  = <?= $metricas['coinciden_count'] ?>;
    const faltantes  = <?= $metricas['faltantes_count'] ?>;
    const sobrantes  = <?= $metricas['sobrantes_count'] ?>;
    const pendientes = <?= $metricas['sin_conteo_count'] ?>;
    const total      = coinciden + faltantes + sobrantes + pendientes;

    // 1. Gráfico de Rosca - Estado de Conciliación
    const elEstado = document.getElementById('chartEstadoConciliacion');
    if (elEstado) {
        const ctxEstado = elEstado.getContext('2d');
        const labels = [];
        const values = [];
        const colors = [];

        if (pendientes > 0) { labels.push('⏳ Pendientes');   values.push(pendientes); colors.push('#64748b'); }
        if (coinciden > 0)  { labels.push('✓ Coinciden');    values.push(coinciden);  colors.push('#10b981'); }
        if (faltantes > 0)  { labels.push('↓ Faltantes');    values.push(faltantes);  colors.push('#ef4444'); }
        if (sobrantes > 0)  { labels.push('↑ Sobrantes');    values.push(sobrantes);  colors.push('#f59e0b'); }

        // Si no hay datos en absoluto
        if (values.length === 0) { labels.push('Sin datos'); values.push(1); colors.push('#e2e8f0'); }

        new Chart(ctxEstado, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: values,
                    backgroundColor: colors,
                    borderWidth: 2,
                    borderColor: '#ffffff',
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                cutout: '65%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            font: { family: 'Inter', size: 12 },
                            padding: 12,
                            generateLabels: function(chart) {
                                const data = chart.data;
                                return data.labels.map((label, i) => {
                                    const val = data.datasets[0].data[i];
                                    const pct = total > 0 ? ((val/total)*100).toFixed(1) : 0;
                                    return {
                                        text: label + ' (' + pct + '%)',
                                        fillStyle: data.datasets[0].backgroundColor[i],
                                        strokeStyle: '#fff',
                                        lineWidth: 1,
                                        index: i
                                    };
                                });
                            }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                const val = ctx.raw;
                                const pct = total > 0 ? ((val/total)*100).toFixed(1) : 0;
                                return ctx.label + ': ' + val + ' SKUs (' + pct + '%)';
                            }
                        }
                    }
                }
            }
        });
    }

    // 2. Gráfico de Barras - Mermas por Categoría (solo si el canvas existe)
    const elMermas = document.getElementById('chartMermasCategoria');
    if (elMermas) {
        const ctxMermas = elMermas.getContext('2d');
        const catLabels = <?= json_encode(array_keys($categoriaMermas)) ?>;
        const catValues = <?= json_encode(array_values($categoriaMermas)) ?>;

        new Chart(ctxMermas, {
            type: 'bar',
            data: {
                labels: catLabels,
                datasets: [{
                    label: 'Pérdida ($)',
                    data: catValues,
                    backgroundColor: 'rgba(239, 68, 68, 0.85)',
                    borderColor: '#dc2626',
                    borderWidth: 1,
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) { return '$' + value.toLocaleString(); }
                        }
                    }
                },
                plugins: {
                    legend: { display: false }
                }
            }
        });
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
