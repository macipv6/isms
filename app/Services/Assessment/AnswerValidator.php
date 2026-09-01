<?php

namespace App\Services\Assessment;

use App\Enums\AnswerType;
use App\Enums\ComplianceStatus;
use App\Models\AssessmentQuestion;
use Illuminate\Validation\ValidationException;

class AnswerValidator
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function validate(AssessmentQuestion $question, array $input): AnswerData
    {
        $statusValue = $input['compliance_status'] ?? null;
        $status = is_string($statusValue)
            ? ComplianceStatus::tryFrom($statusValue)
            : null;

        if (! $status instanceof ComplianceStatus) {
            throw ValidationException::withMessages([
                'compliance_status' => 'Bitte einen gültigen Erfüllungsstatus auswählen.',
            ]);
        }

        $comment = $input['comment'] ?? null;

        if ($comment !== null && (! is_string($comment) || mb_strlen($comment) > 10000)) {
            throw ValidationException::withMessages([
                'comment' => 'Der Kommentar darf höchstens 10.000 Zeichen enthalten.',
            ]);
        }

        $answer = $input['answer'] ?? null;

        if ($answer === null && $status === ComplianceStatus::NotApplicable) {
            return new AnswerData(null, null, $status, $this->comment($comment));
        }

        return match ($question->answer_type) {
            AnswerType::Boolean => $this->boolean($answer, $status, $comment),
            AnswerType::SingleChoice => $this->singleChoice($question, $answer, $status, $comment),
            AnswerType::MultipleChoice => $this->multipleChoice($question, $answer, $status, $comment),
            AnswerType::Text => $this->text($answer, $status, $comment),
            AnswerType::Number => $this->number($answer, $status, $comment),
        };
    }

    private function boolean(mixed $answer, ComplianceStatus $status, ?string $comment): AnswerData
    {
        if (! is_bool($answer)) {
            $this->invalidAnswer();
        }

        return new AnswerData($answer ? 'true' : 'false', null, $status, $this->comment($comment));
    }

    private function singleChoice(
        AssessmentQuestion $question,
        mixed $answer,
        ComplianceStatus $status,
        ?string $comment,
    ): AnswerData {
        if (! is_string($answer) || ! in_array($answer, $this->optionValues($question), true)) {
            $this->invalidAnswer();
        }

        return new AnswerData($answer, null, $status, $this->comment($comment));
    }

    private function multipleChoice(
        AssessmentQuestion $question,
        mixed $answer,
        ComplianceStatus $status,
        ?string $comment,
    ): AnswerData {
        $allowed = $this->optionValues($question);

        if (! is_array($answer)
            || $answer === []
            || array_values($answer) !== $answer
            || count(array_unique($answer, SORT_REGULAR)) !== count($answer)
        ) {
            $this->invalidAnswer();
        }

        foreach ($answer as $value) {
            if (! is_string($value) || ! in_array($value, $allowed, true)) {
                $this->invalidAnswer();
            }
        }

        /** @var list<string> $answer */
        return new AnswerData(null, $answer, $status, $this->comment($comment));
    }

    private function text(mixed $answer, ComplianceStatus $status, ?string $comment): AnswerData
    {
        if (! is_string($answer) || trim($answer) === '' || mb_strlen($answer) > 10000) {
            $this->invalidAnswer();
        }

        return new AnswerData(trim($answer), null, $status, $this->comment($comment));
    }

    private function number(mixed $answer, ComplianceStatus $status, ?string $comment): AnswerData
    {
        if ((! is_int($answer) && ! is_float($answer)) || ! is_finite((float) $answer)) {
            $this->invalidAnswer();
        }

        return new AnswerData((string) $answer, null, $status, $this->comment($comment));
    }

    /**
     * @return list<string>
     */
    private function optionValues(AssessmentQuestion $question): array
    {
        return array_map(
            fn (array $option): string => $option['value'],
            $question->options,
        );
    }

    private function comment(?string $comment): ?string
    {
        if ($comment === null || trim($comment) === '') {
            return null;
        }

        return trim($comment);
    }

    private function invalidAnswer(): never
    {
        throw ValidationException::withMessages([
            'answer' => 'Die Antwort passt nicht zum erwarteten Fragetyp.',
        ]);
    }
}
