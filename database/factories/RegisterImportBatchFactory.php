<?php

namespace Database\Factories;

use App\Enums\RegisterImportKind;
use App\Enums\RegisterImportStatus;
use App\Models\IsmsProject;
use App\Models\RegisterImportBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RegisterImportBatch> */
class RegisterImportBatchFactory extends Factory
{
    protected $model = RegisterImportBatch::class;

    public function definition(): array
    {
        return [
            'project_id' => IsmsProject::factory(),
            'kind' => RegisterImportKind::Processes,
            'created_by' => User::factory(),
            'sha256' => hash('sha256', fake()->uuid()),
            'payload' => [],
            'summary' => [],
            'status' => RegisterImportStatus::Pending,
            'expires_at' => now()->addMinutes(30),
            'applied_at' => null,
        ];
    }
}
