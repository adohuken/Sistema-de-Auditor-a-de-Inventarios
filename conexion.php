<?php
/**
 * Conexión segura a la base de datos MySQL / MariaDB mediante PDO
 * Soporta entorno Híbrido (Local XAMPP/WAMPServer y Online Hosting/InfinityFree)
 * Sistema de Auditoría de Inventarios
 */

require_once __DIR__ . '/config.php';

function getPDOConnection() {
    static $pdo = null;
    if ($pdo === null) {
        $httpHost = strtolower($_SERVER['HTTP_HOST'] ?? '');
        $hostOnly = explode(':', $httpHost)[0];

        $esStrictLocalHost = (
            empty($hostOnly) ||
            $hostOnly === 'localhost' ||
            $hostOnly === '127.0.0.1' ||
            $hostOnly === '::1' ||
            str_ends_with($hostOnly, '.local')
        );

        // Lista de destinos de conexión a intentar en orden adaptativo de prioridad
        $destinos = [];

        if ($esStrictLocalHost) {
            // Petición desde localhost: Probar primero MySQL Local (XAMPP / WAMPServer)
            $destinos[] = ['host' => DB_LOCAL_HOST, 'port' => 3306, 'user' => DB_LOCAL_USER, 'pass' => DB_LOCAL_PASS, 'dbname' => DB_LOCAL_NAME, 'tipo' => 'Local'];
            $destinos[] = ['host' => DB_LOCAL_HOST, 'port' => 3308, 'user' => DB_LOCAL_USER, 'pass' => DB_LOCAL_PASS, 'dbname' => DB_LOCAL_NAME, 'tipo' => 'Local'];
            // Respaldo Online
            $destinos[] = ['host' => DB_HOST,       'port' => 3306, 'user' => DB_USER,       'pass' => DB_PASS,       'dbname' => DB_NAME,       'tipo' => 'Online'];
        } else {
            // Petición desde URL de hosting u otro host: Probar primero MySQL Online (InfinityFree)
            $destinos[] = ['host' => DB_HOST,       'port' => 3306, 'user' => DB_USER,       'pass' => DB_PASS,       'dbname' => DB_NAME,       'tipo' => 'Online'];
            // Respaldo Local por si es servidor web LAN en desarrollo
            $destinos[] = ['host' => DB_LOCAL_HOST, 'port' => 3306, 'user' => DB_LOCAL_USER, 'pass' => DB_LOCAL_PASS, 'dbname' => DB_LOCAL_NAME, 'tipo' => 'Local'];
            $destinos[] = ['host' => DB_LOCAL_HOST, 'port' => 3308, 'user' => DB_LOCAL_USER, 'pass' => DB_LOCAL_PASS, 'dbname' => DB_LOCAL_NAME, 'tipo' => 'Local'];
        }

        $ultimoError = null;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        foreach ($destinos as $target) {
            try {
                $dsn = "mysql:host=" . $target['host'] . ";port=" . $target['port'] . ";dbname=" . $target['dbname'] . ";charset=" . DB_CHARSET;
                $pdo = new PDO($dsn, $target['user'], $target['pass'], $options);

                // Migrar y asegurar tablas y datos iniciales
                migrarEstructuraEventos($pdo);
                asegurarUsuariosBase($pdo);
                return $pdo;
            } catch (PDOException $e) {
                // Si la BD no existe en el puerto local, intentar auto-crearla
                if ($target['tipo'] === 'Local' && (strpos($e->getMessage(), 'Unknown database') !== false || $e->getCode() == 1049)) {
                    try {
                        $tmpPdo = new PDO("mysql:host=" . $target['host'] . ";port=" . $target['port'] . ";charset=" . DB_CHARSET, $target['user'], $target['pass']);
                        $tmpPdo->exec("CREATE DATABASE IF NOT EXISTS `" . $target['dbname'] . "` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
                        
                        $pdo = new PDO($dsn, $target['user'], $target['pass'], $options);
                        migrarEstructuraEventos($pdo);
                        asegurarUsuariosBase($pdo);
                        return $pdo;
                    } catch (PDOException $ex) {
                        $ultimoError = $ex;
                    }
                } else {
                    $ultimoError = $e;
                }
            }
        }

        // Si fallaron todos los destinos posibles
        $msgHtml = '<div style="padding: 24px; font-family: system-ui, -apple-system, sans-serif; background: #fff3f3; color: #721c24; border: 1px solid #f5c6cb; border-radius: 12px; margin: 30px auto; max-width: 650px; box-shadow: 0 4px 12px rgba(0,0,0,0.08);">';
        $msgHtml .= '<h3 style="margin-top:0; color:#b91c1c;">Error de Conexión a Base de Datos</h3>';
        $msgHtml .= '<p>No se pudo conectar a MySQL (Servidor Remoto: <b>' . htmlspecialchars(DB_HOST) . '</b> / Servidor Local: <b>localhost</b>).</p>';
        $msgHtml .= '<p style="font-family: monospace; background: #fee2e2; padding: 10px; border-radius: 6px; font-size: 0.85rem;"><b>Detalle técnico:</b> ' . htmlspecialchars($ultimoError ? $ultimoError->getMessage() : 'Error desconocido') . '</p>';
        $msgHtml .= '<h4>Acciones Sugeridas:</h4>';
        $msgHtml .= '<ul>';
        $msgHtml .= '<li><b>Si estás usando tu enlace en InfinityFree:</b> Verifica que la base de datos <code>' . htmlspecialchars(DB_NAME) . '</code> esté creada en tu panel de control de InfinityFree.</li>';
        $msgHtml .= '<li><b>Si estás en tu PC local:</b> Asegúrate de iniciar MySQL en XAMPP o WAMPServer.</li>';
        $msgHtml .= '</ul></div>';
        
        die($msgHtml);
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
