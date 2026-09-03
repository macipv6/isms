<?php

namespace Tests\Feature\Evidence;

use App\Enums\UserRole;
use App\Exceptions\EvidenceIntegrityException;
use App\Models\EvidenceFile;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;
use App\Services\Evidence\EvidenceDownloadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EvidenceDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_download_uses_safe_attachment_headers_after_verifying_bytes(): void
    {
        [$evidence] = $this->evidence('policy.txt', 'approved policy');
        $response = app(EvidenceDownloadService::class)->download($evidence);

        $this->assertSame('attachment; filename="policy.txt"', $response->headers->get('content-disposition'));
        $this->assertSame('nosniff', $response->headers->get('x-content-type-options'));
    }

    /** @dataProvider corruptObjects */
    public function test_corrupt_or_missing_object_raises_generic_integrity_failure(string $contents, int $size, string $hash): void
    {
        [$evidence] = $this->evidence('private-path.txt', $contents, $size, $hash);
        try {
            app(EvidenceDownloadService::class)->download($evidence);
            $this->fail('Corrupt evidence must not be streamed.');
        } catch (EvidenceIntegrityException) {
        }

        $this->assertDatabaseHas('audit_events', ['event_type' => 'evidence.integrity_failed', 'context->evidence_id' => $evidence->id]);
    }

    public static function corruptObjects(): array
    {
        return [
            'missing' => ['', 1, hash('sha256', 'x')],
            'wrong size' => ['x', 2, hash('sha256', 'x')],
            'wrong hash' => ['x', 1, hash('sha256', 'y')],
        ];
    }

    /** @return array{EvidenceFile, User} */
    private function evidence(string $name, string $contents, ?int $size = null, ?string $hash = null): array
    {
        Storage::fake('evidence');
        $project = IsmsProject::factory()->for(Organization::factory()->create(['organization_type' => 'customer', 'entra_tenant_id' => null]))->create();
        $actor = User::factory()->for(Organization::factory()->create(['organization_type' => 'internal']))->create(['role' => UserRole::Consultant]);
        $evidence = EvidenceFile::factory()->for($project)->create(['original_name' => $name, 'storage_path' => 'projects/'.$project->id.'/object.txt', 'size_bytes' => $size ?? strlen($contents), 'sha256' => $hash ?? hash('sha256', $contents), 'uploaded_by' => $actor->id]);
        if ($contents !== '') {
            Storage::disk('evidence')->put($evidence->storage_path, $contents);
        }

        return [$evidence, $actor];
    }
}
