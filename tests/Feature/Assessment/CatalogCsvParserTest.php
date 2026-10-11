<?php

namespace Tests\Feature\Assessment;

use App\Services\Assessment\CatalogCsvParser;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CatalogCsvParserTest extends TestCase
{
    private function row(array $override = []): array
    {
        return array_replace(array_combine(CatalogCsvParser::HEADER, ['example', 'Eigene Beispiele', '', '10', 'example.first', 'Eigene Frage', "Erste Zeile; Komma,\nzweite Zeile", '', 'boolean', 'medium', '0', '1', '10', '[]', '[]']), $override);
    }

    private function csv(array $rows): string
    {
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, CatalogCsvParser::HEADER, ';', '"', '');
        foreach ($rows as $row) {
            fputcsv($stream, array_values($row), ';', '"', '');
        }
        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        return $contents;
    }

    public function test_valid_options_rules_and_multiline_quoted_text(): void
    {
        $options = '[{"value":"annual","label":"Jährlich","score":1,"sort_order":10}]';
        $rules = '[{"target_question_key":"example.second","operator":"equals","action":"include","expected_value":"annual"}]';
        $rows = app(CatalogCsvParser::class)->parse($this->csv([
            $this->row(['answer_type' => 'single_choice', 'options_json' => $options, 'rules_json' => $rules]),
            $this->row(['question_key' => 'example.second']),
        ]));
        $this->assertCount(2, $rows);
        $this->assertSame("Erste Zeile; Komma,\nzweite Zeile", $rows[0]['question_text']);
        $this->assertSame('annual', $rows[0]['rules_json'][0]['expected_value']);
    }

    public function test_duplicate_keys_categories_options_and_invalid_rule_types_are_rejected(): void
    {
        $option = '{"value":"annual","label":"Jährlich","score":1,"sort_order":10}';
        $invalidSets = [
            [$this->row(), $this->row()],
            [$this->row(), $this->row(['question_key' => 'example.second', 'category_name' => 'Anderer Name'])],
            [$this->row(['answer_type' => 'single_choice', 'options_json' => '['.$option.','.$option.']'])],
            [$this->row(['rules_json' => '[{"target_question_key":"example.second","operator":"contains","action":"include","expected_value":true}]']), $this->row(['question_key' => 'example.second'])],
            [$this->row(['rules_json' => '[{"target_question_key":"example.second","operator":"equals","action":"include","expected_value":"true"}]']), $this->row(['question_key' => 'example.second'])],
            [$this->row(['rules_json' => '[{"target_question_key":"example.first","operator":"equals","action":"include","expected_value":true}]'])],
        ];
        foreach ($invalidSets as $rows) {
            try {
                app(CatalogCsvParser::class)->parse($this->csv($rows));
                $this->fail('Invalid data accepted');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('csv', $exception->errors());
            }
        }
    }

    public function test_malformed_quotes_encoding_size_and_row_limit_are_rejected(): void
    {
        $manyRows = array_map(fn ($index) => $this->row(['question_key' => 'example.'.$index]), range(1, 10001));
        foreach ([implode(';', CatalogCsvParser::HEADER)."\n\"unterminated", "\xFF", str_repeat('x', 5 * 1024 * 1024 + 1), $this->csv($manyRows)] as $contents) {
            try {
                app(CatalogCsvParser::class)->parse($contents);
                $this->fail('Malformed CSV accepted');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('csv', $exception->errors());
            }
        }
    }
}
