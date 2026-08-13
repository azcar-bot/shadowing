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

    public function __construct(
        public int $shadowingSourceId,
        public string $targetVersion = 'vi-v1',
        public bool $force = false
    ) {}

    public function handle(ShadowingTranslationService $service): void
    {
        $source = ShadowingSource::find($this->shadowingSourceId);
        if (!$source) {
            return;
        }

        $service->translateSource($source, $this->targetVersion, $this->force);
    }
}
