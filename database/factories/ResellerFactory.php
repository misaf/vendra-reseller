<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Misaf\VendraReseller\Models\Reseller;

/**
 * @extends Factory<Reseller>
 */
#[UseModel(Reseller::class)]
final class ResellerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name'        => fake()->unique()->company(),
            'description' => fake()->text(),
            'slug'        => fn(array $attributes) => Str::slug($attributes['name']),
            'active'      => true,
            'email'       => fake()->unique()->safeEmail(),
        ];
    }

    public function withoutOwner(): static
    {
        return $this->state(fn(): array => [
            'email' => null,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn(): array => ['active' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn(): array => ['active' => false]);
    }
}
