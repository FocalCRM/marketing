<?php

declare(strict_types=1);

namespace Focal\Marketing\Console\Commands;

use Focal\Core\Models\Contact;
use Focal\Marketing\Actions\CompileCampaignMessageAction;
use Focal\Marketing\Actions\DispatchCampaignAction;
use Focal\Marketing\Enums\CampaignStatus;
use Focal\Marketing\Enums\RecipientStatus;
use Focal\Marketing\Models\Campaign;
use Focal\Marketing\Models\CampaignRecipient;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class DispatchScheduledCampaignsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'marketing:dispatch-scheduled';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch matured scheduled campaigns and sweep pending recipient timezone waves';

    /**
     * Execute the console command.
     */
    public function handle(DispatchCampaignAction $dispatcher, CompileCampaignMessageAction $compiler): int
    {
        // 1. Dispatch due scheduled campaigns
        /** @var Collection<int, Campaign> $dueCampaigns */
        $dueCampaigns = Campaign::query()
            ->where('status', CampaignStatus::Scheduled->value)
            ->where('scheduled_at', '<=', now())
            ->get();

        $dispatchedCount = 0;
        foreach ($dueCampaigns as $campaign) {
            $results = $dispatcher->execute($campaign);
            $this->info("Dispatched scheduled campaign [{$campaign->name}]: delivered {$results['delivered_count']} recipients.");
            $dispatchedCount++;
        }

        // 2. Sweep campaigns with pending timezone / STO waves
        /** @var Collection<int, Campaign> $sendingCampaigns */
        $sendingCampaigns = Campaign::query()
            ->where('status', CampaignStatus::Sending->value)
            ->where(function ($q): void {
                $q->where('send_in_recipient_timezone', true)
                    ->orWhere('send_by_timezone', true)
                    ->orWhere('use_sto', true);
            })
            ->get();

        $timezoneDelivered = 0;
        foreach ($sendingCampaigns as $campaign) {
            /** @var Collection<int, CampaignRecipient> $pendingRecipients */
            $pendingRecipients = $campaign->recipients()
                ->where('status', RecipientStatus::Pending->value)
                ->with('contact')
                ->get();

            $batchDelivered = 0;
            foreach ($pendingRecipients as $recipient) {
                /** @var Contact|null $contact */
                $contact = $recipient->contact;
                $targetTime = $recipient->scheduled_send_at ?? $campaign->calculateScheduledTimeForContact($contact);

                // If local window has arrived (or is now past in their timezone)
                if ($targetTime->isPast() || $targetTime->diffInMinutes(now()) <= 5) {
                    $recipient->update([
                        'status' => RecipientStatus::Sent,
                        'sent_at' => now(),
                    ]);

                    $compiler->execute($campaign, $recipient);

                    if ($contact !== null) {
                        $modeLabel = $campaign->use_sto ? 'Send Time Optimization' : 'Local Timezone';
                        $contact->logTask(
                            title: "Marketing Campaign ({$modeLabel}): {$campaign->name}",
                            dueAt: now(),
                            body: "Delivered scheduled wave to {$recipient->email}".($contact->timezone ? " ({$contact->timezone})" : '')
                        );
                        $contact->updateQuietly(['last_marketing_email_sent_at' => now()]);
                    }

                    $batchDelivered++;
                    $timezoneDelivered++;
                }
            }

            if ($batchDelivered > 0) {
                $campaign->increment('delivered_count', $batchDelivered);
            }

            // Check if all recipients for this campaign have completed
            $remaining = $campaign->recipients()->where('status', RecipientStatus::Pending->value)->count();
            if ($remaining === 0) {
                $campaign->update(['status' => CampaignStatus::Sent]);
                $this->info("All timezone waves completed for campaign [{$campaign->name}]. Status marked Sent.");
            }
        }

        $this->info("Completed scheduled dispatcher run. Dispatched {$dispatchedCount} campaign(s), {$timezoneDelivered} timezone wave email(s).");

        return self::SUCCESS;
    }
}
