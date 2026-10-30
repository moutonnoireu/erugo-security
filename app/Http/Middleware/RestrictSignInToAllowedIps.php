<?php

namespace App\Http\Middleware;

use App\Services\IpAllowlist;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * When the sign-in IP allowlist is enabled, refuse from any other address:
 *  - every sign-in / account route (password login, password reset,
 *    self-registration, external auth providers);
 *  - every request made by a signed-in, non-guest user.
 *
 * Public share links and guest (reverse share) uploads are not affected,
 * so recipients and invited guests can still use Erugo from anywhere.
 */
class RestrictSignInToAllowedIps
{
    /** Routes that start or recover a session. */
    private const SIGN_IN_ROUTES = [
        'auth.login',
        'auth.forgotPassword',
        'auth.resetPassword',
        'auth.register',
        'auth.verifyEmail',
        'auth.resendVerification',
        'social.provider.login',
        'social.provider.callback',
        'social.provider.link',
    ];

    public function __construct(private IpAllowlist $allowlist)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->allowlist->blocks($request->ip())) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();

        if (in_array($routeName, self::SIGN_IN_ROUTES, true) || $this->signedInMember($request, $routeName)) {
            return $this->deny($request);
        }

        return $next($request);
    }

    /**
     * True when the request belongs to a signed-in user who is not a guest.
     */
    private function signedInMember(Request $request, ?string $routeName): bool
    {
        try {
            $guard = Auth::guard('api');

            if ($routeName === 'auth.refresh') {
                $refreshToken = $request->cookie('refresh_token');
                if (!$refreshToken) {
                    return false;
                }
                $user = $guard->setToken($refreshToken)->user();
            } elseif ($request->bearerToken()) {
                $user = $guard->user();
            } else {
                return false;
            }
        } catch (\Throwable $e) {
            // Invalid / expired token: let the regular auth layer answer.
            return false;
        }

        return $user !== null && !$user->is_guest;
    }

    private function deny(Request $request): Response
    {
        $message = 'Sign-in is not allowed from your network.';

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'status' => 'error',
                'code' => 'ip_not_allowed',
                'message' => $message,
            ], 403);
        }

        return response($message, 403)->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
