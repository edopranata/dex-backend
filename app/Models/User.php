<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable(['name', 'email', 'password', 'balance_usd', 'is_admin', 'notification_preferences'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'balance_usd' => 'float',
            'is_admin' => 'boolean',
            'is_system' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'telegram_linked_at' => 'datetime',
            'notification_preferences' => 'array',
        ];
    }

    /**
     * The paper account that follows every signal (created on first use; it cannot log in).
     */
    public static function autoFollower(): self
    {
        $email = 'auto-follow@system.invalid';

        return static::query()->where('email', $email)->first()
            ?? tap(new static, fn (self $user) => $user->forceFill([
                'name' => 'Auto-follow (sistem)',
                'email' => $email,
                'password' => Str::random(64),
                'balance_usd' => config('market.simulator.starting_balance'),
                'is_system' => true,
            ])->save());
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    public function hasTelegram(): bool
    {
        return $this->telegram_chat_id !== null;
    }

    /**
     * Saved preferences on top of the defaults, so new options get a sensible value.
     *
     * @return array{signals: bool, signal_updates: bool, risk: bool, watchlist: bool, system: bool, daily_summary: bool, signal_min: string, daily_summary_hour: int, quiet_start_hour: int, quiet_end_hour: int}
     */
    public function notificationSettings(): array
    {
        return array_replace(config('market.telegram.defaults'), array_intersect_key($this->notification_preferences ?? [], config('market.telegram.defaults')));
    }

    /**
     * Whether this user should receive the given Telegram notification type. Operational alerts are for admins only.
     */
    public function wantsTelegram(string $type): bool
    {
        if (! $this->hasTelegram() || ($type === 'system' && ! $this->is_admin)) {
            return false;
        }

        return (bool) ($this->notificationSettings()[$type] ?? false);
    }

    /**
     * @return HasMany<Position, $this>
     */
    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
