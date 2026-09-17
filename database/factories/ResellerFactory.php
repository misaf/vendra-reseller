<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraUser\Models\User;

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
            'user_id' => User::factory()->state(['tenant_id' => null]),
            'active' => true,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => ['active' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['active' => false]);
    }
}
