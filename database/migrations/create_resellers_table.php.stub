<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createResellersTable();
    }

    public function down(): void
    {
        Schema::dropIfExists('resellers');
    }

    private function createResellersTable(): void
    {
        Schema::create('resellers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')
                ->unique()
                ->constrained()
                ->restrictOnDelete();
            $table->boolean('active')
                ->index();
            $table->text('offboarding_reason')
                ->nullable();
            $table->timestampTz('offboarded_at')
                ->nullable()
                ->index();
            $table->timestampsTz();
            $table->softDeletesTz();
        });
    }
};
