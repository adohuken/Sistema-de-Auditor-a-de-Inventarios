<?php
/**
 * Configuración de Entorno Híbrido (Local & Online)
 * Sistema de Auditoría de Inventarios
 */

// Si existe un archivo de configuración local personalizado, cargarlo con máxima prioridad
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
} else {
    // Detectar entorno mediante ruta física __DIR__ y SERVER_NAME (Probado para InfinityFree / iFastNet)
    $is_local = false;
    if (php_sapi_name() === 'cli' || strpos(__DIR__, 'xampp') !== false || strpos(__DIR__, 'XAMPP') !== false || strpos(__DIR__, 'wamp') !== false) {
        $is_local = true;
    } else if (isset($_SERVER['SERVER_NAME'])) {
        $server_name = strtolower($_SERVER['SERVER_NAME']);
        $is_local = (
            in_array($server_name, ['127.0.0.1', '::1', 'localhost']) || 
            strpos($server_name, '192.168.') === 0 || 
            strpos($server_name, '10.') === 0 || 
            strpos($server_name, '172.') === 0
        );
    }

    if ($is_local) {
        // =========================================================================
        // 1. CREDENCIALES LOCALES (XAMPP / WAMPServer)
        // =========================================================================
        define('DB_HOST', 'localhost');
        define('DB_NAME', 'inventario_db');
        define('DB_USER', 'root');
        define('DB_PASS', '');
        define('DB_PORTS', [3306, 3308]);
        define('BASE_URL', '/s-inventario/');
    } else {
        // =========================================================================
        // 2. CREDENCIALES DE PRODUCCIÓN (InfinityFree: isdautic.ct.ws)
        // =========================================================================
        define('DB_HOST', 'sql205.infinityfree.com');
        define('DB_NAME', 'if0_42994760_inventario_db');
        define('DB_USER', 'if0_42994760');
        define('DB_PASS', 'HBSyvcdBOti1L');
        define('DB_PORTS', [3306]);
        define('BASE_URL', '/');
        
        // InfinityFree Session Fix (previene Permission Denied en /php_sessions)
        @ini_set('session.gc_probability', 0);
    }

    // Definiciones secundarias de respaldo
    if (!defined('DB_LOCAL_HOST')) define('DB_LOCAL_HOST', 'localhost');
    if (!defined('DB_LOCAL_NAME')) define('DB_LOCAL_NAME', 'inventario_db');
    if (!defined('DB_LOCAL_USER')) define('DB_LOCAL_USER', 'root');
    if (!defined('DB_LOCAL_PASS')) define('DB_LOCAL_PASS', '');

    if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');
}

/**
 * Función segura para iniciar sesiones en InfinityFree / iFastNet hosting
 */
function safe_session_start() {
    if (session_status() === PHP_SESSION_NONE) {
        @ini_set('session.gc_probability', 0);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        @session_start(['gc_probability' => 0]);
    }
}
