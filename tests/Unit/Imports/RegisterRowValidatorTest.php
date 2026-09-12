<?php

namespace Tests\Unit\Imports;

use App\Enums\RegisterImportKind;
use App\Services\Imports\RegisterRowValidator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegisterRowValidatorTest extends TestCase
{
    public function test_it_canonicalizes_process_and_asset_rows(): void
    {
        $process = app(RegisterRowValidator::class)->validate(RegisterImportKind::Processes, ['key' => ' pr_1 ', 'name' => ' Payroll ', 'description' => ' ', 'owner_name' => ' Ann ', 'owner_email' => ' ann@example.test ', 'active' => 'FALSE'], 7);
        $asset = app(RegisterRowValidator::class)->validate(RegisterImportKind::Assets, ['key' => ' app-1 ', 'name' => ' ERP ', 'type' => 'application', 'description' => '', 'owner_name' => '', 'owner_email' => '', 'active' => 'true'], 8);

        $this->assertSame(['key' => 'PR_1', 'name' => 'Payroll', 'description' => null, 'owner_name' => 'Ann', 'owner_email' => 'ann@example.test', 'active' => false], $process->values);
        $this->assertSame(['key' => 'APP-1', 'name' => 'ERP', 'type' => 'application', 'description' => null, 'owner_name' => null, 'owner_email' => null, 'active' => true], $asset->values);
    }

    public function test_it_canonicalizes_valid_dependency_nodes(): void
    {
        $row = app(RegisterRowValidator::class)->validate(RegisterImportKind::Dependencies, ['source_type' => 'process', 'source_key' => ' pr-1 ', 'target_type' => 'asset', 'target_key' => ' app-1 ', 'importance' => 'critical', 'reason' => ' hosted ', 'active' => 'true'], 3);

        $this->assertSame(['source_type' => 'process', 'source_key' => 'PR-1', 'target_type' => 'asset', 'target_key' => 'APP-1', 'importance' => 'critical', 'reason' => 'hosted', 'active' => true], $row->values);
    }

    #[DataProvider('invalidDependencyKeys')]
    public function test_it_reports_invalid_dependency_keys_at_their_exact_csv_fields(string $field): void
    {
        $row = ['source_type' => 'process', 'source_key' => 'PR-1', 'target_type' => 'asset', 'target_key' => 'APP-1', 'importance' => 'supporting', 'reason' => '', 'active' => 'true'];
        $row[$field] = 'x';

        try {
            app(RegisterRowValidator::class)->validate(RegisterImportKind::Dependencies, $row, 9);
            $this->fail('The invalid dependency key was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame(['Die CSV-Zeile ist nicht zulässig.'], $exception->errors()['rows.9.'.$field]);
        }
    }

    /** @return array<string, array{string}> */
    public static function invalidDependencyKeys(): array
    {
        return [
            'source key' => ['source_key'],
            'target key' => ['target_key'],
        ];
    }

    #[DataProvider('nonCanonicalRows')]
    public function test_it_rejects_rows_that_do_not_have_the_exact_columns(RegisterImportKind $kind, array $row, string $field): void
    {
        try {
            app(RegisterRowValidator::class)->validate($kind, $row, 14);
            $this->fail('The non-canonical register row was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame(['Die CSV-Zeile ist nicht zulässig.'], $exception->errors()['rows.14.'.$field]);
        }
    }

    /** @return array<string, array{RegisterImportKind, array<string, string>, string}> */
    public static function nonCanonicalRows(): array
    {
        $process = ['key' => 'PR-1', 'name' => 'Process', 'description' => '', 'owner_name' => '', 'owner_email' => '', 'active' => 'true'];
        $asset = ['key' => 'APP-1', 'name' => 'Asset', 'type' => 'application', 'description' => '', 'owner_name' => '', 'owner_email' => '', 'active' => 'true'];
        $dependency = ['source_type' => 'process', 'source_key' => 'PR-1', 'target_type' => 'asset', 'target_key' => 'APP-1', 'importance' => 'supporting', 'reason' => '', 'active' => 'true'];

        $processWithoutKey = $process;
        unset($processWithoutKey['key']);
        $assetWithoutActive = $asset;
        unset($assetWithoutActive['active']);
        $dependencyWithExtra = [...$dependency, 'name' => 'unexpected'];

        return [
            'missing required process key' => [RegisterImportKind::Processes, $processWithoutKey, 'key'],
            'missing required asset active flag' => [RegisterImportKind::Assets, $assetWithoutActive, 'active'],
            'unexpected dependency column' => [RegisterImportKind::Dependencies, $dependencyWithExtra, 'name'],
        ];
    }

    #[DataProvider('invalidRows')]
    public function test_it_rejects_invalid_domain_values_with_stable_row_coordinates(RegisterImportKind $kind, array $row, string $field): void
    {
        try {
            app(RegisterRowValidator::class)->validate($kind, $row, 12);
            $this->fail('The invalid register row was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('rows.12.'.$field, $exception->errors());
            $this->assertSame(['Die CSV-Zeile ist nicht zulässig.'], $exception->errors()['rows.12.'.$field]);
        }
    }

    /** @return array<string, array{RegisterImportKind, array<string, string>, string}> */
    public static function invalidRows(): array
    {
        $process = ['key' => 'PR-1', 'name' => 'Process', 'description' => '', 'owner_name' => '', 'owner_email' => '', 'active' => 'true'];
        $asset = ['key' => 'APP-1', 'name' => 'Asset', 'type' => 'application', 'description' => '', 'owner_name' => '', 'owner_email' => '', 'active' => 'true'];
        $dependency = ['source_type' => 'process', 'source_key' => 'PR-1', 'target_type' => 'asset', 'target_key' => 'APP-1', 'importance' => 'supporting', 'reason' => '', 'active' => 'true'];

        return [
            'invalid key' => [RegisterImportKind::Processes, array_replace($process, ['key' => 'x']), 'key'],
            'too long name' => [RegisterImportKind::Processes, array_replace($process, ['name' => str_repeat('a', 161)]), 'name'],
            'too long description' => [RegisterImportKind::Processes, array_replace($process, ['description' => str_repeat('a', 4001)]), 'description'],
            'too long owner name' => [RegisterImportKind::Processes, array_replace($process, ['owner_name' => str_repeat('a', 161)]), 'owner_name'],
            'invalid email' => [RegisterImportKind::Processes, array_replace($process, ['owner_email' => 'not-email']), 'owner_email'],
            'invalid boolean' => [RegisterImportKind::Processes, array_replace($process, ['active' => '1']), 'active'],
            'invalid asset type' => [RegisterImportKind::Assets, array_replace($asset, ['type' => 'server']), 'type'],
            'invalid node type' => [RegisterImportKind::Dependencies, array_replace($dependency, ['source_type' => 'other']), 'source_type'],
            'invalid importance' => [RegisterImportKind::Dependencies, array_replace($dependency, ['importance' => 'high']), 'importance'],
            'forbidden asset to process' => [RegisterImportKind::Dependencies, array_replace($dependency, ['source_type' => 'asset', 'target_type' => 'process']), 'target_type'],
            'self edge' => [RegisterImportKind::Dependencies, array_replace($dependency, ['target_type' => 'process', 'target_key' => 'pr-1']), 'target_key'],
            'too long reason' => [RegisterImportKind::Dependencies, array_replace($dependency, ['reason' => str_repeat('a', 1001)]), 'reason'],
        ];
    }
}
