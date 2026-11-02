<?php

namespace App\Services\Notifications;

use App\Enums\Recommendation;
use App\Models\Token;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * The Market list in Telegram, filtered by the latest analysis. Reads only what is already stored,
 * so it costs no provider request and starts no job.
 *
 * Button data: "mkt:{filter}".
 */
class TelegramMarket
{
    /**
     * @var array<string, string>
     */
    private const FILTERS = [
        'strong_buy' => '🟢🟢 Strong Buy',
        'buy' => '🟢 Buy',
        'hold' => '⚪ Hold',
        'sell' => '🔴 Sell & Hindari',
        'signals' => '📍 Sinyal terbuka',
        'all' => '📋 Semua',
    ];

    public function __construct(private TelegramClient $telegram) {}

    /**
     * What to show for "/market", with the filter named after the command if there is a valid one.
     *
     * @return array{text: string, reply_markup: array<string, mixed>}
     */
    public function view(string $filter = ''): array
    {
        $filter = Str::snake(strtolower(trim($filter)));

        if (! isset(self::FILTERS[$filter])) {
            return ['text' => "📊 <b>Market</b>\nPilih yang ingin ditampilkan:", 'reply_markup' => self::keyboard()];
        }

        return ['text' => $this->report($filter), 'reply_markup' => self::keyboard()];
    }

    /**
     * Whether a tapped button belongs to this list.
     */
    public static function owns(string $data): bool
    {
        return str_starts_with($data, 'mkt:');
    }

    /**
     * Replace the tapped message with the chosen list. Tapping the current filter again changes nothing.
     *
     * @param  array<string, mixed>  $query
     */
    public function handleCallback(array $query): void
    {
        $chatId = (string) ($query['message']['chat']['id'] ?? '');
        $linked = $chatId !== '' && User::query()->where('telegram_chat_id', $chatId)->exists();

        if ($linked) {
            $view = $this->view(Str::after((string) ($query['data'] ?? ''), 'mkt:'));

            rescue(fn () => $this->telegram->edit($chatId, (int) ($query['message']['message_id'] ?? 0), $view['text'], $view['reply_markup']), report: false);
        }

        rescue(fn () => $this->telegram->answerCallback((string) $query['id'], $linked ? null : 'Chat ini belum terhubung ke akun DEX Simulator.', ! $linked), report: false);
    }

    private function report(string $filter): string
    {
        $tokens = $this->tokens($filter);
        $title = '📊 <b>Market: '.e(Str::after(self::FILTERS[$filter], ' '))."</b> ({$tokens->count()})";

        if ($tokens->isEmpty()) {
            return "{$title}\nTidak ada token untuk pilihan ini.";
        }

        $lines = $tokens->map(fn (Token $token): string => $this->line($token))->all();

        return implode("\n", [$title, ...$lines, '', '<i>Dari analisis terakhir, bukan nasihat keuangan.</i>']);
    }

    /**
     * @return Collection<int, Token>
     */
    private function tokens(string $filter): Collection
    {
        $tokens = Token::query()->active()
            ->with('latestAnalysis')
            ->withExists(['signals as has_open_signal' => fn ($query) => $query->open()])
            ->get();

        $wanted = match ($filter) {
            'strong_buy' => [Recommendation::StrongBuy],
            'buy' => [Recommendation::Buy],
            'hold' => [Recommendation::Hold],
            'sell' => [Recommendation::Sell, Recommendation::StrongSell, Recommendation::Avoid],
            default => null,
        };

        return $tokens
            ->filter(fn (Token $token): bool => match (true) {
                $filter === 'signals' => (bool) $token->has_open_signal,
                $wanted !== null => in_array($token->latestAnalysis?->recommendation, $wanted, true),
                default => true,
            })
            ->sortByDesc(fn (Token $token): int => $token->latestAnalysis?->final_score ?? -1)
            ->values();
    }

    private function line(Token $token): string
    {
        $analysis = $token->latestAnalysis;
        $url = rtrim((string) config('app.url'), '/')."/tokens/{$token->id}";
        $change = $token->price_change_h24 === null ? '' : sprintf(' · %+.1f%%', $token->price_change_h24);

        return sprintf(
            '%s<a href="%s"><b>%s</b></a> <code>%s</code> · %s · skor %s%s · %s',
            $token->has_open_signal ? '📍 ' : '',
            e($url),
            e($token->symbol),
            e($token->chain_id),
            $analysis?->recommendation?->label() ?? 'Belum dianalisis',
            $analysis?->final_score ?? '–',
            $change,
            TelegramNotifier::price($token->price_usd),
        );
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    private static function keyboard(): array
    {
        $buttons = collect(self::FILTERS)
            ->map(fn (string $label, string $filter): array => ['text' => $label, 'callback_data' => "mkt:{$filter}"])
            ->values();

        return ['inline_keyboard' => $buttons->chunk(2)->map(fn ($row): array => $row->values()->all())->values()->all()];
    }
}
