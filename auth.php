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
