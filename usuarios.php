<?php
/**
 * Módulo de Gestión de Usuarios y Roles (Exclusivo Administradores)
 * Sistema de Control de Inventario
 */

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/auth.php';

// Exigir rol de Administrador
exigirRol(['admin']);

$error = null;

// Acción: Crear Usuario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_usuario'])) {
    $nombre   = trim($_POST['nombre'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $perfil   = $_POST['perfil'] ?? 'almacenista';
    $estado   = intval($_POST['estado'] ?? 1);

    if (empty($nombre) || empty($username) || empty($password)) {
        $error = "Nombre, usuario y contraseña son campos obligatorios.";
    } else {
        try {
            $pdo = getPDOConnection();
            $hashPass = password_hash($password, PASSWORD_BCRYPT);
            
            $stmt = $pdo->prepare("INSERT INTO usuarios (nombre, username, password, perfil, estado) VALUES (:nombre, :username, :password, :perfil, :estado)");
            $stmt->execute([
                ':nombre'   => $nombre,
                ':username' => $username,
                ':password' => $hashPass,
                ':perfil'   => $perfil,
                ':estado'   => $estado
            ]);

            $_SESSION['flash_success'] = "Usuario <b>" . htmlspecialchars($username) . "</b> creado exitosamente.";
            header("Location: usuarios.php");
            exit;
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $error = "El nombre de usuario [{$username}] ya se encuentra registrado. Elige otro.";
            } else {
                $error = "Error al crear usuario: " . $e->getMessage();
            }
        }
    }
}

// Acción: Editar Usuario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_usuario'])) {
    $idEdit   = intval($_POST['id_usuario']);
    $nombre   = trim($_POST['nombre'] ?? '');
    $perfil   = $_POST['perfil'] ?? 'almacenista';
    $estado   = intval($_POST['estado'] ?? 1);
    $password = trim($_POST['password'] ?? '');

    if (empty($nombre)) {
        $error = "El nombre no puede estar vacío.";
    } else {
        try {
            $pdo = getPDOConnection();
            
            if (!empty($password)) {
                $hashPass = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("UPDATE usuarios SET nombre = :nombre, perfil = :perfil, estado = :estado, password = :pass WHERE id = :id");
                $stmt->execute([
                    ':nombre' => $nombre,
                    ':perfil' => $perfil,
                    ':estado' => $estado,
                    ':pass'   => $hashPass,
                    ':id'     => $idEdit
                ]);
            } else {
                $stmt = $pdo->prepare("UPDATE usuarios SET nombre = :nombre, perfil = :perfil, estado = :estado WHERE id = :id");
                $stmt->execute([
                    ':nombre' => $nombre,
                    ':perfil' => $perfil,
                    ':estado' => $estado,
                    ':id'     => $idEdit
                ]);
            }

            $_SESSION['flash_success'] = "Usuario actualizado correctamente.";
            header("Location: usuarios.php");
            exit;
        } catch (Exception $e) {
            $error = "Error al actualizar usuario: " . $e->getMessage();
        }
    }
}

// Obtener lista de usuarios
$usuariosList = [];
try {
    $pdo = getPDOConnection();
    $stmt = $pdo->query("SELECT id, nombre, username, perfil, estado, fecha_creacion FROM usuarios ORDER BY id ASC");
    $usuariosList = $stmt->fetchAll();
} catch (Exception $e) {
    $error = "Error al obtener usuarios: " . $e->getMessage();
}

include __DIR__ . '/includes/header.php';
?>

<div class="row mb-4 align-items-center">
    <div class="col-md-7">
        <h3 class="fw-bold text-dark mb-1"><i class="bi bi-people-fill text-primary me-2"></i>Gestión de Usuarios y Perfiles</h3>
        <p class="text-muted mb-0">Administra los usuarios del sistema y asigna permisos por rol.</p>
    </div>
    <div class="col-md-5 text-md-end mt-3 mt-md-0">
        <button type="button" class="btn btn-primary btn-sm rounded-pill fw-semibold px-3" data-bs-toggle="modal" data-bs-target="#modalNuevoUsuario">
            <i class="bi bi-person-plus-fill me-1"></i> Crear Nuevo Usuario
        </button>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Tabla de Usuarios -->
