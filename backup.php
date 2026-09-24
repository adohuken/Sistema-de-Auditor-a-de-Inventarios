<?php
/**
 * Módulo de Configuración, Respaldo (Backup), Restauración y Reseteo de Base de Datos
 * Sistema de Auditoría de Inventarios
 */

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/auth.php';

// Exigir permiso al módulo de backup
exigirPermisoModulo('backup');

$pdo = getPDOConnection();
$error = null;
$exito = null;

// Asegurar existencia del directorio de respaldos
$backupsDir = __DIR__ . '/backups';
if (!file_exists($backupsDir)) {
    @mkdir($backupsDir, 0755, true);
    // Proteger directorio con index.html preventivo
    @file_put_contents($backupsDir . '/index.html', '<!-- Acceso Restringido -->');
}

// -------------------------------------------------------------------------
// FUNCIONES AUXILIARES DE BACKUP, RESTAURACIÓN Y ESTADÍSTICAS
// -------------------------------------------------------------------------

/**
 * Genera la cadena SQL completa o parcial para respaldo
 */
function generarBackupSQLPDO($pdo, $tablasSeleccionadas = []) {
    $dbName = DB_NAME;
    
    if (empty($tablasSeleccionadas)) {
        $stmt = $pdo->query("SHOW TABLES");
        $tablasSeleccionadas = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    $sql = "-- ========================================================\n";
    $sql .= "-- RESPALDO DE BASE DE DATOS: `{$dbName}`\n";
    $sql .= "-- FECHA DE GENERACIÓN: " . date('Y-m-d H:i:s') . "\n";
    $sql .= "-- SISTEMA DE AUDITORÍA DE INVENTARIOS FÍSICOS\n";
    $sql .= "-- ========================================================\n\n";
    $sql .= "SET FOREIGN_KEY_CHECKS=0;\n";
    $sql .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
    $sql .= "SET time_zone = \"+00:00\";\n\n";
    
    foreach ($tablasSeleccionadas as $tabla) {
        // Estructura de la tabla
        $stmtCreate = $pdo->query("SHOW CREATE TABLE `" . $tabla . "`");
        $rowCreate = $stmtCreate->fetch(PDO::FETCH_ASSOC);
        $createSql = $rowCreate['Create Table'] ?? '';
        
        $sql .= "-- --------------------------------------------------------\n";
        $sql .= "-- Estructura de tabla para `" . $tabla . "`\n";
        $sql .= "-- --------------------------------------------------------\n";
        $sql .= "DROP TABLE IF EXISTS `" . $tabla . "`;\n";
        $sql .= $createSql . ";\n\n";
        
        // Datos de la tabla
        $stmtData = $pdo->query("SELECT * FROM `" . $tabla . "`");
        $rows = $stmtData->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($rows)) {
            $sql .= "-- Volcado de datos para la tabla `" . $tabla . "` (" . count($rows) . " registros)\n";
            $cols = array_keys($rows[0]);
            $quotedCols = array_map(function($c) { return "`" . $c . "`"; }, $cols);
            
            $sql .= "INSERT INTO `" . $tabla . "` (" . implode(', ', $quotedCols) . ") VALUES\n";
            
            $vals = [];
            foreach ($rows as $r) {
                $rowVals = [];
                foreach ($r as $colVal) {
                    if ($colVal === null) {
                        $rowVals[] = "NULL";
                    } else {
                        $rowVals[] = $pdo->quote($colVal);
                    }
                }
                $vals[] = "(" . implode(', ', $rowVals) . ")";
            }
            
            $sql .= implode(",\n", $vals) . ";\n\n";
        }
    }
    
    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
    return $sql;
}

/**
 * Ejecuta sentencias SQL de forma secuencial y limpia
 */
function importarSQLPDO($pdo, $sqlContent) {
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0;");
    
    // Eliminar comentarios multilínea (/* ... */)
    $sqlClean = preg_replace('/(?:\/\*(?:[^*]|(?:\*+[^*\/]))*\*+\/)/', '', $sqlContent);
    
    $lines = explode("\n", $sqlClean);
    $query = '';
    $consultasEjecutadas = 0;
    
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || strpos($trimmed, '--') === 0 || strpos($trimmed, '#') === 0) {
            continue;
        }
        
        $query .= $line . "\n";
        
        if (substr(rtrim($trimmed), -1) === ';') {
            $pdo->exec($query);
            $query = '';
            $consultasEjecutadas++;
        }
    }
    
    if (trim($query) !== '') {
        $pdo->exec($query);
        $consultasEjecutadas++;
    }
    
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1;");
    return $consultasEjecutadas;
}

/**
 * Formatear bytes a legibilidad KB / MB
 */
