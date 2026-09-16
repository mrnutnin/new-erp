<?php

namespace App\Modules\Platform\Services;

use App\Models\User;
use App\Models\DocumentSignatureSnapshot;
use App\Modules\Settings\Services\GlobalSettings;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class UserMediaService
{
    public function __construct(
        private readonly FileStorageService $storage,
        private readonly GlobalSettings $settings,
    ) {}

    /** @param Closure(array<string, ?string>): void $persist */
    public function persist(
        User $user,
        ?UploadedFile $profileImage,
        bool $removeProfileImage,
        ?UploadedFile $signatureImage,
        ?string $signatureData,
        bool $removeSignature,
        Closure $persist,
    ): void {
        $newFiles = [];
        $oldFiles = [];
        $values = [];
        $drawnSignature = null;

        try {
            if ($signatureData) {
                $drawnSignature = $this->drawingFile($signatureData);
                $signatureImage = $drawnSignature;
            }

            $this->prepare(
                $user,
                'profile_image',
                'user-profile-images',
                $profileImage,
                $removeProfileImage,
                $values,
                $newFiles,
                $oldFiles,
            );
            $this->prepare(
                $user,
                'signature',
                'user-signatures',
                $signatureImage,
                $removeSignature,
                $values,
                $newFiles,
                $oldFiles,
            );

            $persist($values);
        } catch (Throwable $exception) {
            $this->deleteFiles($newFiles, true);

            throw $exception;
        } finally {
            if ($drawnSignature) {
                @unlink($drawnSignature->getPathname());
            }
        }

        $this->deleteFiles($oldFiles);
    }

    public function inlineProfile(User $user): StreamedResponse
    {
        return $this->inline($user, 'profile_image', 'profile-image');
    }

    public function inlineSignature(User $user): StreamedResponse
    {
        return $this->inline($user, 'signature', 'signature');
    }

    /** @param array<string, ?string> $values
     * @param  array<int, array{disk:string,path:string}>  $newFiles
     * @param  array<int, array{disk:string,path:string}>  $oldFiles
     */
    private function prepare(User $user, string $prefix, string $folder, ?UploadedFile $file, bool $remove, array &$values, array &$newFiles, array &$oldFiles): void
    {
        $diskField = $prefix.'_disk';
        $pathField = $prefix.'_path';
        $old = $user->{$diskField} && $user->{$pathField}
            ? ['disk' => $user->{$diskField}, 'path' => $user->{$pathField}]
            : null;

        if ($file) {
            $companyCode = (string) ($this->settings->current()->tax_id ?: 'company');
            $stored = $this->storage->store($file, $folder, $companyCode);
            $newFiles[] = ['disk' => $stored['disk'], 'path' => $stored['path']];
            $values[$diskField] = $stored['disk'];
            $values[$pathField] = $stored['path'];
            if ($prefix === 'signature') {
                $values['signature_checksum'] = $stored['checksum'];
                $values['signature_mime_type'] = $stored['mime_type'];
            }
            if ($old) {
                $oldFiles[] = $old;
            }
        } elseif ($remove && $old) {
            $values[$diskField] = null;
            $values[$pathField] = null;
            if ($prefix === 'signature') {
                $values['signature_checksum'] = null;
                $values['signature_mime_type'] = null;
            }
            $oldFiles[] = $old;
        }
    }

    private function inline(User $user, string $prefix, string $filename): StreamedResponse
    {
        $disk = $user->{$prefix.'_disk'};
        $path = $user->{$prefix.'_path'};
        abort_unless($disk && $path, 404);
        $filesystem = Storage::disk($disk);
        abort_unless($filesystem->exists($path), 404);

        return $this->storage->inline(
            $disk,
            $path,
            $filename.'-'.($user->employee_code ?: $user->id),
            $filesystem->mimeType($path) ?: 'image/png',
        );
    }

    private function drawingFile(string $dataUrl): UploadedFile
    {
        $contents = base64_decode(substr($dataUrl, 22), true);
        $path = tempnam(sys_get_temp_dir(), 'erp-signature-');
        if (! is_string($contents) || ! is_string($path) || file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Unable to prepare the drawn signature.');
        }

        return new UploadedFile($path, 'signature.png', 'image/png', null, true);
    }

    /** @param array<int, array{disk:string,path:string}> $files */
    private function deleteFiles(array $files, bool $force = false): void
    {
        foreach ($files as $file) {
            try {
                if (! $force && DocumentSignatureSnapshot::query()
                    ->where('signature_disk', $file['disk'])
                    ->where('signature_path', $file['path'])
                    ->exists()) {
                    continue;
                }
                $this->storage->delete($file['disk'], $file['path']);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
