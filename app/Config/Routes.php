<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');

// app/Config/Routes.php

$routes->get('/', 'Home::index');
$routes->group('payments', function ($routes) {
    $routes->get('/', 'Payments::index');
    // Diğer payments endpoint'leri burada tanımlanacak
});
$routes->group('user', function ($routes) {
    $routes->get('signin', 'User::signin');
    $routes->get('signout', 'User::signout');
    // Diğer user endpoint'leri burada tanımlanacak
});