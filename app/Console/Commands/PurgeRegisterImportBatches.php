<?php

namespace App\Console\Commands;

use App\Enums\RegisterImportStatus;
use App\Models\RegisterImportBatch;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PurgeRegisterImportBatches extends Command
{
    protected $signature = 'register-imports:purge';

    protected $description = 'Expire pending register imports and purge expired normalized payloads';

    public function handle(AuditLogger $audit): int
    {
        RegisterImportBatch::query()
            ->where('expires_at', '<=', now('UTC'))
            ->whereIn('status', [RegisterImportStatus::Expired->value, RegisterImportStatus::Applied->value, RegisterImportStatus::Rejected->value])
            ->delete();

        RegisterImportBatch::query()
            ->where('status', RegisterImportStatus::Pending->value)
            ->where('expires_at', '<=', now('UTC'))
            ->pluck('id')
            ->each(function (string $id) use ($audit): void {
                DB::transaction(function () use ($id, $audit): void {
                    $batch = RegisterImportBatch::query()->with(['project', 'creator'])->whereKey($id)->lockForUpdate()->first();
                    if (! $batch instanceof RegisterImportBatch || $batch->status !== RegisterImportStatus::Pending || $batch->expires_at->getTimestamp() > now('UTC')->getTimestamp()) {
                        return;
                    }
                    $batch->update(['status' => RegisterImportStatus::Expired, 'payload' => [], 'summary' => []]);
                    $audit->record('register_import.expired', $batch->creator, [
                        'project_id' => $batch->project_id,
                        'import_batch_id' => $batch->id,
                        'import_kind' => $batch->kind->value,
                    ], $batch->project->organization_id);
                });
            });

        return self::SUCCESS;
    }
}
