<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
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

    public function getProfile(Request $request)
    {
        try {
            // Ensure the user is authenticated
            if (!Auth::check()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            $user = Auth::user();

            // Return the authenticated user's data
            return response()->json([
                'success' => true,
                'user' => $user // Always return a single user object
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to fetch user profile', [
                'user_id' => Auth::id(), // Log the user ID if available
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(), // Add stack trace for better debugging
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to load profile. Please try again.'
            ], 500);
        }
    }
}
