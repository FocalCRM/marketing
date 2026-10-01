<?php

declare(strict_types=1);

namespace Focal\Marketing\Http\Controllers;

use Focal\Core\Models\Contact;
use Focal\Marketing\Actions\TrackAssetDownloadAction;
use Focal\Marketing\Models\MarketingAsset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MarketingAssetController extends Controller
{
    /**
     * Download or access a digital marketing asset, tracking download metrics and contact activity.
     */
    public function download(
        Request $request,
        string $slug,
        TrackAssetDownloadAction $tracker
    ): RedirectResponse|BinaryFileResponse {
        /** @var MarketingAsset $asset */
        $asset = MarketingAsset::query()->where('slug', $slug)->firstOrFail();

        $contactId = $request->query('contact_id');
        $contact = null;
        if (! empty($contactId)) {
            $contact = Contact::query()->find((int) $contactId);
        }

        $tracker->execute(
            asset: $asset,
            contact: $contact,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent()
        );

        if (! empty($asset->external_url)) {
            return redirect()->away($asset->external_url);
        }

        if (! empty($asset->file_path) && file_exists(storage_path('app/'.$asset->file_path))) {
            return response()->download(storage_path('app/'.$asset->file_path));
        }

        return redirect()->back()->with('success', "Download started for {$asset->name}");
    }
}
