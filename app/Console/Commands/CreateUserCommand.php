<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('app:create-user {--name= : Nama} {--email= : Email} {--password= : Password (kosongkan untuk ditanya)}')]
#[Description('Buat akun user (dipakai saat pendaftaran publik ditutup)')]
class CreateUserCommand extends Command
{
    public function handle(): int
    {
        $data = [
            'name' => $this->option('name') ?: text('Nama', required: true),
            'email' => $this->option('email') ?: text('Email', required: true),
            'password' => $this->option('password') ?: password('Password (min. 8 karakter)', required: true),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Password::defaults()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create([...$data, 'balance_usd' => config('market.simulator.starting_balance')]);

        $this->components->info("User {$user->email} dibuat dengan saldo virtual $".number_format($user->balance_usd).'.');

        return self::SUCCESS;
    }
}
