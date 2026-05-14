<?php

use Framework\Routing\Router;

/** @var Router $router */

$router->get('/', function () {
    return '<h1 style="color: red;">Welcome to the Home Page!</h1>';
});

$router->get('/about', function () {
    return '<h1 style="color: blue;">About Us</h1>';
});
