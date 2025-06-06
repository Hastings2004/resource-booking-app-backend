<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
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
        return response()->json([
            'message' => 'Invalid credentials'
        ], 401);
    }

    // Load user with roles
    $user->load('roles');
    
    // Get the first role ID (assuming user has at least one role)
    $roleId = $user->roles->first() ? $user->roles->first()->id : null;
    
    $token = $user->createToken($user->first_name);

    return response()->json([
        'user' => [
            'id' => $user->id,
            'name' => $user->first_name . ' ' . $user->last_name,
            'email' => $user->email,
            'role_id' => $roleId,
            // 'user_type' => $user->user_type, // Include this too
        ],
        'success' => true,
        'token' => $token->plainTextToken,
    ], 200);
}


    //register method
    public function register(Request $request){
        $field = $request->validate([
            'first_name'=> 'required|max:255',
            'last_name'=> 'required|max:255',
            'user_type'=> 'required',
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
            'success'=> true,
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

    /**
     * Get a list of all users.
     * Accessible only by 'admin' users.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        if (!Auth::check()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $user = Auth::user();

        if ($user->user_type === 'admin') {
            // Admin can see all users (if that's the intended use of this endpoint)
            $users = User::all();
            return response()->json([
                'success' => true,
                'users' => $users // Changed 'user' to 'users' to reflect it's a collection
            ], 200);
        } else {
            // Non-admin users see only their own profile
            return response()->json([
                'success' => true,
                'user' => $user // This will be a single user object
            ], 200);
        }
    }

    public function update(Request $request, User $user)
    {
        $authenticatedUser = Auth::user();

        // Authorization check: Admin can update any user, regular user can only update themselves
        if (!$authenticatedUser || ($authenticatedUser->user_type !== 'admin' && $authenticatedUser->id !== $user->id)) {
            return response()->json(['message' => 'Unauthorized to update this user.'], 403);
        }

        try {
            $rules = [
                'first_name' => ['sometimes', 'string', 'max:255'],
                'last_name' => ['sometimes', 'string', 'max:255'],
                'email' => ['sometimes', 'string', 'email', 'max:255', 'unique:users,email,' . $user->id], // Unique except for current user's email
                'password' => ['nullable', 'string', 'min:8', 'confirmed'], // Password is optional for update
                'user_type' => ['sometimes', 'string', 'in:regular,admin,staff,student'], // Only admin should be able to change user_type
            ];

            // If the authenticated user is NOT an admin, remove user_type from rules
            if ($authenticatedUser->user_type !== 'admin') {
                unset($rules['user_type']);
            }

            $validatedData = $request->validate($rules);

            // Hash password if it's provided in the update request
            if (isset($validatedData['password'])) {
                $validatedData['password'] = Hash::make($validatedData['password']);
            }

            $user->update($validatedData);

            return response()->json([
                'message' => 'User updated successfully!',
                'user' => $user,
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('User update failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'message' => 'An unexpected error occurred during user update.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a user.
     * Accessible only by 'admin' users.
     *
     * @param  \App\Models\User  $user
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(User $user)
    {
        // Authorization check: Only admins can delete users
        if (Auth::check() && Auth::user()->user_type === 'admin') {
            // Prevent admin from deleting themselves (optional security measure)
            if (Auth::user()->id === $user->id) {
                return response()->json(['message' => 'You cannot delete your own admin account.'], 403);
            }

            $user->delete();
            return response()->json(['message' => 'User deleted successfully.'], 200);
        }

        return response()->json(['message' => 'Unauthorized to delete users.'], 403);
    }
}