<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Organization> */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999),
            'industry' => fake()->randomElement(['IT', 'Manufacturing', 'Services']),
            'employee_count' => fake()->numberBetween(5, 250),
            'address' => fake()->address(),
            'contact_name' => fake()->name(),
            'contact_email' => fake()->companyEmail(),
            'contact_phone' => fake()->phoneNumber(),
            'notes' => null,
            'entra_tenant_id' => fake()->uuid(),
            'is_active' => true,
        ];
    }
}
