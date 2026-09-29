<?php

namespace Tests\Feature\ProtectionNeeds;

use App\Data\Dependencies\DependencyNode;
use App\Models\Asset;
use App\Models\BusinessProcess;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\ProtectionNeed;
use App\Services\ProtectionNeeds\ProtectionNeedInitializer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProtectionNeedSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_cross_project_or_mixed_target_raw_rows_are_rejected_by_database(): void
    {
        $first = IsmsProject::factory()->for(Organization::factory()->state(['organization_type' => 'customer', 'entra_tenant_id' => null]))->create();
        $second = IsmsProject::factory()->for(Organization::factory()->state(['organization_type' => 'customer', 'entra_tenant_id' => null]))->create();
        $process = BusinessProcess::factory()->for($first, 'project')->create();
        $asset = Asset::factory()->for($second, 'project')->create();
        app(ProtectionNeedInitializer::class)->ensureTarget(DependencyNode::process($process));
        $this->assertSame(3, DB::table('protection_needs')->where('business_process_id', $process->id)->count());
        $this->assertInvalid(fn () => DB::table('protection_needs')->insert(['id' => (string) str()->uuid(), 'project_id' => $second->id, 'business_process_id' => $process->id, 'objective' => 'availability', 'status' => 'draft', 'lock_version' => 0]));
        $this->assertInvalid(fn () => DB::table('protection_needs')->insert(['id' => (string) str()->uuid(), 'project_id' => $first->id, 'business_process_id' => $process->id, 'asset_id' => $asset->id, 'objective' => 'availability', 'status' => 'draft', 'lock_version' => 0]));
        $this->assertInvalid(fn () => DB::table('protection_needs')->where('business_process_id', $process->id)->where('objective', 'availability')->update(['direct_level' => 'high', 'direct_rationale' => null]));
    }

    public function test_factory_creates_a_project_bound_row(): void
    {
        $need = ProtectionNeed::factory()->create();
        $this->assertSame($need->project_id, BusinessProcess::query()->findOrFail($need->business_process_id)->project_id);
    }

    private function assertInvalid(callable $callback): void
    {
        try {
            DB::transaction($callback);
            $this->fail('Database rejection expected.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }
}
