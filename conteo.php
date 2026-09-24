<?php
/**
 * Módulo 2: Registro de Conteo Físico (Vinculado a la Auditoría Activa)
 * Sistema de Auditoría de Inventario
 */

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/auth.php';

// Exigir permiso al módulo de conteo físico
exigirPermisoModulo('conteo');
$usuarioActual = obtenerUsuarioActual();
$eventoActual = obtenerEventoActivo();

$mensajeOk = null;
$error = null;

// Acción: Eliminar una lectura de conteo específica
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_id'])) {
    if ($eventoActual && $eventoActual['estado'] === 'cerrada') {
        $error = "La auditoría está CERRADA. No se pueden modificar lecturas pasadas.";
    } else {
        $idEliminar = intval($_POST['eliminar_id']);
        try {
            $pdo = getPDOConnection();
            $stmt = $pdo->prepare("DELETE FROM conteos_fisicos WHERE id = :id AND evento_id = :ev");
            $stmt->execute([':id' => $idEliminar, ':ev' => $eventoActual['id']]);
            $_SESSION['flash_success'] = "Lectura de conteo física eliminada correctamente.";
            header("Location: conteo.php");
            exit;
        } catch (Exception $e) {
            $error = "Error al eliminar la lectura: " . $e->getMessage();
        }
    }
}

// Acción: Registrar Conteo Físico
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_conteo'])) {
    if (!$eventoActual) {
        $error = "No hay ningún evento de auditoría activo. Solicita al Administrador crear una auditoría.";
    } elseif ($eventoActual['estado'] === 'cerrada') {
        $error = "La auditoría [{$eventoActual['nombre_evento']}] está CERRADA. No se permiten nuevas lecturas.";
    } else {
        $codigo = trim($_POST['codigo_producto'] ?? '');
        $cantidad = floatval($_POST['cantidad_fisica'] ?? 0);
        $usuarioConteo = !empty($_POST['usuario_custom']) ? trim($_POST['usuario_custom']) : $usuarioActual['nombre'];
        $bodegaSeleccionada = !empty($_POST['bodega_conteo']) ? trim($_POST['bodega_conteo']) : '';
        $eventoId = $eventoActual['id'];

        if (empty($codigo)) {
            $error = "Por favor ingresa o escanea un Código de Producto / SKU.";
        } elseif ($cantidad <= 0) {
            $error = "La cantidad física contada debe ser mayor a 0.";
        } else {
            try {
                $pdo = getPDOConnection();
                
                // Verificar si el producto existe en la auditoría activa
                $stmtProd = $pdo->prepare("SELECT codigo_producto, producto, COALESCE(bodega, 'Bodega Principal') AS bodega FROM productos_sistema WHERE codigo_producto = :codigo AND evento_id = :ev LIMIT 1");
                $stmtProd->execute([':codigo' => $codigo, ':ev' => $eventoId]);
                $productoDb = $stmtProd->fetch();

                if (!$productoDb) {
                    $error = "El código SKU [{$codigo}] no está cargado en esta auditoría. Verifica la lista o carga el stock ERP.";
                } else {
                    $bodegaFinal = $bodegaSeleccionada ?: $productoDb['bodega'];
                    $stmtInsert = $pdo->prepare("
                        INSERT INTO conteos_fisicos (evento_id, bodega, codigo_producto, cantidad_fisica, fecha_conteo, usuario, usuario_id) 
                        VALUES (:evento_id, :bodega, :codigo, :cantidad, NOW(), :usuario, :usuario_id)
                    ");
                    $stmtInsert->execute([
                        ':evento_id'  => $eventoId,
                        ':bodega'     => $bodegaFinal,
                        ':codigo'     => $codigo,
                        ':cantidad'   => $cantidad,
                        ':usuario'    => $usuarioConteo,
                        ':usuario_id' => $usuarioActual['id']
                    ]);

                    $_SESSION['flash_success'] = "<b>Conteo Guardado:</b> {$productoDb['producto']} (SKU: {$codigo}) en [{$bodegaFinal}] &rarr; <b>{$cantidad} uds.</b> contadas.";
                    header("Location: conteo.php");
                    exit;
                }
            } catch (Exception $e) {
                $error = "Error al guardar el conteo: " . $e->getMessage();
            }
        }
    }
}

