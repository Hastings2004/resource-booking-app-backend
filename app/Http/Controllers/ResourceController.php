<?php

namespace App\Http\Controllers;

use App\Models\Resource;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreResourceRequest; // For validation of new resources
use App\Http\Requests\UpdateResourceRequest; // For validation of updated resources
use Illuminate\Support\Facades\Auth; // To access the authenticated user

class ResourceController extends Controller
{
    /**
     * Display a listing of the resource.
     * All authenticated users can view resources.
     */
    public function index()
    {
        // No specific role check needed here, as all authenticated users can view resources.
        $resources = Resource::all();
        
        return response()->json($resources);
    }

    /**
     * Store a newly created resource in storage.
     * Only 'admin' users can create resources.
     */
    public function store(StoreResourceRequest $request)
    {
        $user = Auth::user();

        // Check if the authenticated user has the 'admin' role
        if (!$user->hasRole('admin')) {
            return response()->json(['message' => 'Unauthorized to create resources.'], 403);
        }

        // The StoreResourceRequest already handles validation
        $resource = Resource::create($request->validated());

        return response()->json([
            'message' => 'Resource created successfully.',
            'resource' => $resource
        ], 201); // 201 Created
    }

    /**
     * Display the specified resource.
     * All authenticated users can view a specific resource.
     */
    public function show(Resource $resource)
    {
        
        return response()->json($resource);
    }

    /**
     * Update the specified resource in storage.
     * Only 'admin' users can update resources.
     */
    public function update(UpdateResourceRequest $request, Resource $resource)
    {
        $user = Auth::user();

        // Check if the authenticated user has the 'admin' role
        if (!$user->hasRole('admin')) {
            return response()->json(['message' => 'Unauthorized to update resources.'], 403);
        }

        // The UpdateResourceRequest already handles validation
        $resource->update($request->validated());

        return response()->json([
            'message' => 'Resource updated successfully.',
            'resource' => $resource
        ]);
    }

    /**
     * Remove the specified resource from storage.
     * Only 'admin' users can delete resources.
     */
    public function destroy(Resource $resource)
    {
        $user = Auth::user();

        // Check if the authenticated user has the 'admin' role
        if (!$user->hasRole('admin')) {
            return response()->json(['message' => 'Unauthorized to delete resources.'], 403);
        }

        try {
            $resource->delete();
            return response()->json(['message' => 'Resource deleted successfully.'], 200);
        } catch (\Exception $e) {
            // Log the error for debugging purposes (optional, but recommended)
            \Log::error('Resource deletion failed: ' . $e->getMessage(), ['resource_id' => $resource->id]);
            return response()->json(['message' => 'An error occurred while deleting the resource.'], 500);
        }
    }

      public function create()
    {
        //
    }

    public function edit(Resource $resource)
    {
        //
    }
}