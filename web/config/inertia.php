<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Testing
    |--------------------------------------------------------------------------
    |
    | Page components live in resources/js/pages (lowercase). The package
    | default is js/Pages, which only matches on case-insensitive file
    | systems (macOS), so assertInertia() failed on Linux and in CI.
    |
    */

    'testing' => [
        'ensure_pages_exist' => true,
        'page_paths' => [resource_path('js/pages')],
        'page_extensions' => ['js', 'jsx', 'svelte', 'ts', 'tsx', 'vue'],
    ],

];
