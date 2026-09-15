<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_transfers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('target_url');
            $table->string('courier', 32);
            $table->string('database_name');
            $table->string('ack_secret')->nullable();
            $table->string('inbound_token', 64)->unique();
            $table->unsignedInteger('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['courier', 'database_name', 'target_url'], 'webhook_transfers_unique_route');
            $table->index(['courier', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_transfers');
    }
};
