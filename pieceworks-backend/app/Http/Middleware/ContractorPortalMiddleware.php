<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ContractorPortalMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->role !== 'contractor' || ! $user->contractor_id) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Access restricted to contractor portal users.',
                'data'    => null,
            ], 403);
        }

        $contractor = $user->contractor;

        if (! $contractor || ! $contractor->portal_access) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Contractor portal access is not enabled for your account. Contact the factory administrator.',
                'data'    => null,
            ], 403);
        }

        if ($contractor->status !== 'active') {
            return response()->json([
                'status'  => 'error',
                'message' => "Contractor account is {$contractor->status}. Portal access is suspended.",
                'data'    => null,
            ], 403);
        }

        return $next($request);
    }
}
