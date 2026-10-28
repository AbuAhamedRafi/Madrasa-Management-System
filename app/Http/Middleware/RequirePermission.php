<?php

namespace App\Http\Middleware;

use App\Services\AuditTrail\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePermission
{
    public function __construct(
        protected AuditLogger $auditLogger
    ) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasPermission($permission)) {
            if ($user) {
                $this->auditLogger->record(
                    'AUTHZ_DENIED',
                    $user->id,
                    null,
                    null,
                    ['required_permission' => $permission, 'endpoint' => $request->path()],
                    $request->ip(),
                    $request->attributes->get('request_id')
                );
            }

            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'এই কার্যক্রমে আপনার অনুমতি নেই।',
                    'request_id' => $request->attributes->get('request_id'),
                ],
            ], 403);
        }

        return $next($request);
    }
}

