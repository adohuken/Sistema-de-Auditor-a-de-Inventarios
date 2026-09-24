<?php
/**
 * Módulo de Gestión de Eventos & Histórico de Auditorías (2-3 veces al año)
 * Vinculación directa con la Carga de Existencias del ERP
 * Sistema de Auditoría de Inventarios
 */

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/auth.php';

// Exigir permiso al módulo de eventos
exigirPermisoModulo('eventos');
$usuarioActual = obtenerUsuarioActual();

$error = null;

// Helper para limpiar montos numéricos
function limpiarNumero($val) {
    $val = str_replace(['$', 'C$', ' ', ','], '', trim($val));
    return floatval($val);
}

// Helper para extraer filas desde contenido HTML <table> (.xls)
function extraerFilasDeHtml($content) {
    $filas = [];
    preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $content, $trMatches);
    foreach ($trMatches[1] as $trContent) {
        preg_match_all('/<t[dh][^>]*>(.*?)<\/t[dh]>/is', $trContent, $tdMatches);
        if (!empty($tdMatches[1])) {
            $fila = array_map(function($val) {
                return trim(html_entity_decode(strip_tags($val), ENT_QUOTES, 'UTF-8'));
            }, $tdMatches[1]);
            $filas[] = implode(';', $fila);
        }
    }
    return $filas;
}

// Acción: Crear Nueva Auditoría (Con opción de subir archivo ERP directamente)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_evento'])) {
    if (!esAdmin()) {
        $_SESSION['flash_error'] = "Solo los Administradores pueden crear nuevos eventos de auditoría.";
        header("Location: eventos.php");
        exit;
    }

    $nombre   = trim($_POST['nombre_evento'] ?? '');
    $bodega   = trim($_POST['bodega_sucursal'] ?? 'Bodega Principal');
    $notas    = trim($_POST['notas'] ?? '');

    if (empty($nombre)) {
        $error = "El nombre de la auditoría es obligatorio (ej: Auditoría Física Q4 2026).";
    } else {
        try {
            $pdo = getPDOConnection();
            
            // Cerrar anteriores si se marcó el checkbox
            if (isset($_POST['cerrar_anteriores'])) {
                $pdo->exec("UPDATE eventos_auditoria SET estado = 'cerrada', fecha_cierre = NOW() WHERE estado = 'activa'");
            }

            // 1. Crear el registro del Evento de Auditoría
            $stmt = $pdo->prepare("INSERT INTO eventos_auditoria (nombre_evento, bodega_sucursal, fecha_inicio, estado, usuario_creador, notas) VALUES (:nombre, :bodega, NOW(), 'activa', :usuario, :notas)");
            $stmt->execute([
                ':nombre'  => $nombre,
                ':bodega'  => $bodega,
                ':usuario' => $usuarioActual['nombre'],
                ':notas'   => $notas
            ]);

            $nuevoId = $pdo->lastInsertId();
            $_SESSION['evento_id'] = $nuevoId;

            // 2. Si adjuntó archivo ERP en el modal, cargarlo de una vez
            $conArchivo = false;
            $insertados = 0;

            if (isset($_FILES['archivo_csv']) && $_FILES['archivo_csv']['error'] === UPLOAD_ERR_OK) {
                $tmpName = $_FILES['archivo_csv']['tmp_name'];
                $content = file_get_contents($tmpName);
                
                if (strpos($content, '<tr') !== false || strpos($content, '<table') !== false) {
                    $lineas = extraerFilasDeHtml($content);
                } else {
                    if (!mb_check_encoding($content, 'UTF-8')) {
                        $content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1, Windows-1252');
                    }
                    $content = str_replace(["\r\n", "\r"], "\n", $content);
                    $lineas = explode("\n", trim($content));
                }

                if (!empty($lineas)) {
                    $primeraLineaData = '';
                    foreach ($lineas as $l) {
                        if (strlen(trim($l)) > 5 && strpos(strtolower($l), 'sep=') === false) {
                            $primeraLineaData .= $l . "\n";
                        }
                    }
                    $delimitador = ';';
                    if (strpos($primeraLineaData, ';') !== false) {
                        $delimitador = ';';
                    } elseif (strpos($primeraLineaData, "\t") !== false) {
                        $delimitador = "\t";
                    } elseif (strpos($primeraLineaData, ',') !== false) {
                        $delimitador = ',';
                    }

                    $sqlInst = "INSERT INTO productos_sistema 
                            (evento_id, codigo_producto, producto, categoria, tipo, marca, existencias_sistema, costo_promedio, precio_producto)
                            VALUES (:evento_id, :codigo, :producto, :cat, :tipo, :marca, :existencias, :costo, :precio)
                            ON DUPLICATE KEY UPDATE
                            producto = VALUES(producto),
                            categoria = VALUES(categoria),
                            tipo = VALUES(tipo),
                            marca = VALUES(marca),
                            existencias_sistema = VALUES(existencias_sistema),
                            costo_promedio = VALUES(costo_promedio),
                            precio_producto = VALUES(precio_producto)";
                    
                    $stmtInst = $pdo->prepare($sqlInst);

                    foreach ($lineas as $linea) {
                        $linea = trim($linea);
                        if (empty($linea) || strpos(strtolower($linea), 'sep=') === 0) continue;

                        $datos = str_getcsv($linea, $delimitador);
                        if (count($datos) === 0) continue;

                        $primerCol = strtolower(trim($datos[0] ?? ''));

                        if (
                            empty($primerCol) || 
                            strpos($primerCol, 'vargas') !== false || 
                            strpos($primerCol, 'informe') !== false || 
                            strpos($primerCol, 'bodega') !== false || 
                            strpos($primerCol, 'código') !== false || 
                            strpos($primerCol, 'codigo') !== false ||
                            strpos($primerCol, 'puente') !== false ||
                            strpos($primerCol, 'j051') !== false ||
                            strpos($primerCol, 'master') !== false ||
                            strpos($primerCol, 'total general') !== false
                        ) {
                            continue;
                        }

                        if (count($datos) < 2) continue;

                        $codigo      = trim($datos[0]);
                        $producto    = trim($datos[1] ?? 'Sin Descripción');
                        $categoria   = trim($datos[2] ?? 'General');
                        $tipo        = trim($datos[3] ?? 'Artículo');
                        $marca       = trim($datos[4] ?? 'Sin Marca');
                        $existencias = limpiarNumero($datos[5] ?? 0);
                        $costo       = limpiarNumero($datos[6] ?? 0);

                        if (count($datos) >= 9) {
                            $precio = limpiarNumero($datos[8] ?? 0);
                        } else {
                            $precio = limpiarNumero($datos[7] ?? 0);
                        }

                        if (empty($codigo)) continue;

                        $stmtInst->execute([
                            ':evento_id'   => $nuevoId,
                            ':codigo'      => $codigo,
                            ':producto'    => $producto,
                            ':cat'         => $categoria,
                            ':tipo'        => $tipo,
                            ':marca'       => $marca,
                            ':existencias' => $existencias,
                            ':costo'       => $costo,
                            ':precio'      => $precio
                        ]);
                        $insertados++;
                    }
                    $conArchivo = true;
                }
            }

            if ($conArchivo) {
                $_SESSION['flash_success'] = "¡Auditoría <b>" . htmlspecialchars($nombre) . "</b> creada con éxito y <b>{$insertados} SKUs</b> cargados automáticamente del archivo ERP!";
                header("Location: conteo.php");
            } else {
                $_SESSION['flash_success'] = "¡Auditoría <b>" . htmlspecialchars($nombre) . "</b> creada! Ahora sube la plantilla de existencias del ERP.";
                header("Location: importar.php");
            }
            exit;
        } catch (Exception $e) {
            $error = "Error al crear la auditoría: " . $e->getMessage();
        }
    }
}

