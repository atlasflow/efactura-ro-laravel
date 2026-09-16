<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('efactura_submissions', function (Blueprint $table): void {
            $table->id();
            $table->string('cui', 10)->index();
            $table->string('standard', 4);
            $table->string('document_type', 3);
            $table->string('document_number', 200);
            $table->nullableMorphs('subject');
            $table->longText('xml');
            $table->boolean('b2c')->default(false);
            $table->json('upload_options');
            $table->unsignedBigInteger('upload_index')->nullable()->index();
            $table->string('download_id', 50)->nullable();
            $table->string('phase', 20)->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_poll_at')->nullable()->index();
            $table->json('last_error')->nullable();
            $table->string('bundle_path', 500)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('efactura_submissions');
    }
};
