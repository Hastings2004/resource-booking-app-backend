<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    //login method

    public function login(Request $request){
        $request->validate([
            'email'=> 'required|max:255|email|exists:users',
            'password'=> 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if(!$user || !Hash::check($request->password, $user->password)){
            return [
                'errors'=> [
                    'email'=> [
                        'Invalid credentials'
                    ]
                ]
            ];
        }

        $token = $user -> createToken($user -> first_name) ;

        return [
            'user'=> $user,
            'token'=> $token -> plainTextToken,
        ];
    }


    //register method
    public function register(Request $request){
        $field = $request->validate([
            'first_name'=> 'required|max:255',
            'last_name'=> 'required|max:255',
            'user_type'=> 'required|exit',
            'email'=> 'required|max:255|email|unique:users',
            'password'=> 'required|confirmed|min:6',
        ]);

        $user = User::create($field);

        $roleName = $request->input('user_type'); // 'student' or 'staff'
        $role = Role::where('name', $roleName)->first();

        if ($role) {
            $user->roles()->attach($role->id); // This is the line that performs the role assignment
        } else {
            return ['message'=>"role not found"]; // This means the role 'student' or 'staff' doesn't exist in your 'roles' table
        }

        $token = $user->createToken($request->first_name);

        Auth::login($user); // This line is for traditional session-based authentication

        return [
            'user'=> $user,
            'success'=> 'user created successfully',
            'token'=> $token->plainTextToken, // Return the token for API usage
        ];
    }

    //logout method

    public function logout(Request $request){
        $request -> user() -> tokens() -> delete();

        return [
            'message'=> 'you have logged out'
        ];


    }
}