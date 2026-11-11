<?php

namespace App\Services\MarketData;

use App\Models\Token;
use App\Models\TokenSecurityCheck;
use Illuminate\Support\Arr;

/**
 * Turns GoPlus token security data into a 0-100 safety score (100 = safest) and stores it.
 */
class TokenSecurityService
{
    /**
     * Addresses that represent burned or locked liquidity.
     */
    private const BURN_ADDRESSES = [
        '0x0000000000000000000000000000000000000000',
        '0x000000000000000000000000000000000000dead',
        '1nc1nerator11111111111111111111111111111111',
    ];

    public function __construct(private GoPlusClient $goPlus) {}

    public function check(Token $token): ?TokenSecurityCheck
    {
        $assessment = $this->assess($token->chain_id, $token->token_address, $token->pair_address);

        if ($assessment === null) {
            return null;
        }

        return $token->securityChecks()->create([
            ...Arr::except($assessment, 'score'),
            'provider' => 'goplus',
            'liquidity_usd' => $token->liquidity_usd,
            'risk_score' => $assessment['score'],
            'checked_at' => now(),
        ]);
    }

    /**
     * Fetch and score a token without storing anything (used for discovery candidates).
     *
     * @param  string|null  $pairAddress  The token's own pool, so its share is not read as a holder that can sell.
     * @return array{score: int, is_honeypot: bool|null, flags: list<array{level: string, message: string}>, holder_count: int|null, top10_percent: float|null, top_holders: list<array<string, mixed>>, top1_percent: float|null, creator_percent: float|null, owner_percent: float|null, lp_locked_percent: float|null, buy_tax: float|null, sell_tax: float|null}|null
     */
    public function assess(string $chainId, string $tokenAddress, ?string $pairAddress = null): ?array
    {
        $chain = config("market.chains.{$chainId}.goplus");

        if ($chain === null) {
            return null;
        }

        $data = $this->goPlus->tokenSecurity($chain, $tokenAddress);

        if ($data === null) {
            return null;
        }

        [$data, $holderFlags] = $this->withoutImpossibleHolders($data);
        $result = $chain === 'solana' ? $this->scoreSolana($data) : $this->scoreEvm($data);
        $topHolders = $this->topHolders($data, $pairAddress);

        return [
            ...$result,
            'flags' => [...$result['flags'], ...$holderFlags],
            'holder_count' => isset($data['holder_count']) ? (int) $data['holder_count'] : null,
            'top10_percent' => $this->top10Percent($data['holders'] ?? []),
            'top_holders' => $topHolders,
            'top1_percent' => $this->largestFreeHolderPercent($topHolders),
            'creator_percent' => $this->percentField($data, 'creator_percent'),
            'owner_percent' => $this->percentField($data, 'owner_percent'),
            'lp_locked_percent' => $this->lockedLiquidityPercent($data['lp_holders'] ?? []),
            'buy_tax' => isset($data['buy_tax']) && $data['buy_tax'] !== '' ? (float) $data['buy_tax'] * 100 : null,
            'sell_tax' => isset($data['sell_tax']) && $data['sell_tax'] !== '' ? (float) $data['sell_tax'] * 100 : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{score: int, is_honeypot: bool|null, flags: list<array{level: string, message: string}>}
     */
    public function scoreEvm(array $data): array
    {
        $score = 100;
        $flags = [];
        $is = fn (string $key): bool => ($data[$key] ?? '0') === '1';

        $penalties = [
            'is_honeypot' => [100, 'danger', 'Honeypot: token tidak bisa dijual.'],
            'cannot_sell_all' => [40, 'danger', 'Holder tidak bisa menjual seluruh token.'],
            'owner_change_balance' => [35, 'danger', 'Owner bisa mengubah saldo holder.'],
            'hidden_owner' => [25, 'danger', 'Ada hidden owner.'],
            'can_take_back_ownership' => [25, 'danger', 'Ownership bisa diambil kembali.'],
            'selfdestruct' => [25, 'danger', 'Kontrak punya fungsi selfdestruct.'],
            'is_mintable' => [15, 'warning', 'Supply token masih bisa di-mint.'],
            'transfer_pausable' => [10, 'warning', 'Transfer token bisa di-pause.'],
            'is_proxy' => [10, 'warning', 'Kontrak proxy (logika bisa diubah).'],
            'slippage_modifiable' => [15, 'warning', 'Pajak/slippage bisa diubah owner.'],
            'is_blacklisted' => [5, 'info', 'Kontrak punya fitur blacklist.'],
            'external_call' => [5, 'info', 'Kontrak melakukan external call.'],
        ];

        foreach ($penalties as $key => [$points, $level, $message]) {
            if ($is($key)) {
                $score -= $points;
                $flags[] = ['level' => $level, 'message' => $message];
            }
        }

        if (($data['is_open_source'] ?? '1') === '0') {
            $score -= 25;
            $flags[] = ['level' => 'danger', 'message' => 'Source code kontrak tidak terverifikasi.'];
        }

        foreach (['buy_tax' => 'beli', 'sell_tax' => 'jual'] as $key => $label) {
            $tax = (float) ($data[$key] ?? 0) * 100;

            if ($tax > 10) {
                $score -= 25;
                $flags[] = ['level' => 'danger', 'message' => sprintf('Pajak %s %.1f%%.', $label, $tax)];
            } elseif ($tax > 3) {
                $score -= 8;
                $flags[] = ['level' => 'warning', 'message' => sprintf('Pajak %s %.1f%%.', $label, $tax)];
            }
        }

        $common = $this->scoreDistribution($data);

        return [
            'score' => (int) max(0, min(100, $score - $common['penalty'])),
            'is_honeypot' => isset($data['is_honeypot']) ? $is('is_honeypot') : null,
            'flags' => [...$flags, ...$common['flags']],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{score: int, is_honeypot: bool|null, flags: list<array{level: string, message: string}>}
     */
    public function scoreSolana(array $data): array
    {
        $score = 100;
        $flags = [];
        $status = fn (string $key): bool => (string) ($data[$key]['status'] ?? '0') === '1';

        $penalties = [
            'balance_mutable_authority' => [40, 'danger', 'Authority bisa mengubah saldo holder.'],
            'freezable' => [25, 'danger', 'Freeze authority aktif (akun bisa dibekukan).'],
            'mintable' => [20, 'warning', 'Mint authority aktif (supply bisa ditambah).'],
            'closable' => [10, 'warning', 'Akun token bisa ditutup authority.'],
            'transfer_fee_upgradable' => [10, 'warning', 'Fee transfer bisa diubah.'],
            'metadata_mutable' => [5, 'info', 'Metadata token masih bisa diubah.'],
        ];

        foreach ($penalties as $key => [$points, $level, $message]) {
            if ($status($key)) {
                $score -= $points;
                $flags[] = ['level' => $level, 'message' => $message];
            }
        }

        if (($data['non_transferable'] ?? '0') === '1') {
            $score -= 60;
            $flags[] = ['level' => 'danger', 'message' => 'Token tidak bisa ditransfer.'];
        }

        if (! empty($data['transfer_fee'])) {
            $score -= 10;
            $flags[] = ['level' => 'warning', 'message' => 'Token memiliki fee transfer.'];
        }

        if (! empty($data['transfer_hook'])) {
            $score -= 10;
            $flags[] = ['level' => 'warning', 'message' => 'Token memiliki transfer hook.'];
        }

        $common = $this->scoreDistribution($data);
        $score -= $common['penalty'];

        if ((int) ($data['trusted_token'] ?? 0) === 1) {
            $score = max($score, 90);
            $flags[] = ['level' => 'info', 'message' => 'Ditandai sebagai trusted token oleh GoPlus.'];
        }

        return [
            'score' => (int) max(0, min(100, $score)),
            'is_honeypot' => null,
            'flags' => [...$flags, ...$common['flags']],
        ];
    }

    /**
     * Holder concentration and liquidity lock checks shared by all chains.
     *
     * @param  array<string, mixed>  $data
     * @return array{penalty: int, flags: list<array{level: string, message: string}>}
     */
    private function scoreDistribution(array $data): array
    {
        $penalty = 0;
        $flags = [];

        $top10 = $this->top10Percent($data['holders'] ?? []);

        if ($top10 !== null && $top10 > 60) {
            $penalty += 20;
            $flags[] = ['level' => 'danger', 'message' => sprintf('Top 10 holder menguasai %.1f%% supply.', $top10)];
        } elseif ($top10 !== null && $top10 > 35) {
            $penalty += 10;
            $flags[] = ['level' => 'warning', 'message' => sprintf('Top 10 holder menguasai %.1f%% supply.', $top10)];
        }

        $locked = $this->lockedLiquidityPercent($data['lp_holders'] ?? []);

        if ($locked !== null && $locked < 50 && ! empty($data['lp_holders'])) {
            $penalty += 10;
            $flags[] = ['level' => 'warning', 'message' => sprintf('Hanya %.1f%% LP yang terkunci/dibakar.', $locked)];
        }

        $holders = (int) ($data['holder_count'] ?? 0);

        if ($holders > 0 && $holders < 300) {
            $penalty += 15;
            $flags[] = ['level' => 'warning', 'message' => "Jumlah holder masih sangat sedikit ({$holders})."];
        }

        return ['penalty' => $penalty, 'flags' => $flags];
    }

    /**
     * GoPlus sometimes lists holder shares far above the whole supply (TROLL on 10 Nov: its pool at 4,029,241%),
     * which overflowed the stored percentage so the whole check failed to save. Such a holder list is left out,
     * so it is neither stored nor scored as concentration; the rest of the check still counts.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: list<array{level: string, message: string}>}
     */
    private function withoutImpossibleHolders(array $data): array
    {
        $impossible = collect($data['holders'] ?? [])
            ->contains(fn ($holder): bool => is_array($holder) && (float) ($holder['percent'] ?? 0) > 1);

        if (! $impossible) {
            return [$data, []];
        }

        unset($data['holders']);

        return [$data, [['level' => 'info', 'message' => 'Data holder dari GoPlus tidak masuk akal (di atas 100% supply), jadi diabaikan.']]];
    }

    /**
     * Percentage held by the top 10 holders, excluding locked, contract and burn addresses.
     *
     * @param  list<array<string, mixed>>  $holders
     */
    private function top10Percent(array $holders): ?float
    {
        if ($holders === []) {
            return null;
        }

        $percent = collect($holders)
            ->reject(fn (array $holder): bool => ($holder['is_locked'] ?? 0) == 1
                || ($holder['is_contract'] ?? 0) == 1
                || $this->isBurnAddress($holder['address'] ?? $holder['account'] ?? ''))
            ->take(10)
            ->sum(fn (array $holder): float => (float) ($holder['percent'] ?? 0));

        return round($percent * 100, 2);
    }

    /**
     * The ten largest holders as GoPlus lists them, with what each address is. GoPlus marks locked holders on
     * every chain, contracts only on EVM chains; the pool is known from GoPlus's pair list and the token's
     * own pair. On Solana an exchange or a pool vault still shows as an ordinary holder.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{address: string, percent: float, is_locked: bool, is_contract: bool|null, is_pool: bool, is_burn: bool, tag: string|null}>
     */
    private function topHolders(array $data, ?string $pairAddress): array
    {
        $pools = collect($data['dex'] ?? [])
            ->flatMap(fn ($pool): array => is_array($pool) ? [$pool['pair'] ?? null, $pool['id'] ?? null] : [])
            ->push($pairAddress)
            ->filter()
            ->map(fn ($address): string => strtolower((string) $address))
            ->all();

        return collect($data['holders'] ?? [])
            ->take(10)
            ->map(function (array $holder) use ($pools): array {
                $address = (string) ($holder['address'] ?? $holder['account'] ?? '');

                return [
                    'address' => $address,
                    'percent' => round((float) ($holder['percent'] ?? 0) * 100, 4),
                    'is_locked' => ($holder['is_locked'] ?? 0) == 1,
                    'is_contract' => array_key_exists('is_contract', $holder) ? $holder['is_contract'] == 1 : null,
                    'is_pool' => in_array(strtolower($address), $pools, true),
                    'is_burn' => $this->isBurnAddress($address),
                    'tag' => filled($holder['tag'] ?? null) ? (string) $holder['tag'] : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The share of the largest holder that could sell at any moment: not locked, a contract, a burn address or
     * a pool. Above 60% one wallet can take most of the liquidity out.
     *
     * @param  list<array<string, mixed>>  $holders
     */
    private function largestFreeHolderPercent(array $holders): ?float
    {
        if ($holders === []) {
            return null;
        }

        $free = collect($holders)->first(fn (array $holder): bool => ! $holder['is_locked'] && $holder['is_contract'] !== true && ! $holder['is_pool'] && ! $holder['is_burn']);

        return $free === null ? 0.0 : round($free['percent'], 2);
    }

    /**
     * A GoPlus share (0-1) as a percentage, or null when GoPlus does not give it (Solana).
     *
     * @param  array<string, mixed>  $data
     */
    private function percentField(array $data, string $key): ?float
    {
        return isset($data[$key]) && $data[$key] !== '' ? round((float) $data[$key] * 100, 2) : null;
    }

    /**
     * @param  list<array<string, mixed>>  $lpHolders
     */
    private function lockedLiquidityPercent(array $lpHolders): ?float
    {
        if ($lpHolders === []) {
            return null;
        }

        $percent = collect($lpHolders)
            ->filter(fn (array $holder): bool => ($holder['is_locked'] ?? 0) == 1
                || $this->isBurnAddress($holder['address'] ?? $holder['account'] ?? ''))
            ->sum(fn (array $holder): float => (float) ($holder['percent'] ?? 0));

        return round(min(1, $percent) * 100, 2);
    }

    private function isBurnAddress(string $address): bool
    {
        return in_array(strtolower($address), self::BURN_ADDRESSES, true);
    }
}
