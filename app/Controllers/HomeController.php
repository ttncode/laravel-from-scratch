<?php

namespace App\Controllers;

use Framework\Http\Request;

class HomeController
{
    public function index(Request $request)
    {
        return '<h1 style="color: blue;">Welcome to the Home Page!</h1>';
    }
}
