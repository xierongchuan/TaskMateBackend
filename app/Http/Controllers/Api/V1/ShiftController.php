<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Models\User;
use App\Services\ShiftService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Auth\Access\AuthorizationException;
use Carbon\Carbon;

class ShiftController extends Controller
{
    public function __construct(
        private readonly ShiftService $shiftService
    ) {
    }

    /**
     * Get list of shifts with filtering and pagination
     *
     * GET /api/v1/shifts
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', '15');
        $dealershipId = $request->query('dealership_id');
        $status = $request->query('status');
        $date = $request->query('date');
        $userId = $request->query('user_id');

        $query = Shift::with(['user', 'dealership', 'replacement.replacingUser', 'replacement.replacedUser']);

        if ($dealershipId) {
            $query->where('dealership_id', $dealershipId);
        }

        if ($userId) {
            $query->where('user_id', $userId);
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($date) {
            $startOfDay = Carbon::parse($date)->startOfDay();
            $endOfDay = Carbon::parse($date)->endOfDay();
            $query->whereBetween('shift_start', [$startOfDay, $endOfDay]);
        }

        $shifts = $query->orderByDesc('shift_start')->paginate($perPage);

        return response()->json($shifts);
    }

    /**
     * Create a new shift
     *
     * POST /api/v1/shifts
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'dealership_id' => 'required|exists:auto_dealerships,id',
            'opening_photo' => 'required|file|image|mimes:jpeg,png,jpg|max:5120',
            'replacing_user_id' => 'nullable|exists:users,id',
            'reason' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->validated();

        try {
            $user = User::findOrFail($data['user_id']);

            // Validate user belongs to the specified dealership
            if (!$this->shiftService->validateUserDealership($user, $data['dealership_id'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'User does not belong to the specified dealership'
                ], 403);
            }

            $replacingUser = null;
            if (isset($data['replacing_user_id'])) {
                $replacingUser = User::findOrFail($data['replacing_user_id']);

                // Validate replacement user belongs to the same dealership
                if (!$this->shiftService->validateUserDealership($replacingUser, $data['dealership_id'])) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Replacement user does not belong to the specified dealership'
                    ], 403);
                }
            }

            $shift = $this->shiftService->openShift(
                $user,
                $data['opening_photo'],
                $replacingUser,
                $data['reason'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => 'Shift opened successfully',
                'data' => $shift->load(['user', 'dealership', 'replacement'])
            ], 201);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to open shift'
            ], 500);
        }
    }

    /**
     * Get a specific shift
     *
     * GET /api/v1/shifts/{id}
     */
    public function show(int $id): JsonResponse
    {
        $shift = Shift::with(['user', 'dealership', 'replacement.replacingUser', 'replacement.replacedUser'])
            ->find($id);

        if (!$shift) {
            return response()->json([
                'success' => false,
                'message' => 'Смена не найдена'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $shift
        ]);
    }

    /**
     * Update a shift (primarily for closing)
     *
     * PUT /api/v1/shifts/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $shift = Shift::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'closing_photo' => 'sometimes|required|file|image|mimes:jpeg,png,jpg|max:5120',
            'status' => 'sometimes|in:open,closed,replaced',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->validated();

        try {
            // If closing photo is provided, close the shift
            if (isset($data['closing_photo'])) {
                $user = $shift->user;
                $updatedShift = $this->shiftService->closeShift($user, $data['closing_photo']);

                return response()->json([
                    'success' => true,
                    'message' => 'Shift closed successfully',
                    'data' => $updatedShift->load(['user', 'dealership', 'replacement'])
                ]);
            }

            // If only status is being updated
            if (isset($data['status'])) {
                $shift->update(['status' => $data['status']]);

                return response()->json([
                    'success' => true,
                    'message' => 'Shift updated successfully',
                    'data' => $shift->load(['user', 'dealership', 'replacement'])
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'No valid fields to update'
            ], 400);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update shift'
            ], 500);
        }
    }

    /**
     * Delete a shift
     *
     * DELETE /api/v1/shifts/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $shift = Shift::findOrFail($id);

        try {
            // Only allow deletion of shifts that are not in progress
            if ($shift->status === 'open' && !$shift->shift_end) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete an active shift'
                ], 400);
            }

            $shift->delete();

            return response()->json([
                'success' => true,
                'message' => 'Shift deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete shift'
            ], 500);
        }
    }

    /**
     * Get current open shifts
     *
     * GET /api/v1/shifts/current
     */
    public function current(Request $request): JsonResponse
    {
        $dealershipId = $request->query('dealership_id');
        $currentShifts = $this->shiftService->getCurrentShifts($dealershipId);

        return response()->json([
            'success' => true,
            'data' => $currentShifts
        ]);
    }

    /**
     * Get shift statistics
     *
     * GET /api/v1/shifts/statistics
     */
    public function statistics(Request $request): JsonResponse
    {
        $dealershipId = $request->query('dealership_id');
        $startDate = $request->query('start_date')
            ? Carbon::parse($request->query('start_date'))
            : Carbon::now()->subDays(7);
        $endDate = $request->query('end_date')
            ? Carbon::parse($request->query('end_date'))
            : Carbon::now();

        $statistics = $this->shiftService->getShiftStatistics($dealershipId, $startDate, $endDate);

        return response()->json([
            'success' => true,
            'data' => $statistics
        ]);
    }

    /**
     * Get shifts for the authenticated user
     *
     * GET /api/v1/shifts/my
     */
    public function myShifts(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        $filters = [
            'status' => $request->query('status'),
            'date_from' => $request->query('date_from')
                ? Carbon::parse($request->query('date_from'))
                : null,
            'date_to' => $request->query('date_to')
                ? Carbon::parse($request->query('date_to'))
                : null,
        ];

        $shifts = $this->shiftService->getUserShifts($user, $filters);

        return response()->json([
            'success' => true,
            'data' => $shifts
        ]);
    }

    /**
     * Get current user's open shift
     *
     * GET /api/v1/shifts/my/current
     */
    public function myCurrentShift(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        $shift = $this->shiftService->getUserOpenShift($user);

        if (!$shift) {
            return response()->json([
                'success' => true,
                'data' => null,
                'message' => 'No active shift found'
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $shift->load(['dealership', 'replacement'])
        ]);
    }
}
