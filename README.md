# Focal Marketing (`focalcrm/marketing`)

> This is a read-only split of the [focalcrm/focal](https://github.com/focalcrm/focal) monorepo. Please open issues and pull requests there.

The omnichannel marketing automation, lead generation, and closed-loop revenue attribution engine for the Focal RevOps platform. Delivers visual drip workflows, dynamic landing pages, lead capture forms, multi-touch attribution modeling, behavioral lead scoring, and account-based marketing (ABM) intent tracking.

---

## Architecture & Capabilities

```
+-------------------------------------------------------------------------+
|                              FOCAL MARKETING                            |
|                                                                         |
|  +--------------------+   +--------------------+   +-----------------+  |
|  | Multi-Channel      |   | Visual Drip        |   | Lead Capture &  |  |
|  | Campaigns & A/B    |   | Workflow Engine    |   | Landing Pages   |  |
|  +--------------------+   +--------------------+   +-----------------+  |
|             \                       |                       /           |
|              v                      v                      v            |
|       +---------------------------------------------------------+       |
|       |             Identity Stitching & Web Tracking           |       |
|       |     (Anonymous cookies -> Inbound Contacts -> Accounts) |       |
|       +---------------------------------------------------------+       |
|             |                       |                       |           |
|             v                       v                       v           |
|  +--------------------+   +--------------------+   +-----------------+  |
|  | Multi-Touch        |   | Behavioral Lead    |   | ABM Account     |  |
|  | Attribution Models |   | Scoring & Decay    |   | Intent Engine   |  |
|  +--------------------+   +--------------------+   +-----------------+  |
+-------------------------------------------------------------------------+
```

### Core Features

- **Omnichannel Drip Workflows:** Visual automation orchestrating automated emails, SMS alerts, time delays, branching conditional logic, custom webhooks, and sales handoffs.
- **Multi-Touch Revenue Attribution:** Accurately calculate marketing ROI using 6 standard attribution models:
  - *First Touch* (100% to initial acquisition)
  - *Last Touch* (100% to conversion touchpoint)
  - *Linear* (Equal weight across all touchpoints)
  - *U-Shaped / Position-Based* (40% first touch, 40% lead creation, 20% intermediate)
  - *W-Shaped* (30% first touch, 30% lead creation, 30% deal creation, 10% intermediate)
  - *Time Decay* (Exponential decay favoring touchpoints closer to the closed deal)
- **Closed-Loop Sales Attribution:** Connects directly with `focalcrm/sales` to trace closed-won revenue back to initial campaign touches, UTM parameters, and ad spend.
- **Behavioral Lead Scoring & Automated Decay:** Score contacts based on activities (email opens, whitepaper downloads, pricing page visits), with configurable time-decay rules to automatically degrade scores of dormant leads.
- **Account-Based Marketing (ABM) Intent:** Aggregate multi-contact engagement at target companies into account-level intent scores to flag in-market opportunities.
- **Deliverability Pre-Flight Linter & ESP Webhooks:** Audit campaigns before send (spam keyword triggers, missing unsubscribe headers, contrast issues). Ingest webhook delivery logs from SendGrid, Postmark, AWS SES, and Mailgun.
- **Predictive Send-Time Optimization:** Calculate the optimal hour of day for each recipient based on historical engagement patterns.
- **Forms & Progressive Profiling:** Self-hosted forms and embeddable widgets with anti-bot honey-pots and progressive profiling (asking new questions on return visits).
- **Compliance & Granular Topics:** RFC 8058 one-click unsubscribe headers, preference centers, and topic-level subscriptions (*Product Updates*, *Webinars*, *Newsletters*).
- **Ad Audience Sync:** Synchronize active CRM lists directly with Meta Custom Audiences, Google Customer Match, and LinkedIn Matched Audiences.

---

## Installation

```bash
composer require focalcrm/marketing
```

Publish configuration and migrations:

```bash
php artisan vendor:publish --tag=focal-marketing-migrations
php artisan vendor:publish --tag=focal-marketing-config
```

Run migrations:

```bash
php artisan migrate
```

---

## Quick Start & Code Examples

### 1. Enrolling Contacts in a Drip Workflow

```php
use Focal\Marketing\Actions\EnrollContactInWorkflowAction;
use Focal\Marketing\Models\MarketingWorkflow;

$workflow = MarketingWorkflow::where('name', 'Enterprise SaaS Onboarding')->first();

app(EnrollContactInWorkflowAction::class)->execute(
    workflow: $workflow,
    contact: $contact
);

// Progress due workflow steps across all active enrollments:
// Run automatically via: php artisan focal:marketing-process-workflows
```

### 2. Multi-Touch Attribution Analysis

```php
use Focal\Marketing\Actions\GetCampaignAttributionAction;
use Focal\Marketing\Enums\AttributionModel;

// Compute attribution breakdown for a closed deal
$attribution = app(GetCampaignAttributionAction::class)->execute(
    dealId: $deal->id,
    model: AttributionModel::WShaped
);

// Returns touchpoints with weighted revenue credits
foreach ($attribution as $touch) {
    echo "Campaign {$touch['campaign_name']} attributed with \${$touch['revenue_credit']}\n";
}
```

### 3. Behavioral Lead Scoring & Sales Handoff

```php
use Focal\Marketing\Actions\ApplyLeadScoringEventAction;
use Focal\Marketing\Enums\LeadScoringEventType;

// Award points for high-intent behavioral actions
app(ApplyLeadScoringEventAction::class)->execute(
    contact: $contact,
    eventType: LeadScoringEventType::PricingPageViewed,
    points: 25
);

// If the contact crosses the MQL score threshold (e.g. 100), 
// HandoffLeadToSalesAction automatically transitions the contact and notifies SDRs.
```

### 4. Dispatching a Campaign with Pre-Flight Audits

```php
use Focal\Marketing\Actions\DispatchCampaignAction;
use Focal\Marketing\Actions\LintCampaignDeliverabilityAction;

// Pre-flight check
$audit = app(LintCampaignDeliverabilityAction::class)->execute($campaign);

if ($audit->passes()) {
    app(DispatchCampaignAction::class)->execute($campaign);
} else {
    logger()->error("Deliverability check failed: " . implode(', ', $audit->errors()));
}
```

### 5. Ingesting Form Submissions with Identity Stitching

```php
use Focal\Marketing\Actions\ProcessFormSubmissionAction;

// Ingest payload, update contact custom properties, and stitch anonymous visitor cookie
$contact = app(ProcessFormSubmissionAction::class)->execute(
    form: $demoForm,
    payload: [
        'email' => 'alex@enterprisecorp.com',
        'first_name' => 'Alex',
        'company' => 'Enterprise Corp',
        'team_size' => '250-1000',
    ],
    visitorCookie: request()->cookie('focal_vid')
);
```

---

## Routes

Public pages (hosted forms, landing pages, email tracking, unsubscribe and preference center) are registered in the `web` group with no prefix by default. Webhooks and JSON APIs are registered in the `api` group under `/api/marketing`.

Configure them in `config/focal-marketing.php` (publish with `php artisan vendor:publish --tag=focal-marketing-config`) or through environment variables:

```env
FOCAL_MARKETING_PREFIX=crm              # /forms/{slug} becomes /crm/forms/{slug}
FOCAL_MARKETING_API_PREFIX=api/marketing
FOCAL_MARKETING_DOMAIN=go.example.com   # optional, applies to both groups
FOCAL_MARKETING_ROUTES_ENABLED=true
```

Each group also accepts `middleware`. To register the routes yourself, set `routes.enabled` to `false` and define routes with the same names (`focal.marketing.*`), because models, emails and notifications generate links from those names.

---

## Data Models & Schema Reference

| Model | Table | Responsibility |
| :--- | :--- | :--- |
| `Campaign` | `marketing_campaigns` | Broadcast and segmented multi-channel marketing campaigns. |
| `CampaignRecipient`| `marketing_campaign_recipients` | Delivery state tracking (sent, delivered, opened, clicked, bounced). |
| `MarketingWorkflow`| `marketing_workflows` | Event-triggered and time-delayed automation flow definitions. |
| `WorkflowStep` | `marketing_workflow_steps` | Individual actions, conditions, and delays in a workflow. |
| `WorkflowEnrollment`| `marketing_workflow_enrollments` | Progress and state of contacts through workflows. |
| `MarketingForm` | `marketing_forms` | Lead capture schemas, redirect URLs, and field mappings. |
| `FormSubmission` | `marketing_form_submissions` | Raw submission payloads with stitched visitor records. |
| `LandingPage` | `marketing_landing_pages` | Hosted landing pages with conversion tracking. |
| `VisitorSession` | `marketing_visitor_sessions` | Anonymous web traffic sessions with IP and referrers. |
| `PageView` | `marketing_page_views` | Granular URL visits linked to sessions and contacts. |
| `LeadScoringRule` | `marketing_lead_scoring_rules` | Point valuation criteria for explicit and implicit events. |
| `LeadScoreLog` | `marketing_lead_score_logs` | Audit trail of point adjustments and score decay. |
| `NpsSurvey` | `marketing_nps_surveys` | Customer satisfaction feedback forms and NPS calculations. |
| `EmailSuppression` | `marketing_email_suppressions` | Global suppression registry for bounces, spam traps, and opt-outs. |
| `AdAudienceSync` | `marketing_ad_audience_syncs` | Ad platform synchronization records (Meta, Google, LinkedIn). |

---

## Testing

```bash
vendor/bin/pest packages/marketing/tests --compact
```
