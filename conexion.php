<?php
/**
 * Conexión segura a la base de datos MySQL / MariaDB mediante PDO
 * Auto-detectando entorno (Local XAMPP vs Online InfinityFree)
 * Sistema de Auditoría de Inventarios
 */

require_once __DIR__ . '/config.php';

function getPDOConnection() {
    static $pdo = null;
    if ($pdo === null) {
        $host_env = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        $is_local_env = (
            empty($host_env) ||
            $host_env === 'localhost' ||
            strpos($host_env, '127.0.0.1') !== false ||
            php_sapi_name() === 'cli'
        );

        if ($is_local_env) {
            // CONFIGURACIÓN LOCAL (XAMPP / WAMPServer)
            $host = 'localhost';
            $db   = 'inventario_db';
            $user = 'root';
            $pass = '';
        } else {
            // CONFIGURACIÓN PRODUCCIÓN (InfinityFree: isdautic.ct.ws)
            $host = 'sql205.infinityfree.com';
            $db   = 'if0_42994760_inventario_db';
            $user = 'if0_42994760';
            $pass = 'HBSyvcdBOti1L';
        }

        // Asegurar constantes globales de respaldo
        if (!defined('DB_HOST')) define('DB_HOST', $host);
        if (!defined('DB_NAME')) define('DB_NAME', $db);
        if (!defined('DB_USER')) define('DB_USER', $user);
        if (!defined('DB_PASS')) define('DB_PASS', $pass);

        $charset = 'utf8mb4';
        $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        // Configurar Zona Horaria de consistencia
        date_default_timezone_set('America/Managua');

        try {
            $pdo = new PDO($dsn, $user, $pass, $options);
            
            // Asegurar migración de estructura y usuarios base
            migrarEstructuraEventos($pdo);
            asegurarUsuariosBase($pdo);
            return $pdo;
        } catch (\PDOException $e) {
            // Si en entorno local la base de datos no existe aún, intentar auto-crearla
            if ($is_local_env && (strpos($e->getMessage(), 'Unknown database') !== false || $e->getCode() == 1049)) {
                try {
                    $tmpPdo = new PDO("mysql:host=$host;charset=$charset", $user, $pass);
                    $tmpPdo->exec("CREATE DATABASE IF NOT EXISTS `$db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
                    $pdo = new PDO($dsn, $user, $pass, $options);
                    migrarEstructuraEventos($pdo);
                    asegurarUsuariosBase($pdo);
                    return $pdo;
                } catch (\PDOException $ex) {
                    exit('Error de conexión a la Base de Datos: ' . $ex->getMessage());
                }
            }
            exit('Error de conexión a la Base de Datos: ' . $e->getMessage());
        }
    }
    return $pdo;
}

/**
 * Garantiza la existencia de la tabla eventos_auditoria y columnas de evento_id
 */
function migrarEstructuraEventos($pdo) {
    try {
        // 1. Crear tabla eventos_auditoria si no existe
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `eventos_auditoria` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `nombre_evento` VARCHAR(150) NOT NULL,
              `bodega_sucursal` VARCHAR(100) NOT NULL DEFAULT 'Bodega Principal',
              `fecha_inicio` DATETIME DEFAULT CURRENT_TIMESTAMP,
              `fecha_cierre` DATETIME NULL,
              `estado` ENUM('activa', 'cerrada') NOT NULL DEFAULT 'activa',
              `usuario_creador` VARCHAR(100) NOT NULL DEFAULT 'Admin',
              `notas` TEXT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // 2. Garantizar que exista al menos un evento activo por defecto
        $totalEventos = $pdo->query("SELECT COUNT(*) FROM eventos_auditoria")->fetchColumn();
        if ($totalEventos == 0) {
            $pdo->exec("INSERT INTO `eventos_auditoria` (`id`, `nombre_evento`, `bodega_sucursal`, `fecha_inicio`, `estado`, `usuario_creador`, `notas`) VALUES (1, 'Auditoría Física Septiembre 2026', 'Bodega León', NOW(), 'activa', 'Admin', 'Toma física inicial Q3 2026')");
        }

        // 3. Garantizar columnas e índices en productos_sistema
        $hasColProd = $pdo->query("SHOW COLUMNS FROM productos_sistema LIKE 'evento_id'")->fetch();
        if (!$hasColProd) {
            $pdo->exec("ALTER TABLE productos_sistema ADD COLUMN evento_id INT NOT NULL DEFAULT 1 AFTER id");
        }

        $hasColBodega = $pdo->query("SHOW COLUMNS FROM productos_sistema LIKE 'bodega'")->fetch();
        if (!$hasColBodega) {
            $pdo->exec("ALTER TABLE productos_sistema ADD COLUMN bodega VARCHAR(100) NOT NULL DEFAULT 'Bodega Principal' AFTER evento_id");
        }

        // Migrar clave única a (evento_id, bodega, codigo_producto)
        try { $pdo->exec("ALTER TABLE productos_sistema DROP INDEX codigo_producto"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE productos_sistema DROP INDEX uk_evento_codigo"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE productos_sistema ADD UNIQUE KEY uk_evento_bodega_codigo (evento_id, bodega, codigo_producto)"); } catch (Exception $e) {}

        // 4. Garantizar evento_id y bodega en conteos_fisicos
        $hasColCont = $pdo->query("SHOW COLUMNS FROM conteos_fisicos LIKE 'evento_id'")->fetch();
        if (!$hasColCont) {
            $pdo->exec("ALTER TABLE conteos_fisicos ADD COLUMN evento_id INT NOT NULL DEFAULT 1 AFTER id");
        }

        $hasColContBodega = $pdo->query("SHOW COLUMNS FROM conteos_fisicos LIKE 'bodega'")->fetch();
        if (!$hasColContBodega) {
            $pdo->exec("ALTER TABLE conteos_fisicos ADD COLUMN bodega VARCHAR(100) NOT NULL DEFAULT 'Bodega Principal' AFTER evento_id");
        }

        try { $pdo->exec("ALTER TABLE conteos_fisicos ADD INDEX idx_evento_bodega_codigo (evento_id, bodega, codigo_producto)"); } catch (Exception $e) {}

        // 5. Garantizar tabla asignaciones_auditoria
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `asignaciones_auditoria` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `evento_id` INT NOT NULL,
              `usuario_id` INT NOT NULL,
              `rol_evento` ENUM('contador', 'supervisor', 'auditor_lider') NOT NULL DEFAULT 'contador',
              `bodega_asignada` VARCHAR(100) DEFAULT NULL,
              `fecha_asignacion` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY `uk_evento_usuario` (`evento_id`, `usuario_id`),
              INDEX `idx_evento` (`evento_id`),
              INDEX `idx_usuario` (`usuario_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // 6. Garantizar columna permisos en usuarios
        $hasColPermisos = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'permisos'")->fetch();
        if (!$hasColPermisos) {
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN permisos TEXT NULL AFTER perfil");
        }

        // Asignar permisos por defecto a usuarios que aún no tengan definido permisos
        $todosModulos = 'dashboard,eventos,conteo,reporte,informes,importar,usuarios,backup';
        $auditorModulos = 'dashboard,eventos,conteo,reporte,informes';
        $almacenModulos = 'dashboard,conteo';

        $pdo->exec("UPDATE usuarios SET permisos = '{$todosModulos}' WHERE (permisos IS NULL OR permisos = '') AND perfil = 'admin'");
        $pdo->exec("UPDATE usuarios SET permisos = '{$auditorModulos}' WHERE (permisos IS NULL OR permisos = '') AND perfil = 'auditor'");
        $pdo->exec("UPDATE usuarios SET permisos = '{$almacenModulos}' WHERE (permisos IS NULL OR permisos = '') AND perfil = 'almacenista'");

    } catch (Exception $e) {
        // Ignorar advertencias si ya están migrados los índices
    }
}

/**
 * Garantiza que existan los 3 usuarios semilla con contraseñas encriptadas válidas
 */
function asegurarUsuariosBase($pdo) {
    try {
        $passAdmin   = '$2y$10$K2d4Ll.oL8HUXweuV8A0Se/qd/9lQcTjRTouAKrlXeIeBWyrrBL3.';
        $passAuditor = '$2y$10$TJyFmgoQsAbb5yeP6375iulErjvfIpVXLdzbjufJTPVtm.Eq6ItaW';
        $passAlmacen = '$2y$10$9tT2uElCBFzi39XAWDf5R.oyTArYjPTnBreCkGMs/wIv9L4DoD.i2';

        $sql = "INSERT INTO usuarios (nombre, username, password, perfil, estado) VALUES
                ('Carlos Administrador', 'admin', :passAdmin, 'admin', 1),
                ('Ana Auditora', 'auditor', :passAuditor, 'auditor', 1),
                ('Roberto Almacén', 'almacenista', :passAlmacen, 'almacenista', 1)
                ON DUPLICATE KEY UPDATE password = VALUES(password), estado = 1";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':passAdmin'   => $passAdmin,
            ':passAuditor' => $passAuditor,
            ':passAlmacen' => $passAlmacen
        ]);
    } catch (Exception $e) {
        // Ignorar si la tabla aún no se ha creado
    }
}
