<?php

declare(strict_types=1);

namespace Odden\Marketing\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Odden\Core\Events\CompaniesMerged;
use Odden\Core\Events\ContactsMerged;
use Odden\Core\Models\Contact;
use Odden\Marketing\Enums\RecipientStatus;
use Odden\Marketing\Enums\SubscriptionStatus;
use Odden\Marketing\Enums\WorkflowEnrollmentStatus;
use Odden\Marketing\Models\CampaignRecipient;
use Odden\Marketing\Models\CustomBehavioralEvent;
use Odden\Marketing\Models\EspEvent;
use Odden\Marketing\Models\FormSubmission;
use Odden\Marketing\Models\LeadDecayLog;
use Odden\Marketing\Models\LeadScoreLog;
use Odden\Marketing\Models\MarketingAssetDownload;
use Odden\Marketing\Models\MarketingContactTopic;
use Odden\Marketing\Models\MarketingEvent;
use Odden\Marketing\Models\MarketingEventRegistration;
use Odden\Marketing\Models\MarketingSmsMessage;
use Odden\Marketing\Models\MarketingSubscription;
use Odden\Marketing\Models\NpsResponse;
use Odden\Marketing\Models\PageView;
use Odden\Marketing\Models\VisitorSession;
use Odden\Marketing\Models\WorkflowEnrollment;
use Odden\Marketing\Models\WorkflowLog;

/**
 * Moves the Marketing records keyed to a merged-away contact or company onto the record it was
 * merged into. Runs synchronously inside Core's merge transaction, so a failure rolls the merge
 * back. Tables are read from each model, so configured table names are respected.
 */
class MoveMergedRecords
{
    /**
     * Every model with a contact_id. Conflicts are resolved before these rows are moved.
     *
     * @var list<class-string<Model>>
     */
    private const array CONTACT_KEYED = [
        FormSubmission::class,
        CampaignRecipient::class,
        MarketingSubscription::class,
        MarketingContactTopic::class,
        LeadScoreLog::class,
        LeadDecayLog::class,
        WorkflowEnrollment::class,
        WorkflowLog::class,
        VisitorSession::class,
        PageView::class,
        MarketingSmsMessage::class,
        NpsResponse::class,
        MarketingAssetDownload::class,
        MarketingEventRegistration::class,
        CustomBehavioralEvent::class,
    ];

    public function handleContactsMerged(ContactsMerged $event): void
    {
        $from = $event->secondary->getKey();
        $to = $event->primary->getKey();

        $this->resolveRecipientConflicts($from, $to);
        $this->resolveEventRegistrationConflicts($from, $to);
        $this->exitDuplicateWorkflowEnrollments($from, $to);
        $this->carryOverOptOuts($event->primary, $event->secondary);

        foreach (self::CONTACT_KEYED as $model) {
            DB::table($this->table($model))->where('contact_id', $from)->update(['contact_id' => $to]);
        }
    }

    public function handleCompaniesMerged(CompaniesMerged $event): void
    {
        DB::table($this->table(CustomBehavioralEvent::class))
            ->where('company_id', $event->secondary->getKey())
            ->update(['company_id' => $event->primary->getKey()]);
    }

