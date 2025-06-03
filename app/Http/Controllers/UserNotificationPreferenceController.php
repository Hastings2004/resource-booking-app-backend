<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class UserNotificationPreferenceController extends Controller
{
    /**
     * Display the authenticated user's notification preferences.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $user = $request->user(); // Get the authenticated user

        // Assuming notification preferences are stored in a JSON column
        // on the User model named 'notification_preferences'
        $preferences = $user->notification_preferences;

        // If the column is null or empty, return default preferences
        if (empty($preferences)) {
            $preferences = $this->getDefaultPreferences();
        }

        return response()->json([
            'success' => true,
            'preferences' => $preferences
        ]);
    }

    /**
     * Update the authenticated user's notification preferences.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request)
    {
        try {
            // Validate the incoming preferences.
            // Adjust validation rules based on your actual preference keys.
            $validatedData = $request->validate([
                'preferences' => 'required|array',
                'preferences.email_new_messages' => 'boolean',
                'preferences.email_system_updates' => 'boolean',
                'preferences.in_app_mentions' => 'boolean',
                'preferences.in_app_likes' => 'boolean',
                'preferences.push_reminders' => 'boolean',
                // Add more validation rules for other preferences here
            ]);

            $user = $request->user(); // Get the authenticated user

            // Merge existing preferences with new ones
            // Ensure you don't overwrite preferences not included in the request
            $currentPreferences = $user->notification_preferences ?? $this->getDefaultPreferences();
            $newPreferences = array_merge($currentPreferences, $validatedData['preferences']);

            $user->notification_preferences = $newPreferences;
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Notification preferences updated successfully!',
                'preferences' => $user->notification_preferences // Return the saved preferences
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422); // 422 Unprocessable Entity
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while updating preferences.',
                'error_detail' => $e->getMessage()
            ], 500); // 500 Internal Server Error
        }
    }

    /**
     * Get default notification preferences.
     *
     * @return array
     */
    protected function getDefaultPreferences()
    {
        return [
            'email_new_messages' => true,
            'email_system_updates' => true,
            'in_app_mentions' => true,
            'in_app_likes' => true,
            'push_reminders' => false,
        ];
    }
}