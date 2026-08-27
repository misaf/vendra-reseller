<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Misaf\VendraReseller\Database\Factories\ResellerUserFactory;
use Misaf\VendraSupport\Contracts\ShouldLogActivity;

#[Fillable(['reseller_id', 'username', 'email', 'email_verified_at', 'password'])]
#[Hidden(['password', 'remember_token', 'active_reseller_guard', 'active_username_guard', 'active_email_guard'])]
#[UseFactory(ResellerUserFactory::class)]
final class ResellerUser extends Authenticatable implements FilamentUser, HasName, MustVerifyEmail, ShouldLogActivity
{
    /** @use HasFactory<ResellerUserFactory> */
    use HasFactory;
    use Notifiable;
    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reseller_id'       => 'integer',
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return 'reseller' === $panel->getId();
    }

    public function getFilamentName(): string
    {
        return $this->username;
    }

    /**
     * @return BelongsTo<Reseller, $this>
     */
    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    /**
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn(string $value): string => Str::lower(mb_trim($value)),
        );
    }
}
