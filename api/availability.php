<?php
// JSON availability for one date: GET api/availability.php?date=YYYY-MM-DD
// Only public information (open/held/booked) — no names or codes.
require __DIR__ . '/../app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$date = get('date');
if (!valid_date($date)) {
    http_response_code(422);
    echo json_encode(['error' => 'Use date=YYYY-MM-DD']);
    exit;
}
$map = ReservationService::availabilityMonth((int) substr($date, 0, 4), (int) substr($date, 5, 2));
$out = [];
foreach (q_all("SELECT area_id, area_name FROM resort_areas WHERE status = 'ACTIVE' ORDER BY sort_order, area_id") as $a) {
    $out[] = ['area_id' => (int) $a['area_id'], 'area' => $a['area_name'],
              'daytime' => $map[$date][$a['area_id']]['DAYTIME'] ?? 'closed', 'overnight' => $map[$date][$a['area_id']]['OVERNIGHT'] ?? 'closed'];
}
echo json_encode(['date' => $date, 'areas' => $out]);
