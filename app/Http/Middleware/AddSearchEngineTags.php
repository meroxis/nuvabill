<?php

namespace App\Http\Middleware;

use App\Health\Checks\SearchSetupChecks;
use App\Seo\HeadTags;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Adds search engine and link preview tags to client-area pages (see HeadTags), and tells search
 * engines to keep the admin area and the installer out of their results.
 */
class AddSearchEngineTags
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $adminPath = trim((string) config('nuvabill.admin_path'), '/');

        if ($request->is($adminPath, $adminPath.'/*', 'install', 'install/*')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

            return $response;
        }

        if (! $this->isPage($request, $response)) {
            return $response;
        }

        try {
            $response->setContent(app(HeadTags::class)->apply((string) $response->getContent(), $request));
            $this->noteOtherAddress($request);
        } catch (Throwable $exception) {
            // Search engine tags are never worth a broken page.
            report($exception);
        }

        return $response;
    }

    private function isPage(Request $request, Response $response): bool
    {
        return $request->isMethod('GET')
            && $response->getStatusCode() === 200
            && ! $response instanceof StreamedResponse
            && ! $response instanceof BinaryFileResponse
            && str_starts_with((string) $response->headers->get('Content-Type', 'text/html'), 'text/html');
    }

    /**
     * Site health warns when visitors reach the store at an address other than the one in .env,
     * because search engines are sent to the one in .env.
     */
    private function noteOtherAddress(Request $request): void
    {
        SearchSetupChecks::noteAddress($request->getHost());
    }
}
