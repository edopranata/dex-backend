<?php

namespace App\Http\Requests;

use App\Enums\OrderSide;
use App\Enums\OrderType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreOrderRequest extends FormRequest
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
            'token_id' => ['required', 'integer', Rule::exists('tokens', 'id')->where('is_active', true)],
            'side' => ['required', Rule::enum(OrderSide::class)],
            'type' => ['required', Rule::enum(OrderType::class)],
            'amount_usd' => ['required_if:side,buy', 'nullable', 'numeric', 'min:'.config('market.simulator.min_order_usd')],
            'quantity' => ['required_if:side,sell', 'nullable', 'numeric', 'gt:0'],
            'trigger_price' => ['required_unless:type,market', 'nullable', 'numeric', 'gt:0'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $side = $this->enum('side', OrderSide::class);
                $type = $this->enum('type', OrderType::class);

                if ($side === OrderSide::Buy && in_array($type, [OrderType::StopLoss, OrderType::TakeProfit], true)) {
                    $validator->errors()->add('type', 'Stop loss dan take profit hanya untuk order jual.');
                }
            },
        ];
    }
}
