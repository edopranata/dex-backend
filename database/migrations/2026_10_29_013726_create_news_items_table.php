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
        Schema::create('news_items', function (Blueprint $table) {
            $table->id();
            $table->string('source', 64);
            $table->string('title', 500);
            $table->string('url', 1000);
            $table->char('url_hash', 64)->unique();
            $table->text('summary')->nullable();
            $table->timestamp('published_at')->index();
            $table->unsignedTinyInteger('sentiment_score')->nullable();
            $table->string('sentiment_label', 16)->nullable();
            $table->string('sentiment_provider', 16)->nullable();
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('news_items');
    }
};
