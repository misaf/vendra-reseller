<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraReseller\Models\ResellerUser;

/**
 * @extends Factory<ResellerUser>
 */
#[UseModel(ResellerUser::class)]
final class ResellerUserFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reseller_id'       => Reseller::factory(),
            'username'          => fake()->unique()->userName(),
            'email'             => fake()->unique()->safeEmail(),
            'email_verified_at' => Carbon::now(),
            'password'          => Hash::make('password'),
            'remember_token'    => Str::random(10),
        ];
    }

    public function forReseller(Reseller|int $reseller): static
    {
        return $this->state(fn(): array => [
            'reseller_id' => $reseller instanceof Model ? $reseller->getKey() : $reseller,
        ]);
    }

    public function unverified(): static
    {
        return $this->state(fn(): array => [
            'email_verified_at' => null,
        ]);
    }
}
