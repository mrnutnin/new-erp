<?php

namespace App\Modules\Installer\Services;

use Illuminate\Support\Facades\File;
use RuntimeException;

class InstallerStateStore
{
    private function path(): string
    {
        return storage_path('app/private/installer/state.json');
    }

    /** @return array<string, mixed> */
    public function read(): array
    {
        $path = $this->path();

        if (! File::exists($path)) {
            return [];
        }

        $state = json_decode((string) File::get($path), true);

        return is_array($state) ? $state : [];
    }

    /** @param array<string, mixed> $state */
    public function write(array $state): void
    {
        $directory = dirname($this->path());

        if (! File::isDirectory($directory) && ! File::makeDirectory($directory, 0750, true)) {
            throw new RuntimeException('Unable to prepare installer state directory.');
        }

        File::put($this->path(), json_encode([
            ...$state,
            'updated_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true);
    }
}
