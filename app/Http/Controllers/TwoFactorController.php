<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Passkeys;
use App\Services\Totp;
use App\Services\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * Second factor: sign-in verification and self-service management.
 */
class TwoFactorController extends Controller
{
    use IssuesTokens;

    public function __construct(private TwoFactor $twoFactor)
    {
    }

    // ===== sign-in ==========================================================

    /**
     * Finish a password sign-in with an authenticator code or a recovery code.
     */
    public function verifyLogin(Request $request): JsonResponse
    {
        $request->validate([
            'mfa_token' => 'required|string',
            'method' => 'required|in:totp,recovery',
            'code' => 'required|string|max:64',
        ]);

        $user = $this->twoFactor->userForChallenge($request->mfa_token);
        if (!$user) {
            return $this->challengeExpired();
        }

        $valid = $request->input('method') === 'totp'
            ? $this->twoFactor->verifyTotp($user, $request->code)
            : $this->twoFactor->useRecoveryCode($user, $request->code);

        if (!$valid) {
            $this->twoFactor->failChallenge($request->mfa_token);
            return response()->json([
                'status' => 'error',
                'code' => 'mfa_invalid_code',
                'message' => 'Invalid code',
            ], 401);
        }

        $this->twoFactor->completeChallenge($request->mfa_token);

        return $this->respondWithToken($user);
    }

    public function passkeyLoginOptions(Request $request, Passkeys $passkeys): JsonResponse
    {
        $request->validate(['mfa_token' => 'required|string']);

        $user = $this->twoFactor->userForChallenge($request->mfa_token);
        if (!$user || !$user->passkeys()->exists()) {
            return $this->challengeExpired();
        }

        return response()->json([
            'status' => 'success',
            'data' => ['options' => $passkeys->authenticationOptions($user, $request->mfa_token)],
        ]);
    }

    public function passkeyLogin(Request $request, Passkeys $passkeys): JsonResponse
    {
        $request->validate([
            'mfa_token' => 'required|string',
            'credential' => 'required|array',
        ]);

        $user = $this->twoFactor->userForChallenge($request->mfa_token);
        if (!$user) {
            return $this->challengeExpired();
        }

        if (!$passkeys->verify($user, $request->mfa_token, $request->input('credential'))) {
            $this->twoFactor->failChallenge($request->mfa_token);
            return response()->json([
                'status' => 'error',
                'code' => 'mfa_invalid_passkey',
                'message' => 'Passkey verification failed',
            ], 401);
        }

        $this->twoFactor->completeChallenge($request->mfa_token);

        return $this->respondWithToken($user);
    }

