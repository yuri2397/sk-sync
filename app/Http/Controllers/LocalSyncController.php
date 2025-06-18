<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class LocalSyncController extends Controller
{
    /**
     * Récupérer les clients non synchronisés
     */
    public function getCustomers(Request $request)
    {
        try {
            $limit = $request->input('limit', 50);

            $customers = DB::select("
                SELECT TOP (?) 
                    sage_id, code, company_name, contact_name, email, phone, address,
                    payment_delay, currency, credit_limit, max_days_overdue, 
                    risk_level, notes, is_active
                FROM sync_customers 
                WHERE synced = 0 OR synced IS NULL
                ORDER BY created_at
            ", [$limit]);

            return response()->json([
                'success' => true,
                'data' => $customers,
                'count' => count($customers)
            ]);

        } catch (Exception $e) {
            Log::error('Erreur récupération clients: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des clients',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupérer les factures non synchronisées avec leurs échéances
     */
    public function getInvoices(Request $request)
    {
        try {
            $limit = $request->input('limit', 50);

            // Récupérer les factures avec leurs échéances
            $invoices = DB::select("
                SELECT TOP (?) 
                    i.sage_id, i.invoice_number, i.customer_sage_id, i.reference, i.type,
                    i.invoice_date, i.currency, i.total_amount, i.notes, i.created_by,
                    -- Sous-requête pour récupérer les échéances au format JSON
                    (
                        SELECT 
                            FORMAT(dd.due_date, 'yyyy-MM-dd') AS due_date,
                            dd.amount
                        FROM sync_due_dates dd 
                        WHERE dd.invoice_sage_id = i.sage_id
                          AND (dd.synced = 0 OR dd.synced IS NULL)
                        ORDER BY dd.due_date
                        FOR JSON PATH
                    ) AS due_dates_json
                FROM sync_invoices i
                WHERE (i.synced = 0 OR i.synced IS NULL)
                  AND EXISTS (
                      SELECT 1 FROM sync_due_dates dd 
                      WHERE dd.invoice_sage_id = i.sage_id 
                        AND (dd.synced = 0 OR dd.synced IS NULL)
                  )
                ORDER BY i.created_at
            ", [$limit]);

            // Formater les données pour inclure les échéances parsées
            $formattedInvoices = array_map(function($invoice) {
                $invoice->due_dates = $invoice->due_dates_json ?
                    json_decode($invoice->due_dates_json, true) : [];
                unset($invoice->due_dates_json);
                return $invoice;
            }, $invoices);

            return response()->json([
                'success' => true,
                'data' => $formattedInvoices,
                'count' => count($formattedInvoices)
            ]);

        } catch (Exception $e) {
            Log::error('Erreur récupération factures: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des factures',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Marquer les clients comme synchronisés
     */
    public function markCustomersSynced(Request $request)
    {
        try {
            $customerIds = $request->input('customer_ids', []);

            if (empty($customerIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun ID de client fourni'
                ], 400);
            }

            // Construire la requête avec des paramètres sûrs
            $placeholders = str_repeat('?,', count($customerIds) - 1) . '?';

            $affected = DB::update("
                UPDATE sync_customers 
                SET synced = 1, sync_date = GETDATE() 
                WHERE sage_id IN ($placeholders)
            ", $customerIds);

            return response()->json([
                'success' => true,
                'message' => "Clients marqués comme synchronisés",
                'affected' => $affected
            ]);

        } catch (Exception $e) {
            Log::error('Erreur marquage clients synchronisés: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du marquage des clients',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Marquer les factures et échéances comme synchronisées
     */
    public function markInvoicesSynced(Request $request)
    {
        try {
            $invoiceIds = $request->input('invoice_ids', []);

            if (empty($invoiceIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun ID de facture fourni'
                ], 400);
            }

            // Construire la requête avec des paramètres sûrs
            $placeholders = str_repeat('?,', count($invoiceIds) - 1) . '?';

            // Marquer les factures comme synchronisées
            $affectedInvoices = DB::update("
                UPDATE sync_invoices 
                SET synced = 1, sync_date = GETDATE() 
                WHERE sage_id IN ($placeholders)
            ", $invoiceIds);

            // Marquer les échéances comme synchronisées
            $affectedDueDates = DB::update("
                UPDATE sync_due_dates 
                SET synced = 1, sync_date = GETDATE() 
                WHERE invoice_sage_id IN ($placeholders)
            ", $invoiceIds);

            return response()->json([
                'success' => true,
                'message' => "Factures et échéances marquées comme synchronisées",
                'affected_invoices' => $affectedInvoices,
                'affected_due_dates' => $affectedDueDates
            ]);

        } catch (Exception $e) {
            Log::error('Erreur marquage factures synchronisées: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du marquage des factures',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Exécuter la synchronisation depuis Sage vers les tables tampons
     */
    public function refreshFromSage(Request $request)
    {
        try {
            // Exécuter la procédure stockée pour mettre à jour les tables tampons
            DB::statement('EXEC sp_sync_all_to_buffer');

            // Obtenir les statistiques
            $stats = $this->getSyncStats();

            return response()->json([
                'success' => true,
                'message' => 'Données mises à jour depuis Sage',
                'stats' => $stats
            ]);

        } catch (Exception $e) {
            Log::error('Erreur refresh depuis Sage: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour depuis Sage',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtenir les statistiques de synchronisation
     */
    public function getStats()
    {
        try {
            $stats = $this->getSyncStats();

            return response()->json([
                'success' => true,
                'stats' => $stats
            ]);

        } catch (Exception $e) {
            Log::error('Erreur récupération stats: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des statistiques',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Test de connectivité
     */
    public function ping()
    {
        try {
            // Test de la connexion à la base
            DB::select('SELECT 1 as test');

            return response()->json([
                'success' => true,
                'message' => 'Service local de synchronisation opérationnel',
                'timestamp' => now()->toIso8601String()
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de connexion à la base de données',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Méthode privée pour récupérer les statistiques
     */
    private function getSyncStats()
    {
        $stats = DB::select("
            SELECT 
                (SELECT COUNT(*) FROM sync_customers) AS total_customers,
                (SELECT COUNT(*) FROM sync_customers WHERE synced = 1) AS customers_synced,
                (SELECT COUNT(*) FROM sync_customers WHERE synced = 0 OR synced IS NULL) AS customers_pending,
                (SELECT COUNT(*) FROM sync_invoices) AS total_invoices,
                (SELECT COUNT(*) FROM sync_invoices WHERE synced = 1) AS invoices_synced,
                (SELECT COUNT(*) FROM sync_invoices WHERE synced = 0 OR synced IS NULL) AS invoices_pending,
                (SELECT COUNT(*) FROM sync_due_dates) AS total_due_dates,
                (SELECT COUNT(*) FROM sync_due_dates WHERE synced = 1) AS due_dates_synced,
                (SELECT COUNT(*) FROM sync_due_dates WHERE synced = 0 OR synced IS NULL) AS due_dates_pending,
                (SELECT SUM(total_amount) FROM sync_invoices WHERE synced = 0 OR synced IS NULL) AS pending_amount
        ");

        return $stats[0];
    }
}