// Acción: Cerrar y Congelar Auditoría (Solo Admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cerrar_evento_id'])) {
    if (!esAdmin()) {
        $_SESSION['flash_error'] = "Solo los Administradores pueden finalizar eventos de auditoría.";
        header("Location: eventos.php");
        exit;
    }

    $idCerrar = intval($_POST['cerrar_evento_id']);
    try {
        $pdo = getPDOConnection();
        $stmt = $pdo->prepare("UPDATE eventos_auditoria SET estado = 'cerrada', fecha_cierre = NOW() WHERE id = :id");
        $stmt->execute([':id' => $idCerrar]);

        $_SESSION['flash_success'] = "La auditoría ha sido <b>CERRADA Y CONGELADA</b>. Sus registros e histórico se conservan inmutables.";
        header("Location: eventos.php");
        exit;
    } catch (Exception $e) {
        $error = "Error al cerrar la auditoría: " . $e->getMessage();
    }
}

// Acción: Asignar o Actualizar Personal en la Auditoría
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['asignar_personal'])) {
    if (!esAdmin()) {
        $_SESSION['flash_error'] = "Solo los Administradores pueden gestionar la asignación de equipo.";
        header("Location: eventos.php");
        exit;
    }

    $usuarioId = intval($_POST['usuario_id'] ?? 0);
    $rolEvento = trim($_POST['rol_evento'] ?? 'contador');
    $bodegaAsig = trim($_POST['bodega_asignada'] ?? '');
    $eventoId = $eventoActual ? $eventoActual['id'] : 0;

    if ($usuarioId <= 0 || $eventoId <= 0) {
        $error = "Por favor selecciona un usuario válido para la asignación.";
    } else {
        try {
            $pdo = getPDOConnection();
            $stmtAsig = $pdo->prepare("
                INSERT INTO asignaciones_auditoria (evento_id, usuario_id, rol_evento, bodega_asignada, fecha_asignacion)
                VALUES (:ev, :u, :rol, :bod, NOW())
                ON DUPLICATE KEY UPDATE 
                rol_evento = VALUES(rol_evento),
                bodega_asignada = VALUES(bodega_asignada)
            ");
            $stmtAsig->execute([
                ':ev'  => $eventoId,
                ':u'   => $usuarioId,
                ':rol' => $rolEvento,
                ':bod' => !empty($bodegaAsig) ? $bodegaAsig : null
            ]);

            $_SESSION['flash_success'] = "Personal asignado correctamente al equipo de auditoría.";
            header("Location: eventos.php#seccionEquipoAsignado");
            exit;
        } catch (Exception $e) {
            $error = "Error al asignar personal: " . $e->getMessage();
        }
    }
}

// Acción: Eliminar Asignación de Personal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_asignacion_id'])) {
    if (!esAdmin()) {
        $_SESSION['flash_error'] = "Solo los Administradores pueden remover integrantes del equipo.";
        header("Location: eventos.php");
        exit;
    }

    $asigId = intval($_POST['eliminar_asignacion_id']);
    try {
        $pdo = getPDOConnection();
        $stmtDel = $pdo->prepare("DELETE FROM asignaciones_auditoria WHERE id = :id");
        $stmtDel->execute([':id' => $asigId]);

        $_SESSION['flash_success'] = "Integrante removido del equipo de auditoría.";
        header("Location: eventos.php#seccionEquipoAsignado");
        exit;
    } catch (Exception $e) {
        $error = "Error al eliminar asignación: " . $e->getMessage();
    }
}

