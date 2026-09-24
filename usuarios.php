<?php
/**
 * Módulo de Gestión de Usuarios, Roles y Asignación Dinámica de Módulos
 * Sistema de Control de Inventario
 */

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/auth.php';

// Exigir permiso explícito al módulo de usuarios
exigirPermisoModulo('usuarios');

$error = null;
$catalogoModulos = obtenerCatalogoModulos();

// -------------------------------------------------------------------------
// ACCIÓN: CREAR USUARIO
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_usuario'])) {
    $nombre   = trim($_POST['nombre'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $perfil   = $_POST['perfil'] ?? 'almacenista';
    $estado   = intval($_POST['estado'] ?? 1);
    
    // Módulos seleccionados
    $modulosSel = isset($_POST['modulos']) && is_array($_POST['modulos']) ? $_POST['modulos'] : [];
    $permisosStr = !empty($modulosSel) ? implode(',', array_map('trim', $modulosSel)) : '';

    if (empty($nombre) || empty($username) || empty($password)) {
        $error = "Nombre, usuario y contraseña son campos obligatorios.";
    } else {
        try {
            $pdo = getPDOConnection();
            $hashPass = password_hash($password, PASSWORD_BCRYPT);
            
            $stmt = $pdo->prepare("INSERT INTO usuarios (nombre, username, password, perfil, estado, permisos) VALUES (:nombre, :username, :password, :perfil, :estado, :permisos)");
            $stmt->execute([
                ':nombre'   => $nombre,
                ':username' => $username,
                ':password' => $hashPass,
                ':perfil'   => $perfil,
                ':estado'   => $estado,
                ':permisos' => $permisosStr
            ]);

            $_SESSION['flash_success'] = "Usuario <b>" . htmlspecialchars($username) . "</b> creado exitosamente con los módulos seleccionados.";
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

// -------------------------------------------------------------------------
// ACCIÓN: EDITAR USUARIO Y SUS MÓDULOS
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_usuario'])) {
    $idEdit   = intval($_POST['id_usuario']);
    $nombre   = trim($_POST['nombre'] ?? '');
    $perfil   = $_POST['perfil'] ?? 'almacenista';
    $estado   = intval($_POST['estado'] ?? 1);
    $password = trim($_POST['password'] ?? '');

    $modulosSel = isset($_POST['modulos']) && is_array($_POST['modulos']) ? $_POST['modulos'] : [];
    $permisosStr = !empty($modulosSel) ? implode(',', array_map('trim', $modulosSel)) : '';

    if (empty($nombre)) {
        $error = "El nombre no puede estar vacío.";
    } else {
        try {
            $pdo = getPDOConnection();
            
            if (!empty($password)) {
                $hashPass = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("UPDATE usuarios SET nombre = :nombre, perfil = :perfil, estado = :estado, permisos = :permisos, password = :pass WHERE id = :id");
                $stmt->execute([
                    ':nombre'   => $nombre,
                    ':perfil'   => $perfil,
                    ':estado'   => $estado,
                    ':permisos' => $permisosStr,
                    ':pass'     => $hashPass,
                    ':id'       => $idEdit
                ]);
            } else {
                $stmt = $pdo->prepare("UPDATE usuarios SET nombre = :nombre, perfil = :perfil, estado = :estado, permisos = :permisos WHERE id = :id");
                $stmt->execute([
                    ':nombre'   => $nombre,
                    ':perfil'   => $perfil,
                    ':estado'   => $estado,
                    ':permisos' => $permisosStr,
                    ':id'       => $idEdit
                ]);
            }

            // Actualizar permisos en sesión activa si el usuario editó su propia cuenta
            if (isset($_SESSION['usuario_id']) && $_SESSION['usuario_id'] == $idEdit) {
                $_SESSION['usuario_permisos'] = $permisosStr;
            }

            $_SESSION['flash_success'] = "Usuario y asignación de módulos actualizados correctamente.";
            header("Location: usuarios.php");
            exit;
        } catch (Exception $e) {
            $error = "Error al actualizar usuario: " . $e->getMessage();
        }
    }
}

// Obtener lista de usuarios registrados
$usuariosList = [];
try {
    $pdo = getPDOConnection();
    $stmt = $pdo->query("SELECT id, nombre, username, perfil, estado, permisos, fecha_creacion FROM usuarios ORDER BY id ASC");
    $usuariosList = $stmt->fetchAll();
} catch (Exception $e) {
    $error = "Error al obtener lista de usuarios: " . $e->getMessage();
}

include __DIR__ . '/includes/header.php';
?>

<!-- Encabezado de la página -->
<div class="row mb-4 align-items-center">
    <div class="col-md-7">
        <h3 class="fw-bold text-dark mb-1"><i class="bi bi-people-fill text-primary me-2"></i>Gestión de Usuarios & Asignación de Módulos</h3>
        <p class="text-muted mb-0">Crea cuentas de acceso y activa o desactiva de forma dinámica los módulos autorizados para cada usuario.</p>
    </div>
    <div class="col-md-5 text-md-end mt-3 mt-md-0">
        <button type="button" class="btn btn-primary btn-sm rounded-pill fw-semibold px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#modalNuevoUsuario">
            <i class="bi bi-person-plus-fill me-1"></i> Crear Nuevo Usuario
        </button>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show shadow-sm border-0 mb-4" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i><?= htmlspecialchars($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Tabla Principal de Usuarios con Módulos Asignados -->
<div class="card card-custom">
    <div class="card-header-custom d-flex justify-content-between align-items-center">
        <span><i class="bi bi-person-lines-fill me-2 text-primary"></i>Usuarios Registrados y Módulos Autorizados</span>
        <span class="badge bg-light text-muted border"><?= count($usuariosList) ?> usuarios</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-custom align-middle mb-0">
                <thead>
                    <tr>
                        <th>Nombre / Usuario</th>
                        <th>Perfil (Rol)</th>
                        <th>Módulos Asignados (Permisos)</th>
                        <th class="text-center">Estado</th>
                        <th>Fecha Registro</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($usuariosList as $u): ?>
                        <?php
                        $roleBadge = 'badge-role-almacenista';
                        if ($u['perfil'] === 'admin') $roleBadge = 'badge-role-admin';
                        if ($u['perfil'] === 'auditor') $roleBadge = 'badge-role-auditor';

                        // Permisos activos del usuario
                        $userPerms = obtenerPermisosUsuario($u['id']);
                        $totalModulos = count($catalogoModulos);
                        $totalUserPerms = count($userPerms);
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
                            <td>
                                <div class="d-flex flex-wrap gap-1 align-items-center mb-1">
                                    <?php if ($totalUserPerms === $totalModulos || $u['username'] === 'admin'): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 fw-semibold">
                                            <i class="bi bi-shield-check me-1"></i> Todos los Módulos (<?= $totalModulos ?>/<?= $totalModulos ?>)
                                        </span>
                                    <?php elseif ($totalUserPerms === 0): ?>
                                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2 py-1">
                                            <i class="bi bi-slash-circle me-1"></i> Sin Módulos Asignados
                                        </span>
                                    <?php else: ?>
                                        <?php foreach ($userPerms as $modKey): ?>
                                            <?php if (isset($catalogoModulos[$modKey])): ?>
                                                <span class="badge bg-light text-dark border px-2 py-1" title="<?= htmlspecialchars($catalogoModulos[$modKey]['descripcion']) ?>" style="font-size: 0.725rem;">
                                                    <i class="bi <?= $catalogoModulos[$modKey]['icono'] ?> text-primary me-1"></i>
                                                    <?= htmlspecialchars($catalogoModulos[$modKey]['nombre']) ?>
                                                </span>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                <div class="small text-muted" style="font-size: 0.75rem;">
                                    <?= $totalUserPerms ?> de <?= $totalModulos ?> módulos activos
                                </div>
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
                                    <i class="bi bi-pencil-square me-1"></i> Permisos & Editar
                                </button>
                            </td>
                        </tr>

                        <!-- MODAL EDITAR USUARIO Y MÓDULOS -->
                        <div class="modal fade" id="modalEditarUsuario<?= $u['id'] ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content">
                                    <div class="modal-header bg-light">
                                        <h5 class="modal-title fw-bold">
                                            <i class="bi bi-sliders me-2 text-primary"></i>Editar Usuario y Módulos: <?= htmlspecialchars($u['username']) ?>
                                        </h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <form method="POST" action="usuarios.php">
                                        <input type="hidden" name="editar_usuario" value="1">
                                        <input type="hidden" name="id_usuario" value="<?= $u['id'] ?>">
                                        
                                        <div class="modal-body p-4">
                                            <!-- Datos Generales -->
                                            <div class="row g-3 mb-4">
                                                <div class="col-md-6">
                                                    <label class="form-label fw-semibold small">Nombre Completo:</label>
                                                    <input type="text" class="form-control" name="nombre" value="<?= htmlspecialchars($u['nombre']) ?>" required>
                                                </div>

                                                <div class="col-md-3">
                                                    <label class="form-label fw-semibold small">Perfil (Rol):</label>
                                                    <select name="perfil" class="form-select" required onchange="aplicarPresetModulo(this.value, 'modalEditarUsuario<?= $u['id'] ?>')">
                                                        <option value="admin" <?= $u['perfil'] === 'admin' ? 'selected' : '' ?>>Administrador</option>
                                                        <option value="auditor" <?= $u['perfil'] === 'auditor' ? 'selected' : '' ?>>Auditor</option>
                                                        <option value="almacenista" <?= $u['perfil'] === 'almacenista' ? 'selected' : '' ?>>Almacenista</option>
                                                    </select>
                                                </div>

                                                <div class="col-md-3">
                                                    <label class="form-label fw-semibold small">Estado:</label>
                                                    <select name="estado" class="form-select" required>
                                                        <option value="1" <?= $u['estado'] == 1 ? 'selected' : '' ?>>Activo</option>
                                                        <option value="0" <?= $u['estado'] == 0 ? 'selected' : '' ?>>Inactivo</option>
                                                    </select>
                                                </div>

                                                <div class="col-md-12">
                                                    <label class="form-label fw-semibold small">Nueva Contraseña (Dejar en blanco para conservar actual):</label>
                                                    <input type="password" class="form-control" name="password" placeholder="••••••••">
                                                </div>
                                            </div>

                                            <!-- SECTOR DE ASIGNACIÓN DINÁMICA DE MÓDULOS -->
                                            <div class="border rounded-3 p-3 bg-light">
                                                <div class="d-flex justify-content-between align-items-center mb-3">
                                                    <div>
                                                        <h6 class="fw-bold text-dark mb-0"><i class="bi bi-grid-fill text-primary me-2"></i>Asignación de Módulos (Habilitar / Deshabilitar)</h6>
                                                        <span class="small text-muted">Selecciona los módulos a los que este usuario tendrá acceso en su barra lateral.</span>
                                                    </div>
                                                    <div class="btn-group btn-group-sm" role="group">
                                                        <button type="button" class="btn btn-outline-secondary" onclick="marcarTodosModulos('modalEditarUsuario<?= $u['id'] ?>', true)">Marcar Todos</button>
                                                        <button type="button" class="btn btn-outline-secondary" onclick="marcarTodosModulos('modalEditarUsuario<?= $u['id'] ?>', false)">Desmarcar Todos</button>
                                                    </div>
                                                </div>

                                                <div class="row g-3">
                                                    <?php foreach ($catalogoModulos as $modKey => $modInfo): ?>
                                                        <?php $isChecked = in_array($modKey, $userPerms); ?>
                                                        <div class="col-md-6">
                                                            <div class="card card-custom p-2.5 h-100 border">
                                                                <div class="form-check">
                                                                    <input class="form-check-input mod-check" type="checkbox" name="modulos[]" value="<?= $modKey ?>" id="mod_<?= $u['id'] ?>_<?= $modKey ?>" <?= $isChecked ? 'checked' : '' ?>>
                                                                    <label class="form-check-label w-100 cursor-pointer" for="mod_<?= $u['id'] ?>_<?= $modKey ?>">
                                                                        <strong class="d-block text-dark small mb-0.5">
                                                                            <i class="bi <?= $modInfo['icono'] ?> text-primary me-1"></i>
                                                                            <?= htmlspecialchars($modInfo['nombre']) ?>
                                                                        </strong>
                                                                        <span class="text-muted d-block" style="font-size: 0.75rem;"><?= htmlspecialchars($modInfo['descripcion']) ?></span>
                                                                    </label>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>

                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                                            <button type="submit" class="btn btn-primary rounded-pill px-4">Guardar Permisos & Cambios</button>
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

<!-- MODAL CREAR NUEVO USUARIO -->
<div class="modal fade" id="modalNuevoUsuario" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold"><i class="bi bi-person-plus-fill me-2 text-primary"></i>Crear Nuevo Usuario & Asignar Módulos</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="usuarios.php">
                <input type="hidden" name="crear_usuario" value="1">
                
                <div class="modal-body p-4">
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Nombre Completo:</label>
                            <input type="text" class="form-control" name="nombre" placeholder="Ej: Juan Pérez" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Nombre de Usuario (Username):</label>
                            <input type="text" class="form-control" name="username" placeholder="Ej: jperez" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Contraseña Inicial:</label>
                            <input type="password" class="form-control" name="password" placeholder="••••••••" required>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-semibold small">Perfil (Rol Base):</label>
                            <select name="perfil" class="form-select" required onchange="aplicarPresetModulo(this.value, 'modalNuevoUsuario')">
                                <option value="almacenista" selected>Almacenista</option>
                                <option value="auditor">Auditor</option>
                                <option value="admin">Administrador</option>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-semibold small">Estado Inicial:</label>
                            <select name="estado" class="form-select" required>
                                <option value="1" selected>Activo</option>
                                <option value="0">Inactivo</option>
                            </select>
                        </div>
                    </div>

                    <!-- ASIGNACIÓN DE MÓDULOS AL CREAR -->
                    <div class="border rounded-3 p-3 bg-light">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <h6 class="fw-bold text-dark mb-0"><i class="bi bi-grid-fill text-primary me-2"></i>Módulos Asignados (Acceso Habilitado)</h6>
                                <span class="small text-muted">Selecciona los módulos a los que tendrá acceso este usuario.</span>
                            </div>
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="btn btn-outline-secondary" onclick="marcarTodosModulos('modalNuevoUsuario', true)">Marcar Todos</button>
                                <button type="button" class="btn btn-outline-secondary" onclick="marcarTodosModulos('modalNuevoUsuario', false)">Desmarcar Todos</button>
                            </div>
                        </div>

                        <div class="row g-3">
                            <?php foreach ($catalogoModulos as $modKey => $modInfo): ?>
                                <?php $defaultChecked = in_array($modKey, ['dashboard', 'conteo']); ?>
                                <div class="col-md-6">
                                    <div class="card card-custom p-2.5 h-100 border">
                                        <div class="form-check">
                                            <input class="form-check-input mod-check" type="checkbox" name="modulos[]" value="<?= $modKey ?>" id="new_mod_<?= $modKey ?>" <?= $defaultChecked ? 'checked' : '' ?>>
                                            <label class="form-check-label w-100 cursor-pointer" for="new_mod_<?= $modKey ?>">
                                                <strong class="d-block text-dark small mb-0.5">
                                                    <i class="bi <?= $modInfo['icono'] ?> text-primary me-1"></i>
                                                    <?= htmlspecialchars($modInfo['nombre']) ?>
                                                </strong>
                                                <span class="text-muted d-block" style="font-size: 0.75rem;"><?= htmlspecialchars($modInfo['descripcion']) ?></span>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4">Crear Usuario</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Marcar o desmarcar todos los módulos en un modal específico
function marcarTodosModulos(modalId, estado) {
    const modalEl = document.getElementById(modalId);
    if (!modalEl) return;
    const checkboxes = modalEl.querySelectorAll('.mod-check');
    checkboxes.forEach(cb => cb.checked = estado);
}

// Aplicar preset según el perfil seleccionado
function aplicarPresetModulo(perfil, modalId) {
    const modalEl = document.getElementById(modalId);
    if (!modalEl) return;
    
    const presets = {
        'admin': ['dashboard', 'eventos', 'conteo', 'reporte', 'informes', 'importar', 'usuarios', 'backup'],
        'auditor': ['dashboard', 'eventos', 'conteo', 'reporte', 'informes'],
        'almacenista': ['dashboard', 'conteo']
    };

    const modulosPermitidos = presets[perfil] || ['dashboard', 'conteo'];
    const checkboxes = modalEl.querySelectorAll('.mod-check');
    
    checkboxes.forEach(cb => {
        cb.checked = modulosPermitidos.includes(cb.value);
    });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
