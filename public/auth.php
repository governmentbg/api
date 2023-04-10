<?php

use vakata\database\DB;

require_once __DIR__ . '/../bootstrap.php';

$db = new DB(DATABASE);

$getStreamUrl = function (array $mode, array $server, string $name, string $udi) : string
{
    $expires = time() + 6 * 3600;
    $token = str_replace(
        ['+', '/', '='],
        ['-', '_', ''],
        base64_encode(
            openssl_digest(
                $server['key'] . $mode['name'] . $name . $expires,
                'md5',
                true
            )
        )
    );

    return 'rtmp://'.rtrim($server['host'], '/') . ':5689/' . $mode['name'] . '/' . $name .
        '?token=' . $token . '&expires=' . $expires . '&udi=' . $udi;
};
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
        [ date('Y-m-d H:i:s'), 'auth', $udi, $sik, $mode, $request, $response, $error, $_SERVER['REMOTE_ADDR'] ?? '' ]
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
        !isset($data['mode']) ||
        !($mode = $db->one(
            'SELECT mode, name FROM modes WHERE name = ? AND enabled = 1 AND (enabled_to IS NULL OR enabled_to > ?) AND (enabled_from IS NULL OR enabled_from < ?)',
            [ $data['mode'], date('Y-m-d H:i:s'), date('Y-m-d H:i:s') ]
        ))
    ) {
        $sik = [];
        $sik['sik'] = isset($data['sik']) ?
            $db->one(
                'SELECT
                    sik
                FROM
                    siks
                WHERE
                    num = ? AND
                    election = 1 AND
                    video = 1',
                [ $data['sik'] ]
            ) :
            null;
        $device = [];
        $device['udi'] = isset($data['udi']) ?
            $db->one('SELECT udi FROM devices WHERE udi = ?', [ $data['udi'] ]) :
            null;

        throw new RuntimeException('Невалиден режим на работа', 400);
    }
    if (
        !isset($data['udi']) ||
        !preg_match('(^\d{6,8}$)', $data['udi']) ||
        !($device = $db->one('SELECT udi, install_key, registered FROM devices WHERE udi = ?', [ $data['udi'] ]))
    ) {
        throw new RuntimeException('Невалиден идентификатор на устройство', 400);
    }
    $election = $db->one('SELECT slug, keyenc, election FROM elections WHERE enabled = 1 ORDER BY election DESC LIMIT 1');
    if (!$election) {
        throw new RuntimeException('Невалидна конфигурация на стрийминг сървър', 500);
    }
    
    $field = $mode['name'] === 'test-setup' ?
        'key_setup' :
        (
            $mode['name'] === 'test-sik' ?
                'key_sik' :
                'key_real'
        );
    $server = $db->one(
        'SELECT
            servers.server,
            servers.host,
            servers.' . $field . ' as key,
            (
                SELECT
                    COUNT(streams.*)
                FROM
                    streams
                WHERE
                    streams.server = servers.server AND
                    streams.mode = ? AND
                    streams.ended IS NULL
            ) as cnt
        FROM
            servers
        WHERE
            servers.enabled  = 1
        ORDER BY
            cnt ASC
        LIMIT 1',
        [ $mode['mode'] ]
    );

    if (!$server) {
        throw new RuntimeException('Няма свободен сървър', 500);
    }

    if ($mode['name'] === 'test-setup') {
        if (
            !isset($data['key']) ||
            $device['install_key'] !== $data['key']
        ) {
            throw new RuntimeException('Невалиден инсталационен ключ', 400);
        }
        $response = [
            'stream_url'    => $getStreamUrl(
                $mode,
                $server,
                (string) $device['udi'],
                (string) $device['udi']
            ),
            'election'      => $election['slug'],
            'keyenc'        => $election['keyenc']
        ];
    } else {
        if (
            !isset($data['sik']) ||
            !(
                $sik = $db->one(
                    'SELECT
                        sik,
                        num,
                        ' . ($mode['name'] === 'test-sik' ? 'test_key' : 'prod_key') . ' as key
                    FROM
                        siks
                    WHERE
                        num = ? AND
                        election = ? AND
                        video = 1',
                    [ $data['sik'], $election['election'] ]
                )
            )
        ) {
            throw new RuntimeException('Невалиден СИК', 400);
        }
        if (!isset($data['key']) || $sik['key'] !== $data['key']) {
            throw new RuntimeException('Невалиден СИК ключ', 400);
        }
        if ($mode['name'] === 'real') {
            $db->query(
                'INSERT INTO devices_elections (udi, election, sik, registered) VALUES (?, ?, ?, ?)
                ON CONFLICT (udi, election, sik) DO UPDATE
                SET registered = ?',
                [ (string)$device['udi'], $election['election'], $sik['sik'], date('Y-m-d H:i:s'), date('Y-m-d H:i:s') ]
            );
        }
        $response = [
            'stream_url'    => $getStreamUrl(
                $mode,
                $server,
                (string) $sik['num'],
                (string) $device['udi']
            ),
            'election'      => $election['slug']
        ];
        $db->query('UPDATE streams SET ended = ? WHERE sik = ? AND ended IS NULL', [ date('Y-m-d H:i:s'), $sik['sik'] ]);
    }
    $db->query('UPDATE streams SET ended = ? WHERE udi = ? AND ended IS NULL', [ date('Y-m-d H:i:s'), $device['udi'] ]);
    $db->query(
        'INSERT INTO
            streams (udi, election, sik, mode, created, started, ended, url, server)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $device['udi'],
            $election['election'],
            ($sik['sik'] ?? null),
            $mode['mode'],
            date('Y-m-d H:i:s'),
            null,
            null,
            $response['stream_url'],
            $server['server']
        ]
    );

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response);

    $log(
        $device['udi'] ?? null,
        $sik['sik'] ?? null,
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
    echo json_encode([ 'error' => $e->getMessage() ]);
    $log(
        $device['udi'] ?? null,
        $sik['sik'] ?? null,
        $mode['mode'] ?? null,
        json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        json_encode(
            [ 'error' => $e->getMessage() ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        ),
        1
    );
} catch (Throwable $e) {
	@file_put_contents(__DIR__ . '/../err.log', date('r') . ' ' . $e->getMessage() . "\n", FILE_APPEND);
	header(
        'Content-Type: application/json; charset=utf-8',
        true,
        500
    );
    echo json_encode([ 'error' => 'Internal Server Error' ]);
    $log(
        $device['udi'] ?? null,
        $sik['sik'] ?? null,
        $mode['mode'] ?? null,
        json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        json_encode(
            [ 'error'     => 'Internal Server Error' ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        ),
        1
    );
}
