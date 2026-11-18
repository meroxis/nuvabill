<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SeoMissingPage;
use App\Models\SeoRedirect;
use App\Seo\MissingPages;
use App\Seo\OldAddresses;
use App\Seo\RobotsTxt;
use App\Seo\SiteAddress;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Settings → Search engines → Redirects: old addresses that forward to new ones (made by staff,
 * by Nuvabill when a web address changes, by the WHMCS pack or by add-ons), and the addresses
 * people could not open.
 */
class SeoRedirectController extends Controller
{
    /** Where the installation guide explains the web server setup for old .php addresses. */
    public const NGINX_GUIDE = 'https://github.com/meroxis/nuvabill/blob/main/docs/installation.md#nginx';

    public function index(Request $request): View
    {
        $redirects = SeoRedirect::query()->latest('id')->paginate(50)->withQueryString();
        $from = $request->query('from');

        return view('admin.settings.redirects', [
            'redirects' => $redirects,
            'blocked' => $redirects->getCollection()->mapWithKeys(fn (SeoRedirect $redirect): array => [$redirect->id => RobotsTxt::blockedByOwnLine($redirect->from_path)]),
            'missing' => MissingPages::top(),
            'whmcs' => OldAddresses::whmcsTargets(),
            'siteUrl' => rtrim(SiteAddress::url(''), '/').'/',
            'prefill' => is_string($from) ? mb_substr(MissingPages::cleanPath($from) ?? '', 0, 191) : '',
            'nginxGuide' => self::NGINX_GUIDE,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules(), [], $this->attributes());
        [$redirect, $reason, $field] = SeoRedirect::saveManual($data['from'], $data['to'], (int) $data['status'], $request->boolean('keep_query'));

        if ($redirect === null) {
            return back()->withErrors([$field => $reason])->withInput();
        }

        SeoMissingPage::query()->where('path', $redirect->from_path)->delete();
        Activity::log('settings.seo', 'Redirect /'.$redirect->from_path.' → /'.$redirect->to_path.' added');

        return redirect()->route('admin.settings.seo.redirects.index')->with('status', $this->savedMessage($redirect, $data['to']));
    }

    public function update(Request $request, SeoRedirect $redirect): RedirectResponse
    {
        $bag = 'redirect'.$redirect->id;
        $data = $request->validateWithBag($bag, $this->rules(), [], $this->attributes());
        [$saved, $reason, $field] = SeoRedirect::saveManual($data['from'], $data['to'], (int) $data['status'], $request->boolean('keep_query'), $redirect->source ?: 'auto', $redirect);

        if ($saved === null) {
            return back()->withErrors([$field => $reason], $bag)->withInput();
        }

        SeoMissingPage::query()->where('path', $saved->from_path)->delete();
        Activity::log('settings.seo', 'Redirect /'.$saved->from_path.' → /'.$saved->to_path.' changed');

        return back()->with('status', $this->savedMessage($saved, $data['to']));
    }

    public function whmcs(): RedirectResponse
    {
        $count = OldAddresses::addWhmcsPack();
        Activity::log('settings.seo', 'WHMCS addresses added ('.$count.')');

        return back()->with('status', $count > 0
            ? __(':count old WHMCS addresses now forward.', ['count' => $count])
            : __('The old WHMCS addresses forward already.'));
    }

    public function dismiss(SeoMissingPage $missingPage): RedirectResponse
    {
        $missingPage->delete();

        return back()->with('status', __('The address was taken off the list.'));
    }

    public function clear(): RedirectResponse
    {
        SeoMissingPage::query()->delete();

        return back()->with('status', __('The list is empty now.'));
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rules(): array
    {
        return [
            'from' => ['required', 'string', 'max:300'],
            'to' => ['required', 'string', 'max:300'],
            'status' => ['required', Rule::in(SeoRedirect::STATUSES)],
            'keep_query' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function attributes(): array
    {
        return ['from' => __('Old address'), 'to' => __('Goes to'), 'status' => __('Kind')];
    }

    private function savedMessage(SeoRedirect $redirect, string $typed): string
    {
        $message = __('Redirect saved.');

        if ($redirect->to_path !== SeoRedirect::cleanTarget($typed)) {
            $message .= ' '.__('It now goes straight to /:path.', ['path' => $redirect->to_path]);
        }

        return $message;
    }
}
