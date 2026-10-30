<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'balance_usd' => $this->balance_usd,
            'is_admin' => $this->is_admin,
            'two_factor_enabled' => $this->hasTwoFactorEnabled(),
            // The SPA sends the user to the change-password screen; the API refuses everything else.
            'must_change_password' => (bool) $this->must_change_password,
            'telegram_linked' => $this->hasTelegram(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
