<?php
/**
 * Middleware y Helper de Autenticación y Control de Eventos de Auditoría
 * Sistema de Auditoría de Inventarios
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Verifica si el usuario ha iniciado sesión correctamente
 */
function estaAutenticado() {
    return isset($_SESSION['usuario_id']) && !empty($_SESSION['usuario_id']);
}

/**
 * Retorna la información del usuario autenticado en sesión
 */
function obtenerUsuarioActual() {
    if (!estaAutenticado()) {
        return null;
    }
    return [
        'id'       => $_SESSION['usuario_id'],
        'nombre'   => $_SESSION['usuario_nombre'] ?? 'Usuario',
        'username' => $_SESSION['usuario_username'] ?? '',
        'perfil'   => $_SESSION['usuario_perfil'] ?? 'almacenista'
    ];
}

/**
 * Exige que exista una sesión activa; de lo contrario redirige al login
 */
function exigirAutenticacion() {
    if (!estaAutenticado()) {
        $_SESSION['flash_error'] = "Debes iniciar sesión para acceder al sistema.";
        header("Location: login.php");
        exit;
    }
}

/**
 * Exige que el usuario tenga uno de los roles autorizados
 * @param array $rolesPermitidos Lista de roles (ej: ['admin', 'auditor'])
 */
function exigirRol($rolesPermitidos = []) {
    exigirAutenticacion();
    $usuario = obtenerUsuarioActual();
    
    if (!in_array($usuario['perfil'], $rolesPermitidos)) {
        $_SESSION['flash_error'] = "Acceso no autorizado para el perfil [" . strtoupper($usuario['perfil']) . "].";
        header("Location: conteo.php");
        exit;
    }
}

/**
 * Helpers rápidos para comprobación de perfil
 */
function esAdmin() {
    $u = obtenerUsuarioActual();
    return $u && $u['perfil'] === 'admin';
}

function esAuditor() {
    $u = obtenerUsuarioActual();
    return $u && $u['perfil'] === 'auditor';
}

function esAlmacenista() {
    $u = obtenerUsuarioActual();
    return $u && $u['perfil'] === 'almacenista';
}

/**
 * Catálogo maestro de módulos asignables del sistema
 */
function obtenerCatalogoModulos() {
    return [
        'dashboard' => [
            'nombre' => 'Dashboard Ejecutivo',
            'archivo' => 'index.php',
            'icono' => 'bi-speedometer2',
            'categoria' => 'Módulos Principales',
            'descripcion' => 'Panel general de métricas e indicadores de auditoría'
        ],
        'eventos' => [
            'nombre' => 'Auditorías & Histórico',
            'archivo' => 'eventos.php',
            'icono' => 'bi-calendar-event',
            'categoria' => 'Módulos Principales',
            'descripcion' => 'Creación y cierre de auditorías físicas e histórico'
        ],
        'conteo' => [
            'nombre' => 'Toma Física Real',
            'archivo' => 'conteo.php',
            'icono' => 'bi-barcode',
            'categoria' => 'Módulos Principales',
            'descripcion' => 'Registro y captura rápida de conteo físico'
        ],
        'reporte' => [
            'nombre' => 'Conciliación',
            'archivo' => 'reporte.php',
            'icono' => 'bi-bar-chart-line',
            'categoria' => 'Módulos Principales',
            'descripcion' => 'Informe de diferencias, faltantes y sobrantes'
        ],
        'informes' => [
            'nombre' => 'Centro de Informes',
            'archivo' => 'informes.php',
            'icono' => 'bi-file-earmark-bar-graph',
            'categoria' => 'Módulos Principales',
            'descripcion' => 'Generación de reportes imprimibles y exportaciones'
        ],
        'importar' => [
            'nombre' => 'Carga Stock ERP',
            'archivo' => 'importar.php',
            'icono' => 'bi-file-earmark-arrow-up',
            'categoria' => 'Administración',
            'descripcion' => 'Carga masiva de existencias teóricas desde Excel/CSV'
        ],
        'usuarios' => [
            'nombre' => 'Usuarios & Roles',
            'archivo' => 'usuarios.php',
            'icono' => 'bi-people',
            'categoria' => 'Administración',
            'descripcion' => 'Gestión de usuarios y asignación dinámica de permisos'
        ],
        'backup' => [
            'nombre' => 'Backup & Reseteo BD',
            'archivo' => 'backup.php',
            'icono' => 'bi-database-gear',
            'categoria' => 'Administración',
            'descripcion' => 'Generación de respaldos SQL, restauración y reseteo'
        ]
    ];
}

/**
 * Obtener lista de claves de módulos autorizados para un usuario
 */