function formatearBytes($bytes) {
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' Bytes';
}

/**
 * Obtener estadísticas de uso y tamaño de la base de datos
 */
function obtenerEstadisticasBD($pdo) {
    $dbName = DB_NAME;
    $stats = [
        'db_name' => $dbName,
        'size_bytes' => 0,
        'size_formatted' => '0 KB',
        'tables_count' => 0,
        'total_rows' => 0,
        'tables' => []
    ];
    
    try {
        $stmtTables = $pdo->query("SHOW TABLES");
        $tablesList = $stmtTables->fetchAll(PDO::FETCH_COLUMN);
        
        $totalBytes = 0;
        $totalRows = 0;
        
        foreach ($tablesList as $tbl) {
            $countStmt = $pdo->query("SELECT COUNT(*) FROM `" . $tbl . "`");
            $rowsCount = intval($countStmt->fetchColumn());
            
            // Tamaño de datos vía information_schema
            $sizeStmt = $pdo->prepare("
                SELECT (DATA_LENGTH + INDEX_LENGTH) AS total_size, ENGINE 
                FROM information_schema.TABLES 
                WHERE TABLE_SCHEMA = :dbname AND TABLE_NAME = :tbl
            ");
            $sizeStmt->execute([':dbname' => $dbName, ':tbl' => $tbl]);
            $sizeData = $sizeStmt->fetch(PDO::FETCH_ASSOC);
            $tblBytes = intval($sizeData['total_size'] ?? 0);
            $engine = $sizeData['ENGINE'] ?? 'InnoDB';
            
            $totalBytes += $tblBytes;
            $totalRows += $rowsCount;
            
            $stats['tables'][] = [
                'name' => $tbl,
                'engine' => $engine,
                'rows' => $rowsCount,
                'size_bytes' => $tblBytes,
                'size_formatted' => formatearBytes($tblBytes)
            ];
        }
        
        $stats['size_bytes'] = $totalBytes;
        $stats['size_formatted'] = formatearBytes($totalBytes);
        $stats['tables_count'] = count($tablesList);
        $stats['total_rows'] = $totalRows;
    } catch (Exception $e) {
        // En caso de restringirse information_schema
    }
    
    return $stats;
}

// -------------------------------------------------------------------------
// MANEJO DE DESCARGA DIRECTA POR GET
// -------------------------------------------------------------------------
if (isset($_GET['descargar_local']) && !empty($_GET['descargar_local'])) {
    $fileName = basename($_GET['descargar_local']);
    $filePath = $backupsDir . '/' . $fileName;
    
    if (file_exists($filePath) && str_ends_with($fileName, '.sql')) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    } else {
        $_SESSION['flash_error'] = "El archivo de respaldo no existe o no es válido.";
        header("Location: backup.php");
        exit;
    }
}

