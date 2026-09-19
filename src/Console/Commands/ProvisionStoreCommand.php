<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Misaf\VendraReseller\Actions\CreateResellerAction;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraStore\Actions\ProvisionStoreAction;
use Misaf\VendraStore\Models\StoreDomain;
use Misaf\VendraSubscription\Exceptions\SubscriptionLimitException;
use Misaf\VendraSubscription\Exceptions\SubscriptionPaymentException;
use Misaf\VendraSubscription\Models\Plan;

#[Description('Provision a store (tenant) with a domain, administrator user, and role assignment')]
#[Signature('vendra-reseller:provision-store
        {name : Tenant name}
        {domain : Tenant domain}
        {username : Username for the tenant administrator}
        {email : Email address for the tenant administrator}
        {--if-missing : Skip provisioning when the tenant domain already exists}
        {--password= : Password for the tenant administrator (random when omitted)}
        {--reseller= : Attach the store to an existing reseller (id or username of its user)}
        {--plan= : Create a reseller for this store subscribed to the given plan (id or slug)}
        {--seed : Run default tenant seeders after provisioning}')]
final class ProvisionStoreCommand extends Command implements PromptsForMissingInput
{
    public function __construct(
        private readonly ProvisionStoreAction $provisionTenantAction,
        private readonly CreateResellerAction $createResellerAction,
    ) {
        parent::__construct();
    }

    /**
     * @return array<string, string|array<int, string>>
     */
    protected function promptForMissingArgumentsUsing(): array
    {
        return [
            'name' => ['Tenant name', 'Acme'],
            'domain' => ['Tenant domain', 'acme.test'],
            'username' => ['Username for the tenant administrator', 'admin_acme'],
            'email' => ['Email address for the tenant administrator', 'admin@acme.test'],
        ];
    }

    public function handle(): int
    {
        if ($this->shouldSkipExistingTenant()) {
            return self::SUCCESS;
        }

        $data = $this->validatedInput();

        if ($data === null) {
            return self::FAILURE;
        }

        $shouldSeed = $this->shouldSeedTenant();
        $validatedPassword = $this->validatedPassword();

        if ($validatedPassword === false) {
            return self::FAILURE;
        }

        $passwordWasProvided = $validatedPassword !== null;
        $password = $validatedPassword
            ?? Str::password(length: 8, letters: true, numbers: true, symbols: false);

        /*
         | One transaction: a reseller created for --plan and then refused a store
         | (a paid plan leaves no active subscription yet) must not survive the
         | failed run, or rerunning the command collides on its email.
         */
        try {
            $provisioned = DB::transaction(function () use ($data, $password, $shouldSeed): ?array {
                $reseller = $this->resolveReseller($data, $password);

                if ($reseller === false) {
                    return null;
                }

                return [$reseller, $this->provisionTenantAction->execute($data, $shouldSeed, $password, $reseller)];
            });
        } catch (SubscriptionLimitException|SubscriptionPaymentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($provisioned === null) {
            return self::FAILURE;
        }

        [$reseller, $result] = $provisioned;

        $this->info('Store provisioned.');
        $this->table(['Field', 'Value'], [
            ['Domain', Arr::get($data, 'domain')],
            ['Reseller', $reseller === null ? '[none]' : $reseller->displayName()],
            ['Username', Arr::get($result, 'user')->username],
            ['Email', Arr::get($result, 'user')->email],
            ['Password', $passwordWasProvided ? '[provided]' : Arr::get($result, 'password')],
            ['Seeders', $shouldSeed ? 'Run' : 'Skipped'],
        ]);

        return self::SUCCESS;
    }

    /**
     * Resolve the reseller from the `--reseller` or `--plan` options.
     *
     * Returns null when neither is given, or false when one cannot be resolved.
     *
     * @param  array{name: string, domain: string, username: string, email: string}  $data
     */
    private function resolveReseller(array $data, string $password): Reseller|false|null
    {
        $resellerOption = $this->option('reseller');

        if ($resellerOption !== null) {
            $reseller = $this->findReseller((string) $resellerOption);

            if ($reseller === null) {
                $this->error(sprintf('Reseller [%s] was not found.', $resellerOption));

                return false;
            }

            return $reseller;
        }

        $planOption = $this->option('plan');

        if ($planOption === null) {
            return null;
        }

        $plan = $this->findPlan((string) $planOption);

        if ($plan === null) {
            $this->error(sprintf('Plan [%s] was not found.', $planOption));

            return false;
        }

        return Arr::get($this->createResellerAction->execute(
            plan: $plan,
            username: Arr::get($data, 'username'),
            email: Arr::get($data, 'email'),
            password: $password,
        ), 'reseller');
    }

    /**
     * Find a reseller by id, or by the username of its user.
     */
    private function findReseller(string $identifier): ?Reseller
    {
        return Reseller::query()
            ->when(
                ctype_digit($identifier),
                fn (Builder $query): Builder => $query->whereKey((int) $identifier),
                fn (Builder $query): Builder => $query->whereHas(
                    'user',
                    fn (Builder $query): Builder => $query->where('username', $identifier),
                ),
            )
            ->first();
    }

    /**
     * Find an active plan by id or slug.
     */
    private function findPlan(string $identifier): ?Plan
    {
        return Plan::query()
            ->active()
            ->when(
                ctype_digit($identifier),
                fn (Builder $query): Builder => $query->whereKey((int) $identifier),
                fn (Builder $query): Builder => $query->where('slug', $identifier),
            )
            ->first();
    }

    private function shouldSkipExistingTenant(): bool
    {
        if (! (bool) $this->option('if-missing')) {
            return false;
        }

        $domain = StoreDomain::normalizeDomain((string) $this->argument('domain'));

        if (! StoreDomain::query()->where('name', $domain)->exists()) {
            return false;
        }

        $this->info(sprintf('Tenant domain [%s] already exists; provisioning skipped.', $domain));

        return true;
    }

    /**
     * Validate the password option, returning null when omitted or false when invalid.
     */
    private function validatedPassword(): string|false|null
    {
        $password = $this->option('password');

        if ($password === null) {
            return null;
        }

        $validator = Validator::make(
            ['password' => $password],
            ['password' => ['required', 'string', 'min:8', 'max:255']],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return false;
        }

        return (string) $password;
    }

    /**
     * @return array{
     *     name: string,
     *     domain: string,
     *     username: string,
     *     email: string
     * }|null
     */
    private function validatedInput(): ?array
    {
        $input = [
            'name' => $this->argument('name'),
            'domain' => StoreDomain::normalizeDomain((string) $this->argument('domain')),
            'username' => $this->argument('username'),
            'email' => $this->argument('email'),
        ];

        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'domain' => [
                ...StoreDomain::activeDomainRules(),
                Rule::unique('store_domains', 'name')->withoutTrashed(),
            ],
            'username' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->withoutTrashed()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return null;
        }

        /** @var array{name: string, domain: string, username: string, email: string} $data */
        $data = $validator->validated();

        return $data;
    }

    private function shouldSeedTenant(): bool
    {
        if ((bool) $this->option('seed')) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            return false;
        }

        return $this->confirm('Run default tenant seeders?', true);
    }
}
