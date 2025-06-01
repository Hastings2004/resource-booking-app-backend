<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Resource; // Make sure you have a Resource model
use Carbon\Carbon; // For date/time comparisons for availability

class ResourceSearchController extends Controller
{
    /**
     * Search for resources based on various criteria.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function search(Request $request)
    {
        // Start building the query for resources
        $query = Resource::query();

        // 1. Filter by keyword (e.g., resource name)
        if ($request->has('keyword') && $request->input('keyword') !== null) {
            $keyword = $request->input('keyword');
            $query->where('name', 'like', '%' . $keyword . '%')
                  ->orWhere('description', 'like', '%' . $keyword . '%');
        }

        // 2. Filter by resource type (e.g., 'meeting room', 'projector', 'vehicle')
        if ($request->has('type') && $request->input('type') !== null) {
            $type = $request->input('type');
            $query->where('type', $type);
        }

        // 3. Filter by current availability (e.g., 'available', 'booked', 'maintenance')
        // This assumes a 'status' column on your Resource model.
        if ($request->has('status') && $request->input('status') !== null) {
            $status = $request->input('status');
            $query->where('status', $status);
        }

        // 4. Filter by specific time availability (more complex, checking against bookings)
        // This allows users to find resources available for a specific time slot.
        // Requires 'start_time' and 'end_time' parameters from the request.
        if ($request->has('start_time') && $request->has('end_time') &&
            $request->input('start_time') !== null && $request->input('end_time') !== null) {

            try {
                $requestedStartTime = Carbon::parse($request->input('start_time'));
                $requestedEndTime = Carbon::parse($request->input('end_time'));

                // Basic validation: End time must be after start time
                if ($requestedEndTime->lessThanOrEqualTo($requestedStartTime)) {
                    return response()->json(['message' => 'End time must be after start time.'], 400);
                }

                // Filter out resources that have *any* conflicting approved/pending bookings
                $query->whereDoesntHave('bookings', function ($q) use ($requestedStartTime, $requestedEndTime) {
                    $q->where(function ($subQuery) use ($requestedStartTime, $requestedEndTime) {
                        // Check for overlapping bookings
                        $subQuery->where('start_time', '<', $requestedEndTime)
                                 ->where('end_time', '>', $requestedStartTime);
                    })
                    ->whereIn('status', ['approved', 'pending']); // Only consider active bookings
                });

                // Optionally, if you also want to exclude resources explicitly marked as unavailable
                $query->where('status', '!=', 'maintenance')
                      ->where('status', '!=', 'unavailable');

            } catch (\Exception $e) {
                return response()->json(['message' => 'Invalid date/time format provided.'], 400);
            }
        }


        // Execute the query and get the results
        $resources = $query->with('bookings')->paginate(10); // Paginate results for efficiency

        // Return the resources as a JSON response
        return response()->json([
            'message' => 'Resources fetched successfully.',
            'resources' => $resources->items(),
            'pagination' => [
                'total' => $resources->total(),
                'per_page' => $resources->perPage(),
                'current_page' => $resources->currentPage(),
                'last_page' => $resources->lastPage(),
                'from' => $resources->firstItem(),
                'to' => $resources->lastItem(),
            ],
        ]);
    }

    /**
     * Get details of a single resource by its ID.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $resource = Resource::with('bookings')->find($id);

        if (!$resource) {
            return response()->json(['message' => 'Resource not found.'], 404);
        }

        return response()->json([
            'message' => 'Resource fetched successfully.',
            'resource' => $resource,
        ]);
    }
}