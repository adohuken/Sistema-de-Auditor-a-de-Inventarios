<?php
/**
 * Encabezado común con Menú Lateral (Sidebar Navigation)
 * Sistema de Auditoría de Inventario
 */

require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../auth.php';

// Garantizar que la página requiera autenticación
exigirAutenticacion();
$usuarioActual = obtenerUsuarioActual();
$eventoActual = obtenerEventoActivo();
$todosEventos = obtenerTodosEventos();
$paginaActual = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Auditoría de Inventarios Físicos</title>
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="assets/favicon.png">
    <link rel="shortcut icon" type="image/png" href="assets/favicon.png">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Custom CSS (Sidebar & Panel) -->
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<div class="app-layout">

    <!-- =========================================================================
         MENÚ LATERAL VERTICAL (SIDEBAR NAVIGATION)
         ========================================================================= -->
    <aside class="sidebar-wrapper no-print" id="sidebarWrapper">
        <!-- Logo y Nombre de la Aplicación -->
        <a class="sidebar-brand" href="index.php">
            <i class="bi bi-shield-check"></i>
            <span>AUDITORÍA <span class="fw-light text-white-50">CONTROL</span></span>
        </a>

        <!-- Menú de Navegación Lateral -->
        <ul class="sidebar-menu">
            <?php 
            $hayPrincipales = tienePermisoModulo('dashboard') || tienePermisoModulo('eventos') || tienePermisoModulo('conteo') || tienePermisoModulo('reporte') || tienePermisoModulo('informes');
            $hayAdmin = tienePermisoModulo('importar') || tienePermisoModulo('usuarios') || tienePermisoModulo('backup');
            ?>

            <?php if ($hayPrincipales): ?>
            <li class="menu-header">Módulos Principales</li>

            <!-- Dashboard Ejecutivo -->
            <?php if (tienePermisoModulo('dashboard')): ?>
            <li>
                <a class="sidebar-link <?= ($paginaActual === 'index.php' || $paginaActual === 'dashboard.php') ? 'active' : '' ?>" href="index.php">
                    <i class="bi bi-speedometer2"></i>
                    <span>Dashboard Ejecutivo</span>
                </a>
            </li>
            <?php endif; ?>

            <!-- Auditorías e Histórico -->
            <?php if (tienePermisoModulo('eventos')): ?>
            <li>
                <a class="sidebar-link <?= ($paginaActual === 'eventos.php') ? 'active' : '' ?>" href="eventos.php">
                    <i class="bi bi-calendar-event"></i>
                    <span>Auditorías & Histórico</span>
                </a>
            </li>
            <?php endif; ?>

            <!-- Toma Física Real -->
            <?php if (tienePermisoModulo('conteo')): ?>
            <li>
                <a class="sidebar-link <?= ($paginaActual === 'conteo.php') ? 'active' : '' ?>" href="conteo.php">
                    <i class="bi bi-barcode"></i>
                    <span>Toma Física Real</span>
                </a>
            </li>
            <?php endif; ?>

            <!-- Conciliación y Diferencias -->
            <?php if (tienePermisoModulo('reporte')): ?>
            <li>
                <a class="sidebar-link <?= ($paginaActual === 'reporte.php') ? 'active' : '' ?>" href="reporte.php">
                    <i class="bi bi-bar-chart-line"></i>
                    <span>Conciliación</span>
                </a>
            </li>
            <?php endif; ?>

            <!-- Centro de Informes -->
            <?php if (tienePermisoModulo('informes')): ?>
            <li>
                <a class="sidebar-link <?= ($paginaActual === 'informes.php') ? 'active' : '' ?>" href="informes.php">
                    <i class="bi bi-file-earmark-bar-graph"></i>
                    <span>Centro de Informes</span>
                </a>
            </li>
            <?php endif; ?>
            <?php endif; ?>

            <!-- Sección Administración -->
            <?php if ($hayAdmin): ?>
            <li class="menu-header">Administración</li>

            <!-- Carga Stock ERP -->
            <?php if (tienePermisoModulo('importar')): ?>
            <li>
                <a class="sidebar-link <?= ($paginaActual === 'importar.php') ? 'active' : '' ?>" href="importar.php">
                    <i class="bi bi-file-earmark-arrow-up"></i>
                    <span>Carga Stock ERP</span>
                </a>
            </li>
            <?php endif; ?>

            <!-- Usuarios y Roles -->
            <?php if (tienePermisoModulo('usuarios')): ?>
            <li>
                <a class="sidebar-link <?= ($paginaActual === 'usuarios.php') ? 'active' : '' ?>" href="usuarios.php">
                    <i class="bi bi-people"></i>
                    <span>Usuarios & Roles</span>
                </a>
            </li>
            <?php endif; ?>

            <!-- Backup & Reseteo BD -->
            <?php if (tienePermisoModulo('backup')): ?>
            <li>
                <a class="sidebar-link <?= ($paginaActual === 'backup.php') ? 'active' : '' ?>" href="backup.php">
                    <i class="bi bi-database-gear"></i>
                    <span>Backup & Reseteo BD</span>
                </a>
            </li>
            <?php endif; ?>
            <?php endif; ?>
        </ul>

        <!-- Indicador de Auditoría Vista (Ubicado Abajo, justo Arriba de Admin) -->
        <div class="sidebar-event-card">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="text-uppercase text-white-50 fw-bold" style="font-size: 0.65rem; letter-spacing: 0.5px;">Auditoría Vista</span>
                <?php if ($eventoActual && $eventoActual['estado'] === 'activa'): ?>
                    <span class="badge bg-success text-white" style="font-size: 0.6rem;"><i class="bi bi-play-fill"></i> ACTIVA</span>
                <?php else: ?>
                    <span class="badge bg-secondary text-white" style="font-size: 0.6rem;"><i class="bi bi-lock-fill"></i> CERRADA</span>
                <?php endif; ?>
            </div>
            
            <div class="event-title mb-2" title="<?= htmlspecialchars($eventoActual['nombre_evento'] ?? 'Sin Selección') ?>">
                <?= htmlspecialchars($eventoActual['nombre_evento'] ?? 'Ninguna Seleccionada') ?>
            </div>

            <!-- Desplegable para Cambiar de Auditoría -->
            <div class="dropdown">
                <button class="btn btn-dark btn-sm w-100 dropdown-toggle text-start d-flex justify-content-between align-items-center py-1 px-2 border-0" type="button" data-bs-toggle="dropdown" style="background: rgba(255,255,255,0.1); font-size: 0.75rem;">
                    <span>Cambiar Evento</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-dark shadow-lg" style="max-height: 240px; overflow-y: auto; font-size: 0.8rem;">
                    <li><h6 class="dropdown-header text-uppercase" style="font-size: 0.65rem;">Seleccionar Auditoría:</h6></li>
                    <?php foreach ($todosEventos as $ev): ?>
                        <li>
                            <a class="dropdown-item py-1.5 <?= ($eventoActual && $eventoActual['id'] == $ev['id']) ? 'active fw-bold' : '' ?>" href="eventos.php?seleccionar_id=<?= $ev['id'] ?>&redirect=<?= urlencode($_SERVER['REQUEST_URI']) ?>">
                                <?= htmlspecialchars($ev['nombre_evento']) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-primary fw-bold" href="eventos.php"><i class="bi bi-plus-circle me-1"></i>Gestionar Auditorías</a></li>
                </ul>
            </div>
        </div>

        <!-- Pie de la Barra Lateral: Usuario conectado y Salir -->
        <div class="sidebar-footer">
            <div class="d-flex align-items-center gap-2 overflow-hidden">
                <div class="stat-icon primary py-0 px-2" style="width: 34px; height: 34px; font-size: 1rem;">
                    <i class="bi bi-person"></i>
                </div>
                <div class="overflow-hidden">
                    <div class="text-white fw-bold text-truncate small" style="max-width: 130px;"><?= htmlspecialchars($usuarioActual['nombre']) ?></div>
                    <?php
                    $roleClass = 'badge-role-almacenista';
                    if ($usuarioActual['perfil'] === 'admin') $roleClass = 'badge-role-admin';
                    if ($usuarioActual['perfil'] === 'auditor') $roleClass = 'badge-role-auditor';
                    ?>
                    <span class="badge <?= $roleClass ?> text-uppercase" style="font-size: 0.65rem;">
                        <?= htmlspecialchars($usuarioActual['perfil']) ?>
                    </span>
                </div>
            </div>

            <a href="logout.php" class="btn btn-outline-light btn-sm rounded-circle p-1.5 ms-2" title="Cerrar Sesión" style="width: 34px; height: 34px; display: flex; align-items: center; justify-content: center;">
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>
    </aside>

    <!-- =========================================================================
         CONTENEDOR PRINCIPAL DE PÁGINA (MAIN WRAPPER)
         ========================================================================= -->
    <div class="main-wrapper">
        
        <!-- Topbar Superior Delgado -->
        <header class="topbar-custom no-print">
            <div class="d-flex align-items-center gap-3">
                <!-- Botón Toggle para Móviles -->
                <button class="btn btn-light btn-sm d-lg-none border" id="btnToggleSidebar">
                    <i class="bi bi-list fs-5"></i>
                </button>

                <div class="fw-bold text-dark d-none d-sm-block">
                    <i class="bi bi-shield-check text-primary me-1"></i> Sistema de Auditoría de Inventarios
                </div>
            </div>

            <div class="d-flex align-items-center gap-2">
                <?php if ($eventoActual): ?>
                    <span class="badge bg-light text-dark border py-1.5 px-3 rounded-pill fw-normal">
                        <b>Auditoría:</b> <?= htmlspecialchars($eventoActual['nombre_evento']) ?>
                    </span>
                <?php endif; ?>
            </div>
        </header>

        <!-- Main Content Body -->
        <main class="main-content">

        <!-- Notificaciones Flash -->
        <?php if (isset($_SESSION['flash_success'])): ?>
            <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 shadow-sm no-print mb-4" role="alert">
                <i class="bi bi-check-circle-fill fs-5"></i>
                <div><?= $_SESSION['flash_success']; ?></div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['flash_success']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['flash_error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 shadow-sm no-print mb-4" role="alert">
                <i class="bi bi-exclamation-triangle-fill fs-5"></i>
                <div><?= $_SESSION['flash_error']; ?></div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>
