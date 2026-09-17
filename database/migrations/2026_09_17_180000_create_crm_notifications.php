<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary(); $table->string('type'); $table->morphs('notifiable'); $table->text('data'); $table->timestamp('read_at')->nullable(); $table->timestamps();
        });
        Schema::create('crm_notification_deliveries', function (Blueprint $table): void {
            $table->id(); $table->string('idempotency_key')->unique(); $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 30); $table->foreignId('activity_id')->nullable()->constrained('crm_activities')->cascadeOnDelete(); $table->foreignId('branch_id')->nullable()->constrained('branches')->cascadeOnDelete(); $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_notification_deliveries'); Schema::dropIfExists('notifications');
    }
};
