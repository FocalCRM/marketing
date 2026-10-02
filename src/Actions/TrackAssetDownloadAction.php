<?php

declare(strict_types=1);

namespace Odden\Marketing\Actions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Odden\Core\Models\Contact;
use Odden\Marketing\Enums\LeadScoringEventType;
use Odden\Marketing\Enums\WorkflowTriggerType;
use Odden\Marketing\Models\MarketingAsset;
use Odden\Marketing\Models\MarketingAssetDownload;
use Odden\Marketing\Models\MarketingWorkflow;

class TrackAssetDownloadAction
{
    public function __construct(
        public ApplyLeadScoringEventAction $scoringAction,
        public EnrollContactInWorkflowAction $enrollmentAction,
    ) {}

    /**
     * Record a digital asset download, update counters, log activity task,
     * award lead scoring points, and trigger matching workflows.
     */
    public function execute(
        MarketingAsset $asset,
        ?Contact $contact = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): MarketingAssetDownload {
        return DB::transaction(function () use ($asset, $contact, $ipAddress, $userAgent): MarketingAssetDownload {
            $isFirstForContact = false;
            if ($contact !== null) {
                $isFirstForContact = ! MarketingAssetDownload::query()
                    ->where('asset_id', $asset->id)
                    ->where('contact_id', $contact->id)
                    ->exists();
            }

            /** @var MarketingAssetDownload $download */
            $download = MarketingAssetDownload::create([
                'asset_id' => $asset->id,
                'contact_id' => $contact?->id,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'download_token' => Str::random(32),
                'downloaded_at' => now(),
            ]);

            $asset->increment('downloads_count');
            if ($isFirstForContact) {
                $asset->increment('unique_leads_count');
            }

            if ($contact !== null) {
                // Log task on contact activity timeline
                $contact->logTask(
                    title: "Downloaded Asset: {$asset->name}",
                    dueAt: now(),
                    body: "Contact downloaded digital asset '{$asset->name}' ({$asset->asset_type})."
                );

                // Apply lead scoring bonus
                if ($asset->lead_score_points > 0) {
                    $this->scoringAction->execute(
                        contact: $contact,
                        eventType: LeadScoringEventType::PropertyMatch,
                        description: "Downloaded Asset: {$asset->name} (+{$asset->lead_score_points} pts)",
                        points: $asset->lead_score_points,
                    );
                }

                // Trigger active workflows
                /** @var Collection<int, MarketingWorkflow> $workflows */
                $workflows = MarketingWorkflow::query()
                    ->where('is_active', true)
                    ->where('trigger_type', WorkflowTriggerType::AssetDownloaded)
                    ->get();

                foreach ($workflows as $workflow) {
                    $targetAssetId = $workflow->trigger_config['asset_id'] ?? null;
                    if ($targetAssetId === null || (int) $targetAssetId === $asset->id) {
                        $this->enrollmentAction->execute($workflow, $contact);
                    }
                }
            }

            return $download;
        });
    }
}
