<?php

namespace App\Modules\Platform\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileStorageService
{
    /** @return array{disk: string, path: string, checksum: string, bytes: int, mime_type: string, original_name: string} */
    public function store(UploadedFile $file, string $module, string $companyCode): array
    {
        $disk = (string) config('filesystems.private_disk', 'local');
        if ($disk === 'public') {
            throw new RuntimeException('Private attachment storage cannot use the public disk.');
        }

        $name = (string) Str::uuid();
        $extension = strtolower($file->getClientOriginalExtension());
        $directory = implode('/', [
            app()->environment(),
            Str::slug($companyCode) ?: 'company',
            Str::slug($module),
            now()->format('Y'),
            now()->format('m'),
        ]);
        $path = Storage::disk($disk)->putFileAs($directory, $file, $extension === '' ? $name : "{$name}.{$extension}");

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('Unable to store attachment.');
        }

        return [
            'disk' => $disk,
            'path' => $path,
            'checksum' => hash_file('sha256', $file->getRealPath()),
            'bytes' => $file->getSize(),
            'mime_type' => (string) $file->getMimeType(),
            'original_name' => Str::limit($file->getClientOriginalName(), 255, ''),
        ];
    }

    public function delete(string $disk, string $path): void
    {
        if (! Storage::disk($disk)->delete($path)) {
            throw new RuntimeException('Unable to delete attachment storage object.');
        }
    }

    public function download(string $disk, string $path, string $filename, string $mimeType): StreamedResponse
    {
        return $this->stream($disk, $path, $filename, $mimeType, true);
    }

    public function inline(string $disk, string $path, string $filename, string $mimeType): StreamedResponse
    {
        return $this->stream($disk, $path, $filename, $mimeType, false);
    }

    private function stream(string $disk, string $path, string $filename, string $mimeType, bool $download): StreamedResponse
    {
        $stream = Storage::disk($disk)->readStream($path);
        if (! is_resource($stream)) {
            throw new RuntimeException('Attachment storage object is unavailable.');
        }

        $headers = ['Content-Type' => $mimeType, 'X-Content-Type-Options' => 'nosniff'];

        return $download
            ? response()->streamDownload(function () use ($stream): void {
                fpassthru($stream);
                fclose($stream);
            }, $filename, $headers)
            : response()->stream(function () use ($stream): void {
                fpassthru($stream);
                fclose($stream);
            }, 200, [...$headers, 'Content-Disposition' => 'inline; filename="'.$filename.'"']);
    }
}
