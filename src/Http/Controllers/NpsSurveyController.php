<?php

declare(strict_types=1);

namespace Focal\Marketing\Http\Controllers;

use Focal\Marketing\Actions\SubmitNpsResponseAction;
use Focal\Marketing\Models\NpsResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

class NpsSurveyController extends Controller
{
    /**
     * Record 1-click rating directly from an email link and show feedback page.
     */
    public function recordScore(
        string $token,
        int $score,
        SubmitNpsResponseAction $action
    ): View {
        /** @var NpsResponse $response */
        $response = NpsResponse::query()->where('token', $token)->firstOrFail();

        $action->execute($response, $score);
        $survey = $response->survey;

        return view('focal-marketing::nps-feedback', [
            'response' => $response,
            'survey' => $survey,
        ]);
    }

    /**
     * Submit optional qualitative feedback text.
     */
    public function submitFeedback(Request $request, string $token): RedirectResponse
    {
        /** @var NpsResponse $response */
        $response = NpsResponse::query()->where('token', $token)->firstOrFail();

        $feedback = $request->input('feedback');
        if (! empty($feedback)) {
            $response->update([
                'feedback' => (string) $feedback,
            ]);
        }

        return redirect()->back()->with('success', 'Thank you! Your comments have been saved.');
    }
}
