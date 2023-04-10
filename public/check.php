<?php

use vakata\database\DB;

require_once __DIR__ . '/../bootstrap.php';

$db = new DB(DATABASE);
$log = function (
    ?int $udi = null,
    ?int $sik = null,
    ?int $mode = null,
    string $request = '',
    string $response = '',
    int $error = 0
) use ($db) : void {
    $db->query(
        'INSERT INTO api_log (created, type, udi, sik, mode, request, response, err, ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [ date('Y-m-d H:i:s'), 'check', $udi, $sik, $mode, $request, $response, $error, $_SERVER['REMOTE_ADDR'] ?? '' ]
    );
};
try {
    $data = file_get_contents('php://input');
    if (!strlen($data)) {
        throw new RuntimeException('Използвайте POST', 400);
    }
    $data = @json_decode($data, true);
    if (!$data) {
        throw new RuntimeException('Използвайте JSON', 400);
    }
    if (
        !isset($data['udi']) ||
        !preg_match('(^\d{6,8}$)', $data['udi']) ||
        !($device = $db->one('SELECT udi, install_key, registered FROM devices WHERE udi = ?', [ $data['udi'] ]))
    ) {
        throw new RuntimeException('Невалиден идентификатор на устройство', 400);
    }

    if (!isset($data['mode'])) {
        try {
            if (!$device['registered']) {
                throw new RuntimeException('Устройството не е регистрирано', 400);
            }
            $mdm = $db->one("SELECT configurationid, info FROM hmdm_public.devices WHERE number = ?", $device['udi']);
            if (!$mdm) {
                throw new RuntimeException('Устройството не е налично в МДМ', 400);
            }
            if (!in_array($mdm['configurationid'], [8,11])) {
                throw new RuntimeException('Грешна конфигурация в МДМ', 400);
            }
            if (!strpos($mdm['info'], 'io.uslugi.streamer')) {
                throw new RuntimeException('Липсва инсталация на SIK Streamer', 400);
            }
            // $mdm['info'] = json_decode($mdm['info'], true);
            // if (!isset($mdm['info']['kioskMode']) || !$mdm['info']['kioskMode']) {
            //     throw new RuntimeException('Липсва киоск режим', 400);
            // }
            $response = [ 'result' => 'OK' ];
        } catch (RuntimeException $e) {
            $response = [ 'result' => $e->getMessage() ];
        } catch (Throwable $e) {
            $response = [ 'result' => 'Моля, опитайте отново' ];
        }
    } else {
        if (
            !($mode = $db->one(
                'SELECT mode, name FROM modes WHERE name = ? AND enabled = 1 AND (enabled_to IS NULL OR enabled_to > ?) AND (enabled_from IS NULL OR enabled_from < ?)',
                [ $data['mode'], date('Y-m-d H:i:s'), date('Y-m-d H:i:s') ]
            ))
        ) {
            throw new RuntimeException('Невалиден режим на работа', 400);
        }
        $election = $db->one("SELECT election FROM elections WHERE enabled = 1 ORDER BY election DESC LIMIT 1");
        $stream = $db->one(
            'SELECT
                streams.stream,
                streams.url,
                servers.inner_host,
                siks.num,
                siks.sik
            FROM
                streams
            JOIN
                servers ON servers.server = streams.server
            LEFT JOIN
                siks ON siks.sik = streams.sik AND siks.election = streams.election
            WHERE
                streams.udi = ? AND
                streams.mode = ?
            ORDER BY
                streams.created DESC
            LIMIT 1',
            [ $device['udi'], $mode['mode'] ]
        );
        if (!$stream) {
            throw new RuntimeException('Несъществуващ стрийм', 400);
        }
        if (!$stream['inner_host']) {
            throw new RuntimeException('Невалидна конфигурация на стрийминг сървър', 500);
        }
        if ($mode['name'] === 'test-setup') {
            $url = 'https://' . $stream['inner_host'] . '/ingest-check?name=' . $device['udi'] . '&mode=test-setup';
        } else {
            if (!$stream['num']) {
                throw new RuntimeException('Невалиден СИК', 400);
            }
            $url = 'https://' . $stream['inner_host'] . '/ingest-check?name=' . $stream['num'] . '&mode=' . $mode['name'];
        }
        try {
            $status = file_get_contents($url, false, stream_context_create([
                'ssl' => [ 'verify_peer' => false, 'verify_peer_name' => false ]
            ]));
            $db->query(
                'UPDATE streams SET started = ?, ended = ? WHERE stream = ?',
                [ date('Y-m-d H:i:s', time() - 10), date('Y-m-d H:i:s'), $stream['stream'] ]
            );
            if ($mode['name'] === 'test-setup') {
                $db->query(
                    "UPDATE devices SET registered = ? WHERE udi = ? AND registered IS NULL",
                    [ date('Y-m-d H:i:s'), $device['udi'] ]
                );
            }
            if ($mode['name'] === 'test-sik') {
                $db->query(
                    'INSERT INTO devices_elections (udi, election, sik, registered) VALUES (?, ?, ?, ?)
                    ON CONFLICT (udi, election, sik) DO UPDATE
                    SET registered = ?',
                    [ $device['udi'], $election, $stream['sik'], date('Y-m-d H:i:s'), date('Y-m-d H:i:s') ]
                );
            }
            $response = [ 'result' => 'OK' ];
        } catch (\Throwable $e) {
            throw new RuntimeException('Няма намерен запис', 400);
        }
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    $log(
        $device['udi'] ?? null,
        $stream['sik'] ?? null,
        $mode['mode'] ?? null,
        json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        0
    );
} catch (RuntimeException $e) {
    header(
        'Content-Type: application/json; charset=utf-8',
        true,
        $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500
    );
    echo json_encode([ 'error' => $e->getMessage() ], JSON_UNESCAPED_UNICODE);
    $log(
        $device['udi'] ?? null,
        $stream['sik'] ?? null,
        $mode['mode'] ?? null,
        json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        json_encode(
            [ 'error' => $e->getMessage() ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        ),
        1
    );
} catch (Throwable $e) {
	@file_put_contents(__DIR__ . '/../err.log', date('r'). ' ' . $e->getMessage() . "\n", FILE_APPEND);
	header(
        'Content-Type: application/json; charset=utf-8',
        true,
        500
    );
    echo json_encode([ 'error' => 'Internal Server Error' ]);
    $log(
        $device['udi'] ?? null,
        $stream['sik'] ?? null,
        $mode['mode'] ?? null,
        json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        json_encode(
            [ 'error' => 'Internal Server Error' ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        ),
        1
    );
}
