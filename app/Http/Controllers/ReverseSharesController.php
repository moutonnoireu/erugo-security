<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\ReverseShareInvite;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Crypt;
use App\Jobs\sendEmail;
use App\Mail\reverseShareInviteMail;
use App\Models\Setting;
use App\Services\ReverseShareLinks;


class ReverseSharesController extends Controller
{
    private function reverseSharesAllowed(): bool
    {
        $value = Setting::where('key', 'allow_reverse_shares')->first()?->value ?? false;

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function createInvite(Request $request)
    {

        if (!$this->reverseSharesAllowed()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Reverse shares are not allowed'
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'recipient_name' => ['required', 'string', 'max:255'],
            'recipient_email' => ['required', 'email', 'max:255']
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'data' => [
                    'errors' => $validator->errors()
                ]
            ], 422);
        }

        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized'
            ], 401);
        }

        // Check if recipient is an existing non-guest user
        $existingUser = User::where('email', $request->recipient_email)
            ->where(function ($query) {
                $query->where('is_guest', false)
                    ->orWhereNull('is_guest');
            })
            ->first();

        $encryptedToken = null;
        $guestUserId = null;

        if ($existingUser) {
            // Existing user - no token, no guest user
            // They will need to log in with their credentials
            $guestUserId = null;
        } else {
            // Create a guest user for the invite
            $guestUser = User::create([
                'name' => $request->recipient_name,
                'email' => Str::random(20), //we don't need a real email for the guest user
                'password' => Hash::make(Str::random(20)), //set a random password so the user can't login
            ]);
            $guestUser->is_guest = true;
            $guestUser->save();
            $guestUserId = $guestUser->id;

            // Generate a token only for guest users
            $token = auth()->tokenById($guestUser->id);
            $encryptedToken = Crypt::encryptString($token);
        }

        $invite = ReverseShareInvite::create([
            'user_id' => $user->id,
            'guest_user_id' => $guestUserId,
            'recipient_name' => $request->recipient_name,
            'recipient_email' => $request->recipient_email,
            'message' => $request->message,
            'expires_at' => now()->addDays(7)
        ]);

        sendEmail::dispatch($request->recipient_email, reverseShareInviteMail::class, [
            'user' => $user,
            'invite' => $invite,
            'token' => $encryptedToken, // Will be null for existing users
            'isExistingUser' => $existingUser !== null
        ]);

        return response()->json([
            'status' => 'success',
            'data' => [
                'invite' => $invite
            ]
        ]);
    }

    /**
     * Create a reverse share invite and return its link instead of sending an e-mail.
     */
    public function createLink(Request $request, ReverseShareLinks $links)
    {
        if (!$this->reverseSharesAllowed()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Reverse shares are not allowed'
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'recipient_name' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:1000'],
            'expiry_days' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'data' => [
                    'errors' => $validator->errors()
                ]
            ], 422);
        }

        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized'
            ], 401);
        }

        $recipientName = $request->input('recipient_name') ?: 'Invité';
        $expiryDays = (int) ($request->input('expiry_days') ?: 7);

        $guestUser = User::create([
            'name' => $recipientName,
            'email' => Str::random(20), //we don't need a real email for the guest user
            'password' => Hash::make(Str::random(20)), //set a random password so the user can't login
        ]);
        $guestUser->is_guest = true;
        $guestUser->save();

        $encryptedToken = $links->guestToken($guestUser, $expiryDays);

        $invite = ReverseShareInvite::create([
            'user_id' => $user->id,
            'guest_user_id' => $guestUser->id,
            'recipient_name' => $recipientName,
            'recipient_email' => $guestUser->email,
            'message' => $request->input('message'),
            'expires_at' => now()->addDays($expiryDays)
        ]);

        return response()->json([
            'status' => 'success',
            'data' => [
                'invite' => $invite,
                'url' => $links->inviteUrl($invite, $encryptedToken),
                'expires_at' => $invite->expires_at->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            ]
        ], 201);
    }
}
