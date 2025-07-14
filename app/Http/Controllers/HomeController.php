<?php

namespace App\Http\Controllers;

use App\Models\InvoiceSyncBuffer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class HomeController extends Controller
{
    /**
     * URL de l'application principale (à configurer)
     */
    private $mainAppUrl = 'https://votre-app-principale.com'; // À modifier

    /**
     * Afficher le dashboard avec toutes les données compilées
     */
    public function index()
    {
        try {
            // Compiler toutes les données nécessaires pour le dashboard
            $dashboardData = $this->compileDashboardData();

            return view('welcome', compact('dashboardData'));
        } catch (Exception $e) {
            Log::error('Erreur compilation dashboard: ' . $e->getMessage());

            // En cas d'erreur, afficher le dashboard avec des données par défaut
            $dashboardData = $this->getDefaultDashboardData();
            return view('welcome', compact('dashboardData'));
        }
    }

    /**
     * Compiler toutes les données du dashboard
     */
    private function compileDashboardData()
    {
        // 1. Statistiques de la table tampon
        $bufferStats = $this->getBufferStats();

        // 2. Statistiques de la comptabilité
        $comptabilityStats = $this->getComptabilityStats();

        // 3. Factures urgentes
        $urgentInvoices = $this->getUrgentInvoices();

        // 4. Dernières activités
        $recentActivity = $this->getRecentActivity();

        // 5. Progression de synchronisation
        $syncProgress = $this->calculateSyncProgress($bufferStats, $comptabilityStats);

        return [
            'buffer_stats' => $bufferStats,
            'comptability_stats' => $comptabilityStats,
            'urgent_invoices' => $urgentInvoices,
            'recent_activity' => $recentActivity,
            'sync_progress' => $syncProgress,
            'last_updated' => now()->toIso8601String()
        ];
    }

    /**
     * Obtenir les statistiques de la table tampon
     */
    private function getBufferStats()
    {
        $stats = InvoiceSyncBuffer::selectRaw("
            COUNT(*) as total_invoices,
            SUM(CASE WHEN sync_status = 'pending' THEN 1 ELSE 0 END) as pending_invoices,
            SUM(CASE WHEN sync_status = 'synced' THEN 1 ELSE 0 END) as synced_invoices,
            SUM(CASE WHEN sync_status = 'failed' THEN 1 ELSE 0 END) as failed_invoices,
            SUM(CASE WHEN sync_status = 'pending' THEN amount ELSE 0 END) as pending_amount,
            SUM(CASE WHEN sync_status = 'synced' THEN amount ELSE 0 END) as synced_amount,
            SUM(CASE WHEN sync_status = 'pending' AND priority = 'URGENT' THEN 1 ELSE 0 END) as urgent_pending,
            SUM(CASE WHEN sync_status = 'pending' AND priority = 'HIGH' THEN 1 ELSE 0 END) as high_pending,
            MAX(CASE WHEN sync_status = 'synced' THEN synced_at END) as last_sync,
            MAX(created_at) as last_dump
        ")->first();

        return [
            'total_invoices' => (int) $stats->total_invoices,
            'pending_invoices' => (int) $stats->pending_invoices,
            'synced_invoices' => (int) $stats->synced_invoices,
            'failed_invoices' => (int) $stats->failed_invoices,
            'pending_amount' => (float) $stats->pending_amount,
            'synced_amount' => (float) $stats->synced_amount,
            'urgent_pending' => (int) $stats->urgent_pending,
            'high_pending' => (int) $stats->high_pending,
            'last_sync' => $stats->last_sync,
            'last_dump' => $stats->last_dump,
        ];
    }

    /**
     * Obtenir les statistiques de la comptabilité
     */
    private function getComptabilityStats()
    {
        try {
            $stats = DB::select("
                SELECT 
                    COUNT(*) as total_invoices,
                    SUM(e.EC_Montant) as total_amount,
                    COUNT(DISTINCT e.CT_Num) as unique_clients,
                    COUNT(CASE WHEN DATEDIFF(DAY, e.EC_Echeance, GETDATE()) > 90 THEN 1 END) as overdue_90_plus,
                    COUNT(CASE WHEN DATEDIFF(DAY, e.EC_Echeance, GETDATE()) BETWEEN 31 AND 90 THEN 1 END) as overdue_31_90

                FROM F_ECRITUREC e
                LEFT JOIN F_DOCENTETE f ON e.EC_RefPiece = f.DO_Piece AND f.DO_Type = 7

                WHERE 
                    e.CT_Num LIKE '411%'
                    AND e.EC_Sens = 0
                    AND e.EC_Lettre = 0
                    AND e.EC_RefPiece IS NOT NULL
                    AND e.EC_Montant > 0
                    AND e.EC_Echeance >= '2024-12-31 00:00:00'
            ");

            $result = $stats[0];

            return [
                'total_invoices' => (int) $result->total_invoices,
                'total_amount' => (float) $result->total_amount,
                'unique_clients' => (int) $result->unique_clients,
                'overdue_90_plus' => (int) $result->overdue_90_plus,
                'overdue_31_90' => (int) $result->overdue_31_90,
            ];
        } catch (Exception $e) {
            Log::error('Erreur stats comptabilité: ' . $e->getMessage());
            return [
                'total_invoices' => 0,
                'total_amount' => 0,
                'unique_clients' => 0,
                'overdue_90_plus' => 0,
                'overdue_31_90' => 0,
            ];
        }
    }

    /**
     * Obtenir les factures urgentes
     */
    private function getUrgentInvoices()
    {
        return InvoiceSyncBuffer::where('sync_status', 'pending')
            ->whereIn('priority', ['URGENT', 'HIGH'])
            ->orderBy('priority')
            ->orderBy('due_date')
            ->limit(10)
            ->get(['id', 'invoice_number', 'client_name', 'amount', 'due_date', 'priority', 'days_overdue']);
    }

    /**
     * Obtenir l'activité récente
     */
    private function getRecentActivity()
    {
        return InvoiceSyncBuffer::where('sync_status', 'synced')
            ->orderBy('synced_at', 'desc')
            ->limit(5)
            ->get(['invoice_number', 'client_name', 'amount', 'synced_at', 'sync_notes']);
    }

    /**
     * Calculer la progression de synchronisation
     */
    private function calculateSyncProgress($bufferStats, $comptabilityStats)
    {
        $dumpedPercentage = $comptabilityStats['total_invoices'] > 0
            ? round(($bufferStats['total_invoices'] / $comptabilityStats['total_invoices']) * 100, 2)
            : 0;

        $syncedPercentage = $bufferStats['total_invoices'] > 0
            ? round(($bufferStats['synced_invoices'] / $bufferStats['total_invoices']) * 100, 2)
            : 0;

        return [
            'dumped_percentage' => $dumpedPercentage,
            'synced_percentage' => $syncedPercentage,
            'remaining_to_dump' => max(0, $comptabilityStats['total_invoices'] - $bufferStats['total_invoices']),
            'remaining_to_sync' => $bufferStats['pending_invoices']
        ];
    }

    /**
     * Données par défaut en cas d'erreur
     */
    private function getDefaultDashboardData()
    {
        return [
            'buffer_stats' => [
                'total_invoices' => 0,
                'pending_invoices' => 0,
                'synced_invoices' => 0,
                'failed_invoices' => 0,
                'pending_amount' => 0,
                'synced_amount' => 0,
                'urgent_pending' => 0,
                'high_pending' => 0,
                'last_sync' => null,
                'last_dump' => null,
            ],
            'comptability_stats' => [
                'total_invoices' => 0,
                'total_amount' => 0,
                'unique_clients' => 0,
                'overdue_90_plus' => 0,
                'overdue_31_90' => 0,
            ],
            'urgent_invoices' => collect(),
            'recent_activity' => collect(),
            'sync_progress' => [
                'dumped_percentage' => 0,
                'synced_percentage' => 0,
                'remaining_to_dump' => 0,
                'remaining_to_sync' => 0
            ],
            'last_updated' => now()->toIso8601String()
        ];
    }

    /**
     * Faire le dump des nouvelles factures (API endpoint)
     */
    public function dumpInvoices(Request $request)
    {
        try {
            $limit = (int)$request->input('limit', 1000);
            $fromDate = $request->input('from_date', '2025-01-01 00:00:00');

            // Récupérer les factures depuis la base comptable qui ne sont pas encore dans le buffer
            $invoices = DB::select("
                SELECT 
                    e.CT_Num as client_code,
                    ISNULL(c.CT_Intitule, 'Client non trouvé') as client_name,
                    ISNULL(c.CT_Telephone, '') as client_phone,
                    ISNULL(c.CT_Email, '') as client_email,
                    ISNULL(c.CT_Adresse, '') as client_address,
                    e.EC_RefPiece as invoice_number,
                    f.DO_Date as invoice_date,
                    e.EC_Echeance as due_date,
                    e.EC_Montant as amount,
                    ISNULL(f.DO_TotalTTC, 0) as invoice_total,
                    
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
                    
                    CASE 
                        WHEN DATEDIFF(DAY, e.EC_Echeance, GETDATE()) > 90 AND e.EC_Montant >= 1000000 THEN 'URGENT'
                        WHEN DATEDIFF(DAY, e.EC_Echeance, GETDATE()) > 60 AND e.EC_Montant >= 500000 THEN 'HIGH'
                        WHEN DATEDIFF(DAY, e.EC_Echeance, GETDATE()) > 30 THEN 'MEDIUM'
                        WHEN DATEDIFF(DAY, e.EC_Echeance, GETDATE()) > 0 THEN 'NORMAL'
                        ELSE 'FUTURE'
                    END as priority,
                    
                    e.EC_Intitule as description,
                    ISNULL(f.DO_Ref, '') as invoice_reference,
                    ISNULL(c.CT_Contact, '') as commercial_contact,
                    e.EC_No as entry_id,
                    e.cbCreation as created_at,
                    e.cbModification as updated_at

                FROM F_ECRITUREC e
                LEFT JOIN F_DOCENTETE f ON e.EC_RefPiece = f.DO_Piece AND f.DO_Type = 7
                LEFT JOIN F_COMPTET c ON e.CT_Num = c.CT_Num

                WHERE 
                    e.CT_Num LIKE '411%'
                    AND e.EC_Sens = 0
                    AND e.EC_Lettre = 0
                    AND e.EC_RefPiece IS NOT NULL
                    AND e.EC_Montant > 0
                    AND e.EC_Echeance >= ?
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
            ", [$fromDate, $limit]);

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
                        'source_created_at' => $invoice->created_at ? date('Y-m-d H:i:s', strtotime($invoice->created_at)) : null,
                        'source_updated_at' => $invoice->updated_at ? date('Y-m-d H:i:s', strtotime($invoice->updated_at)) : null,
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
     * Envoyer les factures non synchronisées vers l'application principale
     */
    public function pushUnsyncedInvoices(Request $request)
    {
        try {
            $limit = $request->input('limit', 1000);
            $priority = $request->input('priority'); // URGENT, HIGH, MEDIUM, NORMAL

            // Récupérer les factures non synchronisées
            $query = InvoiceSyncBuffer::where('sync_status', 'pending');

            if ($priority) {
                $query->where('priority', $priority);
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
                ->limit($limit)
                ->get();

            if ($invoices->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Aucune facture à synchroniser',
                    'pushed_count' => 0
                ]);
            }

            // Préparer les données pour l'envoi
            $payload = [
                'invoices' => $invoices->map(function ($invoice) {
                    return [
                        'local_id' => $invoice->id,
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
                        'source_entry_id' => $invoice->source_entry_id,
                        'created_at' => $invoice->created_at,
                        'updated_at' => $invoice->updated_at
                    ];
                }),
                'metadata' => [
                    'total_count' => $invoices->count(),
                    'timestamp' => now()->toIso8601String(),
                    'source' => 'laravel-sync-buffer',
                    'version' => '1.0'
                ]
            ];

            // Envoyer vers l'application principale
            $response = Http::timeout(60)
                ->retry(3, 1000)
                ->post($this->mainAppUrl . '/api/sync/receive-invoices', $payload);

            if ($response->successful()) {
                $responseData = $response->json();

                // Marquer les factures comme synchronisées
                $invoiceIds = $invoices->pluck('id')->toArray();
                $updatedCount = InvoiceSyncBuffer::whereIn('id', $invoiceIds)
                    ->update([
                        'sync_status' => 'synced',
                        'synced_at' => now(),
                        'sync_notes' => 'Envoyé vers application principale: ' . ($responseData['message'] ?? 'OK')
                    ]);

                return response()->json([
                    'success' => true,
                    'message' => "Synchronisation réussie: {$updatedCount} factures envoyées",
                    'pushed_count' => $updatedCount,
                    'main_app_response' => $responseData
                ]);
            } else {
                // Marquer les factures comme échouées
                $invoiceIds = $invoices->pluck('id')->toArray();
                InvoiceSyncBuffer::whereIn('id', $invoiceIds)
                    ->update([
                        'sync_status' => 'failed',
                        'sync_attempts' => DB::raw('sync_attempts + 1'),
                        'last_sync_attempt' => now(),
                        'last_error_message' => 'HTTP ' . $response->status() . ': ' . $response->body()
                    ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Erreur lors de l\'envoi vers l\'application principale',
                    'error' => 'HTTP ' . $response->status(),
                    'details' => $response->body()
                ], 500);
            }
        } catch (Exception $e) {
            Log::error('Erreur push factures: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'envoi des factures',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Marquer des factures comme synchronisées
     */
    public function markInvoicesAsSynced(Request $request)
    {
        try {
            $invoiceIds = $request->input('invoice_ids', []);
            $notes = $request->input('notes', 'Synchronisé vers application principale');

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
                    'synced_at' => date('Y-d-m H:m:s', now()->timestamp),
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
     * Obtenir les statistiques en temps réel (API endpoint)
     */
    public function getStats()
    {
        try {
            $dashboardData = $this->compileDashboardData();

            return response()->json([
                'success' => true,
                'stats' => [
                    'buffer' => $dashboardData['buffer_stats'],
                    'comptability' => $dashboardData['comptability_stats'],
                    'sync_progress' => $dashboardData['sync_progress'],
                    'urgent_count' => $dashboardData['urgent_invoices']->count(),
                    'last_updated' => $dashboardData['last_updated']
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
