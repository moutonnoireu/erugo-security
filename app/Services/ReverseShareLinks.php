<?php

namespace App\Services;

use App\Models\ReverseShareInvite;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;

/**
 * Links and tokens of reverse share invites, shared by the invite e-mail and the copyable link endpoint.
 */
class ReverseShareLinks
{
    public function __construct(private SettingsService $settings)
    {
    }

    /**
     * Public URL of an invite: a guest opens it with its encrypted token,
     * an existing user is sent to log in and accept the invite by id.
     */
    public function inviteUrl(ReverseShareInvite $invite, ?string $encryptedToken, bool $isExistingUser = false): string
    {
        $base = rtrim((string) $this->settings->get('application_url'), '/');

        if ($isExistingUser) {
            return $base . '/?invite_id=' . $invite->id;
        }

        return $base . '/?invite_token=' . rawurlencode((string) $encryptedToken);
    }

    /**
     * Encrypted JWT of a guest user, valid for the given number of days.
     * The TTL of the guard is restored afterwards.
     */
    public function guestToken(User $guestUser, int $days): string
    {
        $guard = auth();
        $previousTtl = $guard->factory()->getTTL();

        try {
            $guard->setTTL($days * 24 * 60);
            $token = $guard->tokenById($guestUser->id);
        } finally {
            $guard->setTTL($previousTtl);
        }

        return Crypt::encryptString($token);
    }
}
