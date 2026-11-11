<?php

namespace App\Services\Analysis;

use App\Enums\Recommendation;
use App\Models\Analysis;
use App\Models\Token;
use App\Services\Ai\AiClient;

/**
 * Writes the human-readable conclusion for an analysis, using the LLM when configured. By default only Buy and
 * Strong Buy get an AI summary, and it gives way to news sentiment when the day's AI budget runs low.
 */
class AnalysisSummarizer
{
    public function __construct(private AiClient $ai) {}

    /**
     * @param  array<string, mixed>  $context
     * @return array{summary: string, provider: string}
     */
    public function summarize(Token $token, Recommendation $recommendation, array $context, ?Analysis $previous): array
    {
        if ($previous !== null && $this->canReuse($token, $recommendation, (int) $context['final_score'], $previous)) {
            return ['summary' => $previous->summary, 'provider' => $previous->summary_provider];
        }

        if ($this->ai->isEnabled() && ($recommendation->isBullish() || ! config('market.ai.summary_ai_bullish_only'))) {
            $result = $this->ai->json(
                'Kamu analis kripto yang berhati-hati. Tulis ringkasan analisis dalam Bahasa Indonesia, maksimal 4 kalimat, '
                .'berdasarkan data JSON. Sebutkan faktor terkuat, risiko utama, dan alasan rekomendasi. Jangan mengarang data '
                .'yang tidak ada. Ini simulasi edukasi, bukan nasihat keuangan. Balas JSON: {"summary": "..."}',
                json_encode([
                    'token' => "{$token->symbol} ({$token->chain_id})",
                    'price_usd' => $token->price_usd,
                    'recommendation' => $recommendation->value,
                    ...$context,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
                optional: true,
            );

            if (is_string($result['summary'] ?? null) && $result['summary'] !== '') {
                return ['summary' => mb_substr($result['summary'], 0, 2000), 'provider' => $this->ai->lastProvider()];
            }
        }

        return ['summary' => $this->template($token, $recommendation, $context), 'provider' => 'template'];
    }

    /**
     * Whether the previous AI summary still describes this analysis: same recommendation, written within the TTL,
     * and a score that has not moved much since. A reused summary is copied onto every analysis, so the oldest copy
     * marks when it was written (10 Nov: GANG kept a summary saying 74 for six hours while the score rose to 84,
     * because the TTL was counted from the previous copy).
     */
    private function canReuse(Token $token, Recommendation $recommendation, int $score, Analysis $previous): bool
    {
        if ($previous->summary_provider === null || $previous->summary_provider === 'template' || $previous->recommendation !== $recommendation) {
            return false;
        }

        $ttl = (int) config('market.ai.summary_ttl_minutes');

        // Looking back twice the TTL is enough: an oldest copy beyond the TTL already means stale.
        $written = $token->analyses()
            ->where('summary', $previous->summary)
            ->where('created_at', '>=', now()->subMinutes(2 * $ttl))
            ->oldest()
            ->first(['id', 'created_at', 'final_score']) ?? $previous;

        return $written->created_at->gt(now()->subMinutes($ttl))
            && abs($score - (int) $written->final_score) < (int) config('market.ai.summary_refresh_score_change');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function template(Token $token, Recommendation $recommendation, array $context): string
    {
        $scores = $context['scores'];
        arsort($scores);
        $strongest = array_key_first($scores);
        $weakest = array_key_last($scores);

        $labels = [
            'technical' => 'teknikal',
            'onchain' => 'keamanan on-chain',
            'whale' => 'aktivitas whale',
            'sentiment' => 'sentimen berita',
            'fundamental' => 'kesehatan pasar',
        ];

        return sprintf(
            '%s mendapat skor akhir %d/100 (%s). Faktor terkuat: %s (%d), terlemah: %s (%d). %s',
            $token->symbol,
            $context['final_score'],
            $recommendation->label(),
            $labels[$strongest],
            $scores[$strongest],
            $labels[$weakest],
            $scores[$weakest],
            match (true) {
                in_array($recommendation->value, config('market.analysis.signal_recommendations'), true) => 'Setup beli valid selama harga bertahan di atas stop loss.',
                $recommendation->isBullish() => 'Hanya pantauan: sejak v5 sinyal beli dibuat untuk Strong Buy saja.',
                default => 'Belum ada setup beli yang layak; tunggu konfirmasi.',
            },
        );
    }
}