// Acción: Seleccionar / Cambiar Auditoría Activa
if (isset($_GET['seleccionar_id'])) {
    $idSel = intval($_GET['seleccionar_id']);
    $_SESSION['evento_id'] = $idSel;
    $_SESSION['flash_success'] = "Se ha cambiado la vista activa a la auditoría seleccionada.";
    
    $redirectUrl = !empty($_GET['redirect']) ? $_GET['redirect'] : 'eventos.php';
    // Prevenir redirección abierta (Open Redirect)
    if (strpos($redirectUrl, 'http') === 0 || strpos($redirectUrl, '//') === 0) {
        $redirectUrl = 'eventos.php';
    }
    header("Location: " . $redirectUrl);
    exit;
}

// Obtener lista completa de eventos con métricas de resumen
$eventosList = [];
$todosUsuarios = [];
try {
    $pdo = getPDOConnection();
    $sql = "
        SELECT 
            e.*,
            COUNT(DISTINCT p.id) AS total_skus_teoricos,
            COALESCE(SUM(c.cantidad_fisica), 0) AS total_unidades_contadas,
            COUNT(DISTINCT c.id) AS total_lecturas_tomadas
        FROM eventos_auditoria e
        LEFT JOIN productos_sistema p ON e.id = p.evento_id
        LEFT JOIN conteos_fisicos c ON e.id = c.evento_id
        GROUP BY e.id
        ORDER BY e.fecha_inicio DESC
    ";
    $stmt = $pdo->query($sql);
    $eventosList = $stmt->fetchAll();

    $stmtUsers = $pdo->query("SELECT id, nombre, username, perfil FROM usuarios WHERE estado = 1 ORDER BY nombre ASC");
    $todosUsuarios = $stmtUsers->fetchAll();

} catch (Exception $e) {
    $error = "Error al cargar la lista de auditorías: " . $e->getMessage();
}

$eventoActual = obtenerEventoActivo();
$productosCargados = [];
$bodegasUnicas = [];
$equipoAsignado = [];

