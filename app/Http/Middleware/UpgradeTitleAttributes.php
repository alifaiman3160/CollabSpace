<?php

namespace App\Http\Middleware;

use App\Support\TooltipAttributes;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Every HTML page leaves the server with data-tooltip where it had title (see TooltipAttributes). */
class UpgradeTitleAttributes
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $type = (string) $response->headers->get('Content-Type');

        if ($response->getStatusCode() < 400 && str_contains($type, 'text/html') && ! $response instanceof \Symfony\Component\HttpFoundation\StreamedResponse) {
            $content = $response->getContent();

            if (is_string($content) && $content !== '') {
                $response->setContent(TooltipAttributes::upgrade($content));
            }
        }

        return $response;
    }
}