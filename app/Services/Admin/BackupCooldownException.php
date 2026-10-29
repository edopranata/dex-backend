<?php

namespace App\Services\Admin;

use Illuminate\Support\Carbon;
use RuntimeException;

class BackupCooldownException extends RuntimeException
{
    public function __construct(public readonly Carbon $availableAt)
    {
        parent::__construct(sprintf(
            'Backup terakhir belum berumur %d menit. Backup manual tersedia lagi %s.',
            config('market.backup.manual_cooldown_minutes'),
            $availableAt->locale('id')->diffForHumans(),
        ));
    }
}
