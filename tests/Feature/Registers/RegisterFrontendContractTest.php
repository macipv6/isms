<?php

namespace Tests\Feature\Registers;

use Tests\TestCase;

class RegisterFrontendContractTest extends TestCase
{
    public function test_every_isolated_register_form_renders_field_labelled_errors(): void
    {
        foreach (['processes', 'assets', 'dependencies'] as $register) {
            $source = $this->source("resources/js/pages/{$register}/Index.vue");
            $this->assertGreaterThanOrEqual(4, substr_count($source, '<FormErrorList'), "{$register} must render filter, create, edit and status errors.");
            $this->assertStringContainsString(':errors="filterForm.errors"', $source);
            $this->assertStringContainsString(':errors="createForm.errors"', $source);
            $this->assertStringContainsString(':errors="editForm.errors"', $source);
            $this->assertStringContainsString(':errors="statusForm.errors"', $source);
        }

        $this->assertStringContainsString(':errors="uploadForm.errors"', $this->source('resources/js/components/RegisterImportPanel.vue'));
        $this->assertStringContainsString(':errors="confirmForm.errors"', $this->source('resources/js/components/RegisterImportPreview.vue'));
        $this->assertStringContainsString(':errors="form.errors"', $this->source('resources/js/components/DependencyTraversalPanel.vue'));
    }

    public function test_edit_and_status_controls_use_their_distinct_capabilities(): void
    {
        foreach (['processes', 'assets', 'dependencies'] as $register) {
            $source = $this->source("resources/js/pages/{$register}/Index.vue");
            $this->assertStringContainsString('v-if="capabilities.edit"', $source);
            $this->assertStringContainsString('v-if="capabilities.changeStatus"', $source);
            $this->assertStringNotContainsString('<div v-if="capabilities.edit" class="flex gap-3">', $source);
        }
    }

    private function source(string $path): string
    {
        $source = file_get_contents(base_path($path));
        $this->assertIsString($source);

        return $source;
    }
}
