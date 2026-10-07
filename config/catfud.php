<?php

return [
    // Fixed wording shown wherever Public Advisories appear. Advisories are attributed third-party information,
    // never a verdict from the app, and the absence of an advisory means nothing.
    'advisory_disclaimer' => 'Public advisories are information reported by third parties. Cat Füd has not verified them, '
        .'and the absence of an advisory is not a statement of safety. You decide whether anything here applies to you.',

    // Wording rule for suggestions: they come from the user\'s own records, never from a health claim.
    'suggestion_basis' => 'Based on what you have recorded. This is not advice about what is good for your pet.',

    // The catalogue forks by region: separate brand tree, products and barcodes per region. Only US exists for now.
    'default_region' => env('CATFUD_REGION', 'US'),

    // Brand tree depth cap: manufacturer > brand > line > sub-line > sub-sub-line. Vetted against 390 real products.
    'brand_tree_max_depth' => 5,

    // Where uploaded product pictures and brand logos go (a disk from config/filesystems.php). Run `php artisan storage:link` for 'public'.
    'image_disk' => env('CATFUD_IMAGE_DISK', 'public'),

    // Pictures captured from other storefronts are downloaded once and served from our own copy (see App\Support\ImageMirror).
    'image_mirror' => [
        'max_bytes' => (int) env('CATFUD_MIRROR_MAX_BYTES', 5 * 1024 * 1024),
        'timeout' => (int) env('CATFUD_MIRROR_TIMEOUT', 15),
        'user_agent' => env('CATFUD_MIRROR_UA', 'CatFudImageMirror/1.0 (one-time copy of a catalogue picture; contact the site owner to opt out)'),
        'retry_after_minutes' => 60,        // a failed picture is retried by a request no sooner than this, and at most max_attempts times
        'max_attempts' => 3,
    ],
];
