<?php

declare(strict_types=1);

namespace Odden\Marketing\Actions;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Odden\Core\Models\Contact;
use Odden\Marketing\Models\CampaignRecipient;
use Odden\Marketing\Models\CustomBehavioralEvent;
use Odden\Marketing\Models\FormSubmission;
use Odden\Marketing\Models\PageView;
use Odden\Sales\Enums\DealStatus;
use Odden\Sales\Models\Deal;

class AnalyzeConversionFunnelAction
{
    /**
     * Analyze a multi-stage conversion funnel across marketing and sales touchpoints.
     *
     * @param list<array{
     *     name: string,
     *     type: 'page_view'|'page_visit'|'form_submission'|'behavioral_event'|'campaign_click'|'contact_created'|'deal_created'|'deal_won',
     *     path?: string|null,
     *     form_id?: int|null,
     *     form_slug?: string|null,
     *     event_name?: string|null,
     *     campaign_id?: int|null
     * }> $steps
     * @return array{
     *     steps: list<array{
     *         index: int,
     *         name: string,
     *         type: string,
     *         count: int,
     *         conversion_rate: float,
     *         dropoff_count: int,
     *         dropoff_rate: float,
     *         overall_conversion_rate: float
     *     }>,
     *     total_top_of_funnel: int,
     *     total_bottom_of_funnel: int,
     *     overall_funnel_conversion_rate: float,
     *     time_window_days: int
     * }
     */
    public function execute(
        array $steps,
        ?CarbonInterface $startDate = null,
        ?CarbonInterface $endDate = null
    ): array {
        $start = $startDate ?? Carbon::now()->subDays(30)->startOfDay();
        $end = $endDate ?? Carbon::now()->endOfDay();
        $timeWindowDays = max(1, (int) $start->diffInDays($end));

        if (empty($steps)) {
            return [
                'steps' => [],
                'total_top_of_funnel' => 0,
                'total_bottom_of_funnel' => 0,
                'overall_funnel_conversion_rate' => 0.0,
                'time_window_days' => $timeWindowDays,
            ];
        }

        $stepCounts = [];
        foreach ($steps as $step) {
            $stepCounts[] = $this->countStepMilestone($step, $start, $end);
        }

        $topOfFunnel = $stepCounts[0];
        $bottomOfFunnel = end($stepCounts) ?: 0;

        $analyzedSteps = [];
        $previousCount = null;

        foreach ($steps as $index => $step) {
            $count = $stepCounts[$index];

            if ($previousCount === null || $previousCount === 0) {
                $conversionRate = 100.0;
                $dropoffCount = 0;
                $dropoffRate = 0.0;
            } else {
                $conversionRate = round(($count / $previousCount) * 100, 1);
                $dropoffCount = max(0, $previousCount - $count);
                $dropoffRate = round(($dropoffCount / $previousCount) * 100, 1);
            }

            $overallConversionRate = $topOfFunnel > 0
                ? round(($count / $topOfFunnel) * 100, 1)
                : 0.0;

            $analyzedSteps[] = [
                'index' => $index,
                'name' => $step['name'],
                'type' => $step['type'],
                'count' => $count,
                'conversion_rate' => $conversionRate,
                'dropoff_count' => $dropoffCount,
                'dropoff_rate' => $dropoffRate,
                'overall_conversion_rate' => $overallConversionRate,
            ];

            $previousCount = $count;
        }

        $overallRate = $topOfFunnel > 0
            ? round(($bottomOfFunnel / $topOfFunnel) * 100, 1)
            : 0.0;

        return [
            'steps' => $analyzedSteps,
            'total_top_of_funnel' => $topOfFunnel,
            'total_bottom_of_funnel' => $bottomOfFunnel,
            'overall_funnel_conversion_rate' => $overallRate,
            'time_window_days' => $timeWindowDays,
        ];
    }

    /**
     * Count unique participants reaching a specific funnel milestone.
     *
     * @param array{
     *     name: string,
     *     type: string,
     *     path?: string|null,
     *     form_id?: int|null,
     *     form_slug?: string|null,
     *     event_name?: string|null,
     *     campaign_id?: int|null
     * } $step
     */
    protected function countStepMilestone(array $step, CarbonInterface $start, CarbonInterface $end): int
    {
        return match ($step['type']) {
            'page_view', 'page_visit' => PageView::query()
                ->whereBetween('created_at', [$start, $end])
                ->when(! empty($step['path']), fn ($q) => $q->where('path', 'like', '%'.$step['path'].'%'))
                ->distinct()
                ->count('session_id'),

            'form_submission' => FormSubmission::query()
                ->whereBetween('created_at', [$start, $end])
                ->when(! empty($step['form_id']), fn ($q) => $q->where('form_id', $step['form_id']))
                ->when(! empty($step['form_slug']), function ($q) use ($step): void {
                    $q->whereHas('form', fn ($fq) => $fq->where('slug', $step['form_slug']));
                })
                ->count(),

            'behavioral_event' => CustomBehavioralEvent::query()
                ->whereBetween('occurred_at', [$start, $end])
                ->when(! empty($step['event_name']), fn ($q) => $q->where('event_name', $step['event_name']))
                ->distinct()
                ->count('contact_id'),

            'campaign_click' => CampaignRecipient::query()
                ->whereNotNull('clicked_at')
                ->whereBetween('clicked_at', [$start, $end])
                ->when(! empty($step['campaign_id']), fn ($q) => $q->where('campaign_id', $step['campaign_id']))
                ->distinct()
                ->count('email'),

            'contact_created' => Contact::query()
                ->whereBetween('created_at', [$start, $end])
                ->count(),

            'deal_created' => class_exists(Deal::class)
                ? Deal::query()->whereBetween('created_at', [$start, $end])->count()
                : 0,

            'deal_won' => class_exists(Deal::class)
                ? Deal::query()
                    ->where('status', DealStatus::Won)
                    ->whereBetween('closed_at', [$start, $end])
                    ->count()
                : 0,

            default => 0,
        };
    }
}
