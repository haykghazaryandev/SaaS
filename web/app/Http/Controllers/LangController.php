<?php

namespace App\Http\Controllers;

use App\Support\Translations;
use Illuminate\Http\JsonResponse;

/**
 * GET /lang/{locale}.json?v=<version>: every interface text of a language, flat ("player.size.small"), from the
 * `translations` table. resources/js/lib/i18n.ts loads it before the app mounts. The version in the URL changes
 * with every edit, so the browser may keep the file for a year.
 */
class LangController extends Controller
{
    public function __invoke(string $locale, Translations $translations): JsonResponse
    {
        abort_unless(in_array($locale, config('app.locales'), true), 404);

        return response()->json($translations->forClient($locale), 200, [], JSON_UNESCAPED_UNICODE)
            ->header('Cache-Control', 'public, max-age=31536000, immutable');
    }
}