// -------------------------------------------------------------------------
// PROCESAMIENTO DE ACCIONES POR POST
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    // ACCIÓN 1: GENERAR RESPALDO
    if ($accion === 'generar_backup') {
        $modo = $_POST['modo_backup'] ?? 'completo';
        $destino = $_POST['destino_backup'] ?? 'descargar';
        $tablasSel = [];

        if ($modo === 'personalizado' && isset($_POST['tablas']) && is_array($_POST['tablas'])) {
            $tablasSel = array_map('trim', $_POST['tablas']);
        }

        try {
            $sqlContent = generarBackupSQLPDO($pdo, $tablasSel);
            $timestamp = date('Y-m-d_H-i-s');
            $filename = "backup_inventario_db_" . $timestamp . ".sql";

            if ($destino === 'descargar') {
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Content-Length: ' . strlen($sqlContent));
                echo $sqlContent;
                exit;
            } else {
                $filePath = $backupsDir . '/' . $filename;
                file_put_contents($filePath, $sqlContent);
                $_SESSION['flash_success'] = "Copia de respaldo generada exitosamente y guardada en el servidor como: <code>" . htmlspecialchars($filename) . "</code> (" . formatearBytes(strlen($sqlContent)) . ")";
                header("Location: backup.php?tab=historial");
                exit;
            }
        } catch (Exception $e) {
            $error = "Error al generar el respaldo: " . $e->getMessage();
        }
    }

    // ACCIÓN 2: RESTAURAR DESDE ARCHIVO SUBIDO
    if ($accion === 'restaurar_subida') {
        if (isset($_FILES['sql_file']) && $_FILES['sql_file']['error'] === UPLOAD_ERR_OK) {
            $fileTmpName = $_FILES['sql_file']['tmp_name'];
            $fileName    = $_FILES['sql_file']['name'];
            $ext         = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            if ($ext !== 'sql') {
                $error = "Formato de archivo no permitido. Debe seleccionar un archivo con extensión <b>.sql</b>.";
            } else {
                try {
                    $sqlContent = file_get_contents($fileTmpName);
                    if (empty($sqlContent)) {
                        throw new Exception("El archivo .sql subido está vacío.");
                    }
                    $ejecutadas = importarSQLPDO($pdo, $sqlContent);
                    $_SESSION['flash_success'] = "Restauración completada con éxito. Se ejecutaron <b>{$ejecutadas}</b> consultas en la base de datos.";
                    header("Location: backup.php?tab=restaurar");
                    exit;
                } catch (Exception $e) {
                    $error = "Error al ejecutar la restauración del archivo: " . $e->getMessage();
                }
            }
        } else {
            $error = "Por favor selecciona un archivo .sql válido para subir.";
        }
    }

    // ACCIÓN 3: RESTAURAR DESDE HISTORIAL LOCAL
    if ($accion === 'restaurar_local') {
        $fileName = basename($_POST['filename'] ?? '');
        $filePath = $backupsDir . '/' . $fileName;

        if (!empty($fileName) && file_exists($filePath) && str_ends_with($fileName, '.sql')) {
            try {
                $sqlContent = file_get_contents($filePath);
                $ejecutadas = importarSQLPDO($pdo, $sqlContent);
                $_SESSION['flash_success'] = "Base de datos restaurada correctamente desde el respaldo: <code>" . htmlspecialchars($fileName) . "</code> ({$ejecutadas} sentencias ejecutadas).";
                header("Location: backup.php?tab=historial");
                exit;
            } catch (Exception $e) {
                $error = "Error al restaurar desde el archivo local: " . $e->getMessage();
            }
        } else {
            $error = "El archivo de respaldo seleccionado no existe en el servidor.";
        }
    }

    // ACCIÓN 4: ELIMINAR RESPALDO LOCAL
    if ($accion === 'eliminar_local') {
        $fileName = basename($_POST['filename'] ?? '');
        $filePath = $backupsDir . '/' . $fileName;

        if (!empty($fileName) && file_exists($filePath) && str_ends_with($fileName, '.sql')) {
            unlink($filePath);
            $_SESSION['flash_success'] = "Archivo de respaldo <code>" . htmlspecialchars($fileName) . "</code> eliminado del servidor.";
            header("Location: backup.php?tab=historial");
            exit;
        } else {
            $error = "El archivo especificado no pudo ser encontrado.";
        }
    }

    // ACCIÓN 5: RESETEO CONTROLADO DE BASE DE DATOS
    if ($accion === 'reset_db') {
        $nivelReset   = $_POST['nivel_reset'] ?? '';
        $confirmacion = strtoupper(trim($_POST['confirmacion'] ?? ''));

        if ($confirmacion !== 'CONFIRMAR') {
            $error = "Verificación de seguridad fallida: Debes escribir la palabra exacta <b>CONFIRMAR</b> para proceder con el reseteo.";
        } else {
            try {
                // Paso 1: AUTO-BACKUP PREVENTIVO DE SEGURIDAD
                $backupPreventivoSql = generarBackupSQLPDO($pdo);
                $preventivoName = "auto_backup_previo_reset_" . date('Y-m-d_H-i-s') . ".sql";
                file_put_contents($backupsDir . '/' . $preventivoName, $backupPreventivoSql);

                // Paso 2: Ejecutar el nivel de reseteo correspondiente
                if ($nivelReset === 'reset_conteos') {
                    // Limpiar exclusivamente tabla de conteos físicos
                    $pdo->exec("TRUNCATE TABLE conteos_fisicos");
                    $_SESSION['flash_success'] = "<b>Reseteo de Conteos Físicos Ejecutado:</b> Se han vaciado todos los conteos registrados. El maestro de productos se conserva intacto. Se creó el respaldo preventivo <code>{$preventivoName}</code>.";
                
                } elseif ($nivelReset === 'reset_productos') {
                    // Limpiar conteos y maestro de productos (stock ERP)
                    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
                    $pdo->exec("TRUNCATE TABLE conteos_fisicos");
                    $pdo->exec("TRUNCATE TABLE productos_sistema");
                    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
                    $_SESSION['flash_success'] = "<b>Reseteo de Stock Teórico Ejecutado:</b> Se vaciaron los productos del sistema y conteos. El sistema está listo para importar un nuevo ERP. Se generó el respaldo de seguridad <code>{$preventivoName}</code>.";
                
                } elseif ($nivelReset === 'reset_fabrica') {
                    // Reseteo Total a estado inicial de fábrica
                    $sqlSeed = __DIR__ . '/database.sql';
                    if (file_exists($sqlSeed)) {
                        $seedContent = file_get_contents($sqlSeed);
                        importarSQLPDO($pdo, $seedContent);
                    } else {
                        // Truncar tablas y re-asegurar datos
                        $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
                        $pdo->exec("TRUNCATE TABLE conteos_fisicos");
                        $pdo->exec("TRUNCATE TABLE productos_sistema");
                        $pdo->exec("TRUNCATE TABLE asignaciones_auditoria");
                        $pdo->exec("TRUNCATE TABLE eventos_auditoria");
                        $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
                    }
                    
                    // Asegurar estructura de eventos y usuarios por defecto
                    migrarEstructuraEventos($pdo);
                    asegurarUsuariosBase($pdo);

                    $_SESSION['flash_success'] = "<b>Reseteo Total de Fábrica Completado:</b> La base de datos ha sido re-inicializada a su estado de fábrica original. Las cuentas de usuario administradores fueron aseguradas. Respaldo preventivo guardado como <code>{$preventivoName}</code>.";
                } else {
                    $error = "Selecciona un nivel de reseteo válido.";
                }

                if (!$error) {
                    header("Location: backup.php?tab=reset");
                    exit;
                }
            } catch (Exception $e) {
                $error = "Error durante la ejecución del reseteo: " . $e->getMessage();
            }
        }
    }
}

