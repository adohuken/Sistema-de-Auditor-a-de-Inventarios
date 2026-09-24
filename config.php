<?php
/**
 * Configuración de Entorno Híbrido (Local & Online)
 * Sistema de Auditoría de Inventarios
 */

$host_env = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
$is_local_env = (
    empty($host_env) ||
    $host_env === 'localhost' ||
    strpos($host_env, '127.0.0.1') !== false ||
    php_sapi_name() === 'cli'
);

if ($is_local_env) {
    // LOCAL CONFIG (XAMPP)
    if (!defined('DB_HOST'))  define('DB_HOST', 'localhost');
    if (!defined('DB_NAME'))  define('DB_NAME', 'inventario_db');
    if (!defined('DB_USER'))  define('DB_USER', 'root');
    if (!defined('DB_PASS'))  define('DB_PASS', '');
    if (!defined('BASE_URL')) define('BASE_URL', '/s-inventario/');
} else {
    // LIVE CONFIG (InfinityFree: isdautic.ct.ws)
    if (!defined('DB_HOST'))  define('DB_HOST', 'sql205.infinityfree.com');
    if (!defined('DB_NAME'))  define('DB_NAME', 'if0_42994760_inventario_db');
    if (!defined('DB_USER'))  define('DB_USER', 'if0_42994760');
    if (!defined('DB_PASS'))  define('DB_PASS', 'HBSyvcdBOtilL');
    if (!defined('BASE_URL')) define('BASE_URL', '/');
    
    // InfinityFree Session Fix
    @ini_set('session.gc_probability', 0);
}

if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

/**
 * Función segura para iniciar sesiones
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
