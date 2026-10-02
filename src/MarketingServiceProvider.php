<?php

declare(strict_types=1);

namespace Focal\Marketing;

use DoPHP\MailBuilder\MergeTags\MergeTagRegistry;
use Focal\Core\Models\Contact;
use Focal\Marketing\Console\Commands\DecayLeadScoresCommand;
use Focal\Marketing\Console\Commands\DispatchScheduledCampaignsCommand;
use Focal\Marketing\Console\Commands\EvaluateAbTestsCommand;
use Focal\Marketing\Console\Commands\ProcessWorkflowsCommand;
use Focal\Marketing\Console\Commands\SunsetInactiveSubscribersCommand;
use Focal\Marketing\Models\CampaignRecipient;
use Focal\Marketing\Models\CustomBehavioralEvent;
use Focal\Marketing\Models\FormSubmission;
use Focal\Marketing\Models\LeadDecayLog;
use Focal\Marketing\Models\LeadScoreLog;
use Focal\Marketing\Models\MarketingSubscription;
use Focal\Marketing\Models\WorkflowEnrollment;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\ServiceProvider;

class MarketingServiceProvider extends ServiceProvider
{
    private static bool $ampCorsSkipRegistered = false;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/focal-marketing.php',
            'focal-marketing'
        );

        $this->callAfterResolving(MergeTagRegistry::class, function (MergeTagRegistry $registry): void {
            $this->registerMergeTags($registry);
        });
    }

    /**
     * Register CRM merge tags (contact, company, sender) with the mail builder.
     */
    protected function registerMergeTags(MergeTagRegistry $registry): void
    {
        $registry
            ->register('Contact', [
                '{{contact.first_name}}' => 'Recipient first name (e.g. Sarah)',
                '{{contact.last_name}}' => 'Recipient last name (e.g. Connor)',
                '{{contact.full_name}}' => 'Recipient full name (e.g. Sarah Connor)',
                '{{contact.email}}' => 'Recipient email address',
                '{{contact.job_title}}' => 'Recipient professional title (e.g. VP of Operations)',
                '{{contact.phone}}' => 'Recipient direct phone number',
                '{{contact.lifecycle_stage}}' => 'Current CRM lifecycle stage (e.g. Customer, Lead)',
            ], ['contact' => [
                'first_name' => 'Alex',
                'last_name' => 'Morgan',
                'full_name' => 'Alex Morgan',
                'email' => 'alex.morgan@acme.com',
                'job_title' => 'Chief Technology Officer',
                'phone' => '+1 (555) 234-5678',
                'lifecycle_stage' => 'customer',
            ]])
            ->register('Company', [
                '{{company.name}}' => 'Associated company name (e.g. Acme Corporation)',
                '{{company.domain}}' => 'Company corporate domain (e.g. acme.com)',
                '{{company.industry}}' => 'Company industry vertical (e.g. Software & Technology)',
            ], ['company' => [
                'name' => 'Acme Corporation',
                'domain' => 'acme.com',
                'industry' => 'Artificial Intelligence & Robotics',
            ]])
            ->register('Sender / Owner', [
                '{{sender.name}}' => 'Assigned account executive or sender name',
                '{{sender.email}}' => 'Sender reply-to email address',
            ], ['sender' => [
                'name' => 'Alex Rivera',
                'email' => 'alex@example.com',
            ]]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'focal-marketing');
        if (config('focal-marketing.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
            $this->skipGlobalCorsForAmpRoutes();
        }

        // Dynamic Eloquent relations on Contact
        if (class_exists(Contact::class)) {
            Contact::resolveRelationUsing('formSubmissions', function (Contact $contact) {
                return $contact->hasMany(FormSubmission::class, 'contact_id');
            });

            Contact::resolveRelationUsing('campaignRecipients', function (Contact $contact) {
                return $contact->hasMany(CampaignRecipient::class, 'contact_id');
            });

            Contact::resolveRelationUsing('marketingSubscription', function (Contact $contact) {
                return $contact->hasOne(MarketingSubscription::class, 'contact_id');
            });

            Contact::resolveRelationUsing('leadScoreLogs', function (Contact $contact) {
                return $contact->hasMany(LeadScoreLog::class, 'contact_id')->orderBy('created_at', 'desc');
            });

            Contact::resolveRelationUsing('leadDecayLogs', function (Contact $contact) {
                return $contact->hasMany(LeadDecayLog::class, 'contact_id')->orderBy('created_at', 'desc');
            });

            Contact::resolveRelationUsing('workflowEnrollments', function (Contact $contact) {
                return $contact->hasMany(WorkflowEnrollment::class, 'contact_id');
            });

            Contact::resolveRelationUsing('customBehavioralEvents', function (Contact $contact) {
                return $contact->hasMany(CustomBehavioralEvent::class, 'contact_id');
            });
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                DispatchScheduledCampaignsCommand::class,
                ProcessWorkflowsCommand::class,
                EvaluateAbTestsCommand::class,
                DecayLeadScoresCommand::class,
                SunsetInactiveSubscribersCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/focal-marketing.php' => config_path('focal-marketing.php'),
            ], 'focal-marketing-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'focal-marketing-migrations');
        }
    }

    /**
     * The in-email AMP endpoints set their own CORS headers from an origin
     * allow-list (focal-marketing.amp.allowed_origins). Keep the app's global
     * CORS middleware (config/cors.php, which matches "api/*" by default) from
     * replacing them with its own, usually wildcard, headers.
     */
    protected function skipGlobalCorsForAmpRoutes(): void
    {
        if (self::$ampCorsSkipRegistered) {
            return;
        }

        self::$ampCorsSkipRegistered = true;

        HandleCors::skipWhen(static function (Request $request): bool {
            if (! str_ends_with($request->path(), 'amp/feedback') && ! str_ends_with($request->path(), 'amp/rsvp')) {
                return false;
            }

            foreach (['focal.marketing.amp.feedback', 'focal.marketing.amp.rsvp'] as $name) {
                $route = app('router')->getRoutes()->getByName($name);

                if ($route instanceof Route && trim($route->uri(), '/') === trim($request->path(), '/')) {
                    return true;
                }
            }

            return false;
        });
    }
}
