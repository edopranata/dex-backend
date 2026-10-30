<?php

namespace App\Services\Notifications;

use App\Enums\OrderStatus;
use App\Models\Signal;
use App\Models\User;
use App\Services\Trading\TradingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Buying a signal from Telegram: the signal message carries amount buttons, a tap (or a typed
 * amount) shows a confirmation, and confirming places a limit buy at the top of the entry zone
 * that follows the signal's stop loss and targets once filled.
 *
 * Button data: "buy:{signal}:{usd|custom}", "ok:{signal}:{usd}", "no".
 */
class TelegramBuyFlow
{
    private const UNREACHABLE = 'Telegram sedang gangguan, coba tap lagi sebentar lagi.';

    public function __construct(private TelegramClient $telegram, private TradingService $trading) {}

    /**
     * Amount buttons shown under a signal message.
     *
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public static function keyboard(Signal $signal): array
    {
        $amounts = array_map(
            fn (int|float $usd): array => ['text' => '$'.$usd, 'callback_data' => "buy:{$signal->id}:{$usd}"],
            config('market.telegram.buy_presets'),
        );

        return ['inline_keyboard' => [$amounts, [['text' => '✏️ Nominal lain', 'callback_data' => "buy:{$signal->id}:custom"]]]];
    }

    /**
     * Handle a tapped button. Telegram's spinner is always answered, with a toast when useful.
     *
     * @param  array<string, mixed>  $query
     */
    public function handleCallback(array $query): void
    {
        $chatId = (string) ($query['message']['chat']['id'] ?? '');
        $messageId = (int) ($query['message']['message_id'] ?? 0);
        [$action, $signalId, $amount] = array_pad(explode(':', (string) ($query['data'] ?? '')), 3, '');
        $user = $chatId !== '' ? User::query()->where('telegram_chat_id', $chatId)->first() : null;

        $toast = match (true) {
            $user === null => ['Chat ini belum terhubung ke akun DEX Simulator.', true],
            $action === 'buy' && $amount === 'custom' => $this->askAmount($chatId, (int) $signalId),
            $action === 'buy' => $this->sendConfirmation($user, $chatId, (int) $signalId, (float) $amount),
            $action === 'ok' => $this->place($user, $chatId, $messageId, (int) $signalId, (float) $amount),
            $action === 'no' => $this->cancel($chatId, $messageId),
            default => null,
        };

        rescue(fn () => $this->telegram->answerCallback((string) $query['id'], $toast[0] ?? null, $toast[1] ?? false), report: false);
    }

    /**
     * Reply to a typed amount after "Nominal lain", or null when the chat is not waiting for one.
     *
     * @return array{chat_id: string, text: string, reply_markup?: array<string, mixed>}|null
     */
    public function handleAmount(string $chatId, string $text): ?array
    {
        $signalId = Cache::get("telegram:buy-amount:{$chatId}");

        if ($signalId === null) {
            return null;
        }

        $amount = self::parseAmount($text);

        if ($amount === null) {
            return ['chat_id' => $chatId, 'text' => 'Ketik angka nominal dalam USD, misalnya <code>150</code>.'];
        }

        Cache::forget("telegram:buy-amount:{$chatId}");
        $user = User::query()->where('telegram_chat_id', $chatId)->first();
        $confirmation = $user ? $this->confirmation($user, (int) $signalId, $amount) : ['text' => 'Chat ini belum terhubung.', 'reply_markup' => null];

        return array_filter(['chat_id' => $chatId, ...$confirmation]);
    }

    /**
     * "150", "$150", "150.5" or "150,5" -> 150.5; anything else -> null.
     */
    public static function parseAmount(string $text): ?float
    {
        $clean = str_replace(',', '.', trim(ltrim(trim($text), '$')));

        return preg_match('/^\d+(\.\d{1,2})?$/', $clean) === 1 && (float) $clean > 0 ? (float) $clean : null;
    }

    /**
     * @return array{0: string, 1: bool}|null
     */
    private function askAmount(string $chatId, int $signalId): ?array
    {
        Cache::put("telegram:buy-amount:{$chatId}", $signalId, now()->addMinutes(10));

        $sent = $this->ui(fn () => $this->telegram->send($chatId, 'Ketik nominal pembelian dalam USD (contoh: <code>150</code>):', replyMarkup: [
            'force_reply' => true,
            'input_field_placeholder' => 'Nominal USD',
        ]));

        return $sent ? null : [self::UNREACHABLE, true];
    }

    /**
     * @return array{0: string, 1: bool}|null
     */
    private function sendConfirmation(User $user, string $chatId, int $signalId, float $amount): ?array
    {
        $confirmation = $this->confirmation($user, $signalId, $amount);
        $sent = $this->ui(fn () => $this->telegram->send($chatId, $confirmation['text'], replyMarkup: $confirmation['reply_markup']));

        return $sent ? null : [self::UNREACHABLE, true];
    }

