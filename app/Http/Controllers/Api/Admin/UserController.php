<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\InviteUser;
use App\Services\Notifications\MailSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Throwable;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::query()
            ->withCount(['positions' => fn ($q) => $q->where('quantity', '>', 0), 'orders'])
            ->orderBy('id')
            ->get(['id', 'name', 'email', 'balance_usd', 'is_admin', 'is_system', 'created_at', 'two_factor_confirmed_at', 'telegram_chat_id'])
            ->map(fn (User $user): array => [
                ...$user->only('id', 'name', 'email', 'balance_usd', 'is_admin', 'is_system', 'created_at', 'positions_count', 'orders_count'),
                'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
                'telegram_linked' => $user->telegram_chat_id !== null,
            ]);

        return response()->json(['data' => $users]);
    }

    /**
     * Create an account with a password set by the admin, or (invite) let the user set it from an email link.
     */
    public function store(Request $request, MailSettings $mail): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'invite' => ['sometimes', 'boolean'],
            'password' => ['exclude_if:invite,true', 'required', Password::defaults()],
            'is_admin' => ['boolean'],
        ]);
        $invite = (bool) ($data['invite'] ?? false);

        if ($invite && ! $mail->isEnabled()) {
            throw ValidationException::withMessages(['invite' => 'Email belum diaktifkan (Pengaturan → Email). Isi password secara manual.']);
        }

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            // Nobody knows this one; the invitation link replaces it.
            'password' => $invite ? Str::password(40) : $data['password'],
            'is_admin' => $data['is_admin'] ?? false,
            'balance_usd' => config('market.simulator.starting_balance'),
        ]);

        return response()->json([
            'data' => $user->only('id', 'name', 'email', 'balance_usd', 'is_admin'),
            'invite' => $invite ? $this->sendInvite($user, $request->user()) : null,
        ], 201);
    }

    /**
     * (Re)send the invitation link, e.g. when it expired or the user forgot their password.
     */
    public function invite(Request $request, User $user, MailSettings $mail): JsonResponse
    {
        abort_if($user->is_system, 422, 'Akun sistem tidak bisa login.');
        abort_unless($mail->isEnabled(), 422, 'Email belum diaktifkan (Pengaturan → Email).');

        return response()->json(['invite' => $this->sendInvite($user, $request->user())]);
    }

    public function update(Request $request, User $user): JsonResponse
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

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        // For a user who lost their phone and recovery codes.
        if (isset($data['disable_two_factor'])) {
            $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null]);
            unset($data['disable_two_factor']);
        }

        $user->update($data);

        return response()->json(['data' => $user->only('id', 'name', 'email', 'balance_usd', 'is_admin')]);
    }

    /**
     * Sent right away (not queued) so the admin sees a wrong SMTP setting immediately.
     *
     * @return array{sent: bool, error: string|null}
     */
    private function sendInvite(User $user, User $admin): array
    {
        try {
            $user->notifyNow(new InviteUser(PasswordBroker::broker('invites')->createToken($user), $admin->name));
        } catch (Throwable $e) {
            report($e);

            return ['sent' => false, 'error' => mb_substr($e->getMessage(), 0, 300)];
        }

        return ['sent' => true, 'error' => null];
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
