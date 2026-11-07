<?php

namespace App\Console\Commands;

use App\Enums\OrderSide;
use App\Models\AutoFollowDecision;
use App\Models\Order;
use App\Models\Signal;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('market:backfill-decisions {--path= : Folder log, bawaan storage/logs}')]
#[Description('Isi riwayat keputusan Auto-follow dari order beli dan dari log "melewati sinyal". Baris yang sudah ada tidak diubah.')]
class MarketBackfillDecisionsCommand extends Command
{
    private const SKIP_LINE = '/^\[(?<at>[\d\- :]+)\] \w+\.INFO: Auto-follow (?:(?<key>\w+) )?melewati sinyal #(?<signal>\d+): (?<message>.+?)\s*$/';

    private const REASONS = [
        'batas posisi terbuka tercapai.' => AutoFollowDecision::POSITION_LIMIT,
        'batas rugi harian tercapai.' => AutoFollowDecision::DAILY_LOSS,
        'ukuran order di bawah minimum.' => AutoFollowDecision::BELOW_MINIMUM,
    ];

    public function handle(): int
    {
        $accounts = collect(array_keys(User::AUTO_FOLLOW_ACCOUNTS))->mapWithKeys(fn (string $key): array => [$key => User::autoFollower($key)]);
        $bought = 0;
        $skipped = 0;

        foreach ($accounts as $key => $account) {
            Order::query()->where('user_id', $account->id)->where('side', OrderSide::Buy)->whereNotNull('signal_id')->orderBy('id')
                ->each(function (Order $order) use ($account, $key, &$bought): void {
                    $bought += $this->insert($account, $key, $order->signal_id, AutoFollowDecision::BOUGHT, null, null, $order->created_at);
                });
        }

        $files = glob(rtrim($this->option('path') ?: storage_path('logs'), '/').'/laravel*.log') ?: [];
        sort($files);

        foreach ($files as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                if (! str_contains($line, 'melewati sinyal') || ! preg_match(self::SKIP_LINE, $line, $match)) {
                    continue;
                }

                // Before 1 Nov there was one account, and its lines did not name it.
                $key = $match['key'] !== '' ? $match['key'] : 'all';

                if (! $accounts->has($key) || ! Signal::query()->whereKey((int) $match['signal'])->exists()) {
                    continue;
                }

                $reason = self::REASONS[$match['message']] ?? AutoFollowDecision::REJECTED;
                $message = $reason === AutoFollowDecision::REJECTED ? $match['message'] : null;
                $skipped += $this->insert($accounts[$key], $key, (int) $match['signal'], AutoFollowDecision::SKIPPED, $reason, $message, Carbon::parse($match['at']));
            }
        }

        $this->components->info("{$bought} pembelian dan {$skipped} sinyal dilewati dicatat dari ".count($files).' file log.');

        return self::SUCCESS;
    }

    private function insert(User $account, string $key, int $signalId, string $decision, ?string $reason, ?string $message, ?Carbon $at): int
    {
        return AutoFollowDecision::query()->insertOrIgnore([
            'user_id' => $account->id,
            'account' => $key,
            'signal_id' => $signalId,
            'decision' => $decision,
            'reason' => $reason,
            'message' => $message,
            'source' => 'backfill',
            'decided_at' => $at ?? now(),
        ]);
    }
}
