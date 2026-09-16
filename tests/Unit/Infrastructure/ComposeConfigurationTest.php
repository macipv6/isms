<?php

namespace Tests\Unit\Infrastructure;

use Tests\TestCase;

class ComposeConfigurationTest extends TestCase
{
    public function test_postgresql_18_volume_uses_the_version_compatible_mount_point(): void
    {
        $compose = file_get_contents(base_path('compose.yaml'));

        $this->assertIsString($compose);
        $this->assertStringContainsString('image: postgres:18-alpine', $compose);
        $this->assertMatchesRegularExpression('/^\s*- postgres_data:\/var\/lib\/postgresql\s*$/m', $compose);
        $this->assertStringNotContainsString('postgres_data:/var/lib/postgresql/data', $compose);
    }
}
