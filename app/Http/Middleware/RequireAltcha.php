<?php

namespace App\Http\Middleware;

use App\Services\Altcha;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When ALTCHA is enabled, the request must carry a valid, unused proof-of-work
 * solution in the "altcha" field.
 */
class RequireAltcha
{
    public function __construct(private Altcha $altcha)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->altcha->isEnabled()) {
            return $next($request);
        }

        if ($this->altcha->verify($request->input('altcha'))) {
            return $next($request);
        }

        return response()->json([
            'status' => 'error',
            'code' => 'altcha_invalid',
            'message' => 'Please complete the verification and try again.',
        ], 422);
    }
}
