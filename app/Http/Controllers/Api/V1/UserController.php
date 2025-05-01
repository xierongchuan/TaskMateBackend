<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\User;
use Js;

class UserController extends Controller
{
    public function self(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }
}
