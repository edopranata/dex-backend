<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * Serves the Vue app (built by `npm run build:laravel` into public/) for every non-API path,
 * so Vue Router history URLs like /markets or /tokens/5 work on a hard refresh.
 */
class SpaController extends Controller
{
    public function __invoke(): Response
    {
        $index = config('market.spa_index');

        if (! is_file($index)) {
            return response('Frontend belum di-build. Jalankan: cd frontend && npm run build:laravel', 503)
                ->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        return response(file_get_contents($index))
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-cache, must-revalidate');
    }
}
