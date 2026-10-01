<?php

declare(strict_types=1);

namespace Focal\Marketing\Actions;

use Focal\Core\Models\Contact;
use Focal\Marketing\Models\PageView;
use Focal\Marketing\Models\VisitorSession;

class StitchVisitorToContactAction
{
    /**
     * Retroactively stitch anonymous visitor browsing sessions and page views to an identified contact.
     */
    public function execute(string $visitorToken, Contact $contact): int
    {
        $sessions = VisitorSession::query()
            ->where('visitor_token', $visitorToken)
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
