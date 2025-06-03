<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    //
    public function index()
{
    // ... authentication and authorization checks ...

    // If authorized, fetch all users
    $users = User::all();

    return response()->json([
        "success" => true,
        "users" => $users // <--- This is the expected structure
    ]);
}
}
