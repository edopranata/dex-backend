<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\SettingsRegistry;
use App\Services\Admin\SettingsStore;
use App\Services\Ai\AiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
    public function index(SettingsStore $store): JsonResponse
    {
        $stored = $store->stored();

        $groups = array_map(fn (array $group): array => [
            ...$group,
            'fields' => array_map(function (array $field) use ($store, $stored): array {
                $overridden = array_key_exists($field['key'], $stored);

                if ($field['type'] === 'secret') {
                    $value = (string) config($field['key']);

                    return [
                        ...$field,
                        'is_set' => $value !== '',
                        'masked' => $value !== '' ? '••••'.substr($value, -4) : null,
                        'source' => $overridden ? 'panel' : ($value !== '' ? 'env' : null),
                        'overridden' => $overridden,
                    ];
                }

                return [
                    ...$field,
                    'value' => config($field['key']),
                    'default' => $store->default($field['key']),
                    'overridden' => $overridden,
                ];
            }, $group['fields']),
        ], SettingsRegistry::groups());

        return response()->json(['groups' => $groups, 'ai_status' => app(AiClient::class)->status()]);
    }

    /**
     * Save changed values. Empty secret fields are ignored (keep the current key).
     */
    public function update(Request $request, SettingsStore $store): JsonResponse
    {
        $fields = SettingsRegistry::fields();
        $input = $request->validate(['values' => ['required', 'array']])['values'];
        $unknown = array_diff(array_keys($input), array_keys($fields));

        if ($unknown !== []) {
            throw ValidationException::withMessages(['values' => 'Pengaturan tidak dikenal: '.implode(', ', $unknown)]);
        }

        // Keys contain dots, so validate each value on its own instead of as a nested array.
        $errors = [];
        $values = [];

        foreach ($input as $key => $value) {
            $field = $fields[$key];
            $validator = validator(['value' => $value], ['value' => SettingsRegistry::rules($field)], [], ['value' => $field['label']]);

            if ($validator->fails()) {
                $errors[$key] = $validator->errors()->first('value');

                continue;
            }

            if ($value === null || ($field['type'] === 'secret' && trim((string) $value) === '')) {
                continue;
            }

            $values[$key] = $field['type'] === 'secret' ? trim((string) $value) : SettingsRegistry::cast($field['type'], $value);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $this->validateWeights($values);
        $store->set($values, $request->user()->id);
        $this->resumeAiIfChanged(array_keys($values));

        return $this->index($store);
    }

    /**
     * Remove overrides so the .env value applies again.
     */
    public function reset(Request $request, SettingsStore $store): JsonResponse
    {
        $keys = $request->validate(['keys' => ['required', 'array'], 'keys.*' => ['string']])['keys'];
        $store->reset(array_values(array_intersect($keys, array_keys(SettingsRegistry::fields()))));
        $this->resumeAiIfChanged($keys);

        return $this->index($store);
    }

    public function testAi(AiClient $ai): JsonResponse
    {
        return response()->json($ai->ping());
    }

    /**
     * A new key, model or provider deserves a fresh attempt instead of waiting out an automatic pause.
     *
     * @param  list<string>  $keys
     */
    private function resumeAiIfChanged(array $keys): void
    {
        foreach ($keys as $key) {
            if (str_starts_with($key, 'market.ai.')) {
                app(AiClient::class)->resume();

                return;
            }
        }
    }

    /**
     * Analysis weights must still add up to 1 after the change.
     *
     * @param  array<string, mixed>  $values
     */
    private function validateWeights(array $values): void
    {
        $keys = ['technical', 'onchain', 'whale', 'sentiment', 'fundamental'];
        $touched = array_filter($keys, fn (string $k): bool => array_key_exists("market.analysis.weights.{$k}", $values));

        if ($touched === []) {
            return;
        }

        $sum = array_sum(array_map(fn (string $k): float => (float) ($values["market.analysis.weights.{$k}"] ?? config("market.analysis.weights.{$k}")), $keys));

        if (abs($sum - 1) > 0.011) {
            throw ValidationException::withMessages(['values' => sprintf('Jumlah bobot analisis harus 1,00 (sekarang %.2f).', $sum)]);
        }
    }
}