if ($eventoActual) {
    try {
        $stmtCat = $pdo->prepare("
            SELECT id, bodega, codigo_producto, producto, existencias_sistema 
            FROM productos_sistema 
            WHERE evento_id = :ev 
            ORDER BY producto ASC
        ");
        $stmtCat->execute([':ev' => $eventoActual['id']]);
        $productosCargados = $stmtCat->fetchAll();

        $stmtBodegas = $pdo->prepare("
            SELECT DISTINCT COALESCE(bodega, 'Bodega Principal') AS bodega 
            FROM productos_sistema 
            WHERE evento_id = :ev 
            ORDER BY bodega ASC
        ");
        $stmtBodegas->execute([':ev' => $eventoActual['id']]);
        $bodegasUnicas = $stmtBodegas->fetchAll(PDO::FETCH_COLUMN);

        $stmtEq = $pdo->prepare("
            SELECT a.id AS asignacion_id, a.rol_evento, a.bodega_asignada, a.fecha_asignacion,
                   u.id AS usuario_id, u.nombre, u.username, u.perfil,
                   COUNT(c.id) AS lecturas_realizadas,
                   COALESCE(SUM(c.cantidad_fisica), 0) AS unidades_contadas
            FROM asignaciones_auditoria a
            INNER JOIN usuarios u ON a.usuario_id = u.id
            LEFT JOIN conteos_fisicos c ON (c.usuario_id = u.id AND c.evento_id = a.evento_id)
            WHERE a.evento_id = :ev
            GROUP BY a.id, u.id
            ORDER BY a.fecha_asignacion ASC
        ");
        $stmtEq->execute([':ev' => $eventoActual['id']]);
        $equipoAsignado = $stmtEq->fetchAll();

    } catch (Exception $ex) {}
}

include __DIR__ . '/includes/header.php';
?>

<div class="row mb-4 align-items-center">
    <div class="col-md-7">
        <h3 class="fw-bold text-dark mb-1"><i class="bi bi-calendar-event-fill text-primary me-2"></i>Gestión & Histórico de Auditorías</h3>
        <p class="text-muted mb-0">Crea un evento de toma física (2-3 veces al año) y vincula su carga de existencias ERP.</p>
    </div>
    <div class="col-md-5 text-md-end mt-3 mt-md-0">
        <?php if (esAdmin()): ?>
            <button type="button" class="btn btn-primary btn-sm rounded-pill px-3 fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#modalNuevaAuditoria" style="background-color: var(--primary-color);">
                <i class="bi bi-plus-circle-fill me-1"></i> Crear Nueva Auditoría
            </button>
        <?php endif; ?>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Tarjeta Resumen Auditoría Activa -->
<?php if ($eventoActual): ?>
    <div class="card card-custom mb-4 border-start border-4 <?= $eventoActual['estado'] === 'activa' ? 'border-success' : 'border-secondary' ?>">
        <div class="card-body p-4">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <?php if ($eventoActual['estado'] === 'activa'): ?>
                            <span class="badge bg-success-subtle text-success fw-bold px-2 py-1"><i class="bi bi-play-circle-fill me-1"></i>AUDITORÍA EN CURSO (ACTIVA)</span>
                        <?php else: ?>
                            <span class="badge bg-secondary-subtle text-secondary fw-bold px-2 py-1"><i class="bi bi-lock-fill me-1"></i>AUDITORÍA CERRADA (HISTÓRICO)</span>
                        <?php endif; ?>
                        <span class="text-muted small">&bull; ID: #<?= $eventoActual['id'] ?></span>
                    </div>
                    <h4 class="fw-bold text-dark mb-1"><?= htmlspecialchars($eventoActual['nombre_evento']) ?></h4>
                    <div class="text-muted small">
                        <i class="bi bi-geo-alt-fill text-primary me-1"></i><b>Bodega:</b> <?= htmlspecialchars($eventoActual['bodega_sucursal']) ?> &bull; 
                        <i class="bi bi-clock-history me-1"></i><b>Inicio:</b> <?= date('d/m/Y H:i', strtotime($eventoActual['fecha_inicio'])) ?>
                        <?php if ($eventoActual['fecha_cierre']): ?>
                            &bull; <b>Cierre:</b> <?= date('d/m/Y H:i', strtotime($eventoActual['fecha_cierre'])) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-4 text-md-end mt-3 mt-md-0">
                    <?php if ($eventoActual['estado'] === 'activa'): ?>
                        <a href="importar.php" class="btn btn-primary btn-sm rounded-pill fw-semibold me-1" style="background-color: var(--primary-color);">
                            <i class="bi bi-file-earmark-arrow-up me-1"></i> Cargar Existencias ERP
                        </a>
                    <?php endif; ?>
                    <a href="reporte.php?evento_id=<?= $eventoActual['id'] ?>" class="btn btn-outline-primary btn-sm rounded-pill fw-semibold me-1">
                        <i class="bi bi-bar-chart-line-fill me-1"></i> Conciliación
                    </a>
                    <?php if ($eventoActual['estado'] === 'activa' && esAdmin()): ?>
                        <form method="POST" action="eventos.php" class="d-inline" onsubmit="return confirm('¿Confirmas finalizar y cerrar esta auditoría? Quedará congelada para archivo histórico.');">
                            <input type="hidden" name="cerrar_evento_id" value="<?= $eventoActual['id'] ?>">
                            <button type="submit" class="btn btn-outline-danger btn-sm rounded-pill fw-semibold">
                                <i class="bi bi-lock-fill me-1"></i> Cerrar
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Tarjeta Equipo y Personal Asignado a la Auditoría -->
<div class="card card-custom mb-4" id="seccionEquipoAsignado">
    <div class="card-header-custom d-flex justify-content-between align-items-center">
        <span><i class="bi bi-people-fill me-2 text-primary"></i>Equipo de Personal Asignado — <b><?= htmlspecialchars($eventoActual['nombre_evento'] ?? 'Sin Selección') ?></b></span>
        <div>
            <?php if (esAdmin() && $eventoActual && $eventoActual['estado'] === 'activa'): ?>
                <button type="button" class="btn btn-primary btn-sm rounded-pill px-3 fw-bold shadow-sm me-2" data-bs-toggle="modal" data-bs-target="#modalAsignarPersonal">
                    <i class="bi bi-person-plus-fill me-1"></i> Asignar Integrante
                </button>
            <?php endif; ?>
            <span class="badge bg-light text-muted border"><?= count($equipoAsignado) ?> integrantes</span>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-custom align-middle mb-0">
                <thead>
                    <tr>
                        <th>Integrante / Usuario</th>
                        <th>Perfil Global</th>
                        <th class="text-center">Rol en esta Auditoría</th>
                        <th>Bodega Asignada</th>
                        <th class="text-center">Escaneos / Conteo</th>
                        <th class="text-end">Unidades Contadas</th>
                        <?php if (esAdmin() && $eventoActual && $eventoActual['estado'] === 'activa'): ?>
                            <th class="text-center">Acciones</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($equipoAsignado)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted">
                                <i class="bi bi-person-x fs-3 d-block mb-2 text-black-50"></i>
                                No hay personal asignado explícitamente a este equipo aún.
                                <?php if (esAdmin()): ?>
                                    Haz clic en <b>Asignar Integrante</b> arriba para habilitar auditores/contadores.
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($equipoAsignado as $eq): ?>
                            <?php
                            $rolBadge = 'bg-secondary-subtle text-secondary';
                            $rolTexto = 'Contador Físico';
                            if ($eq['rol_evento'] === 'supervisor') {
                                $rolBadge = 'bg-primary-subtle text-primary';
                                $rolTexto = 'Supervisor de Zona';
                            } elseif ($eq['rol_evento'] === 'auditor_lider') {
                                $rolBadge = 'bg-purple-subtle text-purple';
                                $rolTexto = 'Auditor Líder';
                            }
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-bold text-dark"><i class="bi bi-person-circle me-2 text-secondary"></i><?= htmlspecialchars($eq['nombre']) ?></div>
                                    <div class="small text-muted">@<?= htmlspecialchars($eq['username']) ?></div>
                                </td>
                                <td>
                                    <span class="badge badge-role-<?= htmlspecialchars($eq['perfil']) ?> text-uppercase" style="font-size: 0.7rem;">
                                        <?= htmlspecialchars($eq['perfil']) ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="badge <?= $rolBadge ?> fw-bold px-3 py-1">
                                        <i class="bi bi-shield-check me-1"></i><?= $rolTexto ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($eq['bodega_asignada'])): ?>
                                        <span class="bodega-badge"><i class="bi bi-building"></i><?= htmlspecialchars($eq['bodega_asignada']) ?></span>
                                    <?php else: ?>
                                        <span class="small text-muted">Todas las Bodegas</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-light text-dark border font-monospace">
                                        <?= number_format($eq['lecturas_realizadas']) ?> lecturas
                                    </span>
                                </td>
                                <td class="text-end fw-bold text-dark">
                                    <?= number_format($eq['unidades_contadas'], 2) ?> uds
                                </td>
                                <?php if (esAdmin() && $eventoActual && $eventoActual['estado'] === 'activa'): ?>
                                    <td class="text-center">
                                        <form method="POST" action="eventos.php" onsubmit="return confirm('¿Deseas remover a este integrante del equipo de auditoría?');" class="d-inline">
                                            <input type="hidden" name="eliminar_asignacion_id" value="<?= $eq['asignacion_id'] ?>">
                                            <button type="submit" class="btn btn-link text-danger p-0 border-0 ms-1" title="Remover Integrante">
                                                <i class="bi bi-trash3 fs-6"></i>
                                            </button>
                                        </form>
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

<!-- Tabla de Histórico de Auditorías -->
<div class="card card-custom">
    <div class="card-header-custom d-flex justify-content-between align-items-center">
        <span><i class="bi bi-archive-fill me-2 text-primary"></i>Histórico de Auditorías Realizadas</span>
        <span class="badge bg-light text-muted border"><?= count($eventosList) ?> eventos registrados</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-custom align-middle mb-0">
                <thead>
                    <tr>
                        <th>Nombre del Evento</th>
                        <th>Bodega / Sucursal</th>
                        <th class="text-center">SKUs Teóricos</th>
                        <th class="text-center">Lecturas Tomadas</th>
                        <th class="text-center">Estado</th>
                        <th>Fecha de Inicio</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($eventosList)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted">
                                No hay eventos de auditoría registrados aún.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($eventosList as $ev): ?>
                            <?php $esSeleccionado = ($eventoActual && $eventoActual['id'] == $ev['id']); ?>
                            <tr class="<?= $esSeleccionado ? 'table-active' : '' ?>">
                                <td>
                                    <div class="fw-bold text-dark">
                                        <?= htmlspecialchars($ev['nombre_evento']) ?>
                                        <?php if ($esSeleccionado): ?>
                                            <span class="badge bg-primary ms-1" style="font-size: 0.65rem;">EN VISTA</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($ev['notas'])): ?>
                                        <div class="small text-muted fst-italic"><?= htmlspecialchars($ev['notas']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="small fw-semibold text-secondary"><i class="bi bi-building me-1"></i><?= htmlspecialchars($ev['bodega_sucursal']) ?></span>
                                </td>
                                <td class="text-center fw-semibold text-dark">
                                    <?= number_format($ev['total_skus_teoricos']) ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-light text-dark border font-monospace">
                                        <?= number_format($ev['total_lecturas_tomadas']) ?> escaneos
                                    </span>
                                </td>
                                <td class="text-center">
                                    <?php if ($ev['estado'] === 'activa'): ?>
                                        <span class="badge bg-success-subtle text-success fw-bold"><i class="bi bi-play-fill me-1"></i>ACTIVA</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary-subtle text-secondary fw-semibold"><i class="bi bi-lock-fill me-1"></i>CERRADA</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-muted">
                                    <?= date('d/m/Y H:i', strtotime($ev['fecha_inicio'])) ?>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm">
                                        <a href="eventos.php?seleccionar_id=<?= $ev['id'] ?>#seccionCatalogoProductos" class="btn btn-sm <?= $esSeleccionado ? 'btn-primary fw-bold' : 'btn-outline-primary' ?>" title="Ver catálogo de existencias cargadas de esta auditoría">
                                            <i class="bi bi-boxes me-1"></i> Ver Catálogo
                                        </a>
                                        <a href="importar.php" class="btn btn-outline-success" title="Cargar/Subir existencias del ERP">
                                            <i class="bi bi-file-earmark-arrow-up"></i>
                                        </a>
                                        <a href="reporte.php?evento_id=<?= $ev['id'] ?>" class="btn btn-outline-primary" title="Ver reporte de conciliación">
                                            <i class="bi bi-bar-chart-line-fill"></i>
                                        </a>
                                        <a href="informes.php?evento_id=<?= $ev['id'] ?>" class="btn btn-outline-info" title="Ver centro de informes">
                                            <i class="bi bi-file-earmark-bar-graph-fill"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Catálogo de Productos Cargados en la Auditoría en Vista -->
<div class="card card-custom mt-4" id="seccionCatalogoProductos">
    <div class="card-header-custom d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-boxes text-primary fs-5"></i>
            <span class="fw-bold">Catálogo de Productos Cargados (Existencias ERP)</span>
        </div>

        <div class="d-flex align-items-center gap-2">
            <span class="small text-muted d-none d-sm-inline">Auditoría:</span>
            <select class="form-select form-select-sm border-primary fw-semibold" style="width: auto; min-width: 240px; background-color: #f8fafc;" onchange="location = this.value;">
                <?php foreach ($eventosList as $ev): ?>
                    <option value="eventos.php?seleccionar_id=<?= $ev['id'] ?>#seccionCatalogoProductos" <?= ($eventoActual && $eventoActual['id'] == $ev['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($ev['nombre_evento']) ?> (<?= number_format($ev['total_skus_teoricos']) ?> SKUs)
                    </option>
                <?php endforeach; ?>
            </select>
            <span class="badge bg-primary text-white" id="contadorVisible"><?= number_format(count($productosCargados)) ?> productos</span>
        </div>
    </div>

    <?php if (!empty($productosCargados)): ?>
    <!-- Barra de búsqueda y filtros -->
    <div class="px-3 py-3 border-bottom bg-light d-flex flex-wrap gap-2 align-items-center">
        <div class="input-group" style="max-width:340px;">
            <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
            <input type="text" id="buscarProducto" class="form-control form-control-sm" placeholder="Buscar por código o descripción..." oninput="filtrarTabla()">
        </div>
        <select id="filtroBodega" class="form-select form-select-sm" style="max-width:220px;" onchange="filtrarTabla()">
            <option value="">— Todas las bodegas —</option>
            <?php foreach ($bodegasUnicas as $bod): ?>
                <option value="<?= htmlspecialchars($bod) ?>"><?= htmlspecialchars($bod) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-outline-secondary btn-sm" onclick="limpiarFiltros()"><i class="bi bi-x-circle me-1"></i>Limpiar</button>
        <span class="ms-auto text-muted small">Mostrando <b id="spanMostrando">0</b> de <b><?= number_format(count($productosCargados)) ?></b></span>
    </div>
    <?php endif; ?>

    <div class="card-body p-0">
        <div class="table-responsive" style="max-height:520px; overflow-y:auto;">
            <table class="table table-hover table-custom align-middle mb-0" id="tablaProductos">
                <thead style="position:sticky;top:0;z-index:1;">
                    <tr>
                        <th>Bodega</th>
                        <th>SKU / Código</th>
                        <th>Descripción del Producto</th>
                        <th class="text-end">Stock Teórico</th>
                    </tr>
                </thead>
                <tbody id="tablaBody">
                    <?php if (empty($productosCargados)): ?>
                        <tr>
                            <td colspan="4" class="text-center py-5 text-muted">
                                <i class="bi bi-inbox fs-2 d-block mb-2 text-black-50"></i>
                                Aún no se han cargado existencias para esta auditoría. Ve a <a href="importar.php" class="fw-bold text-primary">Carga Stock ERP</a> para importar el archivo del sistema.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($productosCargados as $p): ?>
                            <tr
                                data-bodega="<?= htmlspecialchars(strtolower($p['bodega'] ?? 'bodega principal')) ?>"
                                data-texto="<?= htmlspecialchars(strtolower($p['codigo_producto'] . ' ' . $p['producto'])) ?>"
                            >
                                <td><span class="bodega-badge"><i class="bi bi-building"></i><?= htmlspecialchars($p['bodega'] ?? 'Bodega Principal') ?></span></td>
                                <td><span class="sku-badge"><?= htmlspecialchars($p['codigo_producto']) ?></span></td>
                                <td class="fw-semibold text-dark"><?= htmlspecialchars($p['producto']) ?></td>
                                <td class="text-end fw-bold text-primary"><?= number_format($p['existencias_sistema'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if (!empty($productosCargados)): ?>
        <!-- Paginación -->
        <div class="d-flex justify-content-between align-items-center px-3 py-2 border-top bg-light">
            <button class="btn btn-outline-secondary btn-sm" id="btnAnterior" onclick="cambiarPagina(-1)" disabled>
                <i class="bi bi-chevron-left"></i> Anterior
            </button>
            <span class="small text-muted">Página <b id="paginaActual">1</b> de <b id="totalPaginas">1</b></span>
            <button class="btn btn-outline-secondary btn-sm" id="btnSiguiente" onclick="cambiarPagina(1)">
                Siguiente <i class="bi bi-chevron-right"></i>
            </button>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Paginación y filtrado del catálogo de productos cargados
const POR_PAGINA = 50;
let paginaActual = 1;
let filasVisibles = [];

function filtrarTabla() {
    const elBusqueda = document.getElementById('buscarProducto');
    const elBodega   = document.getElementById('filtroBodega');
    if (!elBusqueda || !elBodega) return;

    const termino  = elBusqueda.value.toLowerCase().trim();
    const bodega   = elBodega.value.toLowerCase();
    const filas    = Array.from(document.querySelectorAll('#tablaBody tr[data-texto]'));

    filasVisibles = filas.filter(tr => {
        const matchTexto  = !termino || tr.dataset.texto.includes(termino);
        const matchBodega = !bodega  || tr.dataset.bodega === bodega;
        return matchTexto && matchBodega;
    });

    paginaActual = 1;
    renderPagina();
}

function renderPagina() {
    const total = filasVisibles.length;
    const totalPags = Math.max(1, Math.ceil(total / POR_PAGINA));
    const inicio = (paginaActual - 1) * POR_PAGINA;
    const fin    = inicio + POR_PAGINA;

    document.querySelectorAll('#tablaBody tr[data-texto]').forEach(tr => tr.style.display = 'none');
    filasVisibles.slice(inicio, fin).forEach(tr => tr.style.display = '');

    if (document.getElementById('paginaActual')) document.getElementById('paginaActual').textContent  = paginaActual;
    if (document.getElementById('totalPaginas')) document.getElementById('totalPaginas').textContent  = totalPags;
    if (document.getElementById('spanMostrando')) document.getElementById('spanMostrando').textContent = total;
    if (document.getElementById('contadorVisible')) document.getElementById('contadorVisible').textContent = total + ' productos';
    if (document.getElementById('btnAnterior')) document.getElementById('btnAnterior').disabled  = paginaActual <= 1;
    if (document.getElementById('btnSiguiente')) document.getElementById('btnSiguiente').disabled = paginaActual >= totalPags;
}

function cambiarPagina(dir) {
    const totalPags = Math.ceil(filasVisibles.length / POR_PAGINA);
    paginaActual = Math.min(Math.max(1, paginaActual + dir), totalPags);
    renderPagina();
    document.getElementById('tablaProductos').scrollIntoView({behavior:'smooth', block:'start'});
}

function limpiarFiltros() {
    if (document.getElementById('buscarProducto')) document.getElementById('buscarProducto').value = '';
    if (document.getElementById('filtroBodega')) document.getElementById('filtroBodega').value   = '';
    filtrarTabla();
}

document.addEventListener('DOMContentLoaded', filtrarTabla);
</script>

<!-- Modal Crear Nueva Auditoría con Carga Directa ERP -->
<div class="modal fade" id="modalNuevaAuditoria" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle-fill me-2 text-primary"></i>Crear Nuevo Evento de Auditoría</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="eventos.php" enctype="multipart/form-data">
                <input type="hidden" name="crear_evento" value="1">
                
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Nombre de la Auditoría:</label>
                            <input type="text" class="form-control" name="nombre_evento" placeholder="Ej: Auditoría Física Q3 2026 / Cierre de Año" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Bodega / Sucursal:</label>
                            <input type="text" class="form-control" name="bodega_sucursal" value="Bodega León" placeholder="Ej: Bodega Principal" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold small">Notas u Observaciones:</label>
                            <textarea class="form-control" name="notas" rows="2" placeholder="Ej: Toma física anual con presencia de auditoría externa."></textarea>
                        </div>

                        <!-- Carga Directa de Archivo ERP opcional -->
                        <div class="col-12">
                            <div class="border rounded p-3 bg-light">
                                <label class="form-label fw-bold text-primary small d-flex align-items-center gap-1 mb-1">
                                    <i class="bi bi-file-earmark-excel-fill"></i> Adjuntar Archivo Excel / CSV de Existencias del ERP (Opcional):
                                </label>
                                <input type="file" class="form-control" name="archivo_csv" accept=".xls, .xlsx, .csv, .txt">
                                <div class="form-text mt-1">
                                    Si seleccionas tu archivo Excel (.xls) o CSV ahora, el sistema creará la auditoría y cargará el stock teórico inmediatamente. Si lo dejas en blanco, podrás subirlo en el siguiente paso.
                                </div>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="form-check bg-white p-3 rounded border">
                                <input class="form-check-input ms-0 me-2" type="checkbox" name="cerrar_anteriores" id="cerrarAnteriores" value="1" checked>
                                <label class="form-check-label fw-semibold text-dark small" for="cerrarAnteriores">
                                    Cerrar automáticamente cualquier auditoría activa anterior
                                </label>
                                <div class="form-text ms-4">Garantiza que la nueva auditoría sea la única activa en curso.</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary fw-bold px-4" style="background-color: var(--primary-color);">Crear e Iniciar Auditoría</button>
                </div>
            </form>
</div>

<!-- Modal Asignar Personal -->
<div class="modal fade" id="modalAsignarPersonal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-person-plus-fill me-2 text-primary"></i>Asignar Integrante a la Auditoría</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="eventos.php">
                <input type="hidden" name="asignar_personal" value="1">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Seleccionar Usuario / Auditor:</label>
                        <select name="usuario_id" class="form-select" required>
                            <option value="">— Elegir usuario de la lista —</option>
                            <?php foreach ($todosUsuarios as $u): ?>
                                <option value="<?= $u['id'] ?>">
                                    <?= htmlspecialchars($u['nombre']) ?> (@<?= htmlspecialchars($u['username']) ?> - Perfil: <?= strtoupper($u['perfil']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Rol en esta Auditoría:</label>
                        <select name="rol_evento" class="form-select" required>
                            <option value="contador">Contador Físico (Captura de Lecturas)</option>
                            <option value="supervisor">Supervisor de Zona / Re-conteo</option>
                            <option value="auditor_lider">Auditor Líder / Coordinador</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Bodega Preferente / Asignada (Opcional):</label>
                        <select name="bodega_asignada" class="form-select">
                            <option value="">— Todas las Bodegas —</option>
                            <?php foreach ($bodegasUnicas as $bod): ?>
                                <option value="<?= htmlspecialchars($bod) ?>"><?= htmlspecialchars($bod) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Servirá para orientar al personal hacia su zona asignada.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary fw-bold" style="background-color: var(--primary-color);">Guardar Asignación</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
