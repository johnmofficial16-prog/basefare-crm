<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * CentreService — resolves which "centre" (team) each agent belongs to.
 *
 * Centres are derived LIVE from the reporting hierarchy, with a manual override
 * layer (so admins can add/remove individuals):
 *   - JSR  = the JSR manager (Thomas) + everyone under him (recursive).
 *   - MOH  = the MOH manager (Cyrus) + everyone under him (recursive),
 *            MINUS the DMR members (who currently still report to Cyrus).
 *   - DMR  = an explicit member list (no manager of its own).
 *   - per-user overrides win over all of the above.
 *
 * Priority when a user matches several rules: override > DMR > JSR > MOH.
 *
 * Configuration lives in `system_config` (keys under "centre.*") and is managed
 * from the Centre Settings page — no production IDs are hardcoded.
 */
class CentreService
{
    const DMR = 'DMR';
    const MOH = 'MOH';
    const JSR = 'JSR';
    const ALL = [self::DMR, self::MOH, self::JSR];

    const LABELS = [
        self::DMR => 'DMR Team',
        self::MOH => 'MOH Team',
        self::JSR => 'JSR Team',
    ];

    /** Brand-consistent colours for charts/badges, keyed by centre. */
    const COLORS = [
        self::DMR => '#2563eb', // blue
        self::MOH => '#7c3aed', // violet
        self::JSR => '#0d9488', // teal
        'UNASSIGNED' => '#94a3b8',
    ];

    // =========================================================================
    // CONFIG (system_config)
    // =========================================================================

    public function config(): array
    {
        $rows = DB::table('system_config')
            ->whereIn('key', [
                'centre.moh_manager_id', 'centre.jsr_manager_id',
                'centre.dmr_member_ids', 'centre.overrides',
            ])->pluck('value', 'key');

        return [
            'moh_manager_id' => (int) ($rows['centre.moh_manager_id'] ?? 0),
            'jsr_manager_id' => (int) ($rows['centre.jsr_manager_id'] ?? 0),
            'dmr_member_ids' => $this->decodeIds($rows['centre.dmr_member_ids'] ?? '[]'),
            'overrides'      => $this->decodeMap($rows['centre.overrides'] ?? '{}'),
        ];
    }

    public function saveConfig(array $cfg, int $adminId): void
    {
        $this->put('centre.moh_manager_id', (string) (int) ($cfg['moh_manager_id'] ?? 0), $adminId);
        $this->put('centre.jsr_manager_id', (string) (int) ($cfg['jsr_manager_id'] ?? 0), $adminId);
        $this->put('centre.dmr_member_ids', json_encode(array_values(array_unique(array_map('intval', $cfg['dmr_member_ids'] ?? [])))), $adminId);

        // Overrides: {userId: centre}, only valid centres kept.
        $ovr = [];
        foreach (($cfg['overrides'] ?? []) as $uid => $centre) {
            if (in_array($centre, self::ALL, true)) {
                $ovr[(int) $uid] = $centre;
            }
        }
        $this->put('centre.overrides', json_encode((object) $ovr), $adminId);
    }

    public function isConfigured(): bool
    {
        $c = $this->config();
        return $c['moh_manager_id'] > 0 || $c['jsr_manager_id'] > 0 || !empty($c['dmr_member_ids']);
    }

    // =========================================================================
    // RESOLUTION
    // =========================================================================

    /**
     * Map of [user_id => centre] for everyone who belongs to a centre.
     * Users not in any centre simply aren't present in the map.
     */
    public function resolveMap(): array
    {
        $cfg = $this->config();

        $jsrTeam = $cfg['jsr_manager_id'] ? $this->teamWithManager($cfg['jsr_manager_id']) : [];
        $mohTeam = $cfg['moh_manager_id'] ? $this->teamWithManager($cfg['moh_manager_id']) : [];
        $dmr     = $cfg['dmr_member_ids'];

        $map = [];
        // Lowest priority first; higher priority overwrites.
        foreach ($mohTeam as $id) $map[$id] = self::MOH;
        foreach ($jsrTeam as $id) $map[$id] = self::JSR;
        foreach ($dmr as $id)     $map[$id] = self::DMR;
        foreach ($cfg['overrides'] as $id => $centre) $map[(int) $id] = $centre;

        return $map;
    }

    /**
     * [centre => [user_id, ...]] for the three centres. Pass $includeUnassigned
     * to add an 'UNASSIGNED' bucket of active agents in no centre.
     */
    public function agentsByCentre(bool $includeUnassigned = false): array
    {
        $map = $this->resolveMap();
        $out = [self::DMR => [], self::MOH => [], self::JSR => []];
        foreach ($map as $uid => $centre) {
            if (isset($out[$centre])) $out[$centre][] = (int) $uid;
        }

        if ($includeUnassigned) {
            $assigned = array_keys($map);
            $unassigned = User::whereIn('role', [User::ROLE_AGENT, User::ROLE_SUPERVISOR, User::ROLE_MANAGER, User::ROLE_CSA])
                ->where('status', User::STATUS_ACTIVE)
                ->whereNull('deleted_at')
                ->when(!empty($assigned), fn($q) => $q->whereNotIn('id', $assigned))
                ->pluck('id')->map(fn($v) => (int) $v)->all();
            $out['UNASSIGNED'] = $unassigned;
        }

        return $out;
    }

    /** The centre a single user belongs to, or null. */
    public function centreFor(int $userId): ?string
    {
        return $this->resolveMap()[$userId] ?? null;
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /** A manager's id plus all of their reports, recursively. */
    private function teamWithManager(int $managerId): array
    {
        return array_values(array_unique(array_merge([$managerId], $this->descendants($managerId))));
    }

    /** All descendant user ids under a manager (multi-level), excluding self. */
    private function descendants(int $managerId): array
    {
        // Load the whole reports_to graph once, then BFS.
        $children = User::whereNotNull('reports_to_id')
            ->whereNull('deleted_at')
            ->get(['id', 'reports_to_id'])
            ->groupBy('reports_to_id');

        $out = [];
        $queue = [$managerId];
        while ($queue) {
            $cur = array_pop($queue);
            foreach (($children[$cur] ?? []) as $child) {
                $cid = (int) $child->id;
                if (!in_array($cid, $out, true)) {
                    $out[] = $cid;
                    $queue[] = $cid;
                }
            }
        }
        return $out;
    }

    private function put(string $key, string $value, int $adminId): void
    {
        $now = date('Y-m-d H:i:s');
        $exists = DB::table('system_config')->where('key', $key)->exists();
        if ($exists) {
            DB::table('system_config')->where('key', $key)->update([
                'value' => $value, 'updated_by' => $adminId, 'updated_at' => $now,
            ]);
        } else {
            DB::table('system_config')->insert([
                'key' => $key, 'value' => $value, 'updated_by' => $adminId, 'updated_at' => $now,
            ]);
        }
    }

    private function decodeIds(string $json): array
    {
        $arr = json_decode($json, true);
        return is_array($arr) ? array_values(array_map('intval', $arr)) : [];
    }

    private function decodeMap(string $json): array
    {
        $arr = json_decode($json, true);
        if (!is_array($arr)) return [];
        $out = [];
        foreach ($arr as $k => $v) {
            if (in_array($v, self::ALL, true)) $out[(int) $k] = $v;
        }
        return $out;
    }
}
