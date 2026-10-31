<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per container per sample, sent by deploy/monitor.sh. Rows are short-lived history, so
     * backups keep only the table structure.
     */
    public function up(): void
    {
        Schema::create('container_metrics', function (Blueprint $table) {
            $table->id();
            $table->timestamp('sampled_at')->index();
            $table->string('name', 100);
            $table->string('project', 60)->nullable();
            $table->string('service', 60)->nullable();
            $table->string('state', 20);
            $table->string('health', 20)->nullable();
            $table->integer('exit_code')->default(0);
            $table->unsignedInteger('restarts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->decimal('cpu_percent', 7, 2)->nullable();
            $table->decimal('memory_mb', 10, 1)->nullable();

            $table->index(['name', 'sampled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('container_metrics');
    }
};
