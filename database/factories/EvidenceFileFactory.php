<?php

namespace Database\Factories;

use App\Enums\EvidenceReviewStatus;
use App\Models\EvidenceFile;
use App\Models\IsmsProject;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<EvidenceFile> */
class EvidenceFileFactory extends Factory
{
    protected $model = EvidenceFile::class;

    public function definition(): array
    {
        $identifier = (string) Str::uuid();

        return [
            'project_id' => IsmsProject::factory(),
            'storage_path' => 'evidence/'.$identifier.'.pdf',
            'original_name' => 'evidence-'.$identifier.'.pdf',
            'mime_type' => 'application/pdf',
            'file_kind' => 'pdf',
            'size_bytes' => fake()->numberBetween(1, 1048576),
            'sha256' => hash('sha256', $identifier),
            'status' => EvidenceReviewStatus::PendingReview,
            'uploaded_by' => User::factory(),
            'uploaded_at' => now(),
            'reviewed_by' => null,
            'reviewed_at' => null,
            'review_note' => null,
        ];
    }
}
