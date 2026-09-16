<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('efactura_authorisations', function (Blueprint $table): void {
            $table->id();
            $table->string('started_for_cui', 10)->index();
            $table->string('certificate_serial', 100)->nullable();
            $table->json('covered_cuis');
            $table->text('access_token');
            $table->text('refresh_token');
            $table->timestamp('access_expires_at');
            $table->timestamp('refresh_expires_at');
            $table->timestamp('authorised_at');
            $table->timestamp('refreshed_at')->nullable();
            $table->string('label', 200)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('efactura_authorisations');
    }
};
