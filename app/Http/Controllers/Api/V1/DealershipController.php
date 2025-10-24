<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AutoDealership;
use Illuminate\Http\Request;
use Log;

class DealershipController extends Controller
{
    public function index(Request $request)
    {
        $perPage = (int) $request->query('per_page', '15');
        $isActive = $request->query('is_active');

        $query = AutoDealership::query();

        if ($isActive !== null) {
            $query->where('is_active', (bool) $isActive);
        }

        $dealerships = $query->orderBy('name')->paginate($perPage);

        return response()->json($dealerships);
    }

    public function show($id)
    {
        $dealership = AutoDealership::with(['users', 'shifts', 'tasks'])
            ->find($id);

        if (!$dealership) {
            return response()->json([
                'message' => 'Автосалон не найден'
            ], 404);
        }

        return response()->json($dealership);
    }

    public function store(Request $request)
    {
        Log::info("Request Dealership Store: " . json_encode($request->all()));
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $dealership = AutoDealership::create($validated);

        return response()->json($dealership, 201);
    }

    public function update(Request $request, $id)
    {
        $dealership = AutoDealership::find($id);

        if (!$dealership) {
            return response()->json([
                'message' => 'Автосалон не найден'
            ], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $dealership->update($validated);

        return response()->json($dealership);
    }
}
