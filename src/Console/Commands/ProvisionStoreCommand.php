<?php

declare(strict_types=1);

namespace Misaf\VendraReseller\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Misaf\VendraReseller\Actions\CreateResellerAction;
use Misaf\VendraReseller\Models\Reseller;
use Misaf\VendraStore\Actions\ProvisionStoreAction;
use Misaf\VendraStore\Models\StoreDomain;
use Misaf\VendraSubscription\Models\Plan;

final class ProvisionStoreCommand extends Command implements PromptsForMissingInput
{
    protected $signature = 'vendra-subscription:provision
        {name : Tenant name}
        {domain : Tenant domain}
        {username : Username for the tenant owner}
        {email : Email address for the tenant owner}
        {--if-missing : Skip provisioning when the tenant domain already exists}
        {--password= : Password for the tenant owner (random when omitted)}
        {--reseller= : Attach the store to an existing reseller (id or slug)}
        {--plan= : Create a reseller for this store subscribed to the given plan (id or slug)}
        {--seed : Run default tenant seeders after provisioning}';

    protected $description = 'Provision a store (tenant) with a domain, owner user, and role assignment';

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
            'name'     => ['Tenant name', 'Acme'],
            'domain'   => ['Tenant domain', 'acme.test'],
            'username' => ['Username for the tenant owner', 'admin_acme'],
            'email'    => ['Email address for the tenant owner', 'admin@acme.test'],
        ];
    }

    public function handle(): int
    {
        if ($this->shouldSkipExistingTenant()) {
            return self::SUCCESS;
        }

        $data = $this->validatedInput();

        if (null === $data) {
            return self::FAILURE;
        }

        $shouldSeed = $this->shouldSeedTenant();
        $validatedPassword = $this->validatedPassword();

        if (false === $validatedPassword) {
            return self::FAILURE;
        }

        $passwordWasProvided = null !== $validatedPassword;
        $password = $validatedPassword
            ?? Str::password(length: 8, letters: true, numbers: true, symbols: false);
        $reseller = $this->resolveReseller($data, $password);

        if (false === $reseller) {
            return self::FAILURE;
        }

        $result = $this->provisionTenantAction->execute($data, $shouldSeed, $password, $reseller);

        $this->info('Store provisioned.');
        $this->table(['Field', 'Value'], [
            ['Domain', $data['domain']],
            ['Reseller', null === $reseller ? '[none]' : $reseller->name],
            ['Username', $result['user']->username],
            ['Email', $result['user']->email],
            ['Password', $passwordWasProvided ? '[provided]' : $result['password']],
            ['Seeders', $shouldSeed ? 'Run' : 'Skipped'],
        ]);

        return self::SUCCESS;
    }

    /**
     * Resolve the owning reseller from the --reseller or --plan options.
     *
     * Returns null when neither option is given (legacy reseller-less path),
     * or false when an option references something that cannot be resolved.
     */
    /**
     * @param array{name: string, domain: string, username: string, email: string} $data
     */
    private function resolveReseller(array $data, string $password): Reseller|false|null
    {
        $resellerOption = $this->option('reseller');

        if (null !== $resellerOption) {
            $reseller = Reseller::query()
                ->where('id', $resellerOption)
                ->orWhere('slug', $resellerOption)
                ->first();

            if (null === $reseller) {
                $this->error(sprintf('Reseller [%s] was not found.', $resellerOption));

                return false;
            }

            return $reseller;
        }

        $planOption = $this->option('plan');

        if (null !== $planOption) {
            $plan = Plan::query()
                ->where('id', $planOption)
                ->orWhere('slug', $planOption)
                ->first();

            if (null === $plan) {
                $this->error(sprintf('Plan [%s] was not found.', $planOption));

                return false;
            }

            return $this->createResellerAction->execute(
                plan: $plan,
                username: $data['username'],
                email: $data['email'],
                password: $password,
            )['reseller'];
        }

        return null;
    }

    private function shouldSkipExistingTenant(): bool
    {
        if ( ! (bool) $this->option('if-missing')) {
            return false;
        }

        $domain = (string) $this->argument('domain');

        if ( ! StoreDomain::query()->where('name', $domain)->exists()) {
            return false;
        }

        $this->info(sprintf('Tenant domain [%s] already exists; provisioning skipped.', $domain));

        return true;
    }

    /**
     * Validate the optional password option: null when omitted, false when invalid.
     */
    private function validatedPassword(): string|false|null
    {
        $password = $this->option('password');

        if (null === $password) {
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
            'name'     => $this->argument('name'),
            'domain'   => $this->argument('domain'),
            'username' => $this->argument('username'),
            'email'    => $this->argument('email'),
        ];

        $validator = Validator::make($input, [
            'name'   => ['required', 'string', 'max:255'],
            'domain' => [
                'required',
                'string',
                'max:255',
                Rule::unique('store_domains', 'name')->withoutTrashed(),
            ],
            'username' => ['required', 'string', 'max:255'],
            'email'    => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->withoutTrashed()],
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

        if ( ! $this->input->isInteractive()) {
            return false;
        }

        return $this->confirm('Run default tenant seeders?', true);
    }
}
