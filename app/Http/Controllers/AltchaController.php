<?php

namespace App\Http\Controllers;

use App\Services\Altcha;

class AltchaController extends Controller
{
    /**
     * Issue a new proof-of-work challenge for the ALTCHA widget.
     */
    public function challenge(Altcha $altcha)
    {
        if (!$altcha->isEnabled()) {
            return response()->json([
                'status' => 'error',
                'message' => 'ALTCHA is not enabled',
            ], 404);
        }

        return response()
            ->json($altcha->createChallenge())
            ->header('Cache-Control', 'no-store');
    }
}
