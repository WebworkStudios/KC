<?php
use App\Actions\HomeAction;
use App\Actions\NotFoundAction;
use Src\Routing\Router;

/** @var Router $router */
$router = $this->getRouter();

// Grundlegende Routen
$router->get('/', HomeAction::class, 'home');

// Admin Routen
$router->group('/admin', function (Router $router) {
    $router->get('/dashboard', 'App\Actions\Admin\DashboardAction::class', 'admin.dashboard');
    $router->get('/profile', 'App\Actions\Admin\ProfileAction::class', 'admin.profile');
});

// Fallback-Route für nicht gefundene Seiten
$router->fallback(NotFoundAction::class);

// Cache-Routes, wenn die Konfiguration dies ermöglicht
if ($this->getConfig()->get('router.cache_enabled', false)) {
    $router->cacheRoutes();
}