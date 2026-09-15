<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->string('courier', 32);
            $table->string('incoming_path');
            $table->string('forward_path');
            $table->json('headers')->nullable();
            $table->longText('raw_body')->nullable();
            $table->json('payload')->nullable();
            $table->string('invoice_no')->nullable();
            $table->string('tracking_id')->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('name')->nullable();
            $table->string('matched_by', 16)->nullable();
            $table->string('database_name')->nullable();
            $table->foreignId('webhook_transfer_id')->nullable()->constrained('webhook_transfers')->nullOnDelete();
            $table->string('target_url')->nullable();
            $table->string('status', 16)->default('unmatched');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->unsignedInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_attempt_at']);
            $table->index(['courier', 'status']);
            $table->index('invoice_no');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
