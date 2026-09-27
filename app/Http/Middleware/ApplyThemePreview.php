<?php

namespace App\Http\Middleware;

use App\Support\Demo;
use App\Support\Themes;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shows the theme or order form a staff member (or a visitor on the public demo) chose to
 * preview, for their session only. Everyone else keeps seeing the one switched on in Settings.
 */
class ApplyThemePreview
{
    public const SESSION_KEY = 'preview.packages';

    public function __construct(private Themes $themes) {}

    public function handle(Request $request, Closure $next): Response
    {
        $preview = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        if (is_array($preview) && self::allowed()) {
            $this->themes->preview($preview['theme'] ?? null, $preview['orderform'] ?? null);
        }

        return $next($request);
    }

    public static function allowed(): bool
    {
        return Demo::isEnabled() || auth('admin')->check();
    }
}
