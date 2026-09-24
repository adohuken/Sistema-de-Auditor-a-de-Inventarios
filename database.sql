-- Base de Datos para el Sistema de Auditoría de Inventarios Físicos por Eventos
-- Compatible con MySQL / MariaDB (XAMPP, WAMPServer e InfinityFree)

CREATE DATABASE IF NOT EXISTS `inventario_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `inventario_db`;

-- 1. Tabla de Usuarios y Perfiles (Roles y Permisos Dinámicos)
CREATE TABLE IF NOT EXISTS `usuarios` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nombre` VARCHAR(100) NOT NULL,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `perfil` ENUM('admin', 'auditor', 'almacenista') NOT NULL DEFAULT 'almacenista',
  `permisos` TEXT NULL,
  `estado` TINYINT(1) NOT NULL DEFAULT 1,
  `fecha_creacion` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Tabla de Eventos de Auditoría (Tomas Físicas)
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

-- 3. Tabla de Productos Sistema (Stock Teórico Congelado por Evento)
CREATE TABLE IF NOT EXISTS `productos_sistema` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `evento_id` INT NOT NULL DEFAULT 1,
  `bodega` VARCHAR(100) NOT NULL DEFAULT 'Bodega Principal',
  `codigo_producto` VARCHAR(50) NOT NULL,
  `producto` VARCHAR(255) NOT NULL,
  `categoria` VARCHAR(100) DEFAULT 'General',
  `tipo` VARCHAR(100) DEFAULT 'Artículo',
  `marca` VARCHAR(100) DEFAULT 'Sin Marca',
  `existencias_sistema` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `costo_promedio` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `precio_producto` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_evento_bodega_codigo` (`evento_id`, `bodega`, `codigo_producto`),
  INDEX `idx_evento_id` (`evento_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Tabla de Conteos Físicos (Auditoría Real por Evento)
CREATE TABLE IF NOT EXISTS `conteos_fisicos` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `evento_id` INT NOT NULL DEFAULT 1,
  `bodega` VARCHAR(100) NOT NULL DEFAULT 'Bodega Principal',
  `codigo_producto` VARCHAR(50) NOT NULL,
  `cantidad_fisica` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `fecha_conteo` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `usuario` VARCHAR(100) NOT NULL DEFAULT 'Sistema',
  `usuario_id` INT DEFAULT NULL,
  INDEX `idx_evento_bodega_codigo` (`evento_id`, `bodega`, `codigo_producto`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Tabla de Asignación de Personal y Roles por Auditoría
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

-- =========================================================================
-- DATOS SEMILLA INICIALES DE PRUEBA
-- =========================================================================

-- Insertar / Actualizar Usuarios por Defecto
-- Passwords: admin123, auditor123, almacen123
INSERT INTO `usuarios` (`id`, `nombre`, `username`, `password`, `perfil`, `permisos`, `estado`) VALUES
(1, 'Carlos Administrador', 'admin', '$2y$10$K2d4Ll.oL8HUXweuV8A0Se/qd/9lQcTjRTouAKrlXeIeBWyrrBL3.', 'admin', 'dashboard,eventos,conteo,reporte,informes,importar,usuarios,backup', 1),
(2, 'Ana Auditora', 'auditor', '$2y$10$TJyFmgoQsAbb5yeP6375iulErjvfIpVXLdzbjufJTPVtm.Eq6ItaW', 'auditor', 'dashboard,eventos,conteo,reporte,informes', 1),
(3, 'Roberto Almacén', 'almacenista', '$2y$10$9tT2uElCBFzi39XAWDf5R.oyTArYjPTnBreCkGMs/wIv9L4DoD.i2', 'almacenista', 'dashboard,conteo', 1)
ON DUPLICATE KEY UPDATE `password` = VALUES(`password`), `nombre` = VALUES(`nombre`), `perfil` = VALUES(`perfil`), `permisos` = VALUES(`permisos`), `estado` = 1;

-- Evento de Auditoría Semilla Inicial
INSERT INTO `eventos_auditoria` (`id`, `nombre_evento`, `bodega_sucursal`, `fecha_inicio`, `estado`, `usuario_creador`, `notas`) VALUES
(1, 'Auditoría Física Septiembre 2026', 'Bodega León', NOW(), 'activa', 'Carlos Administrador', 'Toma física programada Q3 2026.')
ON DUPLICATE KEY UPDATE `nombre_evento` = VALUES(`nombre_evento`);

-- Productos del Sistema para la Auditoría #1
INSERT INTO `productos_sistema` (`evento_id`, `codigo_producto`, `producto`, `categoria`, `tipo`, `marca`, `existencias_sistema`, `costo_promedio`, `precio_producto`) VALUES
(1, '75010001', 'Arroz Extra Flor 1kg', 'Abarrotes', 'Granos', 'Verde', 150.00, 18.50, 24.00),
(1, '75010002', 'Aceite Vegetal 900ml', 'Abarrotes', 'Líquidos', 'Cocinero', 80.00, 32.00, 41.50),
(1, '75010003', 'Leche Entera 1L', 'Lácteos', 'Líquidos', 'Alpura', 200.00, 21.00, 27.00),
(1, '75010004', 'Detergente Polvo 1kg', 'Limpieza', 'Químicos', 'Ace', 60.00, 28.50, 36.00),
(1, '75010005', 'Galletas Chocolate 150g', 'Abarrotes', 'Snacks', 'Gamesa', 120.00, 12.00, 16.50),
(1, '75010006', 'Jabón de Tocador 120g', 'Cuidado Personal', 'Higiene', 'Palmolive', 90.00, 11.50, 15.00),
(1, '75010007', 'Atún en Agua 140g', 'Enlatados', 'Conservas', 'Dolores', 110.00, 16.00, 21.00),
(1, '75010008', 'Café Soluble 200g', 'Abarrotes', 'Bebidas', 'Nescafé', 45.00, 85.00, 110.00)
ON DUPLICATE KEY UPDATE `producto` = VALUES(`producto`), `existencias_sistema` = VALUES(`existencias_sistema`);