// Obtener listado de conteos recientes de la auditoría activa y rol del usuario en la auditoría
$conteosRecientes = [];
$totalUdsContadasSesion = 0;
$rolAsignadoEvento = null;
$bodegaAsignadaEvento = null;
$bodegasDisponibles = [];
$misMetricasContador = [
    'lecturas' => 0,
    'unidades' => 0,
    'skus_unicos' => 0,
    'ultima_actividad' => null
];

try {
    $pdo = getPDOConnection();
    if ($eventoActual) {
        $eventoId = $eventoActual['id'];
        $sql = "SELECT c.id, c.codigo_producto, c.cantidad_fisica, c.fecha_conteo, c.usuario,
                       COALESCE(c.bodega, 'Bodega Principal') AS bodega,
                       COALESCE(p.producto, 'Producto no registrado') AS producto_nombre
                FROM conteos_fisicos c
                LEFT JOIN productos_sistema p ON (c.codigo_producto = p.codigo_producto AND c.bodega = p.bodega AND c.evento_id = p.evento_id)
                WHERE c.evento_id = :ev
                ORDER BY c.fecha_conteo DESC 
                LIMIT 25";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':ev' => $eventoId]);
        $conteosRecientes = $stmt->fetchAll();

        $stmtSum = $pdo->prepare("SELECT COALESCE(SUM(cantidad_fisica), 0) FROM conteos_fisicos WHERE evento_id = :ev");
        $stmtSum->execute([':ev' => $eventoId]);
        $totalUdsContadasSesion = $stmtSum->fetchColumn();

        // Rol asignado en esta auditoría
        $stmtAsig = $pdo->prepare("SELECT rol_evento, bodega_asignada FROM asignaciones_auditoria WHERE evento_id = :ev AND usuario_id = :usr LIMIT 1");
        $stmtAsig->execute([':ev' => $eventoId, ':usr' => $usuarioActual['id']]);
        $asigUser = $stmtAsig->fetch();
        if ($asigUser) {
            $rolAsignadoEvento = $asigUser['rol_evento'];
            $bodegaAsignadaEvento = $asigUser['bodega_asignada'];
        }

        // Bodegas disponibles en la auditoría
        $stmtBod = $pdo->prepare("SELECT DISTINCT COALESCE(bodega, 'Bodega Principal') AS bodega FROM productos_sistema WHERE evento_id = :ev ORDER BY bodega ASC");
        $stmtBod->execute([':ev' => $eventoId]);
        $bodegasDisponibles = $stmtBod->fetchAll(PDO::FETCH_COLUMN);

        // Métricas personales del contador logueado
        $stmtMisMet = $pdo->prepare("
            SELECT 
                COUNT(*) AS lecturas,
                COALESCE(SUM(cantidad_fisica), 0) AS unidades,
                COUNT(DISTINCT codigo_producto) AS skus_unicos,
                MAX(fecha_conteo) AS ultima_actividad
            FROM conteos_fisicos
            WHERE evento_id = :ev AND (usuario_id = :usr OR usuario = :nombre)
        ");
        $stmtMisMet->execute([
            ':ev'     => $eventoId,
            ':usr'    => $usuarioActual['id'],
            ':nombre' => $usuarioActual['nombre']
        ]);
        $dataMisMet = $stmtMisMet->fetch();
        if ($dataMisMet) {
            $misMetricasContador = $dataMisMet;
        }
    }
} catch (Exception $e) {
    $error = "Error al cargar historial de conteos: " . $e->getMessage();
}

include __DIR__ . '/includes/header.php';
?>

<div class="row mb-4 align-items-center">
    <div class="col-md-7">
        <h3 class="fw-bold text-dark mb-1"><i class="bi bi-barcode text-primary me-2"></i>Toma Física Real (Conteo Ciego)</h3>
        <p class="text-muted mb-0 d-flex align-items-center gap-2 flex-wrap">
            <span>Auditoría Activa: <b><?= htmlspecialchars($eventoActual['nombre_evento'] ?? 'Ninguna') ?></b></span>
            <?php if ($rolAsignadoEvento): ?>
                <?php
                    $badgeColor = 'bg-primary-subtle text-primary border-primary';
                    $labelRol = 'Contador Físico';
                    if ($rolAsignadoEvento === 'supervisor') {
                        $badgeColor = 'bg-warning-subtle text-warning-emphasis border-warning';
                        $labelRol = 'Supervisor de Zona';
                    } elseif ($rolAsignadoEvento === 'auditor_lider') {
                        $badgeColor = 'bg-danger-subtle text-danger-emphasis border-danger';
                        $labelRol = 'Auditor Líder';
                    }
                ?>
                <span class="badge <?= $badgeColor ?> border border-opacity-25 rounded-pill px-2 py-1 small">
                    <i class="bi bi-person-badge me-1"></i>Rol: <?= $labelRol ?>
                    <?= $bodegaAsignadaEvento ? ' &bull; ' . htmlspecialchars($bodegaAsignadaEvento) : '' ?>
                </span>
            <?php endif; ?>
        </p>
    </div>
    <div class="col-md-5 text-md-end mt-3 mt-md-0">
        <div class="stat-card py-2 px-3 d-inline-flex align-items-center bg-white border">
            <div class="stat-icon primary me-2" style="width: 40px; height: 40px; font-size: 1.1rem;">
                <i class="bi bi-card-checklist"></i>
            </div>
            <div class="text-start">
                <div class="fw-bold fs-6 leading-tight"><?= number_format($totalUdsContadasSesion, 2) ?></div>
                <div class="text-muted small" style="font-size: 0.75rem;">Total Físico en esta Auditoría</div>
            </div>
        </div>
    </div>
</div>

<!-- Fila de Tarjetas de Rendimiento Personal del Contador (Mi Resumen) -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card card-custom p-3 bg-white border-start border-4 border-primary shadow-sm h-100">
            <div class="text-muted small fw-semibold">Mis Escaneos / Lecturas</div>
            <div class="d-flex align-items-center justify-content-between mt-1">
                <span class="fs-4 fw-bold text-dark"><?= number_format($misMetricasContador['lecturas']) ?></span>
                <i class="bi bi-barcode text-primary fs-3 opacity-50"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-custom p-3 bg-white border-start border-4 border-success shadow-sm h-100">
            <div class="text-muted small fw-semibold">Mis Unidades Contadas</div>
            <div class="d-flex align-items-center justify-content-between mt-1">
                <span class="fs-4 fw-bold text-success"><?= number_format($misMetricasContador['unidades'], 2) ?></span>
                <i class="bi bi-boxes text-success fs-3 opacity-50"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-custom p-3 bg-white border-start border-4 border-info shadow-sm h-100">
            <div class="text-muted small fw-semibold">Mis SKUs Únicos</div>
            <div class="d-flex align-items-center justify-content-between mt-1">
                <span class="fs-4 fw-bold text-dark"><?= number_format($misMetricasContador['skus_unicos']) ?></span>
                <i class="bi bi-tag text-info fs-3 opacity-50"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-custom p-3 bg-white border-start border-4 border-warning shadow-sm h-100">
            <div class="text-muted small fw-semibold">Último Registro</div>
            <div class="d-flex align-items-center justify-content-between mt-1">
                <span class="fs-6 fw-bold text-dark"><?= $misMetricasContador['ultima_actividad'] ? date('H:i:s d/m', strtotime($misMetricasContador['ultima_actividad'])) : 'Sin registros' ?></span>
                <i class="bi bi-clock-history text-warning fs-3 opacity-50"></i>
            </div>
        </div>
    </div>
</div>

<?php if ($eventoActual && $eventoActual['estado'] === 'cerrada'): ?>
    <div class="alert alert-warning d-flex align-items-center gap-2 shadow-sm mb-4" role="alert">
        <i class="bi bi-lock-fill fs-4"></i>
        <div>
            <b>AUDITORÍA CERRADA Y CONGELADA:</b> La auditoría <i><?= htmlspecialchars($eventoActual['nombre_evento']) ?></i> se encuentra finalizada. No se permiten nuevas lecturas físicas de conteo.
        </div>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-octagon-fill me-2"></i><?= htmlspecialchars($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row g-4">
    <!-- Formulario Rápido de Captura de Conteo -->
    <div class="col-lg-5">
        <div class="card card-custom counter-box">
            <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                <h5 class="fw-bold text-dark mb-0"><i class="bi bi-upc-scan me-2 text-primary"></i>Captura de Producto</h5>
                <span class="badge bg-primary-subtle text-primary fw-semibold px-2 py-1"><i class="bi bi-eye-slash me-1"></i>Conteo Ciego</span>
            </div>

            <form method="POST" action="conteo.php" id="formConteo" autocomplete="off">
                <input type="hidden" name="guardar_conteo" value="1">

                <!-- Código de Barras / SKU -->
                <div class="mb-3 position-relative">
                    <label for="codigo_producto" class="form-label fw-semibold text-secondary small">Código de Barras / SKU:</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light"><i class="bi bi-barcode text-primary fs-5"></i></span>
                        <input type="text" class="form-control form-control-lg fw-bold" id="codigo_producto" name="codigo_producto" placeholder="Escanea o escribe el SKU..." required <?= ($eventoActual && $eventoActual['estado'] === 'cerrada') ? 'disabled' : 'autofocus' ?> onkeyup="buscarSugestiones(this.value)">
                    </div>
                    <!-- Dropdown de autocompletado AJAX -->
                    <div id="listaSugestiones" class="list-group position-absolute w-100 shadow-sm z-3 mt-1" style="display: none; max-height: 200px; overflow-y: auto;"></div>
                </div>

                <!-- Nombre o Descripción Seleccionada -->
                <div class="mb-3 bg-light p-3 rounded border">
                    <div class="text-muted small fw-semibold">Producto Detectado:</div>
                    <div id="nombreProductoDetectado" class="fw-bold text-dark fs-6 mt-1">
                        <span class="text-muted fst-italic">Ingresa un SKU para validar...</span>
                    </div>
                </div>

                <!-- Cantidad Física Contada con Botones Rápidos -->
                <div class="mb-3">
                    <label for="cantidad_fisica" class="form-label fw-semibold text-secondary small">Cantidad Física Encontrada:</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light"><i class="bi bi-plus-slash-minus text-success fs-5"></i></span>
                        <input type="number" step="0.01" min="0.01" class="form-control input-huge text-primary" id="cantidad_fisica" name="cantidad_fisica" placeholder="0.00" required <?= ($eventoActual && $eventoActual['estado'] === 'cerrada') ? 'disabled' : '' ?>>
                    </div>
                    <!-- Botones de incremento rápido para el contador -->
                    <div class="d-flex gap-1 mt-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm flex-fill fw-bold" onclick="sumarCantidad(1)">+1</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm flex-fill fw-bold" onclick="sumarCantidad(5)">+5</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm flex-fill fw-bold" onclick="sumarCantidad(10)">+10</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm flex-fill fw-bold" onclick="sumarCantidad(50)">+50</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm flex-fill fw-bold" onclick="sumarCantidad(100)">+100</button>
                    </div>
                </div>

                <!-- Bodega / Zona de Conteo -->
                <div class="mb-3">
                    <label for="bodega_conteo" class="form-label fw-semibold text-secondary small">Bodega / Ubicación de Conteo:</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light"><i class="bi bi-building text-primary"></i></span>
                        <select class="form-select fw-semibold" id="bodega_conteo" name="bodega_conteo" <?= ($eventoActual && $eventoActual['estado'] === 'cerrada') ? 'disabled' : '' ?>>
                            <?php if (!empty($bodegasDisponibles)): ?>
                                <?php foreach ($bodegasDisponibles as $bod): ?>
                                    <option value="<?= htmlspecialchars($bod) ?>" <?= ($bod === $bodegaAsignadaEvento) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($bod) ?> <?= ($bod === $bodegaAsignadaEvento) ? '(Tu bodega asignada)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="Bodega Principal">Bodega Principal</option>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>

                <!-- Usuario Auditador -->
                <div class="mb-4">
                    <label for="usuario_custom" class="form-label fw-semibold text-secondary small">Registrado Por (Auditor / Almacenista):</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light"><i class="bi bi-person-badge text-muted"></i></span>
                        <input type="text" class="form-control bg-light" id="usuario_custom" name="usuario_custom" value="<?= htmlspecialchars($usuarioActual['nombre']) ?>" <?= ($eventoActual && $eventoActual['estado'] === 'cerrada') ? 'disabled' : '' ?>>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary w-100 py-3 fw-bold fs-6 shadow-sm d-flex align-items-center justify-content-center gap-2" style="background-color: var(--primary-color);" <?= ($eventoActual && $eventoActual['estado'] === 'cerrada') ? 'disabled' : '' ?>>
                    <i class="bi bi-check-lg fs-4"></i> REGISTRAR LECTURA FÍSICA
                </button>
            </form>
        </div>
    </div>

    <!-- Historial de Conteos Registrados Recientemente -->
    <div class="col-lg-7">
        <div class="card card-custom">
            <div class="card-header-custom d-flex justify-content-between align-items-center">
                <span><i class="bi bi-clock-history me-2 text-primary"></i>Historial de Lecturas en esta Auditoría</span>
                <span class="badge bg-light text-muted border"><?= count($conteosRecientes) ?> lecturas</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-custom align-middle mb-0">
                        <thead>
                            <tr>
                                <th>SKU / Producto</th>
                                <th class="text-center">Cant. Física</th>
                                <th>Usuario</th>
                                <th>Hora</th>
                                <th class="text-center">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($conteosRecientes)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-muted">
                                        <i class="bi bi-inbox fs-3 d-block mb-2 text-black-50"></i>
                                        No hay registros de conteo físico para esta auditoría aún.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($conteosRecientes as $row): ?>
                                    <tr>
                                        <td>
                                            <span class="sku-badge mb-1"><?= htmlspecialchars($row['codigo_producto']) ?></span>
                                            <div class="small text-muted"><?= htmlspecialchars($row['producto_nombre']) ?></div>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-success-subtle text-success fs-6 fw-bold px-3 py-1">
                                                <?= number_format($row['cantidad_fisica'], 2) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="small fw-semibold text-secondary"><i class="bi bi-person me-1"></i><?= htmlspecialchars($row['usuario']) ?></span>
                                        </td>
                                        <td class="small text-muted">
                                            <?= date('d/m H:i', strtotime($row['fecha_conteo'])) ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($eventoActual && $eventoActual['estado'] === 'activa'): ?>
                                                <form method="POST" action="conteo.php" onsubmit="return confirm('¿Deseas eliminar esta lectura de conteo?');" class="d-inline">
                                                    <input type="hidden" name="eliminar_id" value="<?= $row['id'] ?>">
                                                    <button type="submit" class="btn btn-link text-danger p-0 border-0" title="Eliminar Conteo">
                                                        <i class="bi bi-trash3 fs-6"></i>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-muted"><i class="bi bi-lock"></i></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let timer = null;

function buscarSugestiones(val) {
    clearTimeout(timer);
    const lista = document.getElementById('listaSugestiones');
    const cajaNombre = document.getElementById('nombreProductoDetectado');

    if (val.length < 1) {
        lista.style.display = 'none';
        cajaNombre.innerHTML = '<span class="text-muted fst-italic">Ingresa un SKU para validar...</span>';
        return;
    }

    timer = setTimeout(() => {
        fetch(`api/buscar_producto.php?q=${encodeURIComponent(val)}`)
            .then(res => res.json())
            .then(data => {
                if (data.error || data.length === 0) {
                    lista.style.display = 'none';
                    cajaNombre.innerHTML = '<span class="text-danger fw-semibold"><i class="bi bi-x-circle me-1"></i>SKU no cargado en esta auditoría</span>';
                } else {
                    let html = '';
                    let exactMatch = data.find(p => p.codigo_producto.toLowerCase() === val.toLowerCase());
                    
                    if (exactMatch) {
                        cajaNombre.innerHTML = `<span class="text-success"><i class="bi bi-check-circle me-1"></i>${exactMatch.producto} (${exactMatch.categoria})</span>`;
                    } else if (data.length > 0) {
                        cajaNombre.innerHTML = `<span class="text-primary"><i class="bi bi-search me-1"></i>Coincidencias encontradas: ${data.length}</span>`;
                    }

                    data.forEach(prod => {
                        html += `
                            <button type="button" class="list-group-item list-group-item-action py-2" onclick="seleccionarProducto('${prod.codigo_producto}', '${escapeHtml(prod.producto)}')">
                                <div class="fw-bold small text-dark">${prod.codigo_producto}</div>
                                <div class="small text-muted">${prod.producto} <span class="badge bg-light text-dark border ms-1">${prod.categoria}</span></div>
                            </button>
                        `;
                    });
                    lista.innerHTML = html;
                    lista.style.display = 'block';
                }
            })
            .catch(err => console.error(err));
    }, 200);
}

function seleccionarProducto(codigo, nombre) {
    document.getElementById('codigo_producto').value = codigo;
    document.getElementById('listaSugestiones').style.display = 'none';
    document.getElementById('nombreProductoDetectado').innerHTML = `<span class="text-success"><i class="bi bi-check-circle me-1"></i>${nombre}</span>`;
    document.getElementById('cantidad_fisica').focus();
}

function escapeHtml(text) {
    return text.replace(/'/g, "\\'").replace(/"/g, "&quot;");
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('#codigo_producto') && !e.target.closest('#listaSugestiones')) {
        document.getElementById('listaSugestiones').style.display = 'none';
    }
});

function sumarCantidad(num) {
    const input = document.getElementById('cantidad_fisica');
    let val = parseFloat(input.value) || 0;
    input.value = (val + num).toFixed(2).replace(/\.00$/, '');
    input.focus();
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
