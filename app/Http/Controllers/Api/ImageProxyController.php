<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductImage;
use App\Support\ImageMirror;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** Serves our own copy of a catalogue picture by its key. Public, immutable and cacheable. */
class ImageProxyController extends Controller
{
    public function __construct(private ImageMirror $mirror) {}

    /**
     * Product picture by key
     *
     * The key is a hash of the picture's original address, as returned in `image_url`. The first request for a key that
     * has not been copied yet downloads it from the original site; every later request is served from our storage.
     * Returns 404 when the original could not be fetched.
     */
    public function show(Request $request, string $key): Response
    {
        $image = preg_match('/^[a-f0-9]{32}$/', $key) ? ProductImage::where('key', $key)->orderByRaw("status = 'ready' desc")->first() : null;
        abort_if(! $image, 404);

        if ($image->status !== 'ready' && $this->mirror->mayRetry($image)) {
            $this->mirror->fetch($key);
            $image = ProductImage::where('key', $key)->first();
        }
        if ($image->status !== 'ready' || ! $image->path || ! Storage::disk($image->disk)->exists($image->path)) {
            return response('', 404, ['Cache-Control' => 'public, max-age=300']);
        }

        $headers = [
            'Cache-Control' => 'public, max-age=31536000, immutable', 'ETag' => '"'.$key.'"',
            'Content-Type' => $image->mime, 'X-Content-Type-Options' => 'nosniff',
        ];
        if ($request->header('If-None-Match') === '"'.$key.'"') {
            return response('', 304, $headers);
        }

        return Storage::disk($image->disk)->response($image->path, null, $headers);
    }
}
