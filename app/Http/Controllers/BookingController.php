<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Resource;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class BookingController extends Controller
{
    /**
     * Display a listing of the bookings.
     * Accessible by admins to see all bookings, or by other users to see their own.
     */
    public function index(Request $request)
    {
       if (!Auth::check()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $user = Auth::user(); // Get the authenticated user

        if ($user->user_type === 'admin') {
            // Admin can see all bookings
            $bookings = Booking::with(['user', 'resource'])
                                ->latest()
                                ->get();
        } else {
            // Staff and Students (and any other non-admin) can only see their own bookings
            $bookings = Booking::where('user_id', $user->id)
                                ->with(['user', 'resource'])
                                ->latest()
                                ->get();
        }

        // Return the bookings
        return response()->json($bookings);
    
    }

    /**
     * Store a newly created booking in storage.
     * All authenticated users (student, staff, admin) can create bookings.
     */
    public function store(Request $request)
    {
       
        try {
            $validatedData = $request->validate([
                'resource_id' => 'required|exists:resources,id',
                'start_time' => 'required|date|after_or_equal:now',
                'end_time' => 'required|date|after:start_time',
                'purpose' => 'required|string|max:500',
            ]);

            $resource = Resource::find($validatedData['resource_id']);

            if (!$resource) {
                return response()->json(['message' => 'Resource not found.'], 404);
            }

            
            $conflictingBookings = Booking::where('resource_id', $validatedData['resource_id'])
                ->where(function ($query) use ($validatedData) {
                    $query->whereBetween('start_time', [$validatedData['start_time'], $validatedData['end_time']])
                          ->orWhereBetween('end_time', [$validatedData['start_time'], $validatedData['end_time']])
                          ->orWhere(function ($q) use ($validatedData) {
                              $q->where('start_time', '<=', $validatedData['start_time'])
                                ->where('end_time', '>=', $validatedData['end_time']);
                          });
                })
                ->whereIn('status', ['pending', 'approved'])
                ->count();

            if ($resource->capacity == 1 && $conflictingBookings > 0) {
                return response()->json(['message' => 'Resource is already booked or unavailable for the requested time slot.'], 409);
            }

            $booking = Auth::user()->bookings()->create([
                'resource_id' => $validatedData['resource_id'],
                'start_time' => $validatedData['start_time'],
                'end_time' => $validatedData['end_time'],
                'status' => 'pending', 
                'purpose' => $validatedData['purpose'],
            ]);

            $booking->load(['user', 'resource']);

            return response()->json([
                'message' => 'Booking request submitted successfully. Awaiting approval.',
                'booking' => $booking
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Booking creation failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'An unexpected error occurred while creating the booking.'], 500);
        }
    }

    /**
     * Display the specified booking.
     * Users can view their own bookings; admins can view any booking.
     */
    public function show(Booking $booking)
    {
        $user = Auth::user();

        // Allow if it's their own booking OR if the user is an admin
        if ($user->id !== $booking->user_id && $user->user_type != 'admin') {
            return response()->json(['message' => 'Unauthorized to view this booking.'], 403);
        }

        $booking->load(['user', 'resource']);
        return response()->json($booking);
    }

    /**
     * Update the specified booking in storage.
     * Users can update their own pending bookings; admins can update any booking including status.
     */
    public function update(Request $request, Booking $booking)
    {
        $user = Auth::user();

        // Check if the user is authorized to update this specific booking
        $isOwner = ($user->id === $booking->user_id);
        $isAdmin = $user->hasRole('admin');

        // Only allow owner to update if booking is pending or approved
        // Admins can always update
        if (!$isOwner && !$isAdmin) {
            return response()->json(['message' => 'Unauthorized to update this booking.'], 403);
        }

        try {
            $rules = [
                'start_time' => 'required|date|after_or_equal:now',
                'end_time' => 'required|date|after:start_time',
                'purpose' => 'required|string|max:500',
                'status' => 'sometimes|string|in:pending,approved,rejected,cancelled', // 'sometimes' as not all users can change status
            ];

            // Only allow admins to change the booking status
            if (!$isAdmin && $request->has('status')) {
                // If a non-admin tries to send 'status', remove it from validation
                // or explicitly make them unauthorized for this field.
                // For simplicity, we'll remove it from the validation rule.
                unset($rules['status']);
                // Or you could directly return unauthorized
                // return response()->json(['message' => 'Unauthorized to change booking status.'], 403);
            }

            $validatedData = $request->validate($rules);

            // If a non-admin is updating, ensure they don't change a non-pending booking's time/purpose
            // Admins can change status as well.
            if ($isOwner && !$isAdmin && $booking->status !== 'pending') {
                return response()->json(['message' => 'Only pending bookings can be modified by the owner.'], 403);
            }


            // Conflict detection logic (remains the same)
            $conflictingBookings = Booking::where('resource_id', $booking->resource_id)
                ->where('id', '!=', $booking->id)
                ->where(function ($query) use ($validatedData) {
                    $query->whereBetween('start_time', [$validatedData['start_time'], $validatedData['end_time']])
                          ->orWhereBetween('end_time', [$validatedData['start_time'], $validatedData['end_time']])
                          ->orWhere(function ($q) use ($validatedData) {
                              $q->where('start_time', '<=', $validatedData['start_time'])
                                ->where('end_time', '>=', $validatedData['end_time']);
                          });
                })
                ->whereIn('status', ['pending', 'approved'])
                ->count();

            if ($booking->resource->capacity == 1 && $conflictingBookings > 0) {
                return response()->json(['message' => 'Resource is already booked or unavailable for the updated time slot.'], 409);
            }

            $booking->update($validatedData);

            $booking->load(['user', 'resource']);

            return response()->json([
                'message' => 'Booking updated successfully.',
                'booking' => $booking
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Booking update failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'An error occurred while processing your request.'], 500);
        }
    }

    /**
     * Remove the specified booking from storage.
     * Users can cancel their own bookings (if pending/approved); admins can delete any booking.
     */
    public function destroy(Booking $booking)
    {
        $user = Auth::user();

        $isOwner = ($user->id === $booking->user_id);
        $isAdmin = $user->hasRole('admin');

        // Only allow owner to delete if booking is pending or approved
        // Admins can always delete
        if (!$isOwner && !$isAdmin) {
            return response()->json(['message' => 'Unauthorized to delete this booking.'], 403);
        }

        // If the user is the owner, they can only delete if the booking is pending or approved
        if ($isOwner && !$isAdmin && !in_array($booking->status, ['pending', 'approved'])) {
            return response()->json(['message' => 'Only pending or approved bookings can be cancelled by the owner.'], 403);
        }

        try {
            $booking->delete();
            return response()->json(['message' => 'Booking deleted successfully.'], 200);
        } catch (\Exception $e) {
            Log::error('Booking deletion failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'An error occurred while deleting the booking.'], 500);
        }
    }

    /**
     * Approve a booking (Admin only).
     */
    public function approve(Booking $booking)
    {
        $user = Auth::user();
        if (!$user->hasRole('admin')) {
            return response()->json(['message' => 'Unauthorized to approve bookings.'], 403);
        }

        if ($booking->status === 'approved') {
            return response()->json(['message' => 'Booking is already approved.'], 400);
        }

        $booking->status = 'approved';
        $booking->save();
        $booking->load(['user', 'resource']);

        return response()->json([
            'message' => 'Booking approved successfully.',
            'booking' => $booking
        ]);
    }

    /**
     * Reject a booking (Admin only).
     */
    public function reject(Booking $booking)
    {
        $user = Auth::user();
        if (!$user->hasRole('admin')) {
            return response()->json(['message' => 'Unauthorized to reject bookings.'], 403);
        }

        if ($booking->status === 'rejected') {
            return response()->json(['message' => 'Booking is already rejected.'], 400);
        }

        $booking->status = 'rejected';
        $booking->save();
        $booking->load(['user', 'resource']);

        return response()->json([
            'message' => 'Booking rejected successfully.',
            'booking' => $booking
        ]);
    }
}