<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * RecordNote — Polymorphic note / activity log entry.
 *
 * Used for both acceptance_requests and transactions.
 * Each note captures who did what and any free-text comment.
 *
 * @property int    $id
 * @property string $entity_type  'acceptance' | 'transaction'
 * @property int    $entity_id
 * @property int    $user_id
 * @property string $note         Free-text note
 * @property string|null $action  e.g. 'created', 'viewed', 'approved', 'voided', 'edited'
 * @property string $created_at
 *
 * @property-read User $user
 */
class RecordNote extends Model
{
    protected $table      = 'record_notes';
    public    $timestamps = false; // we manage created_at manually with DEFAULT CURRENT_TIMESTAMP

    protected $fillable = [
        'entity_type',
        'entity_id',
        'user_id',
        'note',
        'action',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopeForAcceptance($query, int $id)
    {
        return $query->where('entity_type', 'acceptance')->where('entity_id', $id);
    }

    public function scopeForTransaction($query, int $id)
    {
        return $query->where('entity_type', 'transaction')->where('entity_id', $id);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Quick factory: insert a note in one call.
     */
    public static function log(
        string $entityType,
        int    $entityId,
        int    $userId,
        string $note,
        string $action = 'note'
    ): self {
        $record = self::create([
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'user_id'     => $userId,
            'note'        => $note,
            'action'      => $action,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        // Refresh so created_at is cast as a Carbon instance
        return $record->fresh() ?? $record;
    }

    /**
     * Human-readable action label with icon for UI.
     */
    public static function actionBadge(string $action = 'note'): array
    {
        return match($action) {
            'created'  => ['label' => 'Created',  'icon' => 'add_circle',    'color' => 'emerald'],
            'viewed'   => ['label' => 'Viewed',   'icon' => 'visibility',    'color' => 'slate'],
            'edited'   => ['label' => 'Edited',   'icon' => 'edit',          'color' => 'blue'],
            'approved' => ['label' => 'Approved', 'icon' => 'verified',      'color' => 'emerald'],
            'voided'   => ['label' => 'Voided',   'icon' => 'block',         'color' => 'rose'],
            'emailed'  => ['label' => 'Emailed',  'icon' => 'mail',          'color' => 'violet'],
            'resent'   => ['label' => 'Resent',   'icon' => 'send',          'color' => 'amber'],
            'promoted' => ['label' => 'Promoted', 'icon' => 'upgrade',       'color' => 'indigo'],
            default    => ['label' => 'Note',     'icon' => 'sticky_note_2', 'color' => 'slate'],
        };
    }
}
