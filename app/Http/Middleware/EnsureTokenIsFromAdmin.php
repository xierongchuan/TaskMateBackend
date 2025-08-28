<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTokenIsFromAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $req, Closure $next)
    {
        $user = $req->user();
        if (! $user || $user->role !== 'admin') {
            return response()->json(['message' => 'Только для администраторов'], 403);
        }
        return $next($req);
    }
}
