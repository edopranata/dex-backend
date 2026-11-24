<?php

namespace App\Console\Commands;

use App\Services\Analysis\MarketTurn;
use App\Services\Notifications\TelegramNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Daily check of the market-turn triggers (docs/UJI_ULANG_PASAR_NAIK.md in the workspace repo). A trigger that has just
 * been met sends one Telegram message to the admins; one that is no longer met is forgotten, so it can fire again later.
 * --dry only prints the state (used by the routine audit).
 */
#[Signature('app:market-turn-check {--dry : Hanya tampilkan, tanpa Telegram dan tanpa menyimpan}')]
#[Description('Cek pemicu uji ulang saat pasar berubah (BTC > SMA50, tren 4 jam BTC, Fear & Greed, bayangan Buy saat BTC naik)')]
class MarketTurnCheckCommand extends Command
{
    private const STATE = 'market-turn:met';

    public function handle(MarketTurn $turn, TelegramNotifier $telegram): int
    {
        $triggers = $turn->triggers();

        foreach ($triggers as $t) {
            $progress = $t['progress'] === null ? '?' : "{$t['progress']}/{$t['target']}";
            $this->line(sprintf('pemicu %-48s %-7s %s%s', $t['label'], $progress, $t['value'], $t['met'] ? '  TERPENUHI' : ''));
        }

        if ($this->option('dry')) {
            return self::SUCCESS;
        }

        $before = Cache::get(self::STATE, []);
        // An unreadable source (met null) keeps what was known before.
        $now = collect($triggers)->filter(fn (array $t): bool => $t['met'] === true || ($t['met'] === null && in_array($t['key'], $before, true)))
            ->pluck('key')->values()->all();
        $new = collect($triggers)->filter(fn (array $t): bool => $t['met'] === true && ! in_array($t['key'], $before, true));

        if ($new->isNotEmpty()) {
            $lines = collect($triggers)->map(fn (array $t): string => ($t['met'] ? '✅ ' : '▫️ ').$t['label'].': '.($t['progress'] ?? '?').'/'.$t['target'].' ('.$t['value'].')');
            $telegram->system(
                'Pasar berubah: pemicu uji ulang terpenuhi',
                $new->pluck('label')->implode(', ')." terpenuhi.\n\n".$lines->implode("\n")
                    ."\n\nWaktunya menjalankan ulang analisa di docs/UJI_ULANG_PASAR_NAIK.md (DEX, spot, FutureSim).",
            );
            Log::info('Pemicu uji ulang pasar terpenuhi', ['baru' => $new->pluck('key')->all()]);
        }

        Cache::forever(self::STATE, $now);

        return self::SUCCESS;
    }
}
