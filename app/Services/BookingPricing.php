<?php
declare(strict_types=1);

namespace app\Services;

use app\Core\Database;

final class BookingPricing
{
    public static function forCounselor(int $counselorId): array
    {
        if ($counselorId <= 0) return [];
        return Database::fetchAll(
            'SELECT s.id, s.name, s.slug, s.summary, s.format, s.duration,
                    s.price_from AS service_price_from, s.price_to AS service_price_to, s.price_note, cs.price_cents,
                    CASE WHEN cs.price_cents>0 THEN cs.price_cents/100 ELSE s.price_from END AS price_from,
                    CASE WHEN cs.price_cents>0 THEN cs.price_cents/100
                         WHEN s.price_to>0 THEN s.price_to WHEN s.price_from>0 THEN s.price_from END AS price_to,
                    GROUP_CONCAT(cs.counselor_id ORDER BY cs.counselor_id) AS counselor_ids
             FROM counselor_services cs
             JOIN services s ON s.id=cs.service_id AND s.status=1
             JOIN counselors c ON c.id=cs.counselor_id AND c.status=1
             WHERE cs.counselor_id=?
             GROUP BY s.id
             ORDER BY s.sort, s.id',
            [$counselorId]
        );
    }

    public static function serviceForCounselor(int $counselorId, int $serviceId): ?array
    {
        if ($counselorId <= 0 || $serviceId <= 0) return null;
        return Database::fetch(
            'SELECT s.id, s.name, s.price_from AS service_price_from, s.price_to AS service_price_to, cs.price_cents,
                    CASE WHEN cs.price_cents>0 THEN cs.price_cents/100 ELSE s.price_from END AS price_from,
                    CASE WHEN cs.price_cents>0 THEN cs.price_cents/100
                         WHEN s.price_to>0 THEN s.price_to WHEN s.price_from>0 THEN s.price_from END AS price_to
             FROM counselor_services cs
             JOIN services s ON s.id=cs.service_id AND s.status=1
             JOIN counselors c ON c.id=cs.counselor_id AND c.status=1
             WHERE cs.counselor_id=? AND cs.service_id=?',
            [$counselorId, $serviceId]
        );
    }

    public static function offered(int $counselorId, int $serviceId): bool
    {
        return self::serviceForCounselor($counselorId, $serviceId) !== null;
    }

    public static function priceCents(int $counselorId, string $date, string $slotLabel, int $serviceId = 0): ?int
    {
        $service = null;
        if ($serviceId > 0) {
            $service = self::serviceForCounselor($counselorId, $serviceId);
            if (!$service) return null;
        }
        $c = Database::fetch('SELECT fee_from, fee_override FROM counselors WHERE id=? AND status=1', [$counselorId]);
        if (!$c) return null;
        $ts = strtotime($date);
        $weekday = $ts === false ? 0 : (int)date('N', $ts);
        $start5 = substr($slotLabel, 0, 5);
        $override = json_decode((string)($c['fee_override'] ?? ''), true);
        $genericOverride = 0;
        if (is_array($override)) {
            foreach ($override as $row) {
                if ((int)($row['weekday'] ?? 0) !== $weekday || (string)($row['slot'] ?? '') !== $start5) continue;
                $cents = (int)($row['price_cents'] ?? 0);
                if ($cents <= 0) continue;
                if ($serviceId > 0 && (int)($row['service_id'] ?? 0) === $serviceId) return $cents;
                if ((int)($row['service_id'] ?? 0) === 0) $genericOverride = $cents;
            }
        }
        if ($service !== null && (int)($service['price_cents'] ?? 0) > 0) return (int)$service['price_cents'];
        if ($genericOverride > 0) return $genericOverride;
        $fee = $service !== null ? (float)($service['price_from'] ?? 0) : (float)($c['fee_from'] ?? 0);
        return $fee > 0 ? (int)round($fee * 100) : null;
    }

    /** @return array<int,array{from:?float,to:?float,source:string,service_count:int}> */
    public static function ranges(array $counselorIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $counselorIds), static fn(int $id): bool => $id > 0)));
        if (!$ids) return [];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::fetchAll(
            "SELECT cs.counselor_id,
                    MIN(CASE WHEN cs.price_cents>0 THEN cs.price_cents/100 WHEN s.price_from>0 THEN s.price_from END) AS price_from,
                    MAX(CASE WHEN cs.price_cents>0 THEN cs.price_cents/100
                             WHEN s.price_to>0 THEN s.price_to WHEN s.price_from>0 THEN s.price_from END) AS price_to,
                    COUNT(*) AS service_count
             FROM counselor_services cs
             JOIN services s ON s.id=cs.service_id AND s.status=1
             WHERE cs.counselor_id IN ($placeholders)
             GROUP BY cs.counselor_id",
            $ids
        );
        $ranges = [];
        foreach ($rows as $row) {
            $id = (int)$row['counselor_id'];
            $ranges[$id] = [
                'from' => $row['price_from'] === null ? null : (float)$row['price_from'],
                'to' => $row['price_to'] === null ? null : (float)$row['price_to'],
                'source' => 'service',
                'service_count' => (int)$row['service_count'],
            ];
        }
        $missing = array_values(array_diff($ids, array_keys($ranges)));
        if ($missing) {
            $placeholders = implode(',', array_fill(0, count($missing), '?'));
            $fallback = Database::fetchAll("SELECT id, fee_from, fee_to FROM counselors WHERE id IN ($placeholders) AND status=1", $missing);
            foreach ($fallback as $row) {
                $ranges[(int)$row['id']] = [
                    'from' => (float)$row['fee_from'] > 0 ? (float)$row['fee_from'] : null,
                    'to' => (float)($row['fee_to'] ?? 0) > 0 ? (float)$row['fee_to'] : null,
                    'source' => 'counselor',
                    'service_count' => 0,
                ];
            }
        }
        return $ranges;
    }

    public static function offeredServices(): array
    {
        return Database::fetchAll(
            'SELECT s.id, s.name, s.price_from, s.price_to,
                    GROUP_CONCAT(DISTINCT cs.counselor_id ORDER BY cs.counselor_id) AS counselor_ids
             FROM services s
             JOIN counselor_services cs ON cs.service_id=s.id
             JOIN counselors c ON c.id=cs.counselor_id AND c.status=1
             WHERE s.status=1
             GROUP BY s.id
             ORDER BY s.sort, s.id'
        );
    }

    public static function counselorsForService(int $serviceId): array
    {
        if ($serviceId <= 0) return [];
        return Database::fetchAll(
            'SELECT c.name, c.slug, c.title
             FROM counselor_services cs
             JOIN counselors c ON c.id=cs.counselor_id AND c.status=1
             JOIN services s ON s.id=cs.service_id AND s.status=1
             WHERE cs.service_id=?
             ORDER BY c.sort, c.id LIMIT 12',
            [$serviceId]
        );
    }

    public static function formatRange(?float $from, ?float $to): string
    {
        if ($from === null || $from <= 0) return '收费面议';
        $text = '¥' . rtrim(rtrim(number_format($from, 2, '.', ''), '0'), '.');
        if ($to !== null && $to > 0 && abs($to - $from) > 0.001) $text .= '–' . rtrim(rtrim(number_format($to, 2, '.', ''), '0'), '.');
        return $text;
    }

    public static function formatCents(?int $cents): string
    {
        return $cents !== null && $cents > 0
            ? '¥' . rtrim(rtrim(number_format($cents / 100, 2, '.', ''), '0'), '.')
            : '收费面议';
    }
}
