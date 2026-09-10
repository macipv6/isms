<?php

namespace App\Services\Imports;

use App\Data\Imports\ParsedRegisterCsv;
use App\Data\Imports\RegisterCsvRow;
use App\Enums\RegisterImportKind;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class RegisterCsvReader
{
    private const MAX_BYTES = 5 * 1024 * 1024;

    private const MAX_ROWS = 10000;

    private const MAX_DISPLAY_ERRORS = 200;

    public function __construct(private readonly RegisterRowValidator $rowValidator) {}

    public function read(UploadedFile $file, RegisterImportKind $kind): ParsedRegisterCsv
    {
        if (! $file->isValid() || ($file->getSize() !== null && $file->getSize() > self::MAX_BYTES)) {
            $this->fileRejected();
        }

        $input = @ fopen($file->getPathname(), 'rb');
        $temporary = tmpfile();
        if ($input === false || $temporary === false) {
            $this->fileRejected();
        }

        try {
            $hash = hash_init('sha256');
            $bytes = 0;
            while (! feof($input)) {
                $chunk = fread($input, min(8192, self::MAX_BYTES + 1 - $bytes));
                if ($chunk === false) {
                    $this->fileRejected();
                }

                $bytes += strlen($chunk);
                if ($bytes > self::MAX_BYTES) {
                    $this->fileRejected();
                }

                hash_update($hash, $chunk);
                fwrite($temporary, $chunk);
            }

            if ($bytes === 0 || ! $this->isUtf8($temporary) || ! $this->hasWellFormedQuoting($temporary)) {
                $this->fileRejected();
            }

            $delimiter = $this->detectDelimiter($temporary, $kind);
            rewind($temporary);
            $headers = $this->readRecord($temporary, $delimiter);
            if ($headers === null) {
                $this->fileRejected();
            }

            $headers = $this->normalizeHeaders($headers);
            $rows = [];
            $errors = [];
            $seen = [];
            $line = 2;
            $rowCount = 0;
            while (($record = $this->readRecord($temporary, $delimiter)) !== null) {
                $startLine = $line;
                $line += $this->recordLines($record) + 1;
                if ($this->blankRecord($record)) {
                    continue;
                }

                $rowCount++;
                if ($rowCount > self::MAX_ROWS) {
                    $this->addError($errors, 'file', 'Die hochgeladene Datei ist nicht zulässig.');
                    continue;
                }

                if (count($record) !== count($headers)) {
                    $this->addError($errors, 'rows.'.$startLine.'.row', 'Die CSV-Zeile ist nicht zulässig.');
                    continue;
                }

                try {
                    $row = $this->rowValidator->validate($kind, array_combine($headers, $record) ?: [], $startLine);
                    $identifier = $this->identifier($kind, $row);
                    if (isset($seen[$identifier])) {
                        $this->addError($errors, 'rows.'.$startLine.'.'.$this->duplicateField($kind), 'Die CSV-Zeile ist nicht zulässig.');
                    } else {
                        $seen[$identifier] = true;
                        $rows[] = $row;
                    }
                } catch (ValidationException $exception) {
                    foreach ($exception->errors() as $field => $messages) {
                        $this->addError($errors, $field, $messages[0]);
                    }
                }
            }

            if ($rowCount === 0) {
                $this->fileRejected();
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            return new ParsedRegisterCsv($kind, hash_final($hash), $headers, $rows);
        } finally {
            fclose($input);
            fclose($temporary);
        }
    }

    private function detectDelimiter($stream, RegisterImportKind $kind): string
    {
        $valid = [];
        foreach ([',', ';'] as $delimiter) {
            rewind($stream);
            $record = $this->readRecord($stream, $delimiter);
            if ($record !== null && $this->exactHeaders($this->normalizeHeaders($record), $kind)) {
                $valid[] = $delimiter;
            }
        }

        if (count($valid) !== 1) {
            $this->fileRejected();
        }

        return $valid[0];
    }

    /**
     * @return list<string>|null
     */
    private function readRecord($stream, string $delimiter): ?array
    {
        $record = fgetcsv($stream, separator: $delimiter, enclosure: '"', escape: '');

        return $record === false ? null : array_map(static fn (mixed $field): string => (string) $field, $record);
    }

    /**
     * @param  list<string>  $headers
     * @return list<string>
     */
    private function normalizeHeaders(array $headers): array
    {
        return array_map(static fn (string $header): string => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header)), $headers);
    }

    /**
     * @param  list<string>  $headers
     */
    private function exactHeaders(array $headers, RegisterImportKind $kind): bool
    {
        $expected = $this->rowValidator->headers($kind);

        return count($headers) === count($expected) && count(array_unique($headers)) === count($headers) && count(array_diff($headers, $expected)) === 0;
    }

    private function isUtf8($stream): bool
    {
        rewind($stream);
        $carry = '';
        while (! feof($stream)) {
            $chunk = fread($stream, 8192);
            if ($chunk === false) {
                return false;
            }

            $carry .= $chunk;
            $lastNewline = strrpos($carry, "\n");
            if ($lastNewline === false) {
                continue;
            }

            $complete = substr($carry, 0, $lastNewline + 1);
            $carry = substr($carry, $lastNewline + 1);
            if (preg_match('//u', $complete) !== 1) {
                return false;
            }
        }

        return preg_match('//u', $carry) === 1;
    }

    private function hasWellFormedQuoting($stream): bool
    {
        rewind($stream);
        $state = 'start';
        while (($byte = fgetc($stream)) !== false) {
            if ($byte === "\0") {
                return false;
            }

            if ($state === 'quoted') {
                if ($byte === '"') {
                    $state = 'after_quote';
                }

                continue;
            }

            if ($state === 'after_quote') {
                if ($byte === '"') {
                    $state = 'quoted';

                    continue;
                }

                if ($byte === ',' || $byte === ';') {
                    $state = 'start';

                    continue;
                }

                if ($byte === "\r" || $byte === "\n") {
                    $state = 'start';

                    continue;
                }

                return false;
            }

            if ($state === 'start' && $byte === '"') {
                $state = 'quoted';

                continue;
            }

            if ($byte === '"') {
                return false;
            }

            $state = ($byte === ',' || $byte === ';' || $byte === "\r" || $byte === "\n") ? 'start' : 'plain';
        }

        return $state !== 'quoted';
    }

    /**
     * @param  list<string>  $record
     */
    private function blankRecord(array $record): bool
    {
        return count($record) === 1 && trim($record[0]) === '';
    }

    /**
     * @param  list<string>  $record
     */
    private function recordLines(array $record): int
    {
        return substr_count(implode('', $record), "\n");
    }

    private function identifier(RegisterImportKind $kind, RegisterCsvRow $row): string
    {
        return $kind === RegisterImportKind::Dependencies
            ? implode('|', [(string) $row->values['source_type'], (string) $row->values['source_key'], (string) $row->values['target_type'], (string) $row->values['target_key']])
            : (string) $row->values['key'];
    }

    private function duplicateField(RegisterImportKind $kind): string
    {
        return $kind === RegisterImportKind::Dependencies ? 'target_key' : 'key';
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function addError(array &$errors, string $key, string $message): void
    {
        if (count($errors) < self::MAX_DISPLAY_ERRORS && ! isset($errors[$key])) {
            $errors[$key] = [$message];
        }
    }

    private function fileRejected(): never
    {
        throw ValidationException::withMessages(['file' => ['Die hochgeladene Datei ist nicht zulässig.']]);
    }
}