    /**
     * Recipients are unique per campaign and contact. Keep the recipient that unsubscribed, then
     * the most engaged one, and copy the other's engagement timestamps onto it. The other row is
     * detached from the contact if it was sent, or deleted (its ESP events moved) if it wasn't.
     */
    private function resolveRecipientConflicts(mixed $from, mixed $to): void
    {
        $table = $this->table(CampaignRecipient::class);

        foreach ($this->sharedKeys($table, 'campaign_id', $from, $to) as $campaignId) {
            $rows = DB::table($table)
                ->where('campaign_id', $campaignId)
                ->whereIn('contact_id', [$from, $to])
                ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [RecipientStatus::Unsubscribed->value])
                ->orderByRaw('CASE WHEN clicked_at IS NULL THEN 1 ELSE 0 END')
                ->orderByRaw('CASE WHEN opened_at IS NULL THEN 1 ELSE 0 END')
                ->orderByRaw('CASE WHEN sent_at IS NULL THEN 1 ELSE 0 END')
                ->orderBy('id')
                ->get();

            $keep = $rows->shift();
            if ($keep === null) {
                continue;
            }

            $dropIds = $rows->pluck('id')->all();

            $engagement = [];
            foreach (['sent_at', 'opened_at', 'clicked_at'] as $column) {
                $earliest = collect([$keep, ...$rows])->pluck($column)->filter()->min();
                if ($earliest !== $keep->{$column}) {
                    $engagement[$column] = $earliest;
                }
            }

            // A sent row's unsubscribe and tracking links are in someone's inbox: detach it from
            // the contact instead of deleting it, so those links keep working. Unsent rows have no
            // links out there and would be delivered again if kept, so they are deleted.
            $sentIds = $rows->filter(fn (object $row): bool => $row->sent_at !== null)->pluck('id')->all();
            $unsentIds = array_values(array_diff($dropIds, $sentIds));

            DB::table($table)->whereIn('id', $sentIds)->update(['contact_id' => null]);
            DB::table($this->table(EspEvent::class))->whereIn('recipient_id', $unsentIds)->update(['recipient_id' => $keep->id]);
            DB::table($table)->whereIn('id', $unsentIds)->delete();

            if ($engagement !== []) {
                DB::table($table)->where('id', $keep->id)->update($engagement);
            }
        }
    }

    /**
     * Registrations are unique per event and contact. Keep the attended registration, then one
     * that isn't cancelled, then the earliest; fill its missing UTM fields from the other, and
     * correct the event's counters.
     */
    private function resolveEventRegistrationConflicts(mixed $from, mixed $to): void
    {
        $table = $this->table(MarketingEventRegistration::class);
        $eventsTable = $this->table(MarketingEvent::class);

        foreach ($this->sharedKeys($table, 'event_id', $from, $to) as $eventId) {
            $rows = DB::table($table)
                ->where('event_id', $eventId)
                ->whereIn('contact_id', [$from, $to])
                ->orderByRaw("CASE WHEN status = 'attended' OR attended_at IS NOT NULL THEN 0 ELSE 1 END")
                ->orderByRaw("CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END")
                ->orderBy('registered_at')
                ->orderBy('id')
                ->get();

            $keep = $rows->shift();
            if ($keep === null) {
                continue;
            }

            $fill = [];
            foreach (['utm_source', 'utm_medium', 'utm_campaign'] as $column) {
                $value = $rows->pluck($column)->filter()->first();
                if (empty($keep->{$column}) && $value !== null) {
                    $fill[$column] = $value;
                }
            }

            DB::table($table)->whereIn('id', $rows->pluck('id')->all())->delete();

            if ($fill !== []) {
                DB::table($table)->where('id', $keep->id)->update($fill);
            }

            // Both registrations were counted; they're now one person. Attendees are recounted
            // the same way UpdateAttendanceStatusAction counts them.
            $registrations = (int) DB::table($eventsTable)->where('id', $eventId)->lockForUpdate()->value('registrations_count');

            DB::table($eventsTable)->where('id', $eventId)->update([
                'registrations_count' => max(0, $registrations - $rows->count()),
                'attendees_count' => DB::table($table)->where('event_id', $eventId)->where('status', 'attended')->count(),
            ]);
        }
    }

    /**
     * A contact is only active once in a workflow at a time (EnrollContactInWorkflowAction
     * checks for this). Keep the earliest active enrollment and exit the other, so the merged
     * contact isn't sent each step twice. Exited enrollments keep their workflow logs.
     */
    private function exitDuplicateWorkflowEnrollments(mixed $from, mixed $to): void
    {
        $table = $this->table(WorkflowEnrollment::class);
        $active = WorkflowEnrollmentStatus::Active->value;

        $workflowIds = DB::table($table)
            ->where('contact_id', $from)
            ->where('status', $active)
            ->whereIn('workflow_id', DB::table($table)->where('contact_id', $to)->where('status', $active)->select('workflow_id'))
            ->distinct()
            ->pluck('workflow_id');

        foreach ($workflowIds as $workflowId) {
            $ids = DB::table($table)
                ->where('workflow_id', $workflowId)
                ->whereIn('contact_id', [$from, $to])
                ->where('status', $active)
                ->orderBy('enrolled_at')
                ->orderBy('id')
                ->pluck('id');

            DB::table($table)->whereIn('id', $ids->slice(1)->all())->update([
                'status' => WorkflowEnrollmentStatus::Exited->value,
                'next_run_at' => null,
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Subscriptions and topic preferences are keyed by email, so the secondary's rows keep
     * suppressing its own address after they move. If the secondary unsubscribed (globally or
     * from a topic), apply the same opt-out to the primary's address too. Nothing is ever
     * resubscribed. Bounces are specific to an address and aren't copied.
     */
    private function carryOverOptOuts(Contact $primary, Contact $secondary): void
    {
        $primaryEmail = mb_strtolower(trim((string) $primary->email));
        $secondaryEmail = mb_strtolower(trim((string) $secondary->email));

        if ($primaryEmail === '' || $primaryEmail === $secondaryEmail) {
            return;
        }

        $ownedBySecondary = fn ($query) => $query->where('contact_id', $secondary->getKey())
            ->when($secondaryEmail !== '', fn ($query) => $query->orWhere('email', $secondaryEmail));

        $optOut = MarketingSubscription::query()
            ->where($ownedBySecondary)
            ->where('status', SubscriptionStatus::Unsubscribed->value)
            ->orderBy('unsubscribed_at')
            ->first();

        if ($optOut !== null) {
            $subscription = MarketingSubscription::query()->where('email', $primaryEmail)->first();
            $unsubscribedAt = $optOut->unsubscribed_at ?? now();

            if ($subscription === null) {
                MarketingSubscription::create([
                    'contact_id' => $primary->getKey(),
                    'email' => $primaryEmail,
                    'status' => SubscriptionStatus::Unsubscribed,
                    'unsubscribed_at' => $unsubscribedAt,
                ]);
            } elseif (! in_array($subscription->status, [SubscriptionStatus::Unsubscribed, SubscriptionStatus::Bounced], true)) {
                $subscription->update(['status' => SubscriptionStatus::Unsubscribed, 'unsubscribed_at' => $unsubscribedAt]);
            }
        }

        $topicOptOuts = MarketingContactTopic::query()
            ->where($ownedBySecondary)
            ->where('is_subscribed', false)
            ->get();

        foreach ($topicOptOuts as $optOut) {
            $preference = MarketingContactTopic::query()
                ->where('email', $primaryEmail)
                ->where('topic_id', $optOut->topic_id)
                ->first();

            if ($preference === null) {
                MarketingContactTopic::create([
                    'email' => $primaryEmail,
                    'contact_id' => $primary->getKey(),
                    'topic_id' => $optOut->topic_id,
                    'is_subscribed' => false,
                    'unsubscribed_at' => $optOut->unsubscribed_at ?? now(),
                ]);
            } elseif ($preference->is_subscribed) {
                $preference->update(['is_subscribed' => false, 'unsubscribed_at' => $optOut->unsubscribed_at ?? now()]);
            }
        }
    }

    /**
     * Values of $column (a campaign, an event) that both contacts have a row for.
     *
     * @return list<mixed>
     */
    private function sharedKeys(string $table, string $column, mixed $from, mixed $to): array
    {
        return array_values(DB::table($table)
            ->where('contact_id', $from)
            ->whereIn($column, DB::table($table)->where('contact_id', $to)->select($column))
            ->distinct()
            ->pluck($column)
            ->all());
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function table(string $model): string
    {
        return (new $model)->getTable();
    }
}
