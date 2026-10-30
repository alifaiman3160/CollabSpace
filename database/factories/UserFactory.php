<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),

            // The CollabSpace columns carry database defaults, but they must be
            // set here too or a freshly made model has them null in memory and
            // middleware reading $user->is_active sees a falsy value.
            'role' => 'student',
            'matric_no' => null,
            'must_change_password' => false,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => ['role' => 'admin']);
    }

    public function lecturer(): static
    {
        return $this->state(fn (array $attributes) => ['role' => 'lecturer']);
    }

    public function student(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'student',
            // Only the four digits need to be unique. The year used to be
            // wrapped in unique() as well, which allowed just five of them per
            // run: a test creating a sixth student failed with an overflow
            // that had nothing to do with what it was testing.
            'matric_no' => 'A'.fake()->numberBetween(20, 24).'EC'.fake()->unique()->numerify('####'),
        ]);
    }

    /** An account still carrying the temporary password it was created with. */
    public function mustChangePassword(): static
    {
        return $this->state(fn (array $attributes) => ['must_change_password' => true]);
    }

    public function deactivated(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
