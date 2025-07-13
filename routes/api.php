<?php

// routes/api.php - App Locale

use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LocalSyncController;

/*
|--------------------------------------------------------------------------
| API Routes pour l'app locale de synchronisation
|--------------------------------------------------------------------------
*/

Route::prefix('sync')->group(function () {
    // 1. Dump des factures depuis la comptabilité vers la table tampon
    Route::any('/dump-invoices', [LocalSyncController::class, 'dumpInvoices']);

    // 2. Récupérer les factures non synchronisées
    Route::get('/unsynced-invoices', [LocalSyncController::class, 'getUnsyncedInvoices']);

    // 3. Test de connectivité
    Route::get('/ping', [LocalSyncController::class, 'ping']);

    // 4. Statistiques
    Route::get('/stats', [LocalSyncController::class, 'getStats']);

    // Marquer des factures comme synchronisées
    Route::post('/mark-synced', [HomeController::class, 'markInvoicesAsSynced']);
});