<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserPasskey;
use Cose\Algorithms;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * WebAuthn passkeys used as a second factor (web-auth/webauthn-lib).
 *
 * The relying party id is the host the browser uses to reach Erugo, so
 * passkeys are bound to that domain. HTTPS is required, except on localhost.
 */
class Passkeys
{
    private const TIMEOUT_MS = 60000;
    private const OPTIONS_TTL = 300;

    private ?SerializerInterface $serializer = null;

    public function __construct(private Request $request)
    {
    }

    public function rpId(): string
    {
        return $this->request->getHost();
    }

    /**
     * Options for navigator.credentials.create(), as JSON-ready array.
     */
    public function registrationOptions(User $user): array
    {
        $exclude = $user->passkeys()->get()->map(fn (UserPasskey $passkey) => $this->loadSource($passkey)->getPublicKeyCredentialDescriptor())->all();

        $options = PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create((string) (config('app.name') ?: 'Erugo'), $this->rpId()),
            PublicKeyCredentialUserEntity::create($user->email, (string) $user->id, $user->name ?: $user->email),
            random_bytes(32),
            [
                PublicKeyCredentialParameters::createPk(Algorithms::COSE_ALGORITHM_ES256),
                PublicKeyCredentialParameters::createPk(Algorithms::COSE_ALGORITHM_RS256),
            ],
            AuthenticatorSelectionCriteria::create(
                null,
                AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
                AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED,
            ),
            PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            $exclude,
            self::TIMEOUT_MS,
        );

        $json = $this->serialize($options);
        Cache::put($this->registrationKey($user), $json, self::OPTIONS_TTL);

