<?php

namespace App\Services\Notifications;

use App\Enums\Recommendation;
use App\Enums\SignalStatus;
use App\Enums\WatchlistAction;
use App\Jobs\SendTelegramMessage;
use App\Models\Analysis;
use App\Models\Signal;
use App\Models\Token;
use App\Models\WatchlistEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns app events into Telegram messages. Every type can be switched off in Admin → Pengaturan;
 * during quiet hours non-urgent messages arrive without sound.
 */
class TelegramNotifier
{
    /**
     * Notification types that may still ring during quiet hours.
     */
    private const URGENT = ['risk', 'system', 'stop_loss'];

    public function enabled(string $type): bool
    {
        return (bool) config('market.telegram.enabled')
            && filled(config('market.telegram.bot_token'))
            && filled(config('market.telegram.chat_id'))
            && (bool) config("market.telegram.notify.{$type}");
    }

    public function newSignal(Signal $signal): void
    {
        if (! $this->enabled('signals') || ! $this->passesSignalFilter($signal)) {
            return;
        }

        $token = $signal->token;
        $base = $signal->entry_high;
        $lines = [
            ($signal->recommendation === Recommendation::StrongBuy ? '🟢🟢' : '🟢').' <b>Sinyal '.$signal->recommendation->label().': '.$this->symbol($token).'</b>',
            sprintf('Skor %d · harga %s', $signal->analysis?->final_score ?? 0, self::price($token->price_usd)),
            '',
            'Entry: '.self::price($signal->entry_low).' – '.self::price($base),
            'Stop loss: '.self::price($signal->stop_loss).' ('.self::change($base, $signal->stop_loss).')',
        ];

        foreach ($signal->takeProfits() as $i => $target) {
            $lines[] = 'TP'.($i + 1).': '.self::price($target).' ('.self::change($base, $target).')';
        }

        if (filled($signal->analysis?->summary)) {
            $lines[] = '';
            $lines[] = '<i>'.e(Str::limit((string) $signal->analysis->summary, 600)).'</i>';
        }

        $lines[] = $this->link($token);

        $this->send('signals', implode("\n", $lines));
    }

    /**
     * Entry reached, a target hit, or the signal closed or cancelled.
     */
    public function signalProgress(Signal $signal, SignalStatus $previousStatus, int $previousTpHit): void
    {
        if (! $this->passesSignalFilter($signal)) {
            return;
        }

        if (! $this->enabled('signal_updates')) {
            return;
        }

        $message = $this->progressMessage($signal, $previousStatus, $previousTpHit);

        if ($message !== null) {
            $this->send($signal->status === SignalStatus::StopLoss ? 'stop_loss' : 'signal_updates', $message."\n".$this->link($signal->token));
        }
    }

    /**
     * Honeypot or a liquidity pull on a tracked token. Repeats at most every 12 hours per token.
     *
     * @param  list<string>  $reasons
     */
    public function riskAlert(Token $token, array $reasons): void
    {
        if (! $this->enabled('risk') || ! Cache::add("telegram:risk:{$token->id}", true, now()->addHours(12))) {
            return;
        }

        $lines = ['🚨 <b>Peringatan risiko: '.$this->symbol($token).'</b>'];

        foreach (array_slice($reasons, 0, 5) as $reason) {
            $lines[] = '• '.e($reason);
        }

        if ($token->positions()->where('quantity', '>', 0)->exists()) {
            $lines[] = '';
            $lines[] = 'Ada posisi terbuka di token ini — pertimbangkan untuk keluar.';
        }

        $lines[] = $this->link($token);

        $this->send('risk', implode("\n", $lines));
    }

    public function watchlist(WatchlistEvent $event): void
    {
        [$icon, $verb] = match ($event->action) {
            WatchlistAction::Added => ['➕', 'masuk Market'],
            WatchlistAction::Removed => ['➖', 'keluar dari Market'],
            default => [null, null],
        };

        if ($icon === null || ! $this->enabled('watchlist')) {
            return;
        }

        $this->send('watchlist', implode("\n", [
            "{$icon} <b>{$this->symbol($event->token)}</b> {$verb}".($event->score !== null ? " (skor {$event->score})" : ''),
            e($event->reason),
            $this->link($event->token),
        ]));
    }

    /**
     * Operational alert. With a dedupe key, the same alert is sent at most once per window.
     *
     * @param  bool  $sync  Send right away instead of through the queue (e.g. from the watchdog).
     */
    public function system(string $title, string $detail = '', ?string $dedupeKey = null, int $dedupeMinutes = 60, bool $sync = false): void
    {
        if (! $this->enabled('system')) {
            return;
        }

        if ($dedupeKey !== null && ! Cache::add("telegram:system:{$dedupeKey}", true, now()->addMinutes($dedupeMinutes))) {
            return;
        }

        $this->send('system', '⚠️ <b>'.e($title).'</b>'.($detail !== '' ? "\n".e(Str::limit($detail, 1500)) : ''), $sync);
    }

