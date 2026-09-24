<?php
/**
 * Configuración de Entorno Híbrido (Local & Online)
 * Sistema de Auditoría de Inventarios
 */

// Si existe un archivo de configuración local personalizado, cargarlo con máxima prioridad
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
} else {
    // Detectar si la aplicación se está ejecutando en servidor Local (XAMPP / WAMPServer / Red LAN 192.168.x.x) u Online
    $httpHost   = strtolower($_SERVER['HTTP_HOST'] ?? '');
    $hostOnly   = explode(':', $httpHost)[0];
    $serverAddr = $_SERVER['SERVER_ADDR'] ?? '';
    
    // Identificar si la solicitud proviene de localhost, IP en red local (192.168.x.x, 10.x.x.x, 172.16.x.x)
    $esEntornoLocal = (
        empty($hostOnly) ||
        $hostOnly === 'localhost' ||
        $hostOnly === '127.0.0.1' ||
        $hostOnly === '::1' ||
        str_ends_with($hostOnly, '.local') ||
        preg_match('/^192\.168\.\d+\.\d+$/', $hostOnly) ||
        preg_match('/^10\.\d+\.\d+\.\d+$/', $hostOnly) ||
        preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\.\d+\.\d+$/', $hostOnly) ||
        preg_match('/^192\.168\.\d+\.\d+$/', $serverAddr) ||
        preg_match('/^10\.\d+\.\d+\.\d+$/', $serverAddr)
    );

    if ($esEntornoLocal) {
        // =========================================================================
        // 1. CONFIGURACIÓN ENTORNO LOCAL / RED LAN (XAMPP / WAMPServer)
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