<div class="card card-custom">
    <div class="card-header-custom d-flex justify-content-between align-items-center">
        <span><i class="bi bi-person-lines-fill me-2 text-primary"></i>Usuarios Registrados</span>
        <span class="badge bg-light text-muted border"><?= count($usuariosList) ?> usuarios</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-custom align-middle mb-0">
                <thead>
                    <tr>
                        <th>Nombre / Usuario</th>
                        <th>Perfil (Rol)</th>
                        <th>Permisos Asignados</th>
                        <th class="text-center">Estado</th>
                        <th>Fecha Registro</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($usuariosList as $u): ?>
                        <?php
                        $roleBadge = 'badge-role-almacenista';
                        $permisosText = 'Conteo Físico Rápido';
                        if ($u['perfil'] === 'admin') {
                            $roleBadge = 'badge-role-admin';
                            $permisosText = 'Acceso Total (Carga, Conteo, Reporte, Usuarios)';
                        } elseif ($u['perfil'] === 'auditor') {
                            $roleBadge = 'badge-role-auditor';
                            $permisosText = 'Conteo Físico y Reporte Conciliación';
                        }
                        ?>
                        <tr>
                            <td>
                                <div class="fw-bold text-dark"><?= htmlspecialchars($u['nombre']) ?></div>
                                <div class="small text-muted"><i class="bi bi-at"></i><?= htmlspecialchars($u['username']) ?></div>
                            </td>
                            <td>
                                <span class="badge <?= $roleBadge ?> text-uppercase px-2 py-1" style="font-size: 0.75rem;">
                                    <?= htmlspecialchars($u['perfil']) ?>
                                </span>
                            </td>
                            <td class="small text-muted">
                                <?= $permisosText ?>
                            </td>
                            <td class="text-center">
                                <?php if ($u['estado'] == 1): ?>
                                    <span class="badge bg-success-subtle text-success fw-semibold"><i class="bi bi-check-circle me-1"></i>Activo</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary-subtle text-secondary fw-semibold"><i class="bi bi-x-circle me-1"></i>Inactivo</span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted">
                                <?= date('d/m/Y H:i', strtotime($u['fecha_creacion'])) ?>
                            </td>
                            <td class="text-center">
                                <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#modalEditarUsuario<?= $u['id'] ?>">
                                    <i class="bi bi-pencil-square me-1"></i> Editar
                                </button>
                            </td>
                        </tr>

                        <!-- Modal Editar Usuario -->
                        <div class="modal fade" id="modalEditarUsuario<?= $u['id'] ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2 text-primary"></i>Editar Usuario: <?= htmlspecialchars($u['username']) ?></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <form method="POST" action="usuarios.php">
                                        <input type="hidden" name="editar_usuario" value="1">
                                        <input type="hidden" name="id_usuario" value="<?= $u['id'] ?>">
                                        
                                        <div class="modal-body">
                                            <div class="mb-3">
                                                <label class="form-label fw-semibold small">Nombre Completo:</label>
                                                <input type="text" class="form-control" name="nombre" value="<?= htmlspecialchars($u['nombre']) ?>" required>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label fw-semibold small">Perfil (Rol):</label>
                                                <select name="perfil" class="form-select" required>
                                                    <option value="admin" <?= $u['perfil'] === 'admin' ? 'selected' : '' ?>>Administrador (Acceso Total)</option>
                                                    <option value="auditor" <?= $u['perfil'] === 'auditor' ? 'selected' : '' ?>>Auditor (Conteo y Reporte)</option>
                                                    <option value="almacenista" <?= $u['perfil'] === 'almacenista' ? 'selected' : '' ?>>Almacenista (Solo Conteo Físico)</option>
                                                </select>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label fw-semibold small">Estado:</label>
                                                <select name="estado" class="form-select" required>
                                                    <option value="1" <?= $u['estado'] == 1 ? 'selected' : '' ?>>Activo</option>
                                                    <option value="0" <?= $u['estado'] == 0 ? 'selected' : '' ?>>Inactivo</option>
                                                </select>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label fw-semibold small">Nueva Contraseña (Dejar en blanco para no cambiar):</label>
                                                <input type="password" class="form-control" name="password" placeholder="••••••••">
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                                            <button type="submit" class="btn btn-primary">Guardar Cambios</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Nuevo Usuario -->
<div class="modal fade" id="modalNuevoUsuario" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-person-plus-fill me-2 text-primary"></i>Crear Nuevo Usuario</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="usuarios.php">
                <input type="hidden" name="crear_usuario" value="1">
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Nombre Completo:</label>
                        <input type="text" class="form-control" name="nombre" placeholder="Ej: Juan Pérez" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Nombre de Usuario (Username / SKU):</label>
                        <input type="text" class="form-control" name="username" placeholder="Ej: jperez" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Contraseña:</label>
                        <input type="password" class="form-control" name="password" placeholder="••••••••" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Perfil (Rol):</label>
                        <select name="perfil" class="form-select" required>
                            <option value="almacenista" selected>Almacenista (Solo Conteo Físico)</option>
                            <option value="auditor">Auditor (Conteo y Reporte Conciliación)</option>
                            <option value="admin">Administrador (Acceso Total)</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Estado Inicial:</label>
                        <select name="estado" class="form-select" required>
                            <option value="1" selected>Activo</option>
                            <option value="0">Inactivo</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Crear Usuario</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
