<?php
/**
 * API Endpoint: Búsqueda Rápida de Productos por Evento de Auditoría
 * Retorna JSON con catálogo de coincidencia (OCULTA existencias_sistema por diseño)
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../auth.php';

if (!estaAutenticado()) {
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

$eventoActual = obtenerEventoActivo();
$query = trim($_GET['q'] ?? '');

if (empty($query) || !$eventoActual) {
    echo json_encode([]);
    exit;
}

try {
    $pdo = getPDOConnection();
    $stmt = $pdo->prepare("
        SELECT id, bodega, codigo_producto, producto 
        FROM productos_sistema 
        WHERE evento_id = :evento_id
          AND (codigo_producto LIKE :q OR producto LIKE :q_name)
        ORDER BY 
            CASE WHEN codigo_producto = :exact THEN 0 ELSE 1 END,
            producto ASC 
        LIMIT 10
    ");
    
    $stmt->execute([
        ':evento_id' => $eventoActual['id'],
        ':q'         => $query . '%',
        ':q_name'    => '%' . $query . '%',
        ':exact'     => $query
    ]);

    $productos = $stmt->fetchAll();
    echo json_encode($productos);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
