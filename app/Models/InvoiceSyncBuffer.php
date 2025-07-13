<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class InvoiceSyncBuffer extends Model
{
    use HasFactory;

    protected $table = 'invoice_sync_buffer';

    protected $fillable = [
        'invoice_number',
        'invoice_reference',
        'client_code',
        'client_name',
        'amount',
        'invoice_total',
        'invoice_date',
        'due_date',
        'sync_status',
        'synced_at',
        'sync_notes',
        'sync_batch_id',
        'client_phone',
        'client_email',
        'client_address',
        'commercial_contact',
        'overdue_category',
        'days_overdue',
        'priority',
        'source_entry_id',
        'description',
        'source_created_at',
        'source_updated_at',
        'sync_attempts',
        'last_sync_attempt',
        'last_error_message'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'invoice_total' => 'decimal:2',
        'invoice_date' => 'date',
        'due_date' => 'date',
        'synced_at' => 'datetime',
        'source_created_at' => 'datetime',
        'source_updated_at' => 'datetime',
        'last_sync_attempt' => 'datetime',
        'days_overdue' => 'integer',
        'sync_attempts' => 'integer'
    ];

    // Constantes pour les statuts
    const STATUS_PENDING = 'pending';
    const STATUS_SYNCED = 'synced';
    const STATUS_FAILED = 'failed';
    const STATUS_EXCLUDED = 'excluded';

    // Constantes pour les priorités
    const PRIORITY_FUTURE = 'FUTURE';
    const PRIORITY_NORMAL = 'NORMAL';
    const PRIORITY_MEDIUM = 'MEDIUM';
    const PRIORITY_HIGH = 'HIGH';
    const PRIORITY_URGENT = 'URGENT';

    public $timestamps = false;

    // Scopes pour faciliter les requêtes
    public function scopePending(Builder $query): Builder
    {
        return $query->where('sync_status', self::STATUS_PENDING);
    }

    public function scopeSynced(Builder $query): Builder
    {
        return $query->where('sync_status', self::STATUS_SYNCED);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('sync_status', self::STATUS_FAILED);
    }

    public function scopeByPriority(Builder $query, string $priority): Builder
    {
        return $query->where('priority', $priority);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereIn('overdue_category', [
            'OVERDUE_1_30',
            'OVERDUE_31_60',
            'OVERDUE_61_90',
            'OVERDUE_90_PLUS'
        ]);
    }

    public function scopeUrgent(Builder $query): Builder
    {
        return $query->whereIn('priority', [self::PRIORITY_HIGH, self::PRIORITY_URGENT]);
    }

    public function scopeByBatch(Builder $query, string $batchId): Builder
    {
        return $query->where('sync_batch_id', $batchId);
    }

    // Méthodes utilitaires
    public function markAsSynced(string $notes = null): void
    {
        $this->update([
            'sync_status' => self::STATUS_SYNCED,
            'synced_at' => now(),
            'sync_notes' => $notes
        ]);
    }

    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'sync_status' => self::STATUS_FAILED,
            'sync_attempts' => $this->sync_attempts + 1,
            'last_sync_attempt' => now(),
            'last_error_message' => $errorMessage
        ]);
    }

    public function resetForRetry(): void
    {
        $this->update([
            'sync_status' => self::STATUS_PENDING,
            'last_error_message' => null
        ]);
    }

    // Accesseurs
    public function getFormattedAmountAttribute(): string
    {
        return number_format($this->amount, 0, ',', ' ') . ' FCFA';
    }

    public function getIsOverdueAttribute(): bool
    {
        return in_array($this->overdue_category, [
            'OVERDUE_1_30',
            'OVERDUE_31_60',
            'OVERDUE_61_90',
            'OVERDUE_90_PLUS'
        ]);
    }

    public function getIsUrgentAttribute(): bool
    {
        return in_array($this->priority, [self::PRIORITY_HIGH, self::PRIORITY_URGENT]);
    }

    public function getDaysOverdueTextAttribute(): string
    {
        if ($this->days_overdue <= 0) {
            return 'À échoir';
        }
        return $this->days_overdue . ' jour' . ($this->days_overdue > 1 ? 's' : '') . ' de retard';
    }
}
