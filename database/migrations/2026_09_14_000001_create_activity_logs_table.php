<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->string('log_name')->nullable()->comment('contoh: user, role, organization_unit');
            $table->string('event')->comment('updated, deleted, created');
            $table->string('description')->nullable();
            $table->nullableMorphs('subject'); // subject_type + subject_id
            $table->nullableMorphs('causer');  // causer_type + causer_id (user yang melakukan)
            $table->jsonb('properties')->nullable()->comment('old, new, attributes, diff');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();

            $table->index(['log_name', 'event']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
