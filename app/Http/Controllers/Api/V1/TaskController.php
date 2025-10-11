<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskAssignment;
use Illuminate\Http\Request;
use Carbon\Carbon;

class TaskController extends Controller
{
    public function index(Request $request)
    {
        $perPage = (int) $request->query('per_page', '15');
        $dealershipId = $request->query('dealership_id');
        $taskType = $request->query('task_type');
        $isActive = $request->query('is_active');
        $tags = $request->query('tags');

        $query = Task::with(['creator', 'dealership', 'assignments.user', 'responses']);

        if ($dealershipId) {
            $query->where('dealership_id', $dealershipId);
        }

        if ($taskType) {
            $query->where('task_type', $taskType);
        }

        if ($isActive !== null) {
            $query->where('is_active', (bool) $isActive);
        }

        if ($tags) {
            $tagsArray = is_array($tags) ? $tags : explode(',', $tags);
            $query->where(function ($q) use ($tagsArray) {
                foreach ($tagsArray as $tag) {
                    $q->orWhereJsonContains('tags', trim($tag));
                }
            });
        }

        $tasks = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json($tasks);
    }

    public function show($id)
    {
        $task = Task::with([
            'creator',
            'dealership',
            'assignments.user',
            'responses.user'
        ])->find($id);

        if (!$task) {
            return response()->json([
                'message' => 'Задача не найдена'
            ], 404);
        }

        return response()->json($task);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'comment' => 'nullable|string',
            'dealership_id' => 'nullable|exists:auto_dealerships,id',
            'appear_date' => 'nullable|date',
            'deadline' => 'nullable|date',
            'recurrence' => 'nullable|string|in:daily,weekly,monthly',
            'task_type' => 'required|string|in:individual,group',
            'response_type' => 'required|string|in:acknowledge,complete',
            'tags' => 'nullable|array',
            'assigned_users' => 'nullable|array',
            'assigned_users.*' => 'exists:users,id',
        ]);

        $task = Task::create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'comment' => $validated['comment'] ?? null,
            'creator_id' => auth()->id(),
            'dealership_id' => $validated['dealership_id'] ?? null,
            'appear_date' => $validated['appear_date'] ?? null,
            'deadline' => $validated['deadline'] ?? null,
            'recurrence' => $validated['recurrence'] ?? null,
            'task_type' => $validated['task_type'],
            'response_type' => $validated['response_type'],
            'tags' => $validated['tags'] ?? null,
        ]);

        // Assign users
        if (!empty($validated['assigned_users'])) {
            foreach ($validated['assigned_users'] as $userId) {
                TaskAssignment::create([
                    'task_id' => $task->id,
                    'user_id' => $userId,
                ]);
            }
        }

        return response()->json($task->load(['assignments.user']), 201);
    }

    public function update(Request $request, $id)
    {
        $task = Task::find($id);

        if (!$task) {
            return response()->json([
                'message' => 'Задача не найдена'
            ], 404);
        }

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'comment' => 'nullable|string',
            'dealership_id' => 'nullable|exists:auto_dealerships,id',
            'appear_date' => 'nullable|date',
            'deadline' => 'nullable|date',
            'recurrence' => 'nullable|string|in:daily,weekly,monthly',
            'task_type' => 'sometimes|required|string|in:individual,group',
            'response_type' => 'sometimes|required|string|in:acknowledge,complete',
            'tags' => 'nullable|array',
            'is_active' => 'boolean',
        ]);

        $task->update($validated);

        return response()->json($task->load(['assignments.user', 'responses.user']));
    }

    public function postponed(Request $request)
    {
        $dealershipId = $request->query('dealership_id');

        $query = Task::with(['creator', 'dealership', 'responses'])
            ->where('postpone_count', '>', 0)
            ->where('is_active', true);

        if ($dealershipId) {
            $query->where('dealership_id', $dealershipId);
        }

        $postponedTasks = $query->orderByDesc('postpone_count')->get();

        return response()->json($postponedTasks);
    }
}
