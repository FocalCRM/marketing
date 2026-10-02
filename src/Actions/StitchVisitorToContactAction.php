<?php

declare(strict_types=1);

namespace Focal\Marketing\Actions;

use Focal\Core\Models\Contact;
use Focal\Marketing\Models\PageView;
use Focal\Marketing\Models\VisitorSession;
use Focal\Marketing\Support\VisitorToken;

class StitchVisitorToContactAction
{
    /**
     * Retroactively stitch anonymous visitor browsing sessions and page views to an identified contact.
     *
     * The visitor token is readable by scripts on the host site, so it only claims sessions
     * that are still anonymous (or already belong to this contact): a token never moves
     * another contact's browsing history. Malformed tokens stitch nothing.
     */
    public function execute(string $visitorToken, Contact $contact): int
    {
        if (! VisitorToken::isValid($visitorToken)) {
            return 0;
        }

        $sessions = VisitorSession::query()
            ->where('visitor_token', $visitorToken)
            ->where(fn ($query) => $query->whereNull('contact_id')->orWhere('contact_id', $contact->id))
            ->get();

        $stitchedCount = 0;

        foreach ($sessions as $session) {
            $session->update(['contact_id' => $contact->id]);

            // Retroactively update associated page views
            PageView::query()
                ->where('session_id', $session->id)
                ->whereNull('contact_id')
                ->update(['contact_id' => $contact->id]);

            $stitchedCount++;
        }

        return $stitchedCount;
    }
}
