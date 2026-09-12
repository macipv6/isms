<?php

namespace Tests\Unit\Imports;

use App\Enums\RegisterImportKind;
use App\Services\Imports\RegisterCsvReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RegisterCsvReaderTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryPaths = [];

    #[Test]
    public function it_parses_bom_utf8_semicolon_assets_in_any_header_order_with_quoted_delimiters_and_newlines(): void
    {
        $parsed = app(RegisterCsvReader::class)->read($this->upload('assets.csv', "\xEF\xBB\xBFactive;owner_email;name;key;type;description;owner_name\r\nTRUE;;\"ERP; Plattform\"; app-1 ;application;\"erste\r\nzweite\";\r\n\r\n\r\n"), RegisterImportKind::Assets);

        $this->assertSame(RegisterImportKind::Assets, $parsed->kind);
        $this->assertSame(['active', 'owner_email', 'name', 'key', 'type', 'description', 'owner_name'], $parsed->headers);
        $this->assertCount(1, $parsed->rows);
        $this->assertSame(2, $parsed->rows[0]->line);
        $this->assertSame('APP-1', $parsed->rows[0]->values['key']);
        $this->assertSame("erste\r\nzweite", $parsed->rows[0]->values['description']);
    }

    #[DataProvider('invalidFileContents')]
    public function test_it_rejects_file_level_csv_boundaries(string $contents): void
    {
        $this->assertFileRejected($this->upload('assets.csv', $contents));
    }

    /** @return array<string, array{string}> */
    public static function invalidFileContents(): array
    {
        return [
            'unknown header' => ["key,name,type,description,owner_name,owner_email,active,extra\nAPP-1,ERP,application,,,,true\n"],
            'duplicate header' => ["key,name,type,description,owner_name,owner_email,active,key\nAPP-1,ERP,application,,,,true,APP-1\n"],
            'missing header' => ["key,name,type,description,owner_name,active\nAPP-1,ERP,application,,,true\n"],
            'mixed delimiters' => ["key,name,type,description,owner_name,owner_email,active;key,name,type,description,owner_name,owner_email,active\nAPP-1,ERP,application,,,,true\n"],
            'malformed quote' => ["key,name,type,description,owner_name,owner_email,active\nAPP-1,\"ERP,application,,,,true\n"],
            'invalid utf8' => ["key,name,type,description,owner_name,owner_email,active\nAPP-1,\xFF,application,,,,true\n"],
            'nul byte' => ["key,name,type,description,owner_name,owner_email,active\nAPP-1,ERP\0,application,,,,true\n"],
            'blank body' => ["key,name,type,description,owner_name,owner_email,active\n\r\n \r\n"],
            'leading header whitespace' => [" key,name,type,description,owner_name,owner_email,active\nAPP-1,ERP,application,,,,true\n"],
            'trailing header whitespace' => ["key ,name,type,description,owner_name,owner_email,active\nAPP-1,ERP,application,,,,true\n"],
            'bom outside file start' => ["key,\xEF\xBB\xBFname,type,description,owner_name,owner_email,active\nAPP-1,ERP,application,,,,true\n"],
        ];
    }

    #[DataProvider('invalidPostQuoteContents')]
    public function test_it_rejects_characters_after_a_closing_quote_unless_they_are_the_selected_delimiter(string $contents): void
    {
        $this->assertFileRejected($this->upload('assets.csv', $contents));
    }

    /** @return array<string, array{string}> */
    public static function invalidPostQuoteContents(): array
    {
        return [
            'semicolon after quoted comma field' => ["key,name,type,description,owner_name,owner_email,active\nAPP-1,\"ERP\";suffix,application,,,,true\n"],
            'comma after quoted semicolon field' => ["key;name;type;description;owner_name;owner_email;active\nAPP-1;\"ERP\",suffix;application;;;;true\n"],
        ];
    }

    #[Test]
    public function it_rejects_exactly_one_byte_over_five_mib_using_the_real_upload_stream(): void
    {
        $header = "key,name,type,description,owner_name,owner_email,active\n";
        $this->assertFileRejected($this->upload('assets.csv', $header.str_repeat('a', (5 * 1024 * 1024) + 1 - strlen($header))));
    }

    #[Test]
    public function it_accepts_exactly_five_mib_before_csv_validation(): void
    {
        $header = "key,name,type,description,owner_name,owner_email,active\n";
        $body = 'APP-1,ERP,application,,,,true';
        $padding = str_repeat(' ', (5 * 1024 * 1024) - strlen($header) - strlen($body) - 1);
        $parsed = app(RegisterCsvReader::class)->read($this->upload('assets.csv', $header.$body.$padding."\n"), RegisterImportKind::Assets);

        $this->assertCount(1, $parsed->rows);
    }

    #[Test]
    public function it_enforces_ten_thousand_data_rows_but_scans_the_next_row(): void
    {
        $header = "key,name,description,owner_name,owner_email,active\n";
        $rows = implode('', array_map(static fn (int $number): string => sprintf("PR-%05d,Process,,,,true\n", $number), range(1, 10001)));
        $accepted = app(RegisterCsvReader::class)->read($this->upload('processes.csv', $header.substr($rows, 0, strlen($rows) - strlen(sprintf("PR-%05d,Process,,,,true\n", 10001)))), RegisterImportKind::Processes);
        $this->assertCount(10000, $accepted->rows);

        $this->assertFileRejected($this->upload('processes.csv', $header.$rows));
    }

    #[Test]
    public function it_continues_validation_and_duplicate_detection_after_the_row_limit(): void
    {
        $header = "key,name,description,owner_name,owner_email,active\n";
        $rows = implode('', array_map(static fn (int $number): string => sprintf("PR-%05d,Process,,,,true\n", $number), range(1, 10000)));
        $rows .= "PR-00001,Duplicate,,,,true\n";
        $rows .= "PR-10002,=Formula,,,,true\n";

        try {
            app(RegisterCsvReader::class)->read($this->upload('processes.csv', $header.$rows), RegisterImportKind::Processes);
            $this->fail('The oversized CSV file was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame(['Die hochgeladene Datei ist nicht zulässig.'], $exception->errors()['file']);
            $this->assertSame(['Die CSV-Zeile ist nicht zulässig.'], $exception->errors()['rows.10002.key']);
            $this->assertSame(['Die CSV-Zeile ist nicht zulässig.'], $exception->errors()['rows.10003.name']);
            $this->assertLessThanOrEqual(200, count($exception->errors()));
        }
    }

    #[Test]
    public function it_caps_displayed_errors_at_two_hundred(): void
    {
        $header = "key,name,description,owner_name,owner_email,active\n";
        $rows = implode('', array_map(static fn (int $number): string => sprintf("PR-%05d,=Formula,,,,true\n", $number), range(1, 201)));

        try {
            app(RegisterCsvReader::class)->read($this->upload('processes.csv', $header.$rows), RegisterImportKind::Processes);
            $this->fail('The unsafe CSV rows were accepted.');
        } catch (ValidationException $exception) {
            $this->assertCount(200, $exception->errors());
            $this->assertArrayHasKey('rows.201.name', $exception->errors());
            $this->assertArrayNotHasKey('rows.202.name', $exception->errors());
        }
    }

    #[DataProvider('formulaCells')]
    public function test_it_rejects_formula_prefixes_in_every_populated_cell(string $field, string $value): void
    {
        $headers = ['key', 'name', 'type', 'description', 'owner_name', 'owner_email', 'active'];
        $row = ['APP-1', 'ERP', 'application', '', '', '', 'true'];
        $row[array_search($field, $headers, true)] = ' '.$value;
        $this->assertRowRejected($this->upload('assets.csv', implode(',', $headers)."\n".implode(',', $row)."\n"), $field);
    }

    /** @return array<string, array{string, string}> */
    public static function formulaCells(): array
    {
        $cases = [];
        foreach (['key', 'name', 'type', 'description', 'owner_name', 'owner_email', 'active'] as $field) {
            foreach (['=', '+', '-', '@'] as $prefix) {
                $cases[$field.' '.$prefix] = [$field, $prefix.'formula'];
            }
        }

        return $cases;
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryPaths as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function upload(string $name, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'register-csv-');
        $this->assertNotFalse($path);
        $this->temporaryPaths[] = $path;
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }

    private function assertFileRejected(UploadedFile $file): void
    {
        try {
            app(RegisterCsvReader::class)->read($file, RegisterImportKind::Assets);
            $this->fail('The invalid CSV file was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file', $exception->errors());
        }
    }

    private function assertRowRejected(UploadedFile $file, string $field): void
    {
        try {
            app(RegisterCsvReader::class)->read($file, RegisterImportKind::Assets);
            $this->fail('The unsafe CSV row was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('rows.2.'.$field, $exception->errors());
        }
    }
}
