<?php

require_once __DIR__ . '/db.php';

function calculateAward(int $basePoints, string $completionType): int
{
    $isSelf = str_contains($completionType, 'self_initiated');
    $isExcellent = str_contains($completionType, 'excellent');

    $points = $isSelf ? $basePoints * 2 : $basePoints;

    if ($isExcellent) {
        $points += 1;
    }

    return $points;
}

function recordPointEvent(
    int $childId,
    ?int $behaviorId,
    string $behaviorLabel,
    int $basePoints,
    string $completionType,
    string $eventDate,
    ?string $note
): array {
    $pdo = db();

    $child = $pdo->prepare('SELECT * FROM children WHERE id = ?');
    $child->execute([$childId]);
    $child = $child->fetch();

    if (! $child) {
        throw new Exception('孩子不存在');
    }

    $today = date('Y-m-d');

    if ($child['last_score_date'] !== $today) {
        $reset = $pdo->prepare(
            'UPDATE children
            SET daily_points_today = 0,
                last_score_date = ?
            WHERE id = ?'
        );
        $reset->execute([$today, $childId]);

        $child['daily_points_today'] = 0;
        $child['last_score_date'] = $today;
    }

    $awarded = calculateAward($basePoints, $completionType);
    $remaining = max(0, $child['daily_cap'] - $child['daily_points_today']);
    $toBalance = min($awarded, $remaining);
    $toPool = $awarded - $toBalance;

    $stmt = $pdo->prepare(
        'INSERT INTO point_events
         (child_id, behavior_id, behavior_label, base_points, awarded_points, overflow_points, completion_type, event_date, note)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $childId, $behaviorId, $behaviorLabel, $basePoints,
        $awarded, $toPool, $completionType, $eventDate, $note
    ]);

    $update = $pdo->prepare(
        'UPDATE children
         SET normal_balance = normal_balance + ?,
             daily_points_today = daily_points_today + ?,
             quarterly_pool = quarterly_pool + ?
         WHERE id = ?'
    );
    $update->execute([$toBalance, $awarded, $toPool, $childId]);

    return [
        'awarded' => $awarded,
        'to_balance' => $toBalance,
        'to_pool' => $toPool
    ];
}
