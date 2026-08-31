<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_customer_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_setting_id')->default(1)->constrained('company_settings')->cascadeOnUpdate();
            $table->string('code', 30);
            $table->string('name');
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_setting_id', 'code']);
            $table->index(['company_setting_id', 'is_active']);
        });

        Schema::create('pos_customer_group_party', function (Blueprint $table) {
            $table->foreignId('customer_group_id')->constrained('pos_customer_groups')->cascadeOnDelete();
            $table->foreignId('party_id')->constrained('parties')->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['customer_group_id', 'party_id']);
            $table->index('party_id');
        });

        Schema::create('party_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained('parties')->cascadeOnDelete();
            $table->enum('address_type', ['BILLING', 'SHIPPING']);
            $table->string('label', 100)->nullable();
            $table->string('recipient_name')->nullable();
            $table->text('address_line');
            $table->string('district', 100)->nullable();
            $table->string('amphoe', 100)->nullable();
            $table->string('province', 100)->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('phone', 50)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['party_id', 'address_type', 'is_default'], 'party_addresses_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('party_addresses');
        Schema::dropIfExists('pos_customer_group_party');
        Schema::dropIfExists('pos_customer_groups');
    }
};
