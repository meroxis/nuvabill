<?php

namespace App\Http\Controllers\Store;

use App\Domains\DomainName;
use App\Domains\DomainSearch;
use App\Http\Controllers\Controller;
use App\Seo\Seo;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The public domain search: type a name, see which extensions are free and what they cost.
 */
class DomainSearchController extends Controller
{
    public function __invoke(Request $request, DomainSearch $search, Seo $seo): View
    {
        $currency = auth('web')->user()?->currency ?? (string) setting('billing.currency');
        $prices = $search->prices($currency);
        $query = trim((string) $request->query('q', ''));

        // Search results are not pages of their own for search engines; the empty search page is.
        if ($query !== '') {
            $seo->hide();
        }

        return view('theme::store.domains', [
            'query' => $query,
            'results' => $query === '' ? collect() : $search->search($query, $currency, $prices),
            'invalid' => $query !== '' && DomainName::normalize($query) === null && DomainName::label($query) === null,
            'prices' => $prices,
            'currency' => $currency,
        ]);
    }
}
