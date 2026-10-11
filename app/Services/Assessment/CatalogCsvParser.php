<?php

namespace App\Services\Assessment;

use App\Enums\AnswerType;
use App\Enums\RuleAction;
use App\Enums\RuleOperator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CatalogCsvParser
{
    public const HEADER = ['category_key', 'category_name', 'category_description', 'category_sort_order', 'question_key', 'title', 'question_text', 'help_text', 'answer_type', 'severity', 'evidence_expected', 'is_active', 'sort_order', 'options_json', 'rules_json'];

    public function parse(string $contents): array
    {
        if (strlen($contents) > 5 * 1024 * 1024 || ! mb_check_encoding($contents, 'UTF-8') || str_contains($contents, "\0")) {
            $this->fail('CSV muss gültiges UTF-8 sein und darf maximal 5 MiB enthalten.');
        }
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);
        $firstLine = strtok($contents, "\r\n");
        $delimiter = str_getcsv($firstLine ?: '', ';', '"', '') === self::HEADER ? ';' : ',';
        $this->validateQuoting($contents, $delimiter);
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $contents);
        rewind($stream);
        try {
            if (fgetcsv($stream, 0, $delimiter, '"', '') !== self::HEADER) {
                $this->fail('Die CSV-Kopfzeile muss exakt der Vorlage entsprechen.');
            }
            $rows = [];
            $categories = [];
            while (($values = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
                $line = count($rows) + 2;
                if (count($rows) >= 10000 || count($values) !== count(self::HEADER)) {
                    $this->fail("Datensatz {$line}: falsche Spaltenzahl oder mehr als 10.000 Fragen.");
                }
                $row = array_combine(self::HEADER, $values);
                $validator = Validator::make($row, [
                    'category_key' => ['required', 'regex:/^[A-Za-z0-9_.-]{1,64}$/'],
                    'category_name' => ['required', 'string', 'max:255'],
                    'category_description' => ['nullable', 'string', 'max:10000'],
                    'category_sort_order' => ['required', 'integer', 'min:0', 'max:2147483647'],
                    'question_key' => ['required', 'regex:/^[A-Za-z0-9_.-]{1,128}$/'],
                    'title' => ['required', 'string', 'max:255'],
                    'question_text' => ['required', 'string', 'max:10000'],
                    'help_text' => ['nullable', 'string', 'max:10000'],
                    'answer_type' => ['required', Rule::enum(AnswerType::class)],
                    'severity' => ['required', Rule::in(['low', 'medium', 'high', 'critical'])],
                    'evidence_expected' => ['required', Rule::in(['0', '1'])],
                    'is_active' => ['required', Rule::in(['0', '1'])],
                    'sort_order' => ['required', 'integer', 'min:0', 'max:2147483647'],
                ]);
                if ($validator->fails()) {
                    $this->fail("Datensatz {$line}: ungültige Pflichtfelder, Feldlängen oder Werte (".implode(', ', array_keys($validator->errors()->toArray())).').');
                }
                foreach (['category_name', 'category_description', 'title', 'question_text', 'help_text'] as $field) {
                    $this->safeText($row[$field], $line);
                }
                $category = array_intersect_key($row, array_flip(array_slice(self::HEADER, 0, 4)));
                $categoryIdentity = 'category:'.$row['category_key'];
                if (isset($categories[$categoryIdentity]) && $categories[$categoryIdentity] !== $category) {
                    $this->fail("Datensatz {$line}: Kategorieangaben widersprechen sich.");
                }
                $categories[$categoryIdentity] = $category;
                $questionIdentity = 'question:'.$row['question_key'];
                if (isset($rows[$questionIdentity])) {
                    $this->fail("Datensatz {$line}: Frageschlüssel doppelt vorhanden.");
                }
                foreach (['options_json', 'rules_json'] as $field) {
                    try {
                        $row[$field] = json_decode($row[$field], true, 16, JSON_THROW_ON_ERROR);
                    } catch (\JsonException) {
                        $this->fail("Datensatz {$line}: {$field} ist kein gültiges JSON.");
                    }
                    if (! is_array($row[$field]) || ! array_is_list($row[$field]) || count($row[$field]) > 100) {
                        $this->fail("Datensatz {$line}: {$field} muss eine Liste mit maximal 100 Elementen sein.");
                    }
                }
                $choice = in_array($row['answer_type'], ['single_choice', 'multiple_choice'], true);
                if ($choice !== (count($row['options_json']) > 0)) {
                    $this->fail("Datensatz {$line}: Auswahlfragen benötigen Optionen; andere Antworttypen erlauben keine Optionen.");
                }
                $seen = [];
                foreach ($row['options_json'] as $option) {
                    if (! is_array($option) || Validator::make($option, [
                        'value' => ['required', 'string', 'max:64'], 'label' => ['required', 'string', 'max:255'],
                        'score' => ['present', 'nullable', 'integer', 'min:-2147483648', 'max:2147483647'],
                        'sort_order' => ['required', 'integer', 'min:0', 'max:2147483647'],
                    ])->fails() || array_diff(array_keys($option), ['value', 'label', 'score', 'sort_order']) || isset($seen['option:'.$option['value']])) {
                        $this->fail("Datensatz {$line}: ungültige oder doppelte Option.");
                    }
                    $this->safeText($option['value'], $line);
                    $this->safeText($option['label'], $line);
                    $seen['option:'.$option['value']] = true;
                }
                $rows[$questionIdentity] = $row;
            }
            if ($rows === []) {
                $this->fail('CSV enthält keine Fragen.');
            }
            $seenRules = [];
            foreach ($rows as $row) {
                // CSV keys remain strings even when PHP would cast a numeric array key.
                $key = $row['question_key'];
                foreach ($row['rules_json'] as $rule) {
                    if (! is_array($rule) || Validator::make($rule, [
                        'target_question_key' => ['required', 'string'], 'operator' => ['required', Rule::enum(RuleOperator::class)],
                        'action' => ['required', Rule::enum(RuleAction::class)], 'expected_value' => ['present'],
                    ])->fails() || array_diff(array_keys($rule), ['target_question_key', 'operator', 'action', 'expected_value'])) {
                        $this->fail('Regel enthält ungültige Felder, Operatoren oder Aktionen.');
                    }
                    $target = $rule['target_question_key'];
                    $identity = json_encode([$key, $target, $rule['operator'], $rule['action']]);
                    if (! isset($rows['question:'.$target]) || $target === $key || isset($seenRules[$identity])) {
                        $this->fail('Regel enthält unbekanntes Ziel, Selbstbezug oder doppelte Definition.');
                    }
                    $expected = $rule['expected_value'];
                    $valid = match ($row['answer_type']) {
                        'boolean' => is_bool($expected),
                        'number' => is_int($expected) || is_float($expected),
                        'text' => is_string($expected) && mb_strlen($expected) <= 10000,
                        'single_choice' => is_string($expected) && in_array($expected, array_column($row['options_json'], 'value'), true),
                        'multiple_choice' => $rule['operator'] === 'contains'
                            ? is_string($expected) && in_array($expected, array_column($row['options_json'], 'value'), true)
                            : is_array($expected) && array_is_list($expected) && count($expected) <= 100
                                && collect($expected)->every(fn ($value) => is_string($value) && in_array($value, array_column($row['options_json'], 'value'), true))
                                && count(array_unique($expected)) === count($expected),
                    };
                    if (! $valid || ($rule['operator'] === 'contains' && $row['answer_type'] !== 'multiple_choice')) {
                        $this->fail('Erwartungswert oder Operator passt nicht zum Antworttyp der auslösenden Frage.');
                    }
                    $seenRules[$identity] = true;
                }
            }

            return array_values($rows);
        } finally {
            fclose($stream);
        }
    }

    private function safeText(string $text, int $line): void
    {
        if (preg_match('/^[\s]*[=+@-]/u', $text)) {
            $this->fail("Datensatz {$line}: Text darf nicht mit einem Tabellenformel-Präfix beginnen.");
        }
    }

    private function validateQuoting(string $contents, string $delimiter): void
    {
        $state = 'start';
        for ($i = 0, $length = strlen($contents); $i < $length; $i++) {
            $char = $contents[$i];
            if ($state === 'quoted') {
                if ($char === '"') {
                    if (($contents[$i + 1] ?? '') === '"') {
                        $i++;
                    } else {
                        $state = 'closed';
                    }
                }
                continue;
            }
            if ($char === $delimiter || $char === "\r" || $char === "\n") {
                $state = 'start';
            } elseif ($state === 'closed' || ($char === '"' && $state !== 'start')) {
                $this->fail('Ungültige CSV-Anführungszeichen. Bitte Standard-CSV-Quoting verwenden.');
            } elseif ($char === '"') {
                $state = 'quoted';
            } else {
                $state = 'plain';
            }
        }
        if ($state === 'quoted') {
            $this->fail('Nicht geschlossenes CSV-Textfeld.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['csv' => $message]);
    }
}
