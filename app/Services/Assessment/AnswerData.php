<?php

namespace App\Services\Assessment;

use App\Enums\ComplianceStatus;

final readonly class AnswerData
{
    /**
     * @param  list<string>|null  $answerJson
     */
    public function __construct(
        public ?string $answerValue,
        public ?array $answerJson,
        public ComplianceStatus $complianceStatus,
        public ?string $comment,
    ) {}
}
