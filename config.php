<?php
/**
 * Configuración de Entorno Híbrido (Local & Online)
 * Sistema de Auditoría de Inventarios
 */

// Si existe un archivo de configuración local personalizado, cargarlo con máxima prioridad
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

// =========================================================================
// 1. CREDENCIALES ONLINE (InfinityFree / Hosting Web Remoto)
// =========================================================================
if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST') ?: 'sql205.infinityfree.com');
if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: 'if0_42994760_inventario_db');
if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER') ?: 'if0_42994760');
if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASS') ?: 'HBSyvcdBOti1L');

// =========================================================================
// 2. CREDENCIALES LOCALES (XAMPP / WAMPServer)
// =========================================================================
if (!defined('DB_LOCAL_HOST')) define('DB_LOCAL_HOST', 'localhost');
if (!defined('DB_LOCAL_NAME')) define('DB_LOCAL_NAME', 'inventario_db');
if (!defined('DB_LOCAL_USER')) define('DB_LOCAL_USER', 'root');
if (!defined('DB_LOCAL_PASS')) define('DB_LOCAL_PASS', '');

if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');
