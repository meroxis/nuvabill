<?php

namespace App\Http\Middleware;

use App\Security\Captcha;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Checks the CAPTCHA on a form when staff turned it on for that form: ->middleware('captcha:client_login').
 */
class VerifyCaptcha
{
    public function __construct(private Captcha $captcha) {}

    public function handle(Request $request, Closure $next, string $form): Response
    {
        if ($this->captcha->protects($form) && ! $this->captcha->verify($request)) {
            return back()
                ->withInput($request->except(['password', 'password_confirmation', 'current_password']))
                ->withErrors(['captcha' => __('Please confirm you are a person, then try again.')]);
        }

        return $next($request);
    }
}
