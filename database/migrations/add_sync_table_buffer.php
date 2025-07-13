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
        Schema::create('invoice_sync_buffer', function (Blueprint $table) {
            $table->id();

            // Informations de base de la facture
            $table->string('invoice_number')->index();
            $table->string('invoice_reference')->nullable();
            $table->string('client_code')->index();
            $table->string('client_name');
            $table->decimal('amount', 15, 2);
            $table->decimal('invoice_total', 15, 2)->nullable();
            $table->date('invoice_date')->nullable();
            $table->date('due_date')->nullable();

            // Informations de synchronisation
            $table->enum('sync_status', ['pending', 'synced', 'failed', 'excluded'])->default('pending')->index();
            $table->timestamp('synced_at')->nullable();
            $table->text('sync_notes')->nullable();
            $table->string('sync_batch_id')->nullable()->index(); // Pour grouper les syncs

            // Informations client
            $table->string('client_phone')->nullable();
            $table->string('client_email')->nullable();
            $table->text('client_address')->nullable();
            $table->string('commercial_contact')->nullable();

            // Informations de retard et priorité
            $table->enum('overdue_category', [
                'FUTURE',
                'DUE_TODAY',
                'OVERDUE_1_30',
                'OVERDUE_31_60',
                'OVERDUE_61_90',
                'OVERDUE_90_PLUS'
            ])->nullable();
            $table->integer('days_overdue')->nullable();
            $table->enum('priority', ['FUTURE', 'NORMAL', 'MEDIUM', 'HIGH', 'URGENT'])->nullable()->index();

            // Métadonnées de la source
            $table->string('source_entry_id')->nullable(); // ID de F_ECRITUREC
            $table->text('description')->nullable();
            $table->timestamp('source_created_at')->nullable();
            $table->timestamp('source_updated_at')->nullable();

            // Tentatives de synchronisation
            $table->integer('sync_attempts')->default(0);
            $table->timestamp('last_sync_attempt')->nullable();
            $table->text('last_error_message')->nullable();

            $table->timestamps();

            // Index composés pour optimiser les requêtes
            $table->index(['sync_status', 'priority']);
            $table->index(['client_code', 'sync_status']);
            $table->index(['sync_batch_id', 'sync_status']);
            $table->unique(['invoice_number', 'client_code']); // Éviter les doublons
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_sync_buffer');
    }
};
