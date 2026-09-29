<?php

namespace App\Http\Middleware;

use App\Support\BrandIcon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Adds the site's icon (see BrandIcon) to every page, so the browser tab shows it with every theme
 * and add-on page. Icons a page already links to are left alone.
 */
class AddSiteIcon
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->isPage($request, $response)) {
            return $response;
        }

        try {
            $html = (string) $response->getContent();
            $end = stripos($html, '</head>');

            if ($end !== false) {
                $tags = BrandIcon::headTags(substr($html, 0, $end));

                if ($tags !== '') {
                    $response->setContent(substr($html, 0, $end).$tags."\n".substr($html, $end));
                }
            }
        } catch (Throwable $exception) {
            // An icon is never worth a broken page.
            report($exception);
        }

        return $response;
    }

    private function isPage(Request $request, Response $response): bool
    {
        return $request->isMethod('GET')
            && ! $response instanceof StreamedResponse
            && ! $response instanceof BinaryFileResponse
            && str_starts_with((string) $response->headers->get('Content-Type', 'text/html'), 'text/html');
    }
}