// -------------------------------------------------------------------------
// CONSULTAS PARA VISTA Y VÍNCULOS
// -------------------------------------------------------------------------
$tabActiva = $_GET['tab'] ?? 'respaldo';
$dbStats = obtenerEstadisticasBD($pdo);

// Leer lista de archivos de respaldo locales
$archivosRespaldos = [];
if (file_exists($backupsDir)) {
    $files = scandir($backupsDir);
    foreach ($files as $f) {
        if ($f !== '.' && $f !== '..' && str_ends_with($f, '.sql')) {
            $fPath = $backupsDir . '/' . $f;
            $archivosRespaldos[] = [
                'name'     => $f,
                'size'     => filesize($fPath),
                'formatted_size' => formatearBytes(filesize($fPath)),
                'date'     => date('d/m/Y H:i:s', filemtime($fPath)),
                'mtime'    => filemtime($fPath),
                'is_auto'  => strpos($f, 'auto_backup_previo_reset_') === 0
            ];
        }
    }
    // Ordenar de más reciente a más antiguo
    usort($archivosRespaldos, function($a, $b) {
        return $b['mtime'] <=> $a['mtime'];
    });
}

include __DIR__ . '/includes/header.php';
?>

<!-- Encabezado de Página -->
<div class="row mb-4 align-items-center">
    <div class="col-md-7">
        <h3 class="fw-bold text-dark mb-1">
            <i class="bi bi-database-gear text-primary me-2"></i>Módulo de Backup, Restauración & Reseteo
        </h3>
        <p class="text-muted mb-0">Gestión de copias de seguridad de la base de datos MySQL, restauración y limpieza del sistema.</p>
    </div>
    <div class="col-md-5 text-md-end mt-3 mt-md-0">
        <span class="badge bg-white text-dark border shadow-sm px-3 py-2 rounded-pill fs-7">
            <i class="bi bi-server text-success me-1"></i> BD: <strong><?= htmlspecialchars($dbStats['db_name']) ?></strong> (<?= $dbStats['size_formatted'] ?>)
        </span>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show shadow-sm border-0 mb-4" role="alert">
        <div class="d-flex align-items-center">
            <i class="bi bi-exclamation-triangle-fill fs-4 me-3"></i>
            <div><?= $error ?></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Tarjetas Métricas de Estado de la Base de Datos -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon primary">
                <i class="bi bi-database"></i>
            </div>
            <div class="stat-info">
                <div class="stat-value"><?= $dbStats['size_formatted'] ?></div>
                <div class="stat-label">Tamaño Total BD</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon success">
                <i class="bi bi-table"></i>
            </div>
            <div class="stat-info">
                <div class="stat-value"><?= $dbStats['tables_count'] ?></div>
                <div class="stat-label">Tablas Registradas</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon warning">
                <i class="bi bi-card-checklist"></i>
            </div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($dbStats['total_rows']) ?></div>
                <div class="stat-label">Registros Totales</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon secondary">
                <i class="bi bi-folder-check"></i>
            </div>
            <div class="stat-info">
                <div class="stat-value"><?= count($archivosRespaldos) ?></div>
                <div class="stat-label">Respaldos Almacenados</div>
            </div>
        </div>
    </div>
</div>

