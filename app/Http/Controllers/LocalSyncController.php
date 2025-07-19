<?php

namespace App\Http\Controllers;

use App\Models\InvoiceSyncBuffer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class LocalSyncController extends Controller
{
    /**
     * Dump les factures depuis la base comptable vers la table tampon
     * (seulement si elles n'existent pas encore)
     */
    /**
     * Dump les factures depuis la base comptable vers la table tampon
     * (seulement si elles n'existent pas encore)
     */
    public function dumpInvoices(Request $request)
    {
        try {
            $limit = (int)$request->input('limit', 1000);
            $fromDate = $request->input('from_date', '2020-11-11 00:00:00'); // Date d'échéance de référence


            // Récupérer les factures depuis la base comptable qui ne sont pas encore dans le buffer
            $invoices = DB::select("
                SELECT 
                    -- === INFORMATIONS CLIENT ===
                    e.CT_Num as client_code,
                    ISNULL(c.CT_Intitule, 'Client non trouvé') as client_name,
                    ISNULL(c.CT_Telephone, '') as client_phone,
                    ISNULL(c.CT_Email, '') as client_email,
                    ISNULL(c.CT_Adresse, '') as client_address,
                    
                    -- === INFORMATIONS FACTURE ===
                    e.EC_RefPiece as invoice_number,
                    f.DO_Date as invoice_date,
                    e.EC_Echeance as due_date,
                    
                    -- === MONTANTS ===
                    e.EC_Montant as amount,
                    ISNULL(f.DO_TotalTTC, 0) as invoice_total,
                    
                    -- === RETARD ===
                    CASE 
                        WHEN e.EC_Echeance > GETDATE() THEN 'FUTURE'
                        WHEN DATEDIFF(DAY, e.EC_Echeance, GETDATE()) = 0 THEN 'DUE_TODAY'
                        WHEN DATEDIFF(DAY, e.EC_Echeance, GETDATE()) BETWEEN 1 AND 30 THEN 'OVERDUE_1_30'
                        WHEN DATEDIFF(DAY, e.EC_Echeance, GETDATE()) BETWEEN 31 AND 60 THEN 'OVERDUE_31_60'
                        WHEN DATEDIFF(DAY, e.EC_Echeance, GETDATE()) BETWEEN 61 AND 90 THEN 'OVERDUE_61_90'
                        ELSE 'OVERDUE_90_PLUS'
                    END as overdue_category,
                    
                    CASE 
                        WHEN e.EC_Echeance > GETDATE() THEN DATEDIFF(DAY, GETDATE(), e.EC_Echeance) * -1
                        ELSE DATEDIFF(DAY, e.EC_Echeance, GETDATE())
                    END as days_overdue,
                    
                    -- === PRIORITÉ ===
                    CASE 
                        WHEN DATEDIFF(DAY, e.EC_Echeance, GETDATE()) > 90 AND e.EC_Montant >= 1000000 THEN 'URGENT'
                        WHEN DATEDIFF(DAY, e.EC_Echeance, GETDATE()) > 60 AND e.EC_Montant >= 500000 THEN 'HIGH'
                        WHEN DATEDIFF(DAY, e.EC_Echeance, GETDATE()) > 30 THEN 'MEDIUM'
                        WHEN DATEDIFF(DAY, e.EC_Echeance, GETDATE()) > 0 THEN 'NORMAL'
                        ELSE 'FUTURE'
                    END as priority,
                    
                    -- === INFORMATIONS COMPLÉMENTAIRES ===
                    e.EC_Intitule as description,
                    ISNULL(f.DO_Ref, '') as invoice_reference,
                    ISNULL(c.CT_Contact, '') as commercial_contact,
                    
                    -- === TECHNIQUES ===
                    e.EC_No as entry_id,
                    e.cbCreation as created_at,
                    e.cbModification as updated_at

                FROM F_ECRITUREC e
                LEFT JOIN F_DOCENTETE f ON e.EC_RefPiece = f.DO_Piece AND f.DO_Type = 7
                LEFT JOIN F_COMPTET c ON e.CT_Num = c.CT_Num

                WHERE 
                    e.CT_Num LIKE '411%'        -- Clients
                    AND e.EC_Sens = 0           -- Débits (factures)
                    AND e.EC_Lettre = 0         -- NON LETTRÉES = Vraiment impayées
                    AND e.EC_RefPiece IS NOT NULL  -- Avec référence facture
                    AND f.DO_Date IS NOT NULL  -- Date de facture valide
                    AND e.EC_Montant > 0       -- Montant positif
                    AND f.Do_Piece IS NOT NULL
                    AND f.DO_TotalTTC > 0       -- Total TTC valide
                    AND e.EC_Echeance >= ?      -- Date d'échéance à partir de la date définie
                    AND e.JM_Date >= '2025-01-01'
                    -- Exclure les factures déjà dans le buffer
                    AND NOT EXISTS (
                        SELECT 1 
                        FROM invoice_sync_buffer isb 
                        WHERE isb.invoice_number = e.EC_RefPiece
                        AND isb.client_code = e.CT_Num
                    )

                ORDER BY 
                    CASE 
                        WHEN DATEDIFF(DAY, e.EC_Echeance, GETDATE()) > 90 AND e.EC_Montant >= 1000000 THEN 1
                        WHEN DATEDIFF(DAY, e.EC_Echeance, GETDATE()) > 60 AND e.EC_Montant >= 500000 THEN 2
                        WHEN DATEDIFF(DAY, e.EC_Echeance, GETDATE()) > 30 THEN 3
                        WHEN DATEDIFF(DAY, e.EC_Echeance, GETDATE()) > 0 THEN 4
                        ELSE 5
                    END,
                    e.EC_Echeance ASC,
                    e.EC_Montant DESC

                OFFSET 0 ROWS FETCH NEXT ? ROWS ONLY
            ", [$fromDate, (int) $limit]);

            $addedCount = 0;
            $errors = [];



            foreach ($invoices as $invoice) {

                try {
                    InvoiceSyncBuffer::create([
                        'invoice_number' => $invoice->invoice_number,
                        'invoice_reference' => $invoice->invoice_reference,
                        'client_code' => $invoice->client_code,
                        'client_name' => $invoice->client_name,
                        'amount' => (float) $invoice->amount,
                        'invoice_total' => (float) $invoice->invoice_total,
                        'invoice_date' => $invoice->invoice_date ? date('Y-m-d', strtotime($invoice->invoice_date)) : null,
                        'due_date' => $invoice->due_date ? date('Y-m-d', strtotime($invoice->due_date)) : null,
                        'sync_status' => 'pending',
                        'client_phone' => $invoice->client_phone,
                        'client_email' => $invoice->client_email,
                        'client_address' => $invoice->client_address,
                        'commercial_contact' => $invoice->commercial_contact,
                        'overdue_category' => $invoice->overdue_category,
                        'days_overdue' => (int) $invoice->days_overdue,
                        'priority' => $invoice->priority,
                        'source_entry_id' => $invoice->entry_id,
                        'description' => $invoice->description,
                        'source_created_at' => $invoice->created_at ? date('Y-d-m H:i:s', strtotime($invoice->created_at)) : null,
                        'source_updated_at' => $invoice->updated_at ? date('Y-d-m H:i:s', strtotime($invoice->updated_at)) : null,
                    ]);
                    $addedCount++;
                } catch (Exception $e) {
                    $errors[] = "Erreur facture {$invoice->invoice_number}: " . $e->getMessage();
                }
            }


            return response()->json([
                'success' => true,
                'message' => "Dump terminé: {$addedCount} nouvelles factures ajoutées (échéances >= {$fromDate})",
                'added_count' => $addedCount,
                'total_found' => count($invoices),
                'from_date' => $fromDate,
                'errors_count' => count($errors),
                'errors' => $errors
            ]);
        } catch (Exception $e) {
            Log::error('Erreur dump factures: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du dump des factures',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupérer les factures non synchronisées depuis la table tampon
     */
    public function getUnsyncedInvoices(Request $request)
    {
        try {
            $limit = $request->input('limit', 100);
            $offset = $request->input('offset', 0);
            $priority = $request->input('priority');
            $clientCode = $request->input('client_code');

            $query = InvoiceSyncBuffer::where('sync_status', 'pending');

            if ($priority) {
                $query->where('priority', $priority);
            }

            if ($clientCode) {
                $query->where('client_code', 'like', "%{$clientCode}%");
            }

            $invoices = $query->orderByRaw("
                    CASE 
                        WHEN priority = 'URGENT' THEN 1
                        WHEN priority = 'HIGH' THEN 2
                        WHEN priority = 'MEDIUM' THEN 3
                        WHEN priority = 'NORMAL' THEN 4
                        ELSE 5
                    END
                ")
                ->orderBy('due_date', 'asc')
                ->orderBy('amount', 'desc')
                ->offset($offset)
                ->limit($limit)
                ->get();

            // Formatage des données
            $formattedInvoices = $invoices->map(function ($invoice) {
                return [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'invoice_reference' => $invoice->invoice_reference,
                    'invoice_date' => $invoice->invoice_date,
                    'due_date' => $invoice->due_date,
                    'amount' => $invoice->amount,
                    'invoice_total' => $invoice->invoice_total,
                    'currency' => 'XOF',
                    'overdue_category' => $invoice->overdue_category,
                    'days_overdue' => $invoice->days_overdue,
                    'priority' => $invoice->priority,
                    'client' => [
                        'code' => $invoice->client_code,
                        'name' => $invoice->client_name,
                        'phone' => $invoice->client_phone,
                        'email' => $invoice->client_email,
                        'address' => $invoice->client_address,
                        'commercial_contact' => $invoice->commercial_contact
                    ],
                    'description' => $invoice->description,
                    'created_at' => $invoice->created_at,
                    'updated_at' => $invoice->updated_at
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formattedInvoices,
                'count' => $formattedInvoices->count(),
                'pagination' => [
                    'limit' => $limit,
                    'offset' => $offset,
                    'has_more' => $formattedInvoices->count() === $limit
                ]
            ]);
        } catch (Exception $e) {
            Log::error('Erreur récupération factures non sync: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des factures non synchronisées',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Marquer des factures comme synchronisées
     */
    public function markAsSynced(Request $request)
    {
        try {
            $invoiceIds = $request->input('invoice_ids', []);
            $notes = $request->input('notes', 'Synchronisé automatiquement');

            if (empty($invoiceIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucune facture spécifiée'
                ], 400);
            }

            $updatedCount = InvoiceSyncBuffer::whereIn('id', $invoiceIds)
                ->where('sync_status', 'pending')
                ->update([
                    'sync_status' => 'synced',
                    'synced_at' => now(),
                    'sync_notes' => $notes
                ]);

            return response()->json([
                'success' => true,
                'message' => "{$updatedCount} facture(s) marquée(s) comme synchronisée(s)",
                'updated_count' => $updatedCount
            ]);
        } catch (Exception $e) {
            Log::error('Erreur marquage synchronisé: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du marquage des factures',
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
            // Test de la connexion à la base comptable
            DB::select('SELECT 1 as test');

            // Test de la table tampon
            $bufferCount = InvoiceSyncBuffer::count();

            return response()->json([
                'success' => true,
                'message' => 'Service local de synchronisation opérationnel',
                'timestamp' => now()->toIso8601String(),
                'buffer_count' => $bufferCount
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de connexion',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Statistiques de la table tampon et de la synchronisation
     */
    public function getStats()
    {
        try {
            // Stats de la table tampon
            $bufferStats = [
                'total_invoices' => InvoiceSyncBuffer::count(),
                'pending_invoices' => InvoiceSyncBuffer::where('sync_status', 'pending')->count(),
                'synced_invoices' => InvoiceSyncBuffer::where('sync_status', 'synced')->count(),
                'failed_invoices' => InvoiceSyncBuffer::where('sync_status', 'failed')->count(),

                'pending_amount' => InvoiceSyncBuffer::where('sync_status', 'pending')->sum('amount'),
                'synced_amount' => InvoiceSyncBuffer::where('sync_status', 'synced')->sum('amount'),

                'urgent_pending' => InvoiceSyncBuffer::where('sync_status', 'pending')->where('priority', 'URGENT')->count(),
                'high_pending' => InvoiceSyncBuffer::where('sync_status', 'pending')->where('priority', 'HIGH')->count(),
                'medium_pending' => InvoiceSyncBuffer::where('sync_status', 'pending')->where('priority', 'MEDIUM')->count(),
                'normal_pending' => InvoiceSyncBuffer::where('sync_status', 'pending')->where('priority', 'NORMAL')->count(),

                'last_sync' => InvoiceSyncBuffer::where('sync_status', 'synced')->max('synced_at'),
                'last_dump' => InvoiceSyncBuffer::max('created_at'),
            ];

            // Stats de la base comptable (total disponible)
            $comptabilityStats = DB::select("
                SELECT 
                    COUNT(*) as total_invoices_comptability,
                    SUM(e.EC_Montant) as total_amount_comptability,
                    COUNT(DISTINCT e.CT_Num) as unique_clients

                FROM F_ECRITUREC e
                LEFT JOIN F_DOCENTETE f ON e.EC_RefPiece = f.DO_Piece AND f.DO_Type = 7

                WHERE 
                    e.CT_Num LIKE '411%'
                    AND e.EC_Sens = 0
                    AND e.EC_Lettre = 0
                    AND e.EC_RefPiece IS NOT NULL
                    AND e.EC_Montant > 0
            ");

            $comptability = $comptabilityStats[0];

            return response()->json([
                'success' => true,
                'stats' => [
                    'buffer' => $bufferStats,
                    'comptability' => [
                        'total_invoices' => (int) $comptability->total_invoices_comptability,
                        'total_amount' => (float) $comptability->total_amount_comptability,
                        'unique_clients' => (int) $comptability->unique_clients
                    ],
                    'sync_progress' => [
                        'dumped_percentage' => $comptability->total_invoices_comptability > 0
                            ? round(($bufferStats['total_invoices'] / $comptability->total_invoices_comptability) * 100, 2)
                            : 0,
                        'synced_percentage' => $bufferStats['total_invoices'] > 0
                            ? round(($bufferStats['synced_invoices'] / $bufferStats['total_invoices']) * 100, 2)
                            : 0
                    ],
                    'currency' => 'XOF',
                    'generated_at' => now()->toIso8601String()
                ]
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
}
