<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ImportantLink;
use Illuminate\Http\Request;
use Log;

class ImportantLinkController extends Controller
{
    public function index(Request $request)
    {
        $perPage = (int) $request->query('per_page', '15');
        $dealershipId = $request->query('dealership_id') !== null && $request->query('dealership_id') !== '' ? (int) $request->query('dealership_id') : null;
        $isActive = $request->query('is_active');
        $search = $request->query('search');

        $query = ImportantLink::with(['creator', 'dealership']);

        if ($dealershipId !== null) {
            $query->where('dealership_id', $dealershipId);
        }

        if ($isActive !== null) {
            $query->where('is_active', (bool) $isActive);
        }

        // Поиск по title, url и description
        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'ilike', "%{$search}%")
                  ->orWhere('url', 'ilike', "%{$search}%")
                  ->orWhere('description', 'ilike', "%{$search}%");
            });
        }

        $links = $query->orderBy('sort_order')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json($links);
    }

    public function show($id)
    {
        $link = ImportantLink::with(['creator', 'dealership'])->find($id);

        if (!$link) {
            return response()->json([
                'message' => 'Ссылка не найдена'
            ], 404);
        }

        return response()->json($link);
    }

    public function store(Request $request)
    {
        Log::info("Request ImportantLink Store: " . json_encode($request->all()));
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'url' => 'required|string|max:1000|url',
            'description' => 'nullable|string',
            'category' => 'nullable|string|max:50',
            'dealership_id' => 'nullable|integer|exists:auto_dealerships,id',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ]);

        // Устанавливаем creator_id из текущего пользователя
        $validated['creator_id'] = $request->user()->id;

        $link = ImportantLink::create($validated);

        // Загружаем связи для ответа
        $link->load(['creator', 'dealership']);

        return response()->json($link, 201);
    }

    public function update(Request $request, $id)
    {
        $link = ImportantLink::find($id);

        if (!$link) {
            return response()->json([
                'message' => 'Ссылка не найдена'
            ], 404);
        }

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'url' => 'sometimes|required|string|max:1000|url',
            'description' => 'nullable|string',
            'category' => 'nullable|string|max:50',
            'dealership_id' => 'nullable|integer|exists:auto_dealerships,id',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ]);

        $link->update($validated);

        // Загружаем связи для ответа
        $link->load(['creator', 'dealership']);

        return response()->json($link);
    }

    public function destroy($id)
    {
        $link = ImportantLink::find($id);

        if (!$link) {
            return response()->json([
                'message' => 'Ссылка не найдена'
            ], 404);
        }

        try {
            $link->delete();

            return response()->json([
                'success' => true,
                'message' => 'Ссылка успешно удалена'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при удалении ссылки',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
