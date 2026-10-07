<?php

namespace App\Console\Commands;

use App\Models\ProductImage;
use App\Support\ImageMirror;
use Illuminate\Console\Command;

class MirrorImages extends Command
{
    protected $signature = 'catalogue:mirror-images {--limit=0 : Stop after this many pictures} {--retry-failed : Also retry pictures that failed before} {--refresh : Download everything again} {--delay=250 : Pause between downloads, in milliseconds}';

    protected $description = 'Download and keep our own copy of pictures captured from other storefronts (otherwise each is fetched on its first request).';

    public function handle(ImageMirror $mirror): int
    {
        $statuses = $this->option('refresh') ? ['pending', 'failed', 'ready'] : ($this->option('retry-failed') ? ['pending', 'failed'] : ['pending']);
        $keys = ProductImage::whereNotNull('key')->whereIn('status', $statuses)->orderBy('id')->pluck('key')->unique()->values();
        if ($limit = (int) $this->option('limit')) {
            $keys = $keys->take($limit);
        }

        $ok = $bad = 0;
        $bar = $this->output->createProgressBar($keys->count());
        foreach ($keys as $key) {
            $mirror->fetch($key, (bool) $this->option('refresh')) ? $ok++ : $bad++;
            $bar->advance();
            usleep(max(0, (int) $this->option('delay')) * 1000);   // be gentle with the source
        }
        $bar->finish();
        $this->newLine(2);
        $this->info("{$ok} copied, {$bad} failed, out of {$keys->count()}.");
        foreach (ProductImage::where('status', 'failed')->orderByDesc('updated_at')->limit(10)->get(['origin_url', 'last_error']) as $f) {
            $this->warn("  {$f->origin_url}: {$f->last_error}");
        }

        return self::SUCCESS;
    }
}
