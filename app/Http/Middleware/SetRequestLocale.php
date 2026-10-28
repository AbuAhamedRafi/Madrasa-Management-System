<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetRequestLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = 'bn';

        if ($user = $request->user()) {
            $userLocale = $user->preferred_locale?->value ?? 'bn-BD';
            $locale = str_starts_with($userLocale, 'en') ? 'en' : 'bn';
        } elseif ($acceptLang = $request->header('Accept-Language')) {
            $locale = str_starts_with($acceptLang, 'en') ? 'en' : 'bn';
        }

        App::setLocale($locale);

        return $next($request);
    }
}

