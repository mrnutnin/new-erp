<?php

namespace App\Modules\Installer\Services;

use App\Modules\Installer\Models\InstallationSession;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DatabasePreparationService
{
    public function __construct(private readonly InstallerStateStore $stateStore) {}

    /** @return array{status:string, message:string, output:string, error:?string} */
    public function prepare(): array
    {
        $this->stateStore->write([
            'status' => 'IN_PROGRESS',
            'step_code' => 'database',
            'message' => 'กำลังเตรียมฐานข้อมูล',
        ]);

        try {
            $exitCode = Artisan::call('migrate', ['--force' => true]);
            $output = trim(Artisan::output());

            if ($exitCode === 0) {
                $installerExitCode = Artisan::call('migrate', [
                    '--path' => 'database/migrations/installer',
                    '--force' => true,
                ]);
                $installerOutput = trim(Artisan::output());
                $output = trim($output."\n".$installerOutput);
                $exitCode = $installerExitCode;
            }

            if ($exitCode !== 0 || ! Schema::hasTable('installation_sessions')) {
                throw new \RuntimeException($output !== '' ? $output : 'Migration did not complete successfully.');
            }

            $session = DB::transaction(function () use ($output): InstallationSession {
                $session = InstallationSession::query()->latest('id')->first();

                if (! $session) {
                    $session = InstallationSession::query()->create([
                        'status' => 'DATABASE_READY',
                        'progress' => 10,
                        'started_at' => now(),
                        'metadata' => ['created_by' => 'web_installer'],
                    ]);
                } else {
                    $session->forceFill([
                        'status' => 'DATABASE_READY',
                        'progress' => max(10, (int) $session->progress),
                    ])->save();
                }

                $session->steps()->updateOrCreate(['step_code' => 'database'], [
                    'status' => 'COMPLETED',
                    'started_at' => now(),
                    'completed_at' => now(),
                    'error_message' => null,
                    'metadata' => ['migration_output' => str($output ?? '')->limit(2000)->toString()],
                ]);

                return $session;
            });

            $this->stateStore->write([
                'status' => 'COMPLETED',
                'step_code' => 'database',
                'message' => 'เตรียมฐานข้อมูลสำเร็จ',
                'installation_session_id' => $session->id,
            ]);

            return ['status' => 'success', 'message' => 'เตรียมฐานข้อมูลสำเร็จแล้ว', 'output' => $output ?? '', 'error' => null];
        } catch (Throwable $exception) {
            report($exception);
            $message = 'ไม่สามารถเตรียมฐานข้อมูลได้ กรุณาตรวจสอบการเชื่อมต่อและลองใหม่';

            $this->stateStore->write([
                'status' => 'FAILED',
                'step_code' => 'database',
                'message' => $message,
                'technical_detail' => $exception->getMessage(),
            ]);

            if ($this->hasInstallerTables()) {
                DB::table('installation_logs')->insert([
                    'step_code' => 'database',
                    'action' => 'prepare_database',
                    'status' => 'FAILED',
                    'technical_detail' => $exception->getMessage(),
                    'ip_address' => request()->ip(),
                    'created_at' => now(),
                ]);
            }

            return ['status' => 'failed', 'message' => $message, 'output' => '', 'error' => $this->safeError($exception)];
        }
    }

    private function safeError(Throwable $exception): string
    {
        $message = trim((string) $exception->getMessage());
        $message = preg_replace('/(password|passwd|pwd)\s*[=:]\s*[^\s,;]+/i', '$1=[REDACTED]', $message) ?: $message;

        $message = $message !== '' ? $message : 'ไม่พบรายละเอียดจากระบบฐานข้อมูล';

        return str(sprintf('[%s] %s (at %s:%d)', $exception::class, $message, basename($exception->getFile()), $exception->getLine()))->limit(4000)->toString();
    }

    private function hasInstallerTables(): bool
    {
        try {
            return Schema::hasTable('installation_sessions') && Schema::hasTable('installation_logs');
        } catch (Throwable) {
            return false;
        }
    }
}
