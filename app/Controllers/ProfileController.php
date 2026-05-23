<?php

namespace App\Controllers;

use Framework\Http\Request;

class ProfileController
{
    public function index(Request $request)
    {
        return view('profile', ['name' => 'TTNCode']);
    }
}
