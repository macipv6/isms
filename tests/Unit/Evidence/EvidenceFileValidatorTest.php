<?php

namespace Tests\Unit\Evidence;

use App\Services\Evidence\EvidenceFileValidator;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EvidenceFileValidatorTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryPaths = [];

    #[DataProvider('approvedFiles')]
    public function test_approved_file_is_normalized(string $name, string $contents, string $kind): void
    {
        $file = $this->upload($name, $contents);

        $validated = app(EvidenceFileValidator::class)->validate($file);

        $this->assertSame($name, $validated->originalName);
        $this->assertSame($file->getMimeType(), $validated->mimeType);
        $this->assertSame($kind, $validated->kind);
        $this->assertSame(strlen($contents), $validated->sizeBytes);
        $this->assertSame($file->getPathname(), $validated->temporaryPath);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function approvedFiles(): array
    {
        return [
            'pdf' => ['evidence.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<<>>\n%%EOF\n", 'pdf'],
            'png' => ['screenshot.png', "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x02\x00\x00\x00\x90wS\xde\x00\x00\x00\x00IEND\xaeB`\x82", 'png'],
            'jpeg' => ['photo.jpeg', "\xff\xd8\xff\xe0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xff\xd9", 'jpeg'],
            'text' => ['policy.txt', "Dokumentierte Maßnahmen\n", 'txt'],
            'csv' => ['register.csv', "Name,Wert\nISMS,aktiv\n", 'csv'],
            'docx package' => ['report.docx', self::zipContents([
                '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
                '_rels/.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>',
                'word/document.xml' => '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>',
            ]), 'docx'],
            'xlsx package' => ['register.xlsx', self::zipContents([
                '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
                '_rels/.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>',
                'xl/workbook.xml' => '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"/>',
            ]), 'xlsx'],
            'zip' => ['supporting-documents.zip', self::zipContents(['readme.txt' => 'Dokumentiert']), 'zip'],
        ];
    }

    #[Test]
    public function file_at_exactly_fifty_mib_is_accepted(): void
    {
        $file = $this->sizedTextUpload(50 * 1024 * 1024);

        $validated = app(EvidenceFileValidator::class)->validate($file);

        $this->assertSame(50 * 1024 * 1024, $validated->sizeBytes);
    }

    #[Test]
    public function file_larger_than_fifty_mib_is_rejected_with_a_stable_file_error(): void
    {
        $this->assertRejected($this->sizedTextUpload((50 * 1024 * 1024) + 1));
    }

    #[Test]
    public function incomplete_uploads_are_rejected_with_a_stable_file_error(): void
    {
        $fixture = $this->upload('evidence.txt', 'Dokumentiert');
        $file = new UploadedFile(
            $fixture->getPathname(),
            'evidence.txt',
            'text/plain',
            UPLOAD_ERR_PARTIAL,
            true,
        );

        $this->assertRejected($file);
    }

    #[DataProvider('invalidFiles')]
    public function test_mismatched_extensions_and_blocked_aliases_are_rejected(string $name, string $contents): void
    {
        $this->assertRejected($this->upload($name, $contents));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidFiles(): array
    {
        return [
            'pdf extension with text content' => ['evidence.pdf', "not a PDF\n"],
            'php script' => ['payload.php', '<?php echo "not evidence";'],
            'windows executable' => ['payload.exe', "MZ\x90\x00"],
            'seven zip alias' => ['archive.7z', "7z\xbc\xaf'\x1c"],
            'tar archive alias' => ['archive.tar.gz', 'compressed'],
            'macro-enabled word document' => ['report.docm', 'not allowed'],
        ];
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryPaths as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function sizedTextUpload(int $size): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'evidence-size-');

        if ($path === false) {
            $this->fail('Could not create a temporary upload fixture.');
        }

        $this->temporaryPaths[] = $path;
        file_put_contents($path, str_repeat("Dokumentierte Maßnahme\n", 1024));
        $handle = fopen($path, 'r+');

        if ($handle === false || ! ftruncate($handle, $size)) {
            $this->fail('Could not size the temporary upload fixture.');
        }

        fclose($handle);

        return new UploadedFile($path, 'evidence.txt', 'text/plain', null, true);
    }

    private function upload(string $name, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'evidence-upload-');

        if ($path === false) {
            $this->fail('Could not create a temporary upload fixture.');
        }

        $this->temporaryPaths[] = $path;
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function assertRejected(UploadedFile $file): void
    {
        try {
            app(EvidenceFileValidator::class)->validate($file);
            $this->fail('An invalid evidence upload was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame([
                'file' => ['Die hochgeladene Datei ist nicht zulässig.'],
            ], $exception->errors());
        }
    }

    /**
     * @param  array<string, string>  $entries
     */
    private static function zipContents(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'evidence-package-');

        if ($path === false) {
            throw new \RuntimeException('Could not create a ZIP fixture.');
        }

        $zip = new \ZipArchive;

        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not open ZIP fixture.');
        }

        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();
        $contents = file_get_contents($path);
        @unlink($path);

        if ($contents === false) {
            throw new \RuntimeException('Could not read ZIP fixture.');
        }

        return $contents;
    }
}