        return json_decode($json, true);
    }

    /**
     * Verify a navigator.credentials.create() result and store the passkey.
     */
    public function register(User $user, array $credential, string $name): UserPasskey
    {
        $optionsJson = Cache::pull($this->registrationKey($user));
        if (!is_string($optionsJson)) {
            throw new \RuntimeException('Registration expired, please try again.');
        }

        /** @var PublicKeyCredentialCreationOptions $options */
        $options = $this->serializer()->deserialize($optionsJson, PublicKeyCredentialCreationOptions::class, 'json');
        $publicKeyCredential = $this->parseCredential($credential);

        if (!$publicKeyCredential->response instanceof AuthenticatorAttestationResponse) {
            throw new \RuntimeException('Invalid passkey registration.');
        }

        $source = AuthenticatorAttestationResponseValidator::create($this->ceremonies()->creationCeremony())
            ->check($publicKeyCredential->response, $options, $this->rpId());

        $passkey = new UserPasskey(['name' => mb_substr($name, 0, 100) ?: 'Passkey']);
        $passkey->user_id = $user->id;
        $passkey->credential_id = Base64UrlSafe::encodeUnpadded($source->publicKeyCredentialId);
        $passkey->credential_source = $this->serialize($source);
        $passkey->save();

        return $passkey;
    }

    /**
     * Options for navigator.credentials.get(), bound to a login challenge.
     */
    public function authenticationOptions(User $user, string $challengeToken): array
    {
        $allow = $user->passkeys()->get()->map(fn (UserPasskey $passkey) => $this->loadSource($passkey)->getPublicKeyCredentialDescriptor())->all();

        $options = PublicKeyCredentialRequestOptions::create(
            random_bytes(32),
            $this->rpId(),
            $allow,
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_PREFERRED,
            self::TIMEOUT_MS,
        );

        $json = $this->serialize($options);
        Cache::put($this->authenticationKey($challengeToken), $json, self::OPTIONS_TTL);

        return json_decode($json, true);
    }

    /**
     * Verify a navigator.credentials.get() result for this user.
     */
    public function verify(User $user, string $challengeToken, array $credential): bool
    {
        $optionsJson = Cache::pull($this->authenticationKey($challengeToken));
        if (!is_string($optionsJson)) {
            return false;
        }

        try {
            /** @var PublicKeyCredentialRequestOptions $options */
            $options = $this->serializer()->deserialize($optionsJson, PublicKeyCredentialRequestOptions::class, 'json');
            $publicKeyCredential = $this->parseCredential($credential);

            if (!$publicKeyCredential->response instanceof AuthenticatorAssertionResponse) {
                return false;
            }

            $passkey = $user->passkeys()
                ->where('credential_id', Base64UrlSafe::encodeUnpadded($publicKeyCredential->rawId))
                ->first();
            if (!$passkey) {
                return false;
            }

            $source = AuthenticatorAssertionResponseValidator::create($this->ceremonies()->requestCeremony())
                ->check(
                    $this->loadSource($passkey),
                    $publicKeyCredential->response,
                    $options,
                    $this->rpId(),
                    $publicKeyCredential->response->userHandle,
                );
        } catch (\Throwable $e) {
            report($e);
            return false;
        }

        $passkey->credential_source = $this->serialize($source);
        $passkey->last_used_at = now();
        $passkey->save();

        return true;
    }

    /**
     * Options for a sign-in without username or password: the browser offers
     * the passkeys it holds for this site. User verification is required.
     */
    public function discoverableOptions(string $token): array
    {
        $options = PublicKeyCredentialRequestOptions::create(
            random_bytes(32),
            $this->rpId(),
            [],
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            self::TIMEOUT_MS,
        );

        $json = $this->serialize($options);
        Cache::put($this->discoverableKey($token), $json, self::OPTIONS_TTL);

        return json_decode($json, true);
    }

    /**
     * Verify a passwordless sign-in and return the passkey owner.
     */
    public function verifyDiscoverable(string $token, array $credential): ?User
    {
        $optionsJson = Cache::pull($this->discoverableKey($token));
        if (!is_string($optionsJson)) {
            return null;
        }

        try {
            /** @var PublicKeyCredentialRequestOptions $options */
            $options = $this->serializer()->deserialize($optionsJson, PublicKeyCredentialRequestOptions::class, 'json');
            $publicKeyCredential = $this->parseCredential($credential);
            $response = $publicKeyCredential->response;

            // A discoverable credential must say which account it belongs to
            if (!$response instanceof AuthenticatorAssertionResponse || $response->userHandle === null) {
                return null;
            }

            $passkey = UserPasskey::where('credential_id', Base64UrlSafe::encodeUnpadded($publicKeyCredential->rawId))->first();
            if (!$passkey || (string) $passkey->user_id !== $response->userHandle) {
                return null;
            }

            $source = AuthenticatorAssertionResponseValidator::create($this->ceremonies()->requestCeremony())
                ->check($this->loadSource($passkey), $response, $options, $this->rpId(), $response->userHandle);
        } catch (\Throwable $e) {
            report($e);
            return null;
        }

        $passkey->credential_source = $this->serialize($source);
        $passkey->last_used_at = now();
        $passkey->save();

        return $passkey->user;
    }

    private function discoverableKey(string $token): string
    {
        return 'passkey:passwordless:' . hash('sha256', $token);
    }

    private function parseCredential(array $credential): PublicKeyCredential
    {
        return $this->serializer()->deserialize(json_encode($credential), PublicKeyCredential::class, 'json');
    }

    /**
     * Stored credential (public key, counter...). Records written by older
     * versions of web-auth/webauthn-lib (PublicKeyCredentialSource) use the
     * same JSON format and load the same way.
     */
    private function loadSource(UserPasskey $passkey): CredentialRecord
    {
        return $this->serializer()->deserialize($passkey->credential_source, CredentialRecord::class, 'json');
    }

    private function ceremonies(): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory();
        // Browsers only allow WebAuthn over plain HTTP on localhost
        $factory->setSecuredRelyingPartyId(['localhost']);

        return $factory;
    }

    private function serialize(object $value): string
    {
        return $this->serializer()->serialize($value, 'json', ['skip_null_values' => true]);
    }

    private function serializer(): SerializerInterface
    {
        return $this->serializer ??= (new WebauthnSerializerFactory(
            AttestationStatementSupportManager::create([NoneAttestationStatementSupport::create()])
        ))->create();
    }

    private function registrationKey(User $user): string
    {
        return 'passkey:register:' . $user->id;
    }

    private function authenticationKey(string $challengeToken): string
    {
        return 'passkey:login:' . hash('sha256', $challengeToken);
    }
}