    public function dailySummary(): void
    {
        if (! $this->enabled('daily_summary')) {
            return;
        }

        $since = now()->subDay();
        $closed = Signal::query()->with('token')->where('closed_at', '>=', $since)->whereNotNull('result_r')->get();
        $wins = $closed->filter(fn (Signal $s): bool => $s->result_r > 0)->count();

        $lines = [
            '📊 <b>Ringkasan 24 jam — '.now('Asia/Jakarta')->locale('id')->translatedFormat('d M Y').'</b>',
            '',
            'Sinyal baru: '.Signal::query()->where('created_at', '>=', $since)->count(),
            'Sinyal terbuka: '.Signal::query()->open()->count(),
            $closed->isEmpty()
                ? 'Sinyal selesai: 0'
                : sprintf('Sinyal selesai: %d (menang %d) · total %s', $closed->count(), $wins, self::r((float) $closed->sum('result_r'))),
        ];

        foreach ($closed->sortByDesc('result_r')->take(5) as $signal) {
            $lines[] = '• '.$this->symbol($signal->token).' '.self::r((float) $signal->result_r);
        }

        $top = Analysis::query()
            ->whereIn('id', Analysis::query()->selectRaw('MAX(id)')->groupBy('token_id'))
            ->whereHas('token', fn ($query) => $query->active())
            ->with('token')
            ->orderByDesc('final_score')
            ->limit(3)
            ->get();

        if ($top->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Skor tertinggi di Market:';

            foreach ($top as $analysis) {
                $lines[] = sprintf('• %s %d (%s)', $this->symbol($analysis->token), $analysis->final_score, $analysis->recommendation->label());
            }
        }

        $lines[] = '';
        $lines[] = '<a href="'.e($this->appUrl('/signals')).'">Lihat semua sinyal</a>';

        $this->send('daily_summary', implode("\n", $lines));
    }

    /**
     * Whether the given moment falls inside the configured quiet hours (WIB). Equal start and end disables them.
     */
    public function isQuietTime(?Carbon $at = null): bool
    {
        $start = (int) config('market.telegram.quiet_start_hour');
        $end = (int) config('market.telegram.quiet_end_hour');
        $hour = ($at ?? now())->copy()->setTimezone('Asia/Jakarta')->hour;

        if ($start === $end) {
            return false;
        }

        return $start < $end ? $hour >= $start && $hour < $end : $hour >= $start || $hour < $end;
    }

    /**
     * Price with four significant digits, so $0.00001234 stays readable.
     */
    public static function price(?float $value): string
    {
        if ($value === null || $value <= 0) {
            return '-';
        }

        $decimals = (int) min(12, max(2, 3 - floor(log10($value))));
        $formatted = number_format($value, $decimals, '.', ',');

        return '$'.(str_contains($formatted, '.') ? rtrim(rtrim($formatted, '0'), '.') : $formatted);
    }

    public static function change(float $from, float $to): string
    {
        return sprintf('%+.1f%%', ($to / $from - 1) * 100);
    }

    public static function r(float $value): string
    {
        return sprintf('%+.2fR', $value);
    }

    private function progressMessage(Signal $signal, SignalStatus $previousStatus, int $previousTpHit): ?string
    {
        $symbol = '<b>'.$this->symbol($signal->token).'</b>';
        $result = 'Hasil '.self::r((float) $signal->result_r);

        if ($signal->status === SignalStatus::Active && $previousStatus === SignalStatus::Pending) {
            return "▶️ {$symbol} masuk zona entry di ".self::price($signal->entry_price)
                ."\nSL ".self::price($signal->stop_loss).' · TP1 '.self::price($signal->take_profit_1);
        }

        if ($signal->status === SignalStatus::Active && $signal->max_tp_hit > $previousTpHit) {
            return "🎯 {$symbol} TP{$signal->max_tp_hit} tercapai (".self::price($signal->takeProfits()[$signal->max_tp_hit - 1]).')'
                ."\nStop loss pindah ke harga entry ".self::price($signal->entry_price).' (breakeven).';
        }

        return match ($signal->status) {
            SignalStatus::TakeProfit => $signal->max_tp_hit === 3
                ? "✅ {$symbol} semua target tercapai di ".self::price($signal->exit_price).". {$result}"
                : "✅ {$symbol} ditutup di breakeven setelah TP{$signal->max_tp_hit}. {$result}",
            SignalStatus::StopLoss => "🛑 {$symbol} kena stop loss di ".self::price($signal->exit_price).". {$result}",
            SignalStatus::Expired => $previousStatus === SignalStatus::Active
                ? "⏱ {$symbol} ditutup karena melewati ".config('market.analysis.signal_max_hours').' jam, di '.self::price($signal->exit_price).". {$result}"
                : "⚪ Sinyal {$symbol} kedaluwarsa: zona entry tidak tercapai dalam ".config('market.analysis.signal_entry_expiry_hours').' jam.',
            SignalStatus::Invalidated => "⚪ Sinyal {$symbol} batal: harga menyentuh stop loss sebelum masuk zona entry. Jangan entry.",
            default => null,
        };
    }

    private function passesSignalFilter(Signal $signal): bool
    {
        return config('market.telegram.signal_min') === Recommendation::StrongBuy->value
            ? $signal->recommendation === Recommendation::StrongBuy
            : $signal->recommendation->isBullish();
    }

    private function symbol(?Token $token): string
    {
        return e($token?->symbol ?? '?').($token ? ' <code>'.e($token->chain_id).'</code>' : '');
    }

    private function link(?Token $token): string
    {
        return $token ? '<a href="'.e($this->appUrl("/tokens/{$token->id}")).'">Buka di DEX Simulator</a>' : '';
    }

    private function appUrl(string $path): string
    {
        return rtrim((string) config('app.url'), '/').$path;
    }

    /**
     * Never let a notification problem (e.g. Redis down) break the pipeline that triggered it.
     */
    private function send(string $type, string $html, bool $sync = false): void
    {
        $silent = ! in_array($type, self::URGENT, true) && $this->isQuietTime();

        try {
            $sync
                ? SendTelegramMessage::dispatchSync($html, $silent)
                : SendTelegramMessage::dispatch($html, $silent);
        } catch (Throwable $e) {
            Log::warning("Notifikasi Telegram gagal diantrikan: {$e->getMessage()}");
        }
    }
}
