<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;

/**
 * Issue the JWT access token and the refresh-token cookie for a user.
 */
trait IssuesTokens
{
    protected function respondWithToken($user)
    {
        $token = Auth::login($user);

        if (!$token) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized'
            ], 401);
        }

        $twentyFourHours = 60 * 60 * 24;
        $refreshToken = Auth::setTTL($twentyFourHours)->tokenById($user->id);

        $cookie = cookie('refresh_token', $refreshToken, $twentyFourHours, null, null, false, true);

        return response()->json([
            'status' => 'success',
            'message' => 'Login successful',
            'data' => [
                'access_token' => $token,
                'token_type' => 'Bearer',
                'expires_in' => Auth::factory()->getTTL() * 60,
                'guest' => $user->is_guest
            ]
        ])->withCookie($cookie);
    }
}
