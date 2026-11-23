<?php

namespace App\Console\Commands;

use App\Services\Admin\SettingsStore;
use App\Services\Auth\CentralAccount\CentralAccount;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Shows or sets how users log in, the same setting as Admin → Pengaturan → Akun tune-in.trade. Meant as the way
 * back when Account has a problem: `php artisan app:auth-mode local`.
 */
#[Signature('app:auth-mode {mode? : local, both, atau central}')]
#[Description('Tampilkan atau ubah cara masuk: login lama, tune-in.trade, atau keduanya')]
class AuthModeCommand extends Command
{
    public function handle(SettingsStore $settings, CentralAccount $account): int
    {
        $mode = $this->argument('mode');

        if ($mode !== null) {
            if (! in_array($mode, CentralAccount::MODES, true)) {
                $this->components->error('Pilihan: '.implode(', ', CentralAccount::MODES).'.');

                return self::FAILURE;
            }

            if ($mode !== 'local' && ! $account->isConfigured()) {
                $this->components->error('ACCOUNT_CLIENT_ID dan ACCOUNT_CLIENT_SECRET belum ada di .env. Buat dulu di server Akun: php artisan account:connect-app '.config('central_account.app_key').'.');

                return self::FAILURE;
            }

            $settings->set(['central_account.mode' => $mode]);
            $settings->apply();
        }

        $this->components->twoColumnDetail('Pilihan tersimpan', (string) config('central_account.mode'));
        $this->components->twoColumnDetail('Yang berlaku', $account->mode());
        $this->components->twoColumnDetail('Client Akun di .env', $account->isConfigured() ? 'ada' : 'belum');

        return self::SUCCESS;
    }
}
