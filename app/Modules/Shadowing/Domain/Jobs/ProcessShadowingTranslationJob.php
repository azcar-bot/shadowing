<?php

namespace App\Modules\Shadowing\Domain\Jobs;

use App\Modules\Shadowing\Domain\Services\ShadowingTranslationService;
use App\Modules\Shadowing\Infrastructure\Persistence\Models\ShadowingSource;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessShadowingTranslationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300; // 300s baseline: Worst case 5 batches * 45s HTTP timeout = 225s + 75s safety margin

    public function __construct(
        public int $shadowingSourceId,
        public ?string $targetVersion = null,
        public bool $force = false
    ) {}

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(ShadowingTranslationService $service): void
    {
        $source = ShadowingSource::find($this->shadowingSourceId);
        if (! $source) {
            return;
        }

        $service->translateSource($source, $this->targetVersion, $this->force);
    }
}