    /**
     * The confirmation text and buttons, or an explanation when the buy is not possible.
     *
     * @return array{text: string, reply_markup: array<string, mixed>|null}
     */
    private function confirmation(User $user, int $signalId, float $amount): array
    {
        $signal = Signal::query()->with('token')->find($signalId);
        $problem = $this->problem($user, $signal, $amount);

        if ($problem !== null) {
            return ['text' => "⚠️ {$problem}", 'reply_markup' => null];
        }

        $token = $signal->token;
        $usd = TelegramNotifier::usd($amount);
        $targets = implode(' / ', array_map(fn (float $target): string => TelegramNotifier::price($target), $signal->takeProfits()));

        $text = implode("\n", [
            '🛒 <b>Konfirmasi beli '.e($token->symbol).'</b> <code>'.e($token->chain_id).'</code>',
            "Nominal: <b>{$usd}</b> · saldo ".TelegramNotifier::usd($user->balance_usd),
            'Limit: '.TelegramNotifier::price($signal->entry_high).' (harga sekarang '.TelegramNotifier::price($token->price_usd).')',
            'Stop loss: '.TelegramNotifier::price($signal->stop_loss).' ('.TelegramNotifier::change($signal->entry_high, $signal->stop_loss).')',
            "TP1 / TP2 / TP3: {$targets} — masing-masing 1/3 posisi",
            'Setelah TP1, stop loss pindah ke harga entry.',
        ]);

        return ['text' => $text, 'reply_markup' => ['inline_keyboard' => [[
            ['text' => "✅ Beli {$usd}", 'callback_data' => "ok:{$signal->id}:{$amount}"],
            ['text' => '✖️ Batal', 'callback_data' => 'no'],
        ]]]];
    }

    /**
     * @return array{0: string, 1: bool}|null
     */
    private function place(User $user, string $chatId, int $messageId, int $signalId, float $amount): ?array
    {
        // A double tap must not buy twice.
        if (! Cache::add("telegram:buy-confirmed:{$chatId}:{$messageId}", true, now()->addDay())) {
            return ['Order ini sudah diproses.', false];
        }

        $signal = Signal::query()->with('token')->find($signalId);
        $problem = $this->problem($user, $signal, $amount);

        if ($problem === null) {
            try {
                [$buy] = $this->trading->followSignal($user, $signal, $amount, limit: true);
            } catch (ValidationException $e) {
                $problem = collect($e->errors())->flatten()->first();
            } catch (Throwable $e) {
                report($e);
                $problem = 'Order gagal diproses. Coba lagi dari aplikasi.';
            }
        }

        if ($problem !== null) {
            Cache::forget("telegram:buy-confirmed:{$chatId}:{$messageId}");
            $this->ui(fn () => $this->telegram->edit($chatId, $messageId, "⚠️ {$problem}"));

            return [$problem, true];
        }

        $symbol = e($signal->token->symbol);
        $state = $buy->status === OrderStatus::Filled
            ? 'terisi '.self::quantity((float) $buy->filled_quantity).' '.$symbol.' @ '.TelegramNotifier::price($buy->filled_price).'. Stop loss &amp; TP1–TP3 sudah terpasang.'
            : 'pending, menunggu harga ≤ '.TelegramNotifier::price($buy->trigger_price).'. Stop loss &amp; TP dipasang otomatis saat terisi.';

        // The order exists at this point: a failed message update must not turn into an error.
        $this->ui(fn () => $this->telegram->edit($chatId, $messageId, implode("\n", [
            "✅ <b>Order #{$buy->id}: beli {$symbol} ".TelegramNotifier::usd($amount).'</b>',
            'Limit '.TelegramNotifier::price($buy->trigger_price)." — {$state}",
            '<a href="'.e(rtrim((string) config('app.url'), '/').'/portfolio').'">Lihat portofolio</a>',
        ])));

        return ["Order #{$buy->id} dibuat", false];
    }

    /**
     * @return array{0: string, 1: bool}|null
     */
    private function cancel(string $chatId, int $messageId): ?array
    {
        $this->ui(fn () => $this->telegram->edit($chatId, $messageId, '✖️ Pembelian dibatalkan.'));

        return ['Dibatalkan', false];
    }

    /**
     * Run a call that only updates what the user sees; a Telegram outage is logged, not thrown.
     */
    private function ui(callable $call): bool
    {
        try {
            $call();

            return true;
        } catch (TelegramException $e) {
            Log::warning("Pesan Telegram (alur beli) tidak terkirim: {$e->getMessage()}");

            return false;
        }
    }

    private static function quantity(float $value): string
    {
        return number_format($value, $value >= 1000 ? 0 : ($value >= 1 ? 2 : 6), '.', ',');
    }

    private function problem(User $user, ?Signal $signal, float $amount): ?string
    {
        return match (true) {
            $signal === null => 'Sinyal tidak ditemukan.',
            ! $signal->status->isOpen() => 'Sinyal ini sudah ditutup, tidak bisa dibeli lagi.',
            $amount < (float) config('market.simulator.min_order_usd') => 'Nominal minimal '.TelegramNotifier::usd((float) config('market.simulator.min_order_usd')).'.',
            $amount > $user->balance_usd => 'Saldo tidak cukup (saldo '.TelegramNotifier::usd($user->balance_usd).').',
            default => null,
        };
    }
}
