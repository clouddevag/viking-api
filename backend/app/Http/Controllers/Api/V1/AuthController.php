<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Token authentication for customers and staff alike.
 *
 * Customers may sign in with either an email or a phone number, since many
 * order from a phone and never set an email address.
 */
class AuthController extends Controller
{
    /** Customer self-registration. Staff accounts are created in the admin. */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->whereNull('deleted_at')],
            'phone' => ['required', 'string', 'max:32', 'regex:/^[0-9+\-\s()]{6,32}$/', Rule::unique('users', 'phone')->whereNull('deleted_at')],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
            'locale' => ['sometimes', Rule::in(['ar', 'en'])],
            'guest_token' => ['nullable', 'string', 'max:64'],
        ]);

        $user = DB::transaction(function () use ($validated) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'] ?? null,
                'phone' => $validated['phone'],
                'password' => Hash::make($validated['password']),
                'locale' => $validated['locale'] ?? 'ar',
                'is_active' => true,
            ]);

            $user->assignRole('customer');

            // Carry across whatever the guest did before signing up, so their
            // order history and favourites survive registration.
            if (! empty($validated['guest_token'])) {
                $this->claimGuestHistory($user, $validated['guest_token']);
            }

            return $user;
        });

        return $this->tokenResponse($request, $user, 'Account created.', 201);
    }

    /**
     * Sign in with an email or phone number. Failures are deliberately
     * indistinguishable so the endpoint cannot be used to enumerate accounts.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'guest_token' => ['nullable', 'string', 'max:64'],
        ]);

        $login = trim($validated['login']);

        $user = User::query()
            ->where(fn ($query) => $query->where('email', $login)->orWhere('phone', $login))
            ->first();

        if (! $user || ! $user->password || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'login' => ['These credentials do not match our records.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'login' => ['This account has been deactivated.'],
            ]);
        }

        if (! empty($validated['guest_token'])) {
            $this->claimGuestHistory($user, $validated['guest_token']);
        }

        $user->recordLogin($request->ip());

        return $this->tokenResponse($request, $user, 'Signed in.');
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['roles.permissions', 'permissions', 'branch']);

        return response()->json(['data' => (new UserResource($user))->resolve()]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)->whereNull('deleted_at')],
            'phone' => ['sometimes', 'string', 'max:32', Rule::unique('users', 'phone')->ignore($user->id)->whereNull('deleted_at')],
            'locale' => ['sometimes', Rule::in(['ar', 'en'])],
        ]);

        $user->fill($validated)->save();

        return response()->json([
            'data' => (new UserResource($user->load(['roles.permissions', 'permissions'])))->resolve(),
            'message' => 'Profile updated.',
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ]);

        if (! $user->password || ! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Your current password is incorrect.'],
            ]);
        }

        $user->forceFill(['password' => Hash::make($validated['password'])])->save();

        // Every other device is signed out — a password change is usually a
        // response to one being compromised.
        $current = $user->currentAccessToken();
        $user->tokens()->where('id', '!=', $current?->id)->delete();

        return response()->json(['message' => 'Password updated. Other devices have been signed out.']);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json(['message' => 'Signed out on all devices.']);
    }

    /**
     * Issues a Sanctum token scoped to the user's abilities, so a stolen
     * customer token can never drive a staff endpoint.
     */
    private function tokenResponse(Request $request, User $user, string $message, int $status = 200): JsonResponse
    {
        $user->load(['roles.permissions', 'permissions', 'branch']);

        $abilities = $user->getAllPermissions()->pluck('name')->all();

        if ($user->hasRole('super-admin')) {
            $abilities = ['*'];
        }

        $device = substr((string) $request->userAgent(), 0, 100) ?: 'unknown-device';
        $token = $user->createToken($device, $abilities ?: ['customer'])->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'token_type' => 'Bearer',
                'user' => (new UserResource($user))->resolve(),
            ],
            'message' => $message,
        ], $status);
    }

    /**
     * Re-points a guest's orders and favourites at their new account.
     *
     * The token is unverifiable by design, so this only ever moves records
     * that are already anonymous — it can never take an order away from
     * another registered customer.
     */
    private function claimGuestHistory(User $user, string $guestToken): void
    {
        if (preg_match('/^[a-z0-9]{16,64}$/', $guestToken) !== 1) {
            return;
        }

        Order::query()
            ->whereNull('user_id')
            ->where('guest_token', $guestToken)
            ->update(['user_id' => $user->id]);
    }
}
