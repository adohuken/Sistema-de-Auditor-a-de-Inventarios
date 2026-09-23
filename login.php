<?php
/**
 * Pantalla de Inicio de Sesión
 * Sistema de Control de Inventario
 */

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/auth.php';

// Si ya está autenticado, redirigir según su rol
if (estaAutenticado()) {
    header("Location: conteo.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        $error = "Por favor ingresa tu usuario y contraseña.";
    } else {
        try {
            $pdo = getPDOConnection();
            $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE username = :username LIMIT 1");
            $stmt->execute([':username' => $username]);
            $usuario = $stmt->fetch();

            if ($usuario && password_verify($password, $usuario['password'])) {
                if ($usuario['estado'] != 1) {
                    $error = "Este usuario se encuentra inactivo. Contacta al Administrador.";
                } else {
                    // Autenticación Exitosa
                    $_SESSION['usuario_id']       = $usuario['id'];
                    $_SESSION['usuario_nombre']   = $usuario['nombre'];
                    $_SESSION['usuario_username'] = $usuario['username'];
                    $_SESSION['usuario_perfil']   = $usuario['perfil'];

                    $_SESSION['flash_success'] = "¡Bienvenido/a, " . htmlspecialchars($usuario['nombre']) . "!";
                    header("Location: conteo.php");
                    exit;
                }
            } else {
                $error = "Usuario o contraseña incorrectos.";
            }
        } catch (Exception $e) {
            $error = "Error al procesar la solicitud: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - Sistema de Inventario</title>
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="assets/favicon.png">
    <link rel="shortcut icon" type="image/png" href="assets/favicon.png">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-wrapper">

    <div class="login-card">
        <div class="login-header">
            <div class="logo-icon">
                <i class="bi bi-box-seam-fill"></i>
            </div>
            <h4 class="fw-bold text-dark mb-1">Inventario Control</h4>
            <p class="text-muted small">Ingresa tus credenciales de acceso</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger d-flex align-items-center gap-2 small py-2 px-3 mb-3" role="alert">
                <i class="bi bi-exclamation-octagon-fill"></i>
                <div><?= htmlspecialchars($error) ?></div>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['flash_error'])): ?>
            <div class="alert alert-warning d-flex align-items-center gap-2 small py-2 px-3 mb-3" role="alert">
                <i class="bi bi-shield-lock-fill"></i>
                <div><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
            </div>
            <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>

        <form method="POST" action="login.php" autocomplete="off">
            <div class="mb-3">
                <label for="username" class="form-label fw-semibold small text-secondary">Usuario / SKU Admin</label>
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0"><i class="bi bi-person text-muted"></i></span>
                    <input type="text" class="form-control border-start-0 bg-light" id="username" name="username" required placeholder="Ej: admin" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                </div>
            </div>

            <div class="mb-4">
                <label for="password" class="form-label fw-semibold small text-secondary">Contraseña</label>
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0"><i class="bi bi-key text-muted"></i></span>
                    <input type="password" class="form-control border-start-0 bg-light" id="password" name="password" required placeholder="••••••••">
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold shadow-sm d-flex align-items-center justify-content-center gap-2" style="background-color: var(--primary-color); border: none;">
                <i class="bi bi-box-arrow-in-right fs-5"></i> Iniciar Sesión
            </button>
        </form>

        <hr class="my-4 text-muted">

        <!-- Credenciales Semilla para Pruebas Rápida -->
        <div class="bg-light p-3 rounded-3 border">
            <div class="fw-semibold text-dark small mb-2 d-flex align-items-center gap-1">
                <i class="bi bi-info-circle-fill text-primary"></i> Usuarios de Prueba:
            </div>
            <div class="d-flex justify-content-between text-muted small border-bottom pb-1 mb-1">
                <span><b>Admin:</b> admin / admin123</span>
                <span class="badge badge-role-admin">Admin</span>
            </div>
            <div class="d-flex justify-content-between text-muted small border-bottom pb-1 mb-1">
                <span><b>Auditor:</b> auditor / auditor123</span>
                <span class="badge badge-role-auditor">Auditor</span>
            </div>
            <div class="d-flex justify-content-between text-muted small">
                <span><b>Almacén:</b> almacenista / almacen123</span>
                <span class="badge badge-role-almacenista">Almacén</span>
            </div>
        </div>
    </div>

</body>
</html>