    private function challengeExpired(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'code' => 'mfa_expired',
            'message' => 'Sign-in expired, please start again',
        ], 401);
    }

    // ===== self-service =====================================================

    public function status(): JsonResponse
    {
        $user = Auth::user();

        return response()->json([
            'status' => 'success',
            'data' => [
                'enabled' => $this->twoFactor->isEnabledFor($user),
                'totp_enabled' => $user->totp_enabled_at !== null,
                'passkeys' => $user->passkeys()->orderBy('created_at')->get(['id', 'name', 'created_at', 'last_used_at']),
                'recovery_codes_remaining' => $this->twoFactor->remainingRecoveryCodes($user),
            ],
        ]);
    }

    /**
     * Start authenticator app setup: a new secret, kept aside until confirmed.
     */
    public function totpSetup(): JsonResponse
    {
        return $this->startTotp(Auth::user());
    }

    private function startTotp(User $user): JsonResponse
    {
        $secret = Totp::generateSecret();
        Cache::put($this->pendingTotpKey($user), encrypt($secret), 900);

        return response()->json([
            'status' => 'success',
            'data' => [
                'secret' => $secret,
                'otpauth_uri' => Totp::provisioningUri($secret, $user->email, $this->issuer()),
            ],
        ]);
    }

    public function totpConfirm(Request $request): JsonResponse
    {
        $request->validate(['code' => 'required|string|max:16']);

        return $this->confirmTotp(Auth::user(), $request->code);
    }

    private function confirmTotp(User $user, string $code): JsonResponse
    {
        $pending = Cache::get($this->pendingTotpKey($user));
        if (!$pending) {
            return response()->json(['status' => 'error', 'code' => 'totp_setup_expired', 'message' => 'Setup expired, please start again'], 422);
        }

        $secret = decrypt($pending);
        $step = Totp::verify($secret, $code);
        if ($step === null) {
            return response()->json(['status' => 'error', 'code' => 'mfa_invalid_code', 'message' => 'Invalid code'], 422);
        }

        $firstFactor = !$this->twoFactor->isEnabledFor($user);
        $user->forceFill([
            'totp_secret' => $secret,
            'totp_enabled_at' => now(),
            'totp_last_timestep' => $step,
        ])->save();
        Cache::forget($this->pendingTotpKey($user));

        return response()->json([
            'status' => 'success',
            'data' => ['recovery_codes' => $this->recoveryCodesForFirstFactor($user, $firstFactor)],
        ]);
    }

    public function totpDisable(Request $request): JsonResponse
    {
        $user = Auth::user();
        if ($error = $this->checkPassword($request, $user)) {
            return $error;
        }
        if ($error = $this->refuseIfLastFactorRequired($user, removesTotp: true)) {
            return $error;
        }

        $user->forceFill(['totp_secret' => null, 'totp_enabled_at' => null, 'totp_last_timestep' => null])->save();
        $this->dropRecoveryCodesIfUnused($user);

        return $this->status();
    }

    public function passkeyRegistrationOptions(Passkeys $passkeys): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => ['options' => $passkeys->registrationOptions(Auth::user())],
        ]);
    }

    public function passkeyRegister(Request $request, Passkeys $passkeys): JsonResponse
    {
        $request->validate([
            'name' => 'nullable|string|max:100',
            'credential' => 'required|array',
        ]);

        return $this->registerPasskey(Auth::user(), $request, $passkeys);
    }

    private function registerPasskey(User $user, Request $request, Passkeys $passkeys): JsonResponse
    {
        $firstFactor = !$this->twoFactor->isEnabledFor($user);

        try {
            $passkey = $passkeys->register($user, $request->input('credential'), (string) $request->input('name', ''));
        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'status' => 'error',
                'code' => 'passkey_registration_failed',
                'message' => 'The passkey could not be registered',
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'passkey' => $passkey->only(['id', 'name', 'created_at', 'last_used_at']),
                'recovery_codes' => $this->recoveryCodesForFirstFactor($user, $firstFactor),
            ],
        ]);
    }

    public function passkeyDelete(Request $request, int $id): JsonResponse
    {
        $user = Auth::user();
        if ($error = $this->checkPassword($request, $user)) {
            return $error;
        }
        if ($error = $this->refuseIfLastFactorRequired($user, removesPasskeyId: $id)) {
            return $error;
        }

        $user->passkeys()->whereKey($id)->delete();
        $this->dropRecoveryCodesIfUnused($user);

        return $this->status();
    }

    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $user = Auth::user();
        if ($error = $this->checkPassword($request, $user)) {
            return $error;
        }

        if (!$this->twoFactor->isEnabledFor($user)) {
            return response()->json(['status' => 'error', 'message' => 'Two-factor authentication is not enabled'], 422);
        }

        return response()->json([
            'status' => 'success',
            'data' => ['recovery_codes' => $this->twoFactor->regenerateRecoveryCodes($user)],
        ]);
    }

    // ===== mandatory enrolment (admin policy) ==============================
    //
    // A password sign-in by a user who must have 2FA but has none gets an
    // enrolment token instead of a session; the session is issued once a
    // first factor is set up with that token.

    public function enrolTotpSetup(Request $request): JsonResponse
    {
        $request->validate(['enrol_token' => 'required|string']);
        $user = $this->twoFactor->userForEnrolment($request->enrol_token);

        return $user ? $this->startTotp($user) : $this->enrolmentExpired();
    }

    public function enrolTotpConfirm(Request $request): JsonResponse
    {
        $request->validate(['enrol_token' => 'required|string', 'code' => 'required|string|max:16']);
        $user = $this->twoFactor->userForEnrolment($request->enrol_token);
        if (!$user) {
            return $this->enrolmentExpired();
        }

        return $this->finishEnrolment($request->enrol_token, $user, $this->confirmTotp($user, $request->code));
    }

    public function enrolPasskeyOptions(Request $request, Passkeys $passkeys): JsonResponse
    {
        $request->validate(['enrol_token' => 'required|string']);
        $user = $this->twoFactor->userForEnrolment($request->enrol_token);
        if (!$user) {
            return $this->enrolmentExpired();
        }

        return response()->json([
            'status' => 'success',
            'data' => ['options' => $passkeys->registrationOptions($user)],
        ]);
    }

    public function enrolPasskey(Request $request, Passkeys $passkeys): JsonResponse
    {
        $request->validate([
            'enrol_token' => 'required|string',
            'name' => 'nullable|string|max:100',
            'credential' => 'required|array',
        ]);
        $user = $this->twoFactor->userForEnrolment($request->enrol_token);
        if (!$user) {
            return $this->enrolmentExpired();
        }

        return $this->finishEnrolment($request->enrol_token, $user, $this->registerPasskey($user, $request, $passkeys));
    }

    /**
     * After a successful first factor, open the session and hand over the recovery codes.
     */
    private function finishEnrolment(string $token, User $user, JsonResponse $result): JsonResponse
    {
        if (!$result->isSuccessful()) {
            return $result;
        }

        $this->twoFactor->completeEnrolment($token);
        $session = $this->respondWithToken($user->fresh());
        $data = $session->getData(true);
        $data['data']['recovery_codes'] = $result->getData(true)['data']['recovery_codes'] ?? null;

        return $session->setData($data);
    }

    private function enrolmentExpired(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'code' => 'mfa_enrolment_expired',
            'message' => 'Setup expired, please sign in again',
        ], 401);
    }

    /**
     * Keep at least one factor when the admin policy requires 2FA for this user.
     */
    private function refuseIfLastFactorRequired(User $user, bool $removesTotp = false, ?int $removesPasskeyId = null): ?JsonResponse
    {
        if (!$this->twoFactor->isRequiredFor($user)) {
            return null;
        }

        $totpLeft = $user->totp_enabled_at !== null && !$removesTotp;
        $passkeysLeft = $user->passkeys()->when($removesPasskeyId, fn ($q) => $q->whereKeyNot($removesPasskeyId))->exists();
        if ($totpLeft || $passkeysLeft) {
            return null;
        }

        return response()->json([
            'status' => 'error',
            'code' => 'mfa_required_by_policy',
            'message' => 'Two-factor authentication is required for your account: add another method first.',
        ], 422);
    }

    // ===== admin ============================================================

    /**
     * Remove every second factor of a user (lost device).
     */
    public function adminReset(int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $this->twoFactor->disableAll($user);

        return response()->json(['status' => 'success', 'message' => 'Two-factor authentication reset']);
    }

    // ===== helpers ==========================================================

    /**
     * Turning a second factor off requires the current password.
     */
    private function checkPassword(Request $request, User $user): ?JsonResponse
    {
        $password = (string) $request->input('password', '');
        if ($password === '' || !Hash::check($password, $user->password)) {
            return response()->json([
                'status' => 'error',
                'code' => 'invalid_password',
                'message' => 'Current password is incorrect',
            ], 422);
        }

        return null;
    }

    /** @return string[]|null */
    private function recoveryCodesForFirstFactor(User $user, bool $firstFactor): ?array
    {
        return $firstFactor ? $this->twoFactor->regenerateRecoveryCodes($user) : null;
    }

    private function dropRecoveryCodesIfUnused(User $user): void
    {
        $user->refresh();
        if (!$this->twoFactor->isEnabledFor($user)) {
            $user->forceFill(['two_factor_recovery_codes' => null])->save();
        }
    }

    private function pendingTotpKey(User $user): string
    {
        return 'mfa:totp-setup:' . $user->id;
    }

    private function issuer(): string
    {
        $name = \App\Models\Setting::where('key', 'application_name')->value('value');

        return $name ?: 'Erugo';
    }
}
