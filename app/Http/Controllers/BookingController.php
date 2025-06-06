<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Resource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
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
        return response()->json([
            "success"=>true,
            "bookings"=> $bookings
        ]);
    
    }

    
    /**
     * Create a new booking with optimized performance and validation
     */
    public function store(Request $request)
    {
        try {
            // Validate request data with custom messages
            $validatedData = $request->validate([
                'resource_id' => 'required|exists:resources,id',
                'start_time' => 'required|date|after_or_equal:now',
                'end_time' => 'required|date|after:start_time',
                'purpose' => 'required|string|min:10|max:500',
            ], [
                'start_time.after_or_equal' => 'Start time must be in the future.',
                'end_time.after' => 'End time must be after start time.',
                'purpose.min' => 'Purpose must be at least 10 characters.',
                'purpose.max' => 'Purpose cannot exceed 500 characters.',
            ]);

            // Parse and validate time duration
            $startTime = Carbon::parse($validatedData['start_time']);
            $endTime = Carbon::parse($validatedData['end_time']);
            
            // Check minimum booking duration (30 minutes)
            if ($endTime->diffInMinutes($startTime) < 30) {
                return response()->json([
                    'success' => false,
                    'message' => 'Minimum booking duration is 30 minutes.'
                ], 422);
            }

            // Check maximum booking duration (8 hours)
            if ($endTime->diffInHours($startTime) > 8) {
                return response()->json([
                    'success' => false,
                    'message' => 'Maximum booking duration is 8 hours.'
                ], 422);
            }

            // Use database transaction for data consistency
            return DB::transaction(function () use ($validatedData, $startTime, $endTime) {
                
                // Get resource with lock to prevent race conditions
                $resource = Resource::lockForUpdate()
                    ->find($validatedData['resource_id']);

                if (!$resource) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Resource not found.'
                    ], 404);
                }

                // Check if resource is active/available
                if (!$resource->is_active) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Resource is currently unavailable.'
                    ], 409);
                }

                // Optimized conflict checking with indexed query
                $conflictCheck = $this->checkBookingConflicts(
                    $validatedData['resource_id'],
                    $startTime,
                    $endTime,
                    $resource->capacity
                );

                if ($conflictCheck['hasConflict']) {
                    return response()->json([
                        'success' => false,
                        'message' => $conflictCheck['message'],
                        'conflicting_bookings' => $conflictCheck['conflicts']
                    ], 409);
                }

                // Check user's concurrent booking limit
                $userActiveBookings = Auth::user()->bookings()
                    ->whereIn('status', ['pending', 'approved'])
                    ->where('end_time', '>', now())
                    ->count();

                if ($userActiveBookings >= 5) { // Configurable limit
                    return response()->json([
                        'success' => false,
                        'message' => 'You have reached the maximum number of active bookings (5).'
                    ], 429);
                }

                // Create the booking
                $booking = Auth::user()->bookings()->create([
                    'resource_id' => $validatedData['resource_id'],
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'status' => 'pending',
                    'purpose' => trim($validatedData['purpose']),
                    'created_at' => now(),
                ]);

                // Load relationships efficiently
                $booking->load(['user:id,name,email', 'resource:id,name,location,capacity']);

                // Clear relevant caches
                $this->clearBookingCaches($validatedData['resource_id'], $startTime, $endTime);

                // Log the booking for auditing
                Log::info('Booking created', [
                    'booking_id' => $booking->id,
                    'user_id' => Auth::id(),
                    'resource_id' => $validatedData['resource_id'],
                    'start_time' => $startTime->toISOString(),
                    'end_time' => $endTime->toISOString(),
                ]);

                // Dispatch notification job (async)
                dispatch(new \App\Jobs\SendBookingNotification($booking, 'created'));

                return response()->json([
                    'success' => true,
                    'message' => 'Booking request submitted successfully. Awaiting approval.',
                    'booking' => [
                        'id' => $booking->id,
                        'resource' => $booking->resource,
                        'start_time' => $booking->start_time->toISOString(),
                        'end_time' => $booking->end_time->toISOString(),
                        'status' => $booking->status,
                        'purpose' => $booking->purpose,
                        'created_at' => $booking->created_at->toISOString(),
                    ]
                ], 201);
            });

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Booking creation failed', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred while creating the booking.'
            ], 500);
        }
    }

    /**
     * Check for booking conflicts with optimized query
     */
    private function checkBookingConflicts($resourceId, Carbon $startTime, Carbon $endTime, $resourceCapacity = 1)
    {
        // Cache key for conflict check
        $cacheKey = "booking_conflicts_{$resourceId}_{$startTime->format('YmdHi')}_{$endTime->format('YmdHi')}";
        
        return Cache::remember($cacheKey, 300, function () use ($resourceId, $startTime, $endTime, $resourceCapacity) {
            
            // Optimized query with proper indexing
            $conflictingBookings = Booking::where('resource_id', $resourceId)
                ->whereIn('status', ['pending', 'approved'])
                ->where(function ($query) use ($startTime, $endTime) {
                    $query->where(function ($q) use ($startTime, $endTime) {
                        // New booking starts during existing booking
                        $q->where('start_time', '<=', $startTime)
                          ->where('end_time', '>', $startTime);
                    })->orWhere(function ($q) use ($startTime, $endTime) {
                        // New booking ends during existing booking  
                        $q->where('start_time', '<', $endTime)
                          ->where('end_time', '>=', $endTime);
                    })->orWhere(function ($q) use ($startTime, $endTime) {
                        // Existing booking is completely within new booking
                        $q->where('start_time', '>=', $startTime)
                          ->where('end_time', '<=', $endTime);
                    });
                })
                ->select(['id', 'start_time', 'end_time', 'user_id'])
                ->with(['user:id,name'])
                ->get();

            $conflictCount = $conflictingBookings->count();

            if ($resourceCapacity == 1 && $conflictCount > 0) {
                $firstConflict = $conflictingBookings->first();
                return [
                    'hasConflict' => true,
                    'message' => sprintf(
                        'Resource is already booked from %s to %s by %s.',
                        $firstConflict->start_time->format('Y-m-d H:i'),
                        $firstConflict->end_time->format('Y-m-d H:i'),
                        $firstConflict->user->name ?? 'Another user'
                    ),
                    'conflicts' => $conflictingBookings->map(function ($booking) {
                        return [
                            'start_time' => $booking->start_time->format('Y-m-d H:i'),
                            'end_time' => $booking->end_time->format('Y-m-d H:i'),
                            'user' => $booking->user->name ?? 'Unknown'
                        ];
                    })
                ];
            }

            if ($conflictCount >= $resourceCapacity) {
                return [
                    'hasConflict' => true,
                    'message' => sprintf(
                        'Resource capacity (%d) is fully booked for the selected time period.',
                        $resourceCapacity
                    ),
                    'conflicts' => $conflictingBookings->map(function ($booking) {
                        return [
                            'start_time' => $booking->start_time->format('Y-m-d H:i'),
                            'end_time' => $booking->end_time->format('Y-m-d H:i'),
                            'user' => $booking->user->name ?? 'Unknown'
                        ];
                    })
                ];
            }

            return [
                'hasConflict' => false,
                'message' => 'No conflicts found',
                'conflicts' => []
            ];
        });
    }

    /**
     * Check availability endpoint for real-time conflict checking
     */
    public function checkAvailability(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'resource_id' => 'required|exists:resources,id',
                'start_time' => 'required|date|after_or_equal:now',
                'end_time' => 'required|date|after:start_time',
            ]);

            $startTime = Carbon::parse($validatedData['start_time']);
            $endTime = Carbon::parse($validatedData['end_time']);

            $resource = Resource::find($validatedData['resource_id']);
            
            if (!$resource || !$resource->is_active) {
                return response()->json([
                    'available' => false,
                    'message' => 'Resource is not available'
                ], 409);
            }

            $conflictCheck = $this->checkBookingConflicts(
                $validatedData['resource_id'],
                $startTime,
                $endTime,
                $resource->capacity
            );

            if ($conflictCheck['hasConflict']) {
                return response()->json([
                    'available' => false,
                    'message' => $conflictCheck['message'],
                    'conflicts' => $conflictCheck['conflicts']
                ], 409);
            }

            return response()->json([
                'available' => true,
                'message' => 'Resource is available for the selected time period'
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'available' => false,
                'message' => 'Invalid request data',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Availability check failed', [
                'error' => $e->getMessage(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'available' => false,
                'message' => 'Error checking availability'
            ], 500);
        }
    }

    /**
     * Get user's bookings with pagination and filtering
     */
    public function getUserBookings(Request $request)
    {
        try {
            $perPage = min($request->get('per_page', 10), 50); // Limit per page
            $status = $request->get('status');
            $upcoming = $request->get('upcoming', false);

            $query = Auth::user()->bookings()
                ->with(['resource:id,name,location'])
                ->select(['id', 'resource_id', 'start_time', 'end_time', 'status', 'purpose', 'created_at']);

            if ($status) {
                $query->where('status', $status);
            }

            if ($upcoming) {
                $query->where('start_time', '>', now());
            }

            $bookings = $query->orderBy('start_time', 'desc')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'bookings' => $bookings->items(),
                'pagination' => [
                    'current_page' => $bookings->currentPage(),
                    'last_page' => $bookings->lastPage(),
                    'per_page' => $bookings->perPage(),
                    'total' => $bookings->total(),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch user bookings', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch bookings'
            ], 500);
        }
    }

    /**
     * Cancel a booking
     */
    public function cancelBooking(Request $request, $bookingId)
    {
        try {
            $booking = Auth::user()->bookings()
                ->where('id', $bookingId)
                ->whereIn('status', ['pending', 'approved'])
                ->where('start_time', '>', now()->addHours(2)) // Must cancel at least 2 hours before
                ->first();

            if (!$booking) {
                return response()->json([
                    'success' => false,
                    'message' => 'Booking not found or cannot be cancelled'
                ], 404);
            }

            $booking->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => $request->get('reason', 'Cancelled by user')
            ]);

            // Clear relevant caches
            $this->clearBookingCaches($booking->resource_id, $booking->start_time, $booking->end_time);

            // Send notification
            dispatch(new \App\Jobs\SendBookingNotification($booking, 'cancelled'));

            return response()->json([
                'success' => true,
                'message' => 'Booking cancelled successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Booking cancellation failed', [
                'booking_id' => $bookingId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel booking'
            ], 500);
        }
    }

    /**
     * Get resource availability for a specific date range
     */
    public function getResourceAvailability(Request $request, $resourceId)
    {
        try {
            $validatedData = $request->validate([
                'start_date' => 'required|date|after_or_equal:today',
                'end_date' => 'required|date|after_or_equal:start_date',
            ]);

            $startDate = Carbon::parse($validatedData['start_date'])->startOfDay();
            $endDate = Carbon::parse($validatedData['end_date'])->endOfDay();

            // Limit date range to prevent abuse
            if ($endDate->diffInDays($startDate) > 30) {
                return response()->json([
                    'success' => false,
                    'message' => 'Date range cannot exceed 30 days'
                ], 422);
            }

            $resource = Resource::find($resourceId);
            if (!$resource) {
                return response()->json([
                    'success' => false,
                    'message' => 'Resource not found'
                ], 404);
            }

            // Cache key for availability data
            $cacheKey = "resource_availability_{$resourceId}_{$startDate->format('Ymd')}_{$endDate->format('Ymd')}";

            $availability = Cache::remember($cacheKey, 3600, function () use ($resourceId, $startDate, $endDate) {
                $bookings = Booking::where('resource_id', $resourceId)
                    ->whereIn('status', ['pending', 'approved'])
                    ->whereBetween('start_time', [$startDate, $endDate])
                    ->select(['start_time', 'end_time', 'status'])
                    ->orderBy('start_time')
                    ->get();

                return $bookings->map(function ($booking) {
                    return [
                        'start_time' => $booking->start_time->toISOString(),
                        'end_time' => $booking->end_time->toISOString(),
                        'status' => $booking->status
                    ];
                });
            });

            return response()->json([
                'success' => true,
                'resource' => [
                    'id' => $resource->id,
                    'name' => $resource->name,
                    'capacity' => $resource->capacity,
                    'is_active' => $resource->is_active
                ],
                'availability' => $availability,
                'date_range' => [
                    'start_date' => $startDate->toDateString(),
                    'end_date' => $endDate->toDateString()
                ]
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid request data',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to fetch resource availability', [
                'resource_id' => $resourceId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch availability data'
            ], 500);
        }
    }

    /**
     * Clear booking-related caches
     */
    private function clearBookingCaches($resourceId, Carbon $startTime, Carbon $endTime)
    {
        // Clear conflict check caches
        $startKey = $startTime->format('YmdHi');
        $endKey = $endTime->format('YmdHi');
        Cache::forget("booking_conflicts_{$resourceId}_{$startKey}_{$endKey}");

        // Clear availability caches for the affected date range
        $startDate = $startTime->format('Ymd');
        $endDate = $endTime->format('Ymd');
        Cache::forget("resource_availability_{$resourceId}_{$startDate}_{$endDate}");

        // Clear weekly availability cache
        $weekStart = $startTime->startOfWeek()->format('Ymd');
        $weekEnd = $startTime->endOfWeek()->format('Ymd');
        Cache::forget("resource_availability_{$resourceId}_{$weekStart}_{$weekEnd}");
    }
}

