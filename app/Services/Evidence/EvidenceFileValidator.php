<?php

namespace App\Services\Evidence;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class EvidenceFileValidator
{
    private const int MAX_SIZE_BYTES = 50 * 1024 * 1024;

    /**
     * @var array<string, array{kind: string, mimeTypes: list<string>}>
     */
    private const array ALLOWED_TYPES = [
        'pdf' => [
            'kind' => 'pdf',
            'mimeTypes' => ['application/pdf'],
        ],
        'png' => [
            'kind' => 'png',
            'mimeTypes' => ['image/png'],
        ],
        'jpg' => [
            'kind' => 'jpeg',
            'mimeTypes' => ['image/jpeg'],
        ],
        'jpeg' => [
            'kind' => 'jpeg',
            'mimeTypes' => ['image/jpeg'],
        ],
        'txt' => [
            'kind' => 'txt',
            'mimeTypes' => ['text/plain'],
        ],
        'csv' => [
            'kind' => 'csv',
            'mimeTypes' => ['text/csv', 'text/plain', 'application/csv'],
        ],
        'docx' => [
            'kind' => 'docx',
            'mimeTypes' => [
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/zip',
            ],
        ],
        'xlsx' => [
            'kind' => 'xlsx',
            'mimeTypes' => [
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/zip',
            ],
        ],
        'zip' => [
            'kind' => 'zip',
            'mimeTypes' => ['application/zip', 'application/x-zip-compressed'],
        ],
    ];

    public function __construct(private ZipArchiveInspector $zipArchiveInspector) {}

    public function validate(UploadedFile $file): ValidatedEvidenceFile
    {
        $size = $file->getSize();
        $temporaryPath = $file->getPathname();

        if (! $file->isValid()
            || ! is_int($size)
            || $size < 0
            || $size > self::MAX_SIZE_BYTES
            || ! is_file($temporaryPath)
            || ! is_readable($temporaryPath)
        ) {
            $this->reject();
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $type = self::ALLOWED_TYPES[$extension] ?? null;

        if ($type === null) {
            $this->reject();
        }

        try {
            $mimeType = $file->getMimeType();
        } catch (\Throwable) {
            $this->reject();
        }

        if (! is_string($mimeType) || ! in_array($mimeType, $type['mimeTypes'], true)) {
            $this->reject();
        }

        if ($type['kind'] === 'zip') {
            $this->zipArchiveInspector->assertSafe($temporaryPath);
        }

        return new ValidatedEvidenceFile(
            $file->getClientOriginalName(),
            $mimeType,
            $type['kind'],
            $size,
            $temporaryPath,
        );
    }

    private function reject(): never
    {
        throw ValidationException::withMessages([
            'file' => 'Die hochgeladene Datei ist nicht zulässig.',
        ]);
    }
}
