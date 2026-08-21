<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('external_id');
            $table->string('name');
            $table->string('url');
            $table->boolean('enabled')->default(true)->index();
            $table->boolean('auto_download')->default(true);
            $table->unsignedInteger('scan_interval_minutes')->default(360);
            $table->timestamp('last_scanned_at')->nullable();
            $table->timestamp('next_scan_at')->nullable()->index();
            $table->string('last_scan_status')->nullable();
            $table->text('last_scan_error')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'type', 'external_id']);
        });

        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('youtube_id', 20)->unique();
            $table->string('title');
            $table->longText('description')->nullable();
            $table->string('channel_name')->nullable()->index();
            $table->string('channel_id')->nullable()->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->string('original_url');
            $table->string('status', 20)->default('discovered')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('media_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->timestamps();
            $table->unique(['media_id', 'path']);
        });

        Schema::create('download_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt_number');
            $table->string('status', 20)->index();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->integer('exit_code')->nullable();
            $table->string('yt_dlp_version')->nullable();
            $table->string('error_category')->nullable()->index();
            $table->longText('stdout')->nullable();
            $table->longText('stderr')->nullable();
            $table->timestamps();
        });

        Schema::create('youtube_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->longText('cookies');
            $table->string('status')->default('not_configured');
            $table->timestamp('tested_at')->nullable();
            $table->text('status_message')->nullable();
            $table->timestamps();
        });

        Schema::create('watch_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position_seconds')->default(0);
            $table->boolean('watched')->default(false)->index();
            $table->timestamp('last_watched_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['user_id', 'media_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watch_history');
        Schema::dropIfExists('youtube_credentials');
        Schema::dropIfExists('download_attempts');
        Schema::dropIfExists('media_files');
        Schema::dropIfExists('media');
        Schema::dropIfExists('sources');
    }
};
