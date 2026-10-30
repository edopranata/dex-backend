<?php

namespace App\Services\Analysis;

use App\Enums\Recommendation;
use App\Models\Analysis;
use App\Models\Token;
use App\Services\Ai\AiClient;

/**
 * Writes the human-readable conclusion for an analysis, using the LLM when configured.
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
        $isFresh = $previous?->summary_provider !== null
            && $previous->summary_provider !== 'template'
            && $previous->recommendation === $recommendation
            && $previous->created_at->gt(now()->subMinutes(config('market.ai.summary_ttl_minutes')));

        if ($isFresh) {
            return ['summary' => $previous->summary, 'provider' => $previous->summary_provider];
        }

        if ($this->ai->isEnabled()) {
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
            );

            if (is_string($result['summary'] ?? null) && $result['summary'] !== '') {
                return ['summary' => mb_substr($result['summary'], 0, 2000), 'provider' => $this->ai->lastProvider()];
            }
        }

        return ['summary' => $this->template($token, $recommendation, $context), 'provider' => 'template'];
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
            $recommendation->isBullish()
                ? 'Setup beli valid selama harga bertahan di atas stop loss.'
                : 'Belum ada setup beli yang layak; tunggu konfirmasi.',
        );
    }
}
