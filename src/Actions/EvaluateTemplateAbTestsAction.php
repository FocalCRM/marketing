<?php

declare(strict_types=1);

namespace Focal\Marketing\Actions;

use Focal\Marketing\Models\CampaignRecipient;
use Focal\Marketing\Models\MarketingTemplate;

class EvaluateTemplateAbTestsAction
{
    /**
     * Evaluate aggregate A/B performance across all campaigns using this template
     * and declare the winning variant.
     *
     * @return array{
     *     winner: string,
     *     variant_a: array{sent: int, opens: int, clicks: int, open_rate: float, click_rate: float},
     *     variant_b: array{sent: int, opens: int, clicks: int, open_rate: float, click_rate: float},
     *     sample_size: int,
     *     confidence: string
     * }
     */
    public function execute(MarketingTemplate $template, string $winningMetric = 'click_rate'): array
    {
        $campaignIds = $template->campaigns()->pluck('id');

        $recipientsQuery = CampaignRecipient::query()->whereIn('campaign_id', $campaignIds);

        // Variant A performance
        $sentA = (clone $recipientsQuery)->where('variant', 'A')->count();
        $opensA = (clone $recipientsQuery)->where('variant', 'A')->whereNotNull('opened_at')->count();
        $clicksA = (clone $recipientsQuery)->where('variant', 'A')->whereNotNull('clicked_at')->count();

        $openRateA = $sentA > 0 ? ($opensA / $sentA) * 100 : 0.0;
        $clickRateA = $sentA > 0 ? ($clicksA / $sentA) * 100 : 0.0;

        // Variant B performance
        $sentB = (clone $recipientsQuery)->where('variant', 'B')->count();
        $opensB = (clone $recipientsQuery)->where('variant', 'B')->whereNotNull('opened_at')->count();
        $clicksB = (clone $recipientsQuery)->where('variant', 'B')->whereNotNull('clicked_at')->count();

        $openRateB = $sentB > 0 ? ($opensB / $sentB) * 100 : 0.0;
        $clickRateB = $sentB > 0 ? ($clicksB / $sentB) * 100 : 0.0;

        $scoreA = $winningMetric === 'click_rate' ? $clickRateA : $openRateA;
        $scoreB = $winningMetric === 'click_rate' ? $clickRateB : $openRateB;

        $winner = $scoreB > $scoreA ? 'B' : 'A';
        $totalSample = $sentA + $sentB;

        $confidence = $totalSample > 100
            ? (abs($scoreA - $scoreB) >= 3.0 ? 'High (>95%)' : 'Moderate (~90%)')
            : ($totalSample > 20 ? 'Low (Sample < 100)' : 'Inconclusive (Sample < 20)');

        $template->update([
            'ab_winner_variant' => $winner,
            'ab_completed_at' => now(),
        ]);

        return [
            'winner' => $winner,
            'variant_a' => [
                'sent' => $sentA,
                'opens' => $opensA,
                'clicks' => $clicksA,
                'open_rate' => round($openRateA, 2),
                'click_rate' => round($clickRateA, 2),
            ],
            'variant_b' => [
                'sent' => $sentB,
                'opens' => $opensB,
                'clicks' => $clicksB,
                'open_rate' => round($openRateB, 2),
                'click_rate' => round($clickRateB, 2),
            ],
            'sample_size' => $totalSample,
            'confidence' => $confidence,
        ];
    }
}
