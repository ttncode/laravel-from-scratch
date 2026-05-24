<?php

namespace App\Controllers;

use Framework\Http\Request;
use Framework\Validation\Validator;

class ProfileController
{
    public function index(Request $request)
    {
        $data = [
            'name' => 'TTNCode',
            'email' => 'ttncode@example.com',
            'password' => 'secret123', 
        ];

        $validator = new Validator($data, [
            'name' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'min:8'],
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();
            return view('profile', ['errors' => $errors]);
        }

        return view('profile', $data);
    }
}
