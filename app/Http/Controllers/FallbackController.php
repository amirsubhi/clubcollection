<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

/**
 * Unknown URLs, every method (routes/web.php registers it twice):
 * - /admin and /admin/* (route "fallback.admin"): with the session, so the 404 page can tell a signed-in admin
 *   (admin-styled page, errors/_admin) from a member or guest (branded page). No CSRF check: it only ever 404s.
 * - everything else (the fallback route): without the session, so a guest's mistyped URL starts no session
 *   (and writes no session row); the branded page needs none.
 * A URL that exists only for other methods stays a 405 (bootstrap/app.php shows a typed GET as a 404 outside
 * local), as before these routes existed. A URL no route has is a 404 for every method.
 */
class FallbackController extends Controller
{
    public function __invoke(Request $request)
    {
        // A URL that exists for other methods stays a 405, as without these routes (bootstrap/app.php shows a
        // typed GET as a 404 outside local). A URL no route has is a 404, whatever the method.
        $allowed = [];
        $routes = app('router')->getRoutes();
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            if ($request->isMethod($method) || ($method === 'GET' && $request->isMethod('HEAD'))) {
                continue;
            }
            try {
                $route = $routes->match(Request::create($request->getPathInfo(), $method));
                if (! $route->isFallback && $route->getName() !== 'fallback.admin') {
                    $allowed[] = $method;
                }
            } catch (HttpException) {
                // Not this method either.
            }
        }
        if ($allowed !== []) {
            throw new MethodNotAllowedHttpException($allowed);
        }

        abort(404);
    }
}