<!-- Navegación Segmentada (Tabs) -->
<ul class="nav nav-segmented" role="tablist">
    <li class="nav-item">
        <a class="nav-link <?= ($tabActiva === 'respaldo') ? 'active' : '' ?>" href="backup.php?tab=respaldo">
            <i class="bi bi-download"></i>
            <span>Generar Backup</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= ($tabActiva === 'restaurar') ? 'active' : '' ?>" href="backup.php?tab=restaurar">
            <i class="bi bi-upload"></i>
            <span>Restaurar BD</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= ($tabActiva === 'historial') ? 'active' : '' ?>" href="backup.php?tab=historial">
            <i class="bi bi-clock-history"></i>
            <span>Historial (<?= count($archivosRespaldos) ?>)</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link text-danger <?= ($tabActiva === 'reset') ? 'active' : '' ?>" href="backup.php?tab=reset">
            <i class="bi bi-radioactive"></i>
            <span>Reseteo BD</span>
        </a>
    </li>
</ul>

<!-- CONTENIDO DE LAS PESTAÑAS -->
<div class="tab-content">
    
    <!-- =========================================================================
         PESTAÑA 1: GENERAR BACKUP
         ========================================================================= -->
    <?php if ($tabActiva === 'respaldo'): ?>
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card card-custom">
                <div class="card-header-custom d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-shield-lock-fill text-primary me-2"></i>Configuración de Copia de Seguridad</span>
                    <span class="badge bg-primary-subtle text-primary fw-semibold"><i class="bi bi-filetype-sql me-1"></i>Script SQL Dump</span>
                </div>
                <div class="card-body p-4">
                    <form method="POST" action="backup.php">
                        <input type="hidden" name="accion" value="generar_backup">

                        <!-- Alcance del Respaldo -->
                        <div class="mb-4">
                            <label class="form-label fw-bold text-dark">1. Alcance de las Tablas a Respaldar:</label>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="form-check card-radio p-3 border rounded-3 bg-light">
                                        <input class="form-check-input ms-0 me-2" type="radio" name="modo_backup" id="modoCompleto" value="completo" checked onclick="toggleTablasSelector(false)">
                                        <label class="form-check-label w-100 cursor-pointer" for="modoCompleto">
                                            <strong class="d-block text-dark mb-1"><i class="bi bi-layers-fill text-primary me-1"></i> Base de Datos Completa</strong>
                                            <span class="small text-muted">Incluye estructura y todos los datos de las <?= $dbStats['tables_count'] ?> tablas.</span>
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-check card-radio p-3 border rounded-3 bg-light">
                                        <input class="form-check-input ms-0 me-2" type="radio" name="modo_backup" id="modoPersonalizado" value="personalizado" onclick="toggleTablasSelector(true)">
                                        <label class="form-check-label w-100 cursor-pointer" for="modoPersonalizado">
                                            <strong class="d-block text-dark mb-1"><i class="bi bi-ui-checks text-primary me-1"></i> Seleccionar Tablas</strong>
                                            <span class="small text-muted">Elige manualmente las tablas específicas que deseas exportar.</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Selector de Tablas (Oculto por defecto) -->
                        <div class="mb-4 d-none" id="selectorTablas">
                            <label class="form-label fw-bold text-dark mb-2">Selecciona las tablas a incluir:</label>
                            <div class="border rounded-3 p-3 bg-white">
                                <div class="row g-2">
                                    <?php foreach ($dbStats['tables'] as $t): ?>
                                    <div class="col-md-6">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="tablas[]" value="<?= htmlspecialchars($t['name']) ?>" id="tbl_<?= $t['name'] ?>" checked>
                                            <label class="form-check-label small" for="tbl_<?= $t['name'] ?>">
                                                <code><?= htmlspecialchars($t['name']) ?></code> (<?= number_format($t['rows']) ?> filas)
                                            </label>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Destino del Archivo -->
                        <div class="mb-4">
                            <label class="form-label fw-bold text-dark">2. Destino de la Copia de Seguridad:</label>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="form-check card-radio p-3 border rounded-3">
                                        <input class="form-check-input ms-0 me-2" type="radio" name="destino_backup" id="destDescargar" value="descargar" checked>
                                        <label class="form-check-label w-100 cursor-pointer" for="destDescargar">
                                            <strong class="d-block text-dark mb-1"><i class="bi bi-download text-success me-1"></i> Descargar Directamente</strong>
                                            <span class="small text-muted">Guarda el archivo <code>.sql</code> inmediatamente en tu equipo.</span>
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-check card-radio p-3 border rounded-3">
                                        <input class="form-check-input ms-0 me-2" type="radio" name="destino_backup" id="destServidor" value="servidor">
                                        <label class="form-check-label w-100 cursor-pointer" for="destServidor">
                                            <strong class="d-block text-dark mb-1"><i class="bi bi-hdd-network text-info me-1"></i> Guardar en Servidor</strong>
                                            <span class="small text-muted">Almacena la copia en el historial del servidor (<code>/backups</code>).</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Botón de Ejecución -->
                        <div class="pt-3 border-top d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary px-4 py-2 rounded-pill fw-semibold shadow-sm">
                                <i class="bi bi-cloud-arrow-down-fill me-2"></i> Generar Respaldo SQL
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Panel Lateral Informativo -->
        <div class="col-lg-4">
            <div class="card card-custom mb-4">
                <div class="card-header-custom">
                    <i class="bi bi-info-circle-fill text-info me-2"></i>Información sobre Respaldos
                </div>
                <div class="card-body small text-muted">
                    <p class="mb-3">Los respaldos generados contienen sentencias SQL con soporte para <code>CREATE TABLE</code> e <code>INSERT INTO</code> procesadas mediante sentencias preparadas PDO.</p>
                    <ul class="ps-3 mb-0">
                        <li class="mb-2">Son totalmente compatibles con <strong>phpMyAdmin</strong>, MySQL Workbench y consolas MySQL/MariaDB.</li>
                        <li class="mb-2">Desactivan temporalmente las comprobaciones de claves foráneas (<code>FOREIGN_KEY_CHECKS=0</code>) para evitar conflictos de integridad al restaurar.</li>
                        <li>Se recomienda realizar copias antes de cierres de auditoría o cargas masivas de inventario.</li>
                    </ul>
                </div>
            </div>

            <!-- Resumen de Estructura de Tablas -->
            <div class="card card-custom">
                <div class="card-header-custom">
                    <i class="bi bi-diagram-3-fill text-primary me-2"></i>Detalle por Tabla
                </div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush small">
                        <?php foreach ($dbStats['tables'] as $tbl): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-3 py-2.5">
                            <div>
                                <span class="sku-badge me-1"><?= htmlspecialchars($tbl['name']) ?></span>
                            </div>
                            <div>
                                <span class="badge bg-light text-dark border me-1"><?= number_format($tbl['rows']) ?> reg</span>
                                <span class="badge bg-secondary-subtle text-secondary"><?= $tbl['size_formatted'] ?></span>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- =========================================================================
         PESTAÑA 2: RESTAURAR BASE DE DATOS
         ========================================================================= -->
    <?php if ($tabActiva === 'restaurar'): ?>
    <div class="row g-4 justify-content-center">
        <div class="col-lg-8">
            <div class="card card-custom">
                <div class="card-header-custom">
                    <i class="bi bi-upload text-success me-2"></i>Restaurar Base de Datos desde Archivo SQL
                </div>
                <div class="card-body p-4">
                    
                    <div class="alert alert-warning d-flex align-items-start gap-3 shadow-sm border-0 mb-4" role="alert">
                        <i class="bi bi-exclamation-triangle-fill fs-3 text-warning"></i>
                        <div>
                            <strong class="d-block text-dark">¡Atención antes de restaurar!</strong>
                            La restauración ejecutará todas las sentencias contenidas en el script <code>.sql</code>. Si el respaldo contiene cláusulas <code>DROP TABLE</code> o <code>INSERT</code>, los datos actuales de las tablas afectadas serán reemplazados.
                        </div>
                    </div>

                    <form method="POST" action="backup.php" enctype="multipart/form-data">
                        <input type="hidden" name="accion" value="restaurar_subida">

                        <div class="mb-4">
                            <label class="form-label fw-bold text-dark">Selecciona el archivo .SQL de respaldo:</label>
                            <input type="file" name="sql_file" class="form-control form-control-lg" accept=".sql" required>
                            <div class="form-text mt-2">Formatos permitidos: <code>.sql</code> (Máximo tamaño permitido por PHP: <?= ini_get('upload_max_filesize') ?>).</div>
                        </div>

                        <div class="pt-3 border-top d-flex justify-content-between align-items-center">
                            <span class="small text-muted"><i class="bi bi-shield-check text-success me-1"></i> Conexión activa PDO MySQL</span>
                            <button type="submit" class="btn btn-success px-4 py-2 rounded-pill fw-semibold shadow-sm" onclick="return confirm('¿Estás seguro de que deseas restaurar la base de datos con este archivo? Se sobreescribirá la información actual.');">
                                <i class="bi bi-check-circle-fill me-2"></i> Iniciar Restauración
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- =========================================================================
         PESTAÑA 3: HISTORIAL DE RESPALDOS LOCALES
         ========================================================================= -->
    <?php if ($tabActiva === 'historial'): ?>
    <div class="card card-custom">
        <div class="card-header-custom d-flex justify-content-between align-items-center">
            <span><i class="bi bi-folder-check text-primary me-2"></i>Archivos de Respaldo Guardados en el Servidor</span>
            <span class="badge bg-light text-dark border"><?= count($archivosRespaldos) ?> archivos encontrados</span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($archivosRespaldos)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-folder2-open display-4 text-secondary mb-3 d-block"></i>
                    <h5>No hay respaldos guardados en el servidor</h5>
                    <p class="small mb-3">Puedes generar un nuevo respaldo seleccionando "Guardar en Servidor" en la pestaña de Generar Backup.</p>
                    <a href="backup.php?tab=respaldo" class="btn btn-primary btn-sm rounded-pill px-3">
                        <i class="bi bi-plus-circle me-1"></i> Generar Primer Respaldo
                    </a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover table-custom align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Nombre del Archivo</th>
                                <th>Tipo / Origen</th>
                                <th>Fecha de Creación</th>
                                <th>Tamaño</th>
                                <th class="text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($archivosRespaldos as $bk): ?>
                            <tr>
                                <td>
                                    <div class="fw-bold text-dark">
                                        <i class="bi bi-filetype-sql text-primary me-1 fs-5"></i>
                                        <?= htmlspecialchars($bk['name']) ?>
                                    </div>
                                    <div class="small text-muted">Ubicación: <code>/backups/<?= htmlspecialchars($bk['name']) ?></code></div>
                                </td>
                                <td>
                                    <?php if ($bk['is_auto']): ?>
                                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle rounded-pill"><i class="bi bi-shield-fill-check me-1"></i>Auto-Backup Preventivo</span>
                                    <?php else: ?>
                                        <span class="badge bg-info-subtle text-info border border-info-subtle rounded-pill"><i class="bi bi-person-fill me-1"></i>Manual</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-muted">
                                    <i class="bi bi-clock me-1"></i><?= $bk['date'] ?>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border"><?= $bk['formatted_size'] ?></span>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group" role="group">
                                        <!-- Descargar -->
                                        <a href="backup.php?descargar_local=<?= urlencode($bk['name']) ?>" class="btn btn-sm btn-outline-primary" title="Descargar archivo .sql">
                                            <i class="bi bi-download"></i>
                                        </a>

                                        <!-- Restaurar Directo -->
                                        <form method="POST" action="backup.php" class="d-inline" onsubmit="return confirm('¿Confirmas restaurar la base de datos utilizando el respaldo \'<?= htmlspecialchars($bk['name']) ?>\'?');">
                                            <input type="hidden" name="accion" value="restaurar_local">
                                            <input type="hidden" name="filename" value="<?= htmlspecialchars($bk['name']) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-success" title="Restaurar este respaldo ahora">
                                                <i class="bi bi-arrow-counterclockwise"></i> Restaurar
                                            </button>
                                        </form>

                                        <!-- Eliminar -->
                                        <form method="POST" action="backup.php" class="d-inline" onsubmit="return confirm('¿Deseas eliminar permanentemente el archivo \'<?= htmlspecialchars($bk['name']) ?>\'?');">
                                            <input type="hidden" name="accion" value="eliminar_local">
                                            <input type="hidden" name="filename" value="<?= htmlspecialchars($bk['name']) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar respaldo del servidor">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- =========================================================================
         PESTAÑA 4: ZONA PELIGROSA - RESETEO DE BASE DE DATOS
         ========================================================================= -->
    <?php if ($tabActiva === 'reset'): ?>
    <div class="row justify-content-center">
        <div class="col-lg-9">
            <div class="card card-custom border-danger">
                <div class="card-header-custom bg-danger text-white border-bottom-0 d-flex justify-content-between align-items-center py-3">
                    <span class="fw-bold"><i class="bi bi-exclamation-octagon-fill me-2 fs-5"></i>Zona de Control y Reseteo de Base de Datos</span>
                    <span class="badge bg-white text-danger fw-bold text-uppercase">Acción Crítica</span>
                </div>
                <div class="card-body p-4">
                    
                    <!-- Alerta de Respaldo Automático Preventivo -->
                    <div class="alert alert-info border-0 shadow-sm d-flex align-items-center gap-3 mb-4" role="alert">
                        <i class="bi bi-shield-lock-fill fs-2 text-info"></i>
                        <div>
                            <strong class="d-block text-dark">Protección Automática de Datos:</strong>
                            Antes de ejecutar cualquier nivel de reseteo, el sistema generará un <strong>Auto-Backup Preventivo</strong> en la carpeta <code>/backups</code> para garantizar que puedas revertir o recuperar la información en cualquier momento.
                        </div>
                    </div>

                    <form method="POST" action="backup.php">
                        <input type="hidden" name="accion" value="reset_db">

                        <label class="form-label fw-bold text-dark fs-6 mb-3">1. Selecciona el Nivel de Reseteo Deseado:</label>

                        <div class="row g-3 mb-4">
                            <!-- Nivel 1: Resetear Conteos Físicos -->
                            <div class="col-md-12">
                                <div class="form-check card-radio p-3 border border-warning-subtle rounded-3 bg-warning-subtle bg-opacity-10">
                                    <input class="form-check-input mt-1 ms-0 me-3" type="radio" name="nivel_reset" id="resetConteos" value="reset_conteos" checked>
                                    <label class="form-check-label w-100 cursor-pointer" for="resetConteos">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <strong class="text-dark fs-6"><i class="bi bi-barcode text-warning me-2"></i> Opción A: Resetear Conteos Físicos (Mantener Productos)</strong>
                                            <span class="badge bg-warning text-dark">Impacto Bajo</span>
                                        </div>
                                        <p class="small text-muted mb-0 mt-1">
                                            Elimina todos los conteos registrados en la tabla <code>conteos_fisicos</code>. Conserva intactos el catálogo de productos teóricos del ERP, los usuarios y las auditorías creadas. Útil para reiniciar la toma física desde cero.
                                        </p>
                                    </label>
                                </div>
                            </div>

                            <!-- Nivel 2: Resetear Stock Teórico -->
                            <div class="col-md-12">
                                <div class="form-check card-radio p-3 border border-danger-subtle rounded-3 bg-danger-subtle bg-opacity-10">
                                    <input class="form-check-input mt-1 ms-0 me-3" type="radio" name="nivel_reset" id="resetProductos" value="reset_productos">
                                    <label class="form-check-label w-100 cursor-pointer" for="resetProductos">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <strong class="text-dark fs-6"><i class="bi bi-box-seam-fill text-danger me-2"></i> Opción B: Resetear Maestro de Productos & Conteos</strong>
                                            <span class="badge bg-danger text-white">Impacto Medio</span>
                                        </div>
                                        <p class="small text-muted mb-0 mt-1">
                                            Vacía completamente las tablas de <code>productos_sistema</code> y <code>conteos_fisicos</code>. Deja la base de datos lista para cargar un nuevo archivo CSV/Excel de inventario ERP desde la pestaña de Carga Stock.
                                        </p>
                                    </label>
                                </div>
                            </div>

                            <!-- Nivel 3: Reseteo Total a Fábrica -->
                            <div class="col-md-12">
                                <div class="form-check card-radio p-3 border border-danger rounded-3 bg-danger bg-opacity-10">
                                    <input class="form-check-input mt-1 ms-0 me-3" type="radio" name="nivel_reset" id="resetFabrica" value="reset_fabrica">
                                    <label class="form-check-label w-100 cursor-pointer" for="resetFabrica">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <strong class="text-dark fs-6"><i class="bi bi-radioactive text-danger me-2"></i> Opción C: Reseteo Total de Fábrica (Reiniciar Sistema)</strong>
                                            <span class="badge bg-dark text-white">Impacto Crítico</span>
                                        </div>
                                        <p class="small text-muted mb-0 mt-1">
                                            Restablece todas las tablas del sistema a su estado inicial semilla (vaciando auditorías y productos, e insertando los datos iniciales por defecto). Se aseguran los usuarios administradores para evitar pérdida de acceso.
                                        </p>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Confirmación de Seguridad requerida -->
                        <div class="p-3 bg-light rounded-3 border mb-4">
                            <label class="form-label fw-bold text-dark small mb-1">
                                2. Confirmación de Seguridad Obligatoria:
                            </label>
                            <p class="small text-muted mb-2">Para evitar reseteos accidentales, escribe exactamente la palabra <code>CONFIRMAR</code> en el siguiente campo:</p>
                            <div class="input-group" style="max-width: 400px;">
                                <span class="input-group-text bg-white"><i class="bi bi-key-fill text-muted"></i></span>
                                <input type="text" name="confirmacion" class="form-control font-monospace" placeholder="CONFIRMAR" required autocomplete="off">
                            </div>
                        </div>

                        <!-- Botón de Acción -->
                        <div class="d-flex justify-content-end gap-2 border-top pt-3">
                            <a href="backup.php" class="btn btn-light rounded-pill px-4">Cancelar</a>
                            <button type="submit" class="btn btn-danger rounded-pill px-4 fw-semibold shadow" onclick="return confirm('¿Confirmas ejecutar la acción de reseteo seleccionada? Se creará una copia de seguridad automáticamente.');">
                                <i class="bi bi-trash3-fill me-2"></i> Ejecutar Reseteo de Base de Datos
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<script>
function toggleTablasSelector(mostrar) {
    const el = document.getElementById('selectorTablas');
    if (el) {
        if (mostrar) {
            el.classList.remove('d-none');
        } else {
            el.classList.add('d-none');
        }
    }
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
