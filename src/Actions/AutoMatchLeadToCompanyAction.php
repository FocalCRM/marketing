<?php

declare(strict_types=1);

namespace Focal\Marketing\Actions;

use Focal\Core\Models\Company;
use Focal\Core\Models\Contact;

class AutoMatchLeadToCompanyAction
{
    /**
     * Common free email providers that should not trigger domain matching.
     *
     * @var list<string>
     */
    protected const FREE_EMAIL_DOMAINS = [
        'gmail.com',
        'yahoo.com',
        'hotmail.com',
        'outlook.com',
        'icloud.com',
        'aol.com',
        'proton.me',
        'protonmail.com',
        'zoho.com',
        'mail.com',
        'gmx.com',
        'yandex.com',
        'live.com',
        'msn.com',
    ];

    /**
     * Automatically match a contact to an existing company by corporate email domain,
     * inherit sales rep ownership, trigger target account alerts, and sync intent score.
     */
    public function execute(Contact $contact): ?Company
    {
        if (empty($contact->email) || ! str_contains($contact->email, '@')) {
            return null;
        }

        $parts = explode('@', strtolower(trim($contact->email)));
        $domain = trim($parts[1] ?? '');

        if (empty($domain) || in_array($domain, self::FREE_EMAIL_DOMAINS, true)) {
            return null;
        }

        /** @var Company|null $company */
        $company = Company::whereDomain($domain)->first();

        if ($company === null) {
            return null;
        }

        // 1. Associate contact with company if not already linked
        if (! $contact->isAssociatedWith($company)) {
            $contact->associateWith($company, 'member');
        }

        // 2. Inherit sales rep owner if contact is unassigned
        if ($contact->owner_id === null && $company->owner_id !== null) {
            $contact->update(['owner_id' => $company->owner_id]);
        }

        // 3. High-priority task notification if target account
        if ($company->isTargetAccount()) {
            $contactName = $contact->full_name ?: $contact->email;
            $company->logTask(
                title: "L2A Match: New Lead from Target Account {$company->name}",
                dueAt: now()->addHours(2),
                body: "Lead-to-Account auto-matched {$contactName} ({$contact->email}) to {$company->account_tier} target account."
            );
        }

        // 4. Recalculate company buying committee and intent score
        app(CalculateCompanyIntentScoreAction::class)->execute($company);

        return $company;
    }
}
