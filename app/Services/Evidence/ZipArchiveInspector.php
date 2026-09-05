<?php

namespace App\Services\Evidence;

use Illuminate\Validation\ValidationException;
use ZipArchive;

class ZipArchiveInspector
{
    private const int MAX_REGULAR_FILES = 200;

    private const int MAX_UNCOMPRESSED_BYTES = 250 * 1024 * 1024;

    /** @var list<string> */
    private const array BLOCKED_EXTENSIONS = [
        '7z', 'apk', 'app', 'appx', 'arj', 'bash', 'bat', 'bin', 'bz2', 'cab', 'cmd', 'com', 'cpl', 'csh',
        'deb', 'dll', 'dmg', 'docm', 'docx', 'dylib', 'ear', 'exe', 'gz', 'img', 'ipa', 'iso', 'jar', 'js',
        'ksh', 'lua', 'mjs', 'msi', 'msp', 'msix', 'odt', 'ods', 'odp', 'phar', 'php', 'phtml', 'pl', 'pkg',
        'pptm', 'pptx', 'ps1', 'py', 'rar', 'rb', 'rpm', 'scr', 'sh', 'so', 'tar', 'tbz', 'tbz2', 'tgz',
        'vbe', 'vbs', 'vhd', 'vhdx', 'war', 'xlsm', 'xlsx', 'xz', 'zip', 'zsh',
    ];

    public function assertSafe(string $path): void
    {
        if (! is_file($path) || ! is_readable($path)) {
            $this->reject();
        }

        $archive = new ZipArchive;
        $opened = false;

        try {
            if ($archive->open($path, ZipArchive::RDONLY) !== true) {
                $this->reject();
            }

            $opened = true;
            $regularFiles = 0;
            $uncompressedBytes = 0;

            for ($index = 0; $index < $archive->numFiles; $index++) {
                $stat = $archive->statIndex($index, ZipArchive::FL_UNCHANGED);

                if ($stat === false) {
                    $this->reject();
                }

                $normalizedName = $this->normalizedEntryName($stat['name']);
                $this->assertUnencrypted($stat);

                if ($this->isDirectory($archive, $index, $normalizedName)) {
                    continue;
                }

                $this->assertRegularNonExecutableFile($archive, $index);
                $this->assertAllowedExtension($normalizedName);

                if ($stat['size'] < 0) {
                    $this->reject();
                }

                $regularFiles++;
                $uncompressedBytes += $stat['size'];
                $this->assertArchiveLimits($regularFiles, $uncompressedBytes);
            }
        } finally {
            if ($opened) {
                $archive->close();
            }
        }
    }

    public function assertArchiveLimits(int $regularFiles, int $uncompressedBytes): void
    {
        if ($regularFiles < 0
            || $uncompressedBytes < 0
            || $regularFiles > self::MAX_REGULAR_FILES
            || $uncompressedBytes > self::MAX_UNCOMPRESSED_BYTES
        ) {
            $this->reject();
        }
    }

    public function assertEntryNameSafe(string $entryName): void
    {
        $this->normalizedEntryName($entryName);
    }

    /**
     * @param  array{encryption_method: int}  $stat
     */
    private function assertUnencrypted(array $stat): void
    {
        if ($stat['encryption_method'] !== ZipArchive::EM_NONE) {
            $this->reject();
        }
    }

    private function isDirectory(ZipArchive $archive, int $index, string $normalizedName): bool
    {
        $operatingSystem = 0;
        $attributes = 0;
        $hasAttributes = $archive->getExternalAttributesIndex(
            $index,
            $operatingSystem,
            $attributes,
            ZipArchive::FL_UNCHANGED,
        );

        if ($hasAttributes && $operatingSystem === ZipArchive::OPSYS_UNIX) {
            $mode = ($attributes >> 16) & 0177777;
            $fileType = $mode & 0170000;

            if ($fileType === 0120000 || ($fileType !== 0 && $fileType !== 0040000 && $fileType !== 0100000)) {
                $this->reject();
            }

            if ($fileType === 0040000) {
                return true;
            }
        }

        return str_ends_with($normalizedName, '/');
    }

    private function assertRegularNonExecutableFile(ZipArchive $archive, int $index): void
    {
        $operatingSystem = 0;
        $attributes = 0;
        $hasAttributes = $archive->getExternalAttributesIndex(
            $index,
            $operatingSystem,
            $attributes,
            ZipArchive::FL_UNCHANGED,
        );

        if (! $hasAttributes || $operatingSystem !== ZipArchive::OPSYS_UNIX) {
            return;
        }

        $mode = ($attributes >> 16) & 0177777;
        $fileType = $mode & 0170000;

        if ($fileType !== 0 && $fileType !== 0100000) {
            $this->reject();
        }

        if (($mode & 0111) !== 0) {
            $this->reject();
        }
    }

    private function assertAllowedExtension(string $normalizedName): void
    {
        $extension = strtolower(pathinfo(basename($normalizedName), PATHINFO_EXTENSION));

        if (in_array($extension, self::BLOCKED_EXTENSIONS, true)) {
            $this->reject();
        }
    }

    private function normalizedEntryName(string $entryName): string
    {
        $normalizedName = str_replace('\\', '/', $entryName);

        if ($normalizedName === ''
            || str_contains($normalizedName, "\0")
            || str_starts_with($normalizedName, '/')
            || preg_match('/^[A-Za-z]:/', $normalizedName) === 1
        ) {
            $this->reject();
        }

        foreach (explode('/', $normalizedName) as $segment) {
            if ($segment === '..') {
                $this->reject();
            }
        }

        return $normalizedName;
    }

    private function reject(): never
    {
        throw ValidationException::withMessages([
            'file' => 'Die hochgeladene Datei ist nicht zulässig.',
        ]);
    }
}
