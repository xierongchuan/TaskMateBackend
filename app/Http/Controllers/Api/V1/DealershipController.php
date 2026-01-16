<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AutoDealership;
use Illuminate\Http\Request;
use Log;
use App\Enums\Role;

class DealershipController extends Controller
{
    public function index(Request $request)
    {
        $perPage = (int) $request->query('per_page', '15');
        $isActive = $request->query('is_active');
        $search = $request->query('search');

        $query = AutoDealership::query();

        if ($isActive !== null) {
            $query->where('is_active', (bool) $isActive);
        }

        // Search by name, address, description, and phone
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ILIKE', "%{$search}%")
                  ->orWhere('address', 'ILIKE', "%{$search}%")
                  ->orWhere('description', 'ILIKE', "%{$search}%")
                  ->orWhere('phone', 'ILIKE', "%{$search}%");
            });
        }

        // Scope access for non-owners
        /** @var \App\Models\User $currentUser */
        $currentUser = $request->user();
        if ($currentUser && $currentUser->role !== Role::OWNER) {
             $accessibleIds = $currentUser->getAccessibleDealershipIds();
             $query->whereIn('id', $accessibleIds);
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

    public function destroy($id)
    {
        $dealership = AutoDealership::find($id);

        if (!$dealership) {
            return response()->json([
                'message' => 'Автосалон не найден'
            ], 404);
        }

        // Проверяем наличие связанных данных
        $relatedData = [];

        if ($dealership->users()->count() > 0) {
            $relatedData['users'] = $dealership->users()->count();
        }

        if ($dealership->shifts()->count() > 0) {
            $relatedData['shifts'] = $dealership->shifts()->count();
        }

        if ($dealership->tasks()->count() > 0) {
            $relatedData['tasks'] = $dealership->tasks()->count();
        }

        if (!empty($relatedData)) {
            return response()->json([
                'message' => 'Невозможно удалить автосалон с связанными данными',
                'related_data' => $relatedData,
                'errors' => [
                    'dealership' => ['Автосалон имеет связанные записи: ' . implode(', ', array_keys($relatedData))]
                ]
            ], 422);
        }

        try {
            $dealership->delete();

            return response()->json([
                'success' => true,
                'message' => 'Автосалон успешно удален'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при удалении автосалона',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
