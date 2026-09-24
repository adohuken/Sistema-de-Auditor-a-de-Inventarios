<?php
/**
 * Configuración de Entorno Híbrido (Local & Online)
 * Sistema de Auditoría de Inventarios
 */

// Si existe un archivo de configuración local personalizado, cargarlo con máxima prioridad
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
} else {
    // Detectar si la aplicación se está ejecutando en servidor Local (XAMPP / WAMPServer) u Online (Hosting)
    $httpHost   = $_SERVER['HTTP_HOST'] ?? '';
    $serverAddr = $_SERVER['SERVER_ADDR'] ?? '';
    
    $esEntornoLocal = (
        empty($httpHost) ||
        in_array($httpHost, ['localhost', '127.0.0.1', '::1']) ||
        strpos($httpHost, 'localhost:') === 0 ||
        strpos($httpHost, '127.0.0.1:') === 0 ||
        in_array($serverAddr, ['127.0.0.1', '::1'])
    );

    if ($esEntornoLocal) {
        // =========================================================================
        // 1. CONFIGURACIÓN ENTORNO LOCAL (XAMPP / WAMPServer)
        // =========================================================================
        if (!defined('DB_HOST'))  define('DB_HOST', 'localhost');
        if (!defined('DB_NAME'))  define('DB_NAME', 'inventario_db');
        if (!defined('DB_USER'))  define('DB_USER', 'root');
        if (!defined('DB_PASS'))  define('DB_PASS', '');
        if (!defined('DB_PORTS')) define('DB_PORTS', [3306, 3308]);
    } else {
        // =========================================================================
        // 2. CONFIGURACIÓN ENTORNO ONLINE (InfinityFree / Hosting Remoto)
        // =========================================================================
        if (!defined('DB_HOST'))  define('DB_HOST', getenv('DB_HOST') ?: 'sql205.infinityfree.com');
        if (!defined('DB_NAME'))  define('DB_NAME', getenv('DB_NAME') ?: 'if0_42994760_inventario_db');
        if (!defined('DB_USER'))  define('DB_USER', getenv('DB_USER') ?: 'if0_42994760');
        if (!defined('DB_PASS'))  define('DB_PASS', getenv('DB_PASS') ?: 'HBSyvcdBOti1L');
        if (!defined('DB_PORTS')) define('DB_PORTS', [intval(getenv('DB_PORT') ?: 3306)]);
    }

    if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');
}
