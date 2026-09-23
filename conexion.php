<?php
/**
 * Conexión segura a la base de datos MySQL / MariaDB mediante PDO
 * Optimizado para WAMPServer y XAMPP
 * Sistema de Auditoría de Inventarios
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'inventario_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

function getPDOConnection() {
    static $pdo = null;
    if ($pdo === null) {
        $puertos = [3306, 3308]; // Puertos estándar en WAMPServer (MySQL y MariaDB)
        $ultimoError = null;

        foreach ($puertos as $puerto) {
            try {
                $dsn = "mysql:host=" . DB_HOST . ";port=" . $puerto . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
                $options = [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ];
                $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
                
                // Asegurar estructura de eventos y usuarios semilla
                migrarEstructuraEventos($pdo);
                asegurarUsuariosBase($pdo);
                return $pdo;
            } catch (PDOException $e) {
                // Si la BD no existe aún en este puerto, intentar auto-crearla con database.sql
                if (strpos($e->getMessage(), 'Unknown database') !== false || $e->getCode() == 1049) {
                    try {
                        $tmpPdo = new PDO("mysql:host=" . DB_HOST . ";port=" . $puerto . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
                        $sqlFile = __DIR__ . '/database.sql';
                        if (file_exists($sqlFile)) {
                            $sql = file_get_contents($sqlFile);
                            $tmpPdo->exec($sql);
                            // Reintentar conexión tras crear BD
                            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
                            migrarEstructuraEventos($pdo);
                            asegurarUsuariosBase($pdo);
                            return $pdo;
                        }
                    } catch (PDOException $ex) {
                        $ultimoError = $ex;
                    }
                } else {
                    $ultimoError = $e;
                }
            }
        }

        // Si fallaron todos los puertos
        die('<div style="padding: 20px; font-family: sans-serif; background: #fff3f3; color: #721c24; border: 1px solid #f5c6cb; border-radius: 8px; margin: 20px;">' .
            '<h3>Error de Conexión a Base de Datos (WAMPServer / MySQL)</h3>' .
            '<p>No se pudo conectar a MySQL ni MariaDB en los puertos 3306 o 3308.</p>' .
            '<p><b>Detalle técnico:</b> ' . htmlspecialchars($ultimoError ? $ultimoError->getMessage() : 'Error desconocido') . '</p>' .
            '<ul>' .
            '<li>Verifica que el icono de <b>WAMPServer</b> esté en verde en la barra de tareas.</li>' .
            '<li>Asegúrate de haber ejecutado el archivo <code>database.sql</code> en <b>phpMyAdmin</b>.</li>' .
            '</ul>' .
            '</div>');
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