function obtenerPermisosUsuario($usuarioId = null) {
    if (!isset($GLOBALS['pdo_connection'])) {
        $GLOBALS['pdo_connection'] = getPDOConnection();
    }
    $pdo = $GLOBALS['pdo_connection'];

    if ($usuarioId === null && estaAutenticado()) {
        $usuarioId = $_SESSION['usuario_id'];
    }

    if (!$usuarioId) {
        return [];
    }

    if (isset($_SESSION['usuario_id']) && $_SESSION['usuario_id'] == $usuarioId && isset($_SESSION['usuario_permisos'])) {
        $permStr = $_SESSION['usuario_permisos'];
        if ($permStr === 'ALL') {
            return array_keys(obtenerCatalogoModulos());
        }
        return array_filter(array_map('trim', explode(',', $permStr)));
    }

    try {
        $stmt = $pdo->prepare("SELECT perfil, username, permisos FROM usuarios WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $usuarioId]);
        $u = $stmt->fetch();

        if ($u) {
            if ($u['username'] === 'admin') {
                return array_keys(obtenerCatalogoModulos());
            }

            if (!empty($u['permisos'])) {
                return array_filter(array_map('trim', explode(',', $u['permisos'])));
            } else {
                if ($u['perfil'] === 'admin') {
                    return array_keys(obtenerCatalogoModulos());
                } elseif ($u['perfil'] === 'auditor') {
                    return ['dashboard', 'eventos', 'conteo', 'reporte', 'informes'];
                } else {
                    return ['dashboard', 'conteo'];
                }
            }
        }
    } catch (Exception $e) {
        // Ignorar
    }

    return ['dashboard', 'conteo'];
}

/**
 * Verifica si el usuario actual (o el especificado) tiene permiso para un módulo determinado
 */
function tienePermisoModulo($claveModulo, $usuarioId = null) {
    $u = obtenerUsuarioActual();
    if ($u && $u['username'] === 'admin') {
        return true;
    }

    $permisos = obtenerPermisosUsuario($usuarioId);
    return in_array($claveModulo, $permisos);
}

/**
 * Exige permiso de acceso a un módulo específico; si no lo tiene, redirige
 */
function exigirPermisoModulo($claveModulo) {
    exigirAutenticacion();
    
    if (!tienePermisoModulo($claveModulo)) {
        $modulos = obtenerCatalogoModulos();
        $nombreMod = $modulos[$claveModulo]['nombre'] ?? $claveModulo;
        $_SESSION['flash_error'] = "Acceso restringido: No tienes asignado el módulo <b>" . htmlspecialchars($nombreMod) . "</b>.";
        
        $misPermisos = obtenerPermisosUsuario();
        if (in_array('dashboard', $misPermisos)) {
            header("Location: index.php");
        } elseif (in_array('conteo', $misPermisos)) {
            header("Location: conteo.php");
        } elseif (!empty($misPermisos)) {
            $primerModulo = $misPermisos[0];
            $archivo = $modulos[$primerModulo]['archivo'] ?? 'conteo.php';
            header("Location: " . $archivo);
        } else {
            header("Location: conteo.php");
        }
        exit;
    }
}

/**
 * Retorna el Evento de Auditoría Activo seleccionado en sesión o el último activo en DB
 */
function obtenerEventoActivo() {
    if (!isset($GLOBALS['pdo_connection'])) {
        $GLOBALS['pdo_connection'] = getPDOConnection();
    }
    $pdo = $GLOBALS['pdo_connection'];

    // Si se especificó por URL ?evento_id=X
    if (isset($_GET['evento_id']) && is_numeric($_GET['evento_id'])) {
        $evId = intval($_GET['evento_id']);
        $stmt = $pdo->prepare("SELECT * FROM eventos_auditoria WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $evId]);
        $ev = $stmt->fetch();
        if ($ev) {
            $_SESSION['evento_id'] = $ev['id'];
            return $ev;
        }
    }

    // Si ya existe en sesión
    if (isset($_SESSION['evento_id']) && !empty($_SESSION['evento_id'])) {
        $stmt = $pdo->prepare("SELECT * FROM eventos_auditoria WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $_SESSION['evento_id']]);
        $ev = $stmt->fetch();
        if ($ev) {
            return $ev;
        }
    }

    // Buscar el último evento activo en DB
    $stmtActive = $pdo->query("SELECT * FROM eventos_auditoria WHERE estado = 'activa' ORDER BY id DESC LIMIT 1");
    $evActive = $stmtActive->fetch();

    if ($evActive) {
        $_SESSION['evento_id'] = $evActive['id'];
        return $evActive;
    }

    // Si no hay evento activo, buscar el último evento creado (cerrado)
    $stmtLast = $pdo->query("SELECT * FROM eventos_auditoria ORDER BY id DESC LIMIT 1");
    $evLast = $stmtLast->fetch();

    if ($evLast) {
        $_SESSION['evento_id'] = $evLast['id'];
        return $evLast;
    }

    return null;
}

/**
 * Retorna todos los eventos de auditoría (activos y cerrados) para selectores e histórico
 */
function obtenerTodosEventos() {
    $pdo = getPDOConnection();
    $stmt = $pdo->query("SELECT * FROM eventos_auditoria ORDER BY fecha_inicio DESC");
    return $stmt->fetchAll();
}
