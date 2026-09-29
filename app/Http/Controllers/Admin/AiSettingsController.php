<?php

namespace App\Http\Controllers\Admin;

use App\Ai\AiModels;
use App\Ai\AiUnavailable;
use App\Ai\Claude;
use App\Http\Controllers\Controller;
use App\Support\Activity;
use App\Support\Locales;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Settings → AI: the owner's Anthropic key, the model, a monthly spending limit, the staff language
 * and what AI may help with.
 */
class AiSettingsController extends Controller
{
    public function edit(Claude $claude): View
    {
        return view('admin.settings.ai', [
            'models' => AiModels::ALL,
            'currentModel' => AiModels::current(),
            'hasKey' => trim((string) setting('ai.key')) !== '',
            'spent' => $claude->spentThisMonth(),
            'requests' => $claude->requestsThisMonth(),
            'limit' => $claude->limitMicros(),
            'languages' => array_map(fn (array $language): string => $language['native'], Locales::ALL),
        ]);
    }

    public function update(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['nullable', 'string', 'max:300', 'starts_with:sk-ant-'],
            'remove_key' => ['boolean'],
            'model' => ['required', Rule::in(array_keys(AiModels::ALL))],
            'monthly_limit' => ['required', 'numeric', 'min:0', 'max:10000'],
            'staff_language' => ['required', Rule::in(array_keys(Locales::ALL))],
            'drafts' => ['boolean'],
            'translate' => ['boolean'],
            'summaries' => ['boolean'],
            'descriptions' => ['boolean'],
        ], [
            'key.starts_with' => __('This does not look like an Anthropic API key. Keys start with sk-ant-.'),
        ]);

        $values = [
            'ai.model' => $data['model'],
            'ai.monthly_limit' => (int) round((float) $data['monthly_limit'] * 100),
            'ai.staff_language' => $data['staff_language'],
        ];

        foreach (Claude::FEATURES as $feature) {
            $values['ai.'.$feature] = (bool) ($data[$feature] ?? false);
        }

        if ($request->boolean('remove_key')) {
            $values['ai.key'] = '';
        } elseif (filled($data['key'] ?? null)) {
            $values['ai.key'] = trim((string) $data['key']);
        }

        $settings->setMany($values);
        Activity::log('settings.ai', 'AI settings changed');

        return back()->with('status', __('Settings saved.'));
    }

    /**
     * Send one tiny request with the saved key and model.
     */
    public function test(Claude $claude): RedirectResponse
    {
        try {
            $answer = $claude->test();
        } catch (AiUnavailable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', __('AI help works. :answer', ['answer' => $answer]));
    }
}
