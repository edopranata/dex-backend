<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'chain_id' => ['required', 'string', Rule::in(array_keys(config('market.chains')))],
            'address' => ['required', 'string', 'min:32', 'max:128', 'regex:/^[A-Za-z0-9]+$/'],
        ];
    }
}
