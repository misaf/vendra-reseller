<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Pages\Auth;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rules\Unique;
use InvalidArgumentException;
use Misaf\VendraReseller\Actions\CreateResellerAction;
use Misaf\VendraSubscription\Models\Plan;
use Misaf\VendraUser\Models\User;
use SensitiveParameter;

final class Register extends \Filament\Auth\Pages\Register
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getUsernameFormComponent(),
                $this->getEmailFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                $this->getPlanFormComponent(),
            ]);
    }

    protected function getUsernameFormComponent(): Component
    {
        return TextInput::make('username')
            ->label(__('console.username'))
            ->autofocus()
            ->minLength(3)
            ->maxLength(12)
            ->rules(['alpha_dash:ascii'])
            ->required()
            ->unique(
                table: User::class,
                modifyRuleUsing: fn (Unique $rule): Unique => $rule->withoutTrashed(),
            );
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label(__('console.email'))
            ->email()
            ->maxLength(255)
            ->required()
            ->unique(
                table: User::class,
                modifyRuleUsing: fn (Unique $rule): Unique => $rule->withoutTrashed(),
            );
    }

    protected function getPasswordFormComponent(): Component
    {
        return TextInput::make('password')
            ->label(__('filament-panels::auth/pages/register.form.password.label'))
            ->password()
            ->revealable(filament()->arePasswordsRevealable())
            ->required()
            ->rule(Password::default())
            ->showAllValidationMessages()
            ->same('passwordConfirmation')
            ->validationAttribute(__('filament-panels::auth/pages/register.form.password.validation_attribute'));
    }

    protected function getPlanFormComponent(): Component
    {
        return Select::make('plan_id')
            ->label(__('console.subscription_plan'))
            ->options(fn (): array => Plan::query()->active()->pluck('name', 'id')->all())
            ->rule(Rule::exists(Plan::class, 'id')->where('active', true))
            ->required()
            ->native(false);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRegistration(#[SensitiveParameter] array $data): Model
    {
        $planId = Arr::get($data, 'plan_id', null);
        $username = Arr::get($data, 'username', null);
        $email = Arr::get($data, 'email', null);
        $password = Arr::get($data, 'password', null);

        throw_if(! is_numeric($planId)
            || ! is_string($username)
            || ! is_string($email)
            || ! is_string($password), InvalidArgumentException::class, 'Invalid reseller registration details.');

        $plan = Plan::query()->active()->findOrFail((int) $planId);

        return Arr::get(resolve(CreateResellerAction::class)->execute(
            plan: $plan,
            username: $username,
            email: $email,
            password: $password,
            emailVerified: false,
        ), 'user');
    }
}
