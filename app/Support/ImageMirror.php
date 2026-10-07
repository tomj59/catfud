<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Keeps our own copy of pictures captured from other storefronts, so the apps never load them from someone else's CDN.
 *
 * register() is cheap and offline: it records the origin URL, the page it came from (credit), and a key = a hash of the URL.
 * The product's image_url becomes our keyed path (/api/v1/img/{key}). fetch() downloads the picture once, checks it really is
 * a picture, and stores it on the image disk. Products that share a picture share one key and one file.
 * Fetching happens on the first request for the key, or in bulk with `php artisan catalogue:mirror-images`.
 */
class ImageMirror
{
    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public static function keyFor(string $originUrl): string
    {
        return substr(hash('sha256', self::normalize($originUrl)), 0, 32);
    }

    public static function normalize(string $url): string
    {
        return trim(explode('#', trim($url), 2)[0]);
    }

    public static function isRemote(?string $url): bool
    {
        return is_string($url) && preg_match('#^https?://#i', $url) === 1;
    }

    /**
     * Point a product at an outside picture and queue it for copying. Returns true if anything changed. A picture a person
     * uploaded is never replaced, and an unchanged origin is left alone, so re-importing a file is harmless.
     */
    public function register(Product $product, string $originUrl, ?string $pageUrl = null): bool
    {
        $origin = self::normalize($originUrl);
        $key = self::keyFor($origin);
        $image = ProductImage::where('product_id', $product->id)->first();

        if ($image && $image->uploaded_by !== null) {
            return false;
        }
        if ($image && $image->origin_url === $origin) {
            return false;
        }

        $host = parse_url($pageUrl ?: $origin, PHP_URL_HOST) ?: null;
        $shared = ProductImage::where('key', $key)->where('status', 'ready')->first();   // someone already fetched this exact picture
        $fields = [
            'key' => $key, 'origin_url' => $origin, 'source' => 'other', 'source_url' => $pageUrl ?: null,
            'attribution' => $host ? 'Image from '.preg_replace('/^www\./', '', $host) : null, 'licence' => null, 'uploaded_by' => null,
            'fail_count' => 0, 'last_error' => null,
            ...($shared
                ? ['status' => 'ready', 'disk' => $shared->disk, 'path' => $shared->path, 'mime' => $shared->mime, 'bytes' => $shared->bytes,
                    'width' => $shared->width, 'height' => $shared->height, 'fetched_at' => $shared->fetched_at]
                : ['status' => 'pending', 'disk' => null, 'path' => null, 'mime' => null, 'bytes' => null, 'width' => null, 'height' => null, 'fetched_at' => null]),
        ];

        $old = $image?->replicate();
        $row = ProductImage::updateOrCreate(['product_id' => $product->id], $fields);
        if ($old && $old->path && $old->path !== $row->path && ! ProductImage::where('disk', $old->disk)->where('path', $old->path)->exists()) {
            Storage::disk($old->disk)->delete($old->path);
        }
        Product::withoutGlobalScope(\App\Models\Scopes\VisibleScope::class)->whereKey($product->id)->update(['image_url' => $row->url()]);
        $product->image_url = $row->url();

        return true;
    }

