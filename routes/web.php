<?php

use App\Controllers\HomeController;
use Framework\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index']);

Route::get('/about', function () {
    return '<h1 style="color: blue;">About Us</h1>';
});
