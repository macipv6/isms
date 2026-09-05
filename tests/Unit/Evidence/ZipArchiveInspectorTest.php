<?php

namespace Tests\Unit\Evidence;

use App\Services\Evidence\ZipArchiveInspector;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ZipArchiveInspectorTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryPaths = [];

    #[Test]
    public function ordinary_directories_and_documents_are_accepted_without_extraction(): void
    {
        $path = $this->zip([
            'documents/' => null,
            'documents/policy.txt' => 'Dokumentiert',
            'documents/evidence.pdf' => '%PDF-1.4',
        ]);

        app(ZipArchiveInspector::class)->assertSafe($path);

        $this->assertDirectoryDoesNotExist($path.'.extracted');
    }

    #[Test]
    public function exactly_two_hundred_regular_files_and_two_hundred_fifty_mib_are_accepted(): void
    {
        app(ZipArchiveInspector::class)->assertArchiveLimits(200, 250 * 1024 * 1024);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function more_than_two_hundred_regular_files_is_rejected(): void
    {
        $entries = [];

        for ($index = 0; $index < 201; $index++) {
            $entries[sprintf('documents/%03d.txt', $index)] = 'x';
        }

        $this->assertRejected(fn (): mixed => app(ZipArchiveInspector::class)->assertSafe($this->zip($entries)));
    }

    #[Test]
    public function one_byte_more_than_the_uncompressed_limit_is_rejected(): void
    {
        $this->assertRejected(
            fn (): mixed => app(ZipArchiveInspector::class)->assertArchiveLimits(1, (250 * 1024 * 1024) + 1),
        );
    }

    #[Test]
    public function encrypted_entries_are_rejected(): void
    {
        $path = $this->zip(['protected.txt' => 'vertraulich']);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $this->assertTrue($zip->setEncryptionName('protected.txt', \ZipArchive::EM_AES_256, 'secret'));
        $zip->close();

        $this->assertRejected(fn (): mixed => app(ZipArchiveInspector::class)->assertSafe($path));
    }

    #[DataProvider('unsafeEntryNames')]
    public function test_traversal_and_absolute_entry_names_are_rejected(string $entryName): void
    {
        $this->assertRejected(
            fn (): mixed => app(ZipArchiveInspector::class)->assertSafe($this->zip([$entryName => 'blocked'])),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeEntryNames(): array
    {
        return [
            'parent traversal' => ['../escape.txt'],
            'nested traversal' => ['documents/../escape.txt'],
            'unix absolute path' => ['/etc/passwd'],
            'windows absolute path' => ['C:\\temp\\escape.txt'],
        ];
    }

    #[Test]
    public function nul_bytes_in_entry_names_are_rejected_by_the_entry_name_guard(): void
    {
        $this->assertRejected(
            fn (): mixed => app(ZipArchiveInspector::class)->assertEntryNameSafe("documents/unsafe\0name.txt"),
        );
    }

    #[Test]
    public function unix_symbolic_links_are_rejected(): void
    {
        $path = $this->zip(['shortcut' => 'documents/policy.txt']);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $this->assertTrue(
            $zip->setExternalAttributesName('shortcut', \ZipArchive::OPSYS_UNIX, 0120777 << 16),
        );
        $zip->close();

        $this->assertRejected(fn (): mixed => app(ZipArchiveInspector::class)->assertSafe($path));
    }

    #[DataProvider('blockedEntryExtensions')]
    public function test_nested_archives_scripts_executables_macros_installers_and_disk_images_are_rejected(
        string $entryName,
    ): void {
        $this->assertRejected(
            fn (): mixed => app(ZipArchiveInspector::class)->assertSafe($this->zip([$entryName => 'blocked'])),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function blockedEntryExtensions(): array
    {
        return [
            'nested zip' => ['nested.zip'],
            'php script' => ['payload.php'],
            'shell script' => ['payload.sh'],
            'windows executable' => ['payload.exe'],
            'windows installer' => ['installer.msi'],
            'disk image' => ['disk.iso'],
            'macro word document' => ['report.docm'],
            'macro spreadsheet' => ['register.xlsm'],
        ];
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryPaths as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    /**
     * @param  array<string, string|null>  $entries
     */
    private function zip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'evidence-zip-');

        if ($path === false) {
            $this->fail('Could not create a temporary ZIP fixture.');
        }

        $this->temporaryPaths[] = $path;
        $zip = new \ZipArchive;
        $this->assertSame(true, $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));

        foreach ($entries as $name => $contents) {
            if ($contents === null) {
                $this->assertTrue($zip->addEmptyDir(rtrim($name, '/')));
            } else {
                $this->assertTrue($zip->addFromString($name, $contents));
            }
        }

        $this->assertTrue($zip->close());

        return $path;
    }

    /**
     * @param  callable(): mixed  $callback
     */
    private function assertRejected(callable $callback): void
    {
        try {
            $callback();
            $this->fail('An unsafe ZIP file was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame([
                'file' => ['Die hochgeladene Datei ist nicht zulässig.'],
            ], $exception->errors());
        }
    }
}
