<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
// Liveness / readiness probe (unauthenticated).
$routes->get('health', 'HealthController::index');

// CORS preflight for the API. The `cors` filter (Config\Filters) answers it.
$routes->options('api/(:any)', static function (): void {
});

$routes->group('api/v1', static function (RouteCollection $routes): void {
    $routes->post('auth/login', 'AuthController::login');
    $routes->post('auth/refresh', 'AuthController::refresh');

    $routes->group('', ['filter' => 'jwt'], static function (RouteCollection $routes): void {
        $routes->post('auth/logout', 'AuthController::logout');
        $routes->get('auth/me', 'AuthController::me');

        $routes->get('writings', 'WritingController::index');
        $routes->post('writings', 'WritingController::create');
        $routes->delete('writings', 'WritingController::deleteAll');
        $routes->get('writings/(:segment)', 'WritingController::show/$1');
        $routes->delete('writings/(:segment)', 'WritingController::delete/$1');
        $routes->post('writings/(:segment)/retry', 'WritingController::retry/$1');
        $routes->patch('writings/(:segment)/errors', 'WritingController::updateErrors/$1');
        $routes->patch('writings/(:segment)/errors/(:segment)', 'WritingController::updateError/$1/$2');
    });
});