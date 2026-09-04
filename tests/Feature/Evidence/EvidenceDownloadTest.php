<?php

namespace Tests\Feature\Evidence;

use App\Enums\UserRole;
use App\Exceptions\EvidenceIntegrityException;
use App\Models\AuditEvent;
use App\Models\EvidenceFile;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Evidence\EvidenceDownloadService;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
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

    public function test_verified_temporary_bytes_are_streamed_when_storage_changes_after_verification(): void
    {
        [$evidence] = $this->evidence('policy.txt', 'approved policy');
        $response = app(EvidenceDownloadService::class)->download($evidence);
        Storage::disk('evidence')->delete($evidence->storage_path);

        ob_start();
        $response->sendContent();
        $contents = ob_get_clean();

        $this->assertSame('approved policy', $contents);
    }

    #[DataProvider('corruptObjects')]
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

    public function test_oversized_object_is_rejected_before_it_can_be_streamed(): void
    {
        [$evidence] = $this->evidence('oversized.txt', str_repeat('x', 8193), 1, hash('sha256', 'x'));

        $this->expectException(EvidenceIntegrityException::class);
        app(EvidenceDownloadService::class)->download($evidence);
    }

    public function test_integrity_audit_failure_is_reported_but_the_client_exception_stays_generic(): void
    {
        [$evidence] = $this->evidence('private-path.txt', 'x', 1, hash('sha256', 'y'));
        $auditFailure = new RuntimeException('intentional integrity audit failure');
        $this->app->instance(AuditLogger::class, $this->failingAuditLogger($auditFailure));
        $handler = Mockery::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->once()->with($auditFailure);
        $this->app->instance(ExceptionHandler::class, $handler);

        try {
            app(EvidenceDownloadService::class)->download($evidence);
            $this->fail('The integrity failure must stop the download.');
        } catch (EvidenceIntegrityException $exception) {
            $this->assertSame('Der Nachweis konnte nicht sicher bereitgestellt werden.', $exception->getMessage());
            $this->assertSame($auditFailure, $exception->getPrevious());
        }
    }

    /**
     * @return array<string, array{string, int, string}>
     */
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

    private function failingAuditLogger(RuntimeException $failure): AuditLogger
    {
        return new class($failure) extends AuditLogger
        {
            public function __construct(private RuntimeException $failure) {}

            public function record(
                string $eventType,
                ?User $actor,
                array $context = [],
                ?string $organizationId = null,
            ): AuditEvent {
                throw $this->failure;
            }
        };
    }
}
