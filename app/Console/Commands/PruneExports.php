<?php

namespace App\Console\Commands;

use App\Models\Export;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('exports:prune {--days=7}')]
#[Description('Remove exportações ZIP antigas')]
class PruneExports extends Command
{
    public function handle(): int
    {
        Export::where('created_at', '<', now()->subDays((int) $this->option('days')))->each(function (Export $export): void {
            if ($export->path) {
                Storage::disk(config('sefaz.xml_disk'))->delete($export->path);
            }

            $export->delete();
        });

        return self::SUCCESS;
    }
}
