<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('efactura_inbox_messages', function (Blueprint $table): void {
            $table->id();
            $table->string('cui', 10)->index();
            $table->string('anaf_id', 50)->unique();
            $table->string('type', 40)->index();
            $table->string('request_index', 50)->nullable()->index();
            $table->string('seller_cui', 10)->nullable();
            $table->string('buyer_cui', 10)->nullable();
            $table->text('details');
            $table->timestamp('anaf_created_at');
            $table->string('bundle_path', 500)->nullable();
            $table->timestamp('parsed_at')->nullable();
            $table->string('document_number', 200)->nullable();
            $table->date('document_date')->nullable();
            $table->string('document_currency', 3)->nullable();
            $table->decimal('document_total', 18, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('efactura_inbox_messages');
    }
};
