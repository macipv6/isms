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
        $this->assertSame('app-1', $parsed->rows[0]->values['key']);
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
            'ambiguous delimiter' => ["key,name,type,description,owner_name,owner_email,active;key,name,type,description,owner_name,owner_email,active\nAPP-1,ERP,application,,,,true\n"],
            'malformed quote' => ["key,name,type,description,owner_name,owner_email,active\nAPP-1,\"ERP,application,,,,true\n"],
            'invalid utf8' => ["key,name,type,description,owner_name,owner_email,active\nAPP-1,\xFF,application,,,,true\n"],
            'nul byte' => ["key,name,type,description,owner_name,owner_email,active\nAPP-1,ERP\0,application,,,,true\n"],
            'blank body' => ["key,name,type,description,owner_name,owner_email,active\n\r\n \r\n"],
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
        $row = "PR-1,Process,,,,true\n";
        $accepted = app(RegisterCsvReader::class)->read($this->upload('processes.csv', $header.str_repeat($row, 10000)), RegisterImportKind::Processes);
        $this->assertCount(10000, $accepted->rows);

        $this->assertFileRejected($this->upload('processes.csv', $header.str_repeat($row, 10001)));
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
