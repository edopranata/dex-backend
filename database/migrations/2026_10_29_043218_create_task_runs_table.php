<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('task_runs', function (Blueprint $table) {
            $table->id();
            $table->string('command', 191);
            $table->string('schedule', 64)->nullable();
            $table->string('trigger', 16)->default('schedule');
            $table->string('status', 16);
            $table->integer('exit_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('output')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['command', 'id']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_runs');
    }
};