    /** Download and store the picture behind a key. True when a good copy is on disk afterwards. */
    public function fetch(string $key, bool $force = false): bool
    {
        $lock = Cache::lock('mirror:'.$key, 60);
        if (! $lock->get()) {
            return false;                       // another request is already fetching it
        }
        try {
            $rows = ProductImage::where('key', $key)->get();
            $first = $rows->first();
            if (! $first || ! $first->origin_url) {
                return false;
            }
            if (! $force && $first->status === 'ready' && $first->path && Storage::disk($first->disk)->exists($first->path)) {
                return true;
            }

            try {
                [$bytes, $mime] = $this->download($first->origin_url);
                $disk = ImageStore::disk();
                $path = 'mirror/'.substr($key, 0, 2).'/'.$key.'.'.self::TYPES[$mime];
                Storage::disk($disk)->put($path, $bytes);
                [$w, $h] = @getimagesizeFromString($bytes) ?: [null, null];
                ProductImage::where('key', $key)->update([
                    'status' => 'ready', 'disk' => $disk, 'path' => $path, 'mime' => $mime, 'bytes' => strlen($bytes), 'width' => $w, 'height' => $h,
                    'fetched_at' => now(), 'fail_count' => 0, 'last_error' => null,
                ]);

                return true;
            } catch (Throwable $e) {
                ProductImage::where('key', $key)->update([
                    'status' => 'failed', 'fail_count' => $first->fail_count + 1, 'last_error' => mb_substr($e->getMessage(), 0, 250), 'updated_at' => now(),
                ]);

                return false;
            }
        } finally {
            $lock->release();
        }
    }

    /** May a request for this key trigger another download attempt? */
    public function mayRetry(ProductImage $image): bool
    {
        $cfg = config('catfud.image_mirror');

        return $image->status === 'pending'
            || ($image->status === 'failed' && $image->fail_count < $cfg['max_attempts'] && $image->updated_at->lt(now()->subMinutes($cfg['retry_after_minutes'])));
    }

    /** @return array{0:string, 1:string} bytes and mime type */
    private function download(string $url): array
    {
        $cfg = config('catfud.image_mirror');
        for ($hop = 0; $hop <= 3; $hop++) {
            $ip = $this->publicAddress($url);
            $parts = parse_url($url);
            $port = $parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80);
            $response = Http::withHeaders(['User-Agent' => $cfg['user_agent'], 'Accept' => 'image/jpeg,image/png,image/webp,*/*;q=0.1'])
                ->timeout($cfg['timeout'])
                ->withOptions([
                    'allow_redirects' => false,
                    // Connect to the address we just vetted, so the name cannot be re-pointed at a private address in between.
                    'curl' => defined('CURLOPT_RESOLVE') ? [CURLOPT_RESOLVE => ["{$parts['host']}:{$port}:{$ip}"]] : [],
                    'on_headers' => function ($r) use ($cfg) {
                        if ((int) $r->getHeaderLine('Content-Length') > $cfg['max_bytes']) {
                            throw new RuntimeException('Picture is larger than the size limit.');
                        }
                    },
                ])->get($url);

            if ($response->redirect()) {
                $next = $response->header('Location');
                if ($next === '') {
                    throw new RuntimeException('Redirect without a location.');
                }
                $url = $this->absolute($url, $next);

                continue;
            }
            if (! $response->successful()) {
                throw new RuntimeException('Source answered HTTP '.$response->status().'.');
            }
            $body = $response->body();
            if ($body === '' || strlen($body) > $cfg['max_bytes']) {
                throw new RuntimeException('Picture is empty or larger than the size limit.');
            }
            // Trust the bytes, not the headers: only real jpeg/png/webp files are kept (no SVG, no HTML pretending to be a picture).
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($body);
            if (! isset(self::TYPES[$mime]) || @getimagesizeFromString($body) === false) {
                throw new RuntimeException('Not a jpeg, png or webp picture ('.($mime ?: 'unknown').').');
            }

            return [$body, $mime];
        }
        throw new RuntimeException('Too many redirects.');
    }

    /** Only http(s) to a public address. Returns the address to connect to. */
    private function publicAddress(string $url): string
    {
        $parts = parse_url($url);
        if (! $parts || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) {
            throw new RuntimeException('Not an http(s) address.');
        }
        $host = $parts['host'];
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (! $ips) {
            throw new RuntimeException("Could not resolve {$host}.");
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException("{$host} points at a private address.");
            }
        }

        return $ips[0];
    }

    private function absolute(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $p = parse_url($base);
        $origin = $p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '');
        if (str_starts_with($location, '//')) {
            return $p['scheme'].':'.$location;
        }
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        return $origin.rtrim(dirname($p['path'] ?? '/'), '/').'/'.$location;
    }
}
