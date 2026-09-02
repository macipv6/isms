<?php

namespace Tests\Unit\Assessment;

use App\Enums\AnswerType;
use App\Enums\ComplianceStatus;
use App\Models\AssessmentQuestion;
use App\Services\Assessment\AnswerValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AnswerValidatorTest extends TestCase
{
    public function test_boolean_answer_is_normalized_but_string_boolean_is_rejected(): void
    {
        $validator = app(AnswerValidator::class);
        $question = $this->question(AnswerType::Boolean);

        $data = $validator->validate($question, [
            'answer' => true,
            'compliance_status' => 'fulfilled',
            'comment' => 'Geprüft',
        ]);

        $this->assertSame('true', $data->answerValue);
        $this->assertNull($data->answerJson);
        $this->assertSame(ComplianceStatus::Fulfilled, $data->complianceStatus);

        $this->expectException(ValidationException::class);
        $validator->validate($question, [
            'answer' => 'true',
            'compliance_status' => 'fulfilled',
        ]);
    }

    public function test_choice_answers_must_use_frozen_options_and_multiple_values_are_unique(): void
    {
        $validator = app(AnswerValidator::class);
        $options = [
            ['value' => 'daily', 'label' => 'Täglich', 'score' => 2, 'sort_order' => 1],
            ['value' => 'weekly', 'label' => 'Wöchentlich', 'score' => 1, 'sort_order' => 2],
        ];

        $single = $validator->validate($this->question(AnswerType::SingleChoice, $options), [
            'answer' => 'daily',
            'compliance_status' => 'partial',
        ]);
        $multiple = $validator->validate($this->question(AnswerType::MultipleChoice, $options), [
            'answer' => ['weekly', 'daily'],
            'compliance_status' => 'fulfilled',
        ]);

        $this->assertSame('daily', $single->answerValue);
        $this->assertSame(['weekly', 'daily'], $multiple->answerJson);

        foreach (['unknown', ['daily', 'daily'], ['daily', 'unknown']] as $invalid) {
            try {
                $validator->validate(
                    $this->question(is_array($invalid) ? AnswerType::MultipleChoice : AnswerType::SingleChoice, $options),
                    ['answer' => $invalid, 'compliance_status' => 'fulfilled'],
                );
                $this->fail('Invalid frozen option was accepted.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_text_number_status_and_not_applicable_contracts_are_enforced(): void
    {
        $validator = app(AnswerValidator::class);

        $text = $validator->validate($this->question(AnswerType::Text), [
            'answer' => '  Dokumentierter Ablauf  ',
            'compliance_status' => 'not_fulfilled',
        ]);
        $number = $validator->validate($this->question(AnswerType::Number), [
            'answer' => 12,
            'compliance_status' => 'partial',
        ]);
        $notApplicable = $validator->validate($this->question(AnswerType::Text), [
            'answer' => null,
            'compliance_status' => 'not_applicable',
        ]);

        $this->assertSame('Dokumentierter Ablauf', $text->answerValue);
        $this->assertSame('12', $number->answerValue);
        $this->assertNull($notApplicable->answerValue);

        foreach ([
            ['answer' => '   ', 'compliance_status' => 'fulfilled'],
            ['answer' => INF, 'compliance_status' => 'fulfilled'],
            ['answer' => 1, 'compliance_status' => 'unknown'],
        ] as $invalid) {
            try {
                $type = is_float($invalid['answer']) ? AnswerType::Number : AnswerType::Text;
                $validator->validate($this->question($type), $invalid);
                $this->fail('Invalid typed answer was accepted.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * @param  list<array{value: string, label: string, score: int|null, sort_order: int}>  $options
     */
    private function question(AnswerType $type, array $options = []): AssessmentQuestion
    {
        return new AssessmentQuestion([
            'question_key' => 'test.question',
            'answer_type' => $type,
            'options' => $options,
            'rules' => [],
        ]);
    }
}
