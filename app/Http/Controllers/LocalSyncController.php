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
                FROM anonymes_customers 
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
     * Récupérer les échéances groupées par facture (invoice_number = DO_Ref)
     */
    public function getInvoices(Request $request)
    {
        try {
            $limit = $request->input('limit', 100);

            // Récupérer les échéances groupées par numéro de facture (DO_Ref)
            $invoiceGroups = DB::select("
                SELECT DISTINCT TOP (?)
                    invoice_number,  -- DO_Ref (numéro facture commun)
                    customer_sage_id,
                    MIN(invoice_date) as invoice_date,
                    currency,
                    SUM(total_amount) as total_amount,
                    MIN(created_by) as created_by,
                    -- Récupérer toutes les échéances pour cette facture
                    (
                        SELECT 
                            sage_id,           -- DO_Piece (ID unique échéance)
                            reference,         -- DO_Piece aussi
                            FORMAT(invoice_date, 'yyyy-MM-dd') AS due_date,
                            total_amount as amount,
                            type
                        FROM anonymes_invoices i2 
                        WHERE i2.invoice_number = i1.invoice_number
                          AND i2.customer_sage_id = i1.customer_sage_id
                          AND (i2.synced = 0 OR i2.synced IS NULL)
                        ORDER BY i2.invoice_date
                        FOR JSON PATH
                    ) AS due_dates_json
                FROM anonymes_invoices i1
                WHERE (synced = 0 OR synced IS NULL)
                  AND invoice_number IS NOT NULL
                  AND invoice_number <> ''
                GROUP BY invoice_number, customer_sage_id, currency
                ORDER BY MIN(created_at)
            ", [$limit]);

            // Formater les données pour créer une facture avec ses échéances
            $formattedInvoices = array_map(function ($group) {
                $due_dates = $group->due_dates_json ?
                    json_decode($group->due_dates_json, true) : [];

                return [
                    'invoice_number' => $group->invoice_number,  // Numéro facture commun
                    'customer_sage_id' => $group->customer_sage_id,
                    'invoice_date' => $group->invoice_date,
                    'currency' => $group->currency,
                    'total_amount' => $group->total_amount,      // Somme de toutes les échéances
                    'created_by' => $group->created_by,
                    'type' => 'invoice',                         // C'est une facture dans l'app
                    'due_dates' => $due_dates                    // Liste des échéances
                ];
            }, $invoiceGroups);

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
                UPDATE anonymes_customers 
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
     * Marquer les échéances comme synchronisées par numéro de facture
     */
    public function markInvoicesSynced(Request $request)
    {
        try {
            $invoiceNumbers = $request->input('invoice_numbers', []);

            if (empty($invoiceNumbers)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun numéro de facture fourni'
                ], 400);
            }

            // Construire la requête avec des paramètres sûrs
            $placeholders = str_repeat('?,', count($invoiceNumbers) - 1) . '?';

            // Marquer toutes les échéances de ces factures comme synchronisées
            $affected = DB::update("
                UPDATE anonymes_invoices 
                SET synced = 1, sync_date = GETDATE() 
                WHERE invoice_number IN ($placeholders)
            ", $invoiceNumbers);

            return response()->json([
                'success' => true,
                'message' => "Échéances marquées comme synchronisées",
                'affected' => $affected
            ]);
        } catch (Exception $e) {
            Log::error('Erreur marquage échéances synchronisées: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du marquage des échéances',
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
                (SELECT COUNT(*) FROM anonymes_customers) AS total_customers,
                (SELECT COUNT(*) FROM anonymes_customers WHERE synced = 1) AS customers_synced,
                (SELECT COUNT(*) FROM anonymes_customers WHERE synced = 0 OR synced IS NULL) AS customers_pending,
                (SELECT COUNT(DISTINCT invoice_number) FROM anonymes_invoices WHERE invoice_number IS NOT NULL AND invoice_number <> '') AS total_invoices,
                (SELECT COUNT(DISTINCT invoice_number) FROM anonymes_invoices WHERE synced = 1 AND invoice_number IS NOT NULL AND invoice_number <> '') AS invoices_synced,
                (SELECT COUNT(DISTINCT invoice_number) FROM anonymes_invoices WHERE (synced = 0 OR synced IS NULL) AND invoice_number IS NOT NULL AND invoice_number <> '') AS invoices_pending,
                (SELECT COUNT(*) FROM anonymes_invoices) AS total_echeances,
                (SELECT COUNT(*) FROM anonymes_invoices WHERE synced = 1) AS echeances_synced,
                (SELECT COUNT(*) FROM anonymes_invoices WHERE synced = 0 OR synced IS NULL) AS echeances_pending,
                (SELECT SUM(total_amount) FROM anonymes_invoices WHERE synced = 0 OR synced IS NULL) AS pending_amount
        ");

        return $stats[0];
    }
}
