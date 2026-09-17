<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Filament\Pages\Auth;

use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Misaf\VendraUser\Actions\UpdateUserPasswordAction;
use Misaf\VendraUser\Models\User;
use SensitiveParameter;

/**
 * The reseller's own password change.
 *
 * Filament's default profile writes the form straight onto the user: a `name`
 * column users do not have, an email change that skips the reseller's email
 * action, and a password hashed outside vendra-user. The main account's email
 * is changed by the console; here the reseller changes only the password, and
 * that goes through {@see UpdateUserPasswordAction}.
 */
final class EditProfile extends \Filament\Auth\Pages\EditProfile
{
    private ?string $newPassword = null;

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                $this->getCurrentPasswordFormComponent(),
            ]);
    }

    protected function getPasswordFormComponent(): Component
    {
        $component = parent::getPasswordFormComponent();

        return $component instanceof TextInput
            ? $component->dehydrateStateUsing(fn (#[SensitiveParameter] ?string $state): ?string => $state)
            : $component;
    }

    /**
     * The plain password is held back from `$data`, so Filament neither writes it
     * onto the user nor stores it as the session's password hash.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(#[SensitiveParameter] array $data): array
    {
        $password = Arr::get($data, 'password', null);
        $this->newPassword = is_string($password) && $password !== '' ? $password : null;

        unset($data['password']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, #[SensitiveParameter] array $data): Model
    {
        if (! $record instanceof User || $this->newPassword === null) {
            return $record;
        }

        $user = resolve(UpdateUserPasswordAction::class)->execute($record, $this->newPassword);
        $this->newPassword = null;

        // Keep this session signed in: AuthenticateSession compares against the stored hash.
        if (request()->hasSession()) {
            request()->session()->put('password_hash_'.Filament::getAuthGuard(), $user->getAuthPassword());
        }

        return $user;
    }
}
