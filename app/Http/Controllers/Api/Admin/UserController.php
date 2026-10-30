<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\TemporaryPasswords;
use App\Services\Auth\TwoFactorReset;
use App\Services\Notifications\MailSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Accounts are created only here (there is no public registration). With email set up, the user
 * gets a temporary password by email; without it the admin types one. Either way the user must
 * replace it at the first login.
 */
class UserController extends Controller
{
    public function __construct(private TemporaryPasswords $passwords) {}

    public function index(): JsonResponse
    {
        $users = User::query()
            ->withCount(['positions' => fn ($q) => $q->where('quantity', '>', 0), 'orders'])
            ->orderBy('id')
            ->get(['id', 'name', 'email', 'balance_usd', 'is_admin', 'is_system', 'created_at', 'two_factor_confirmed_at', 'telegram_chat_id', 'must_change_password', 'temporary_password_expires_at'])
            ->map(fn (User $user): array => [
                ...$user->only('id', 'name', 'email', 'balance_usd', 'is_admin', 'is_system', 'created_at', 'positions_count', 'orders_count', 'must_change_password'),
                'temporary_password_expires_at' => $user->temporary_password_expires_at?->toIso8601String(),
                'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
                'telegram_linked' => $user->telegram_chat_id !== null,
            ]);

        return response()->json(['data' => $users]);
    }

    public function store(Request $request, MailSettings $mail): JsonResponse
    {
        $byEmail = $mail->isEnabled();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => [$byEmail ? 'exclude' : 'required', Password::defaults()],
            'is_admin' => ['boolean'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'] ?? $this->passwords->generate(),
            'is_admin' => $data['is_admin'] ?? false,
            'balance_usd' => config('market.simulator.starting_balance'),
        ]);

        if ($byEmail) {
            $delivery = $this->passwords->issue($user, $request->user(), isNew: true);
        } else {
            $this->passwords->assign($user, $data['password']);
            $delivery = null;
        }

        return response()->json(['data' => $user->only('id', 'name', 'email', 'balance_usd', 'is_admin'), 'delivery' => $delivery], 201);
    }

    /**
     * Email a new temporary password (forgotten password, expired temporary password, locked out).
     */
    public function temporaryPassword(Request $request, User $user, MailSettings $mail): JsonResponse
    {
        abort_if($user->is_system, 422, 'Akun sistem tidak bisa login.');
        abort_if($user->is($request->user()), 422, 'Ganti password Anda sendiri di Profil.');
        abort_unless($mail->isEnabled(), 422, 'Email belum diaktifkan (Pengaturan → Email).');

        return response()->json(['delivery' => $this->passwords->issue($user, $request->user(), isNew: false)]);
    }

    public function update(Request $request, User $user, TwoFactorReset $twoFactor): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'password' => ['sometimes', 'nullable', Password::defaults()],
            'is_admin' => ['sometimes', 'boolean'],
            'disable_two_factor' => ['sometimes', 'accepted'],
        ]);

        if ($user->is($request->user()) && array_key_exists('is_admin', $data) && ! $data['is_admin']) {
            throw ValidationException::withMessages(['is_admin' => 'Anda tidak bisa mencabut status admin akun sendiri.']);
        }

        // A password typed by an admin is temporary for anyone but the admin themself.
        if (filled($data['password'] ?? null)) {
            $user->is($request->user())
                ? $user->update(['password' => $data['password']])
                : $this->passwords->assign($user, $data['password']);
        }

        // For a user who lost their phone and recovery codes; they get an email notice.
        if (isset($data['disable_two_factor'])) {
            $twoFactor->disable($user, byAdmin: true);
        }

        $user->update(collect($data)->only('name', 'email', 'is_admin')->all());

        return response()->json(['data' => $user->only('id', 'name', 'email', 'balance_usd', 'is_admin')]);
    }

    public function destroy(Request $request, User $user): Response
    {
        if ($user->is($request->user())) {
            throw ValidationException::withMessages(['user' => 'Anda tidak bisa menghapus akun sendiri.']);
        }

        $user->delete();

        return response()->noContent();
    }
}
