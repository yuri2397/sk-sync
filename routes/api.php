<?php

// routes/api.php - App Locale

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LocalSyncController;

/*
|--------------------------------------------------------------------------
| API Routes pour l'app locale de synchronisation
|--------------------------------------------------------------------------
*/

Route::prefix('sync')->group(function () {
    // Test de connectivité
    Route::get('/ping', [LocalSyncController::class, 'ping']);

    // Statistiques
    Route::get('/stats', [LocalSyncController::class, 'getStats']);

    // Récupération des données
    Route::get('/customers', [LocalSyncController::class, 'getCustomers']);
    Route::get('/invoices', [LocalSyncController::class, 'getInvoices']);

    // Marquage comme synchronisé
    Route::post('/customers/mark-synced', [LocalSyncController::class, 'markCustomersSynced']);
    Route::post('/invoices/mark-synced', [LocalSyncController::class, 'markInvoicesSynced']);

    // Rafraîchissement depuis Sage
    Route::post('/refresh-from-sage', [LocalSyncController::class, 'refreshFromSage']);
});