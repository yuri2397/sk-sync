<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Synchronisation Factures - {{ config('app.name', 'Laravel') }}</title>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
    
    <!-- Styles -->
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Figtree', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .header {
            text-align: center;
            color: white;
            margin-bottom: 40px;
        }

        .header h1 {
            font-size: 2.5rem;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .header p {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        .dashboard {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 40px;
        }

        .stats-card {
            background: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }

        .stats-card:hover {
            transform: translateY(-5px);
        }

        .stats-card h3 {
            color: #374151;
            font-size: 1.25rem;
            margin-bottom: 20px;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 10px;
        }

        .stat-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #f3f4f6;
        }

        .stat-item:last-child {
            border-bottom: none;
        }

        .stat-label {
            color: #6b7280;
            font-weight: 500;
        }

        .stat-value {
            font-weight: 600;
            font-size: 1.1rem;
        }

        .stat-value.pending {
            color: #f59e0b;
        }

        .stat-value.synced {
            color: #10b981;
        }

        .stat-value.urgent {
            color: #ef4444;
        }

        .actions-section {
            background: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }

        .actions-section h3 {
            color: #374151;
            font-size: 1.25rem;
            margin-bottom: 25px;
            text-align: center;
        }

        .action-buttons {
            display: flex;
            gap: 20px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn {
            padding: 15px 30px;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            min-width: 200px;
            justify-content: center;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }

        .btn-warning {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }

        .loading {
            display: none;
            text-align: center;
            padding: 20px;
            color: #6b7280;
        }

        .spinner {
            border: 3px solid #f3f4f6;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            animation: spin 1s linear infinite;
            margin: 0 auto 15px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin: 20px 0;
            display: none;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        .alert-info {
            background: #dbeafe;
            color: #1e40af;
            border: 1px solid #93c5fd;
        }

        .progress-bar {
            background: #f3f4f6;
            border-radius: 10px;
            height: 20px;
            margin: 15px 0;
            overflow: hidden;
        }

        .progress-fill {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            height: 100%;
            width: 0%;
            transition: width 0.5s ease;
            border-radius: 10px;
        }

        @media (max-width: 768px) {
            .dashboard {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
                align-items: center;
            }
            
            .btn {
                width: 100%;
                max-width: 300px;
            }
        }

        .sync-log {
            background: #f8fafc;
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
            max-height: 300px;
            overflow-y: auto;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            display: none;
        }

        .log-entry {
            padding: 5px 0;
            border-bottom: 1px solid #e5e7eb;
        }

        .log-entry:last-child {
            border-bottom: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>🔄 Synchronisation des Factures</h1>
            <p>Tableau de bord pour la synchronisation des factures impayées</p>
        </div>

        <!-- Alerts -->
        <div id="alert-success" class="alert alert-success"></div>
        <div id="alert-error" class="alert alert-error"></div>
        <div id="alert-info" class="alert alert-info"></div>

        <!-- Dashboard Stats -->
        <div class="dashboard">
            <!-- Stats Buffer -->
            <div class="stats-card">
                <h3>📊 Statistiques Buffer</h3>
                <div class="stat-item">
                    <span class="stat-label">Total factures buffer</span>
                    <span class="stat-value" id="total-buffer">-</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">En attente sync</span>
                    <span class="stat-value pending" id="pending-count">-</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Déjà synchronisées</span>
                    <span class="stat-value synced" id="synced-count">-</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Montant en attente</span>
                    <span class="stat-value pending" id="pending-amount">-</span>
                </div>
            </div>

            <!-- Stats Comptabilité -->
            <div class="stats-card">
                <h3>💰 Statistiques Comptabilité</h3>
                <div class="stat-item">
                    <span class="stat-label">Total factures comptabilité</span>
                    <span class="stat-value" id="total-comptability">-</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Clients uniques</span>
                    <span class="stat-value" id="unique-clients">-</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Factures urgentes</span>
                    <span class="stat-value urgent" id="urgent-count">-</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Progression dump</span>
                    <span class="stat-value" id="dump-progress">-</span>
                </div>
            </div>
        </div>

        <!-- Actions -->
        <div class="actions-section">
            <h3>🚀 Actions de Synchronisation</h3>
            <div class="action-buttons">
                <button class="btn btn-primary" onclick="dumpInvoices()">
                    📥 Dump Nouvelles Factures
                </button>
                <button class="btn btn-success" onclick="pushInvoices()">
                    🚀 Push vers App Principale
                </button>
                <button class="btn btn-warning" onclick="refreshStats()">
                    📊 Actualiser Stats
                </button>
            </div>

            <!-- Loading -->
            <div id="loading" class="loading">
                <div class="spinner"></div>
                <p>Traitement en cours...</p>
            </div>

            <!-- Progress Bar -->
            <div id="progress-container" style="display: none;">
                <div class="progress-bar">
                    <div class="progress-fill" id="progress-fill"></div>
                </div>
                <p id="progress-text" style="text-align: center; color: #6b7280;"></p>
            </div>

            <!-- Sync Log -->
            <div id="sync-log" class="sync-log">
                <h4>📋 Journal de synchronisation</h4>
                <div id="log-entries"></div>
            </div>
        </div>
    </div>

    <script>
        // Configuration
        const API_BASE = 'http://localhost:8080/sk/public/api/sync'; // laragon url
        const EXTERNAL_SYNC_URL = 'https://app-skd-cloud-api-prod.digita.sn/api/sage-sync/receive-invoices'; // À configurer

        // Éléments DOM
        const elements = {
            totalBuffer: document.getElementById('total-buffer'),
            pendingCount: document.getElementById('pending-count'),
            syncedCount: document.getElementById('synced-count'),
            pendingAmount: document.getElementById('pending-amount'),
            totalComptability: document.getElementById('total-comptability'),
            uniqueClients: document.getElementById('unique-clients'),
            urgentCount: document.getElementById('urgent-count'),
            dumpProgress: document.getElementById('dump-progress'),
            loading: document.getElementById('loading'),
            progressContainer: document.getElementById('progress-container'),
            progressFill: document.getElementById('progress-fill'),
            progressText: document.getElementById('progress-text'),
            syncLog: document.getElementById('sync-log'),
            logEntries: document.getElementById('log-entries')
        };

        // Utilitaires
        function showAlert(type, message) {
            const alertElement = document.getElementById(`alert-${type}`);
            alertElement.textContent = message;
            alertElement.style.display = 'block';
            setTimeout(() => {
                alertElement.style.display = 'none';
            }, 5000);
        }

        function showLoading() {
            elements.loading.style.display = 'block';
            document.querySelectorAll('.btn').forEach(btn => btn.disabled = true);
        }

        function hideLoading() {
            elements.loading.style.display = 'none';
            document.querySelectorAll('.btn').forEach(btn => btn.disabled = false);
        }

        function addLogEntry(message) {
            const timestamp = new Date().toLocaleTimeString();
            const logEntry = document.createElement('div');
            logEntry.className = 'log-entry';
            logEntry.textContent = `[${timestamp}] ${message}`;
            elements.logEntries.appendChild(logEntry);
            elements.syncLog.style.display = 'block';
            elements.syncLog.scrollTop = elements.syncLog.scrollHeight;
        }

        function formatAmount(amount) {
            return new Intl.NumberFormat('fr-FR', {
                style: 'currency',
                currency: 'XOF',
                minimumFractionDigits: 0
            }).format(amount);
        }

        // API Calls
        async function apiCall(endpoint, method = 'GET', data = null) {
            const options = {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                }
            };

            if (data) {
                options.body = JSON.stringify(data);
            }

            const response = await fetch(`${API_BASE}${endpoint}`, options);
            return await response.json();
        }

        // Fonctions principales
        async function refreshStats() {
            try {
                addLogEntry('Actualisation des statistiques...');
                const response = await apiCall('/stats');
                
                if (response.success) {
                    const stats = response.stats;
                    
                    // Stats buffer
                    elements.totalBuffer.textContent = stats.buffer.total_invoices;
                    elements.pendingCount.textContent = stats.buffer.pending_invoices;
                    elements.syncedCount.textContent = stats.buffer.synced_invoices;
                    elements.pendingAmount.textContent = formatAmount(stats.buffer.pending_amount);
                    
                    // Stats comptabilité
                    elements.totalComptability.textContent = stats.comptability.total_invoices;
                    elements.uniqueClients.textContent = stats.comptability.unique_clients;
                    elements.urgentCount.textContent = stats.buffer.urgent_pending || 0;
                    elements.dumpProgress.textContent = `${stats.sync_progress.dumped_percentage}%`;
                    
                    addLogEntry('✅ Statistiques actualisées');
                } else {
                    throw new Error(response.message);
                }
            } catch (error) {
                addLogEntry(`❌ Erreur: ${error.message}`);
                showAlert('error', `Erreur lors de l'actualisation: ${error.message}`);
            }
        }

        async function dumpInvoices() {
            try {
                showLoading();
                addLogEntry('🔄 Démarrage du dump des factures...');
                
                const response = await apiCall('/dump-invoices', 'POST');
                
                if (response.success) {
                    addLogEntry(`✅ Dump terminé: ${response.added_count} nouvelles factures`);
                    showAlert('success', `${response.added_count} nouvelles factures ajoutées au buffer`);
                    await refreshStats();
                } else {
                    throw new Error(response.message);
                }
            } catch (error) {
                addLogEntry(`❌ Erreur dump: ${error.message}`);
                showAlert('error', `Erreur lors du dump: ${error.message}`);
            } finally {
                hideLoading();
            }
        }

        async function pushInvoices() {
            try {
                showLoading();
                elements.progressContainer.style.display = 'block';
                addLogEntry('🚀 Démarrage de la synchronisation...');
                
                // Étape 1: Dump des nouvelles factures
                addLogEntry('📥 Étape 1: Dump des factures...');
                elements.progressFill.style.width = '20%';
                elements.progressText.textContent = 'Dump des nouvelles factures...';
                
                const dumpResponse = await apiCall('/dump-invoices', 'POST');
                if (!dumpResponse.success) {
                    throw new Error(`Erreur dump: ${dumpResponse.message}`);
                }
                addLogEntry(`✅ ${dumpResponse.added_count} nouvelles factures ajoutées`);
                
                // Étape 2: Récupération des factures non synchronisées
                addLogEntry('📋 Étape 2: Récupération des factures non sync...');
                elements.progressFill.style.width = '40%';
                elements.progressText.textContent = 'Récupération des factures à synchroniser...';
                
                const unsyncedResponse = await apiCall('/unsynced-invoices?limit=1000');
                if (!unsyncedResponse.success) {
                    throw new Error(`Erreur récupération: ${unsyncedResponse.message}`);
                }
                
                const invoicesData = unsyncedResponse.data;
                addLogEntry(`📊 ${invoicesData.length} factures à synchroniser`);
                
                if (invoicesData.length === 0) {
                    addLogEntry('ℹ️ Aucune facture à synchroniser');
                    showAlert('info', 'Aucune facture en attente de synchronisation');
                    return;
                }
                
                // Étape 3: Envoi vers l'application externe
                addLogEntry('🌐 Étape 3: Envoi vers l\'application externe...');
                elements.progressFill.style.width = '60%';
                elements.progressText.textContent = 'Envoi des données à l\'application externe...';
                
                const syncPayload = {
                    invoices: invoicesData,
                    timestamp: new Date().toISOString(),
                    source: 'laravel-sync-buffer'
                };
                
                // Envoyer vers l'application externe (à configurer)
                const externalResponse = await fetch(EXTERNAL_SYNC_URL, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-API-Key':'sk-digitanalh2HRpxrDVJ6bkk5Gy0iHehnf6i9Czhtiv7rG82REOENWLzK42Sv6qGW04cLz4j3hhyf44yJ3d8jShdudGl9NzvuGUfQHPkiHg1YtUL9dEWsbZ55yrJYY'
                    },
                    body: JSON.stringify(syncPayload)
                });

                console.log('Envoi vers l\'application externe:', EXTERNAL_SYNC_URL, syncPayload);
                console.log('Réponse de l\'application externe:', externalResponse);
                if (!externalResponse.ok) {
                    throw new Error(`Erreur application externe: ${externalResponse.status}`);
                }
                
                const externalResult = await externalResponse.json();
                addLogEntry(`✅ Réponse externe reçue: ${externalResult.message || 'OK'}`);
                
                // Étape 4: Marquage comme synchronisé
                addLogEntry('✅ Étape 4: Marquage des factures comme synchronisées...');
                elements.progressFill.style.width = '80%';
                elements.progressText.textContent = 'Mise à jour du statut des factures...';
                
                const invoiceIds = invoicesData.map(invoice => invoice.id);
                const markSyncedResponse = await apiCall('/mark-synced', 'POST', {
                    invoice_ids: invoiceIds,
                    notes: `Synchronisé via application externe le ${new Date().toLocaleString()}`
                });
                
                if (!markSyncedResponse.success) {
                    throw new Error(`Erreur marquage: ${markSyncedResponse.message}`);
                }
                
                // Finalisation
                elements.progressFill.style.width = '100%';
                elements.progressText.textContent = 'Synchronisation terminée!';
                addLogEntry(`🎉 Synchronisation réussie: ${invoiceIds.length} factures`);
                
                showAlert('success', `${invoiceIds.length} factures synchronisées avec succès!`);
                await refreshStats();
                
            } catch (error) {
                addLogEntry(`❌ Erreur synchronisation: ${error.message}`);
                showAlert('error', `Erreur lors de la synchronisation: ${error.message}`);
            } finally {
                hideLoading();
                setTimeout(() => {
                    elements.progressContainer.style.display = 'none';
                    elements.progressFill.style.width = '0%';
                }, 3000);
            }
        }

        // Initialisation
        document.addEventListener('DOMContentLoaded', function() {
            refreshStats();
            addLogEntry('🚀 Interface de synchronisation initialisée');
            
            // Actualisation automatique toutes les 30 secondes
            setInterval(refreshStats, 30000);
        });
    </script>
</body>
</html>