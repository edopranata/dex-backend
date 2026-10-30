<?php

namespace App\Services\Admin;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Persists admin overrides and applies them on top of the file/.env configuration.
 * Registered as a singleton: the original config values are captured once per process
 * so overrides can be re-applied (e.g. before every queue job) and reset cleanly.
 */
class SettingsStore
{
    private const CACHE_KEY = 'admin-settings';

    /**
     * @var array<string, mixed>|null
     */
    private ?array $defaults = null;

    /**
     * Apply stored overrides to the runtime config. Safe before migrations have run.
     */
    public function apply(): void
    {
        $this->captureDefaults();
        $stored = $this->stored();

        foreach (SettingsRegistry::fields() as $key => $field) {
            config([$key => array_key_exists($key, $stored)
                ? SettingsRegistry::cast($field['type'], $stored[$key])
                : $this->defaults[$key]]);
        }

        // Mailers are built once per process; drop them so changed SMTP settings reach queue workers.
        if (app()->resolved('mail.manager')) {
            app('mail.manager')->forgetMailers();
        }
    }

    /**
     * The value from config files / .env, ignoring admin overrides.
     */
    public function default(string $key): mixed
    {
        $this->captureDefaults();

        return $this->defaults[$key] ?? null;
    }

    /**
     * Decrypted overrides keyed by config path.
     *
     * @return array<string, mixed>
     */
    public function stored(): array
    {
        try {
            $rows = Cache::rememberForever(self::CACHE_KEY, fn (): array => Setting::query()->pluck('value', 'key')->all());
        } catch (Throwable) {
            return [];
        }

        $fields = SettingsRegistry::fields();
        $values = [];

        foreach ($rows as $key => $value) {
            if (! isset($fields[$key])) {
                continue;
            }

            if ($fields[$key]['type'] === 'secret') {
                $value = rescue(fn () => Crypt::decryptString((string) $value), null, report: false);

                if ($value === null) {
                    continue;
                }
            }

            $values[$key] = $value;
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $values  Config path => value. Secrets are encrypted at rest.
     */
    public function set(array $values, ?int $userId = null): void
    {
        $fields = SettingsRegistry::fields();

        foreach ($values as $key => $value) {
            $stored = $fields[$key]['type'] === 'secret'
                ? Crypt::encryptString((string) $value)
                : (is_bool($value) ? ($value ? '1' : '0') : (string) $value);

            Setting::query()->updateOrCreate(['key' => $key], ['value' => $stored, 'updated_by' => $userId]);
        }

        $this->flush();
    }

    /**
     * Remove overrides so the .env / config file value applies again.
     *
     * @param  list<string>  $keys
     */
    public function reset(array $keys): void
    {
        Setting::query()->whereIn('key', $keys)->delete();
        $this->flush();
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->apply();
    }

    private function captureDefaults(): void
    {
        if ($this->defaults !== null) {
            return;
        }

        $this->defaults = [];

        foreach (array_keys(SettingsRegistry::fields()) as $key) {
            $this->defaults[$key] = config($key);
        }
    }
}
