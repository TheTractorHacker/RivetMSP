<?php

/*
 * ITFlow - SSE core for live ticket updates (replies, status changes, chat)
 *
 * Included by agent/sse_ticket_stream.php and client/sse_ticket_stream.php
 * after they've validated $ticket_id and the caller's access to it.
 */

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

while (ob_get_level() > 0) {
    ob_end_flush();
}

$redis = getRedisClient();

if (!$redis) {
    echo "event: error\ndata: {\"message\":\"Live updates unavailable\"}\n\n";
    flush();
    exit;
}

// MSP-DS-138: each stream pins a PHP-FPM worker for up to 45 s, so one person may only hold a few at once. The slot counter lives in Redis
// (expires on its own if a worker dies); over the cap the answer is a 429, which makes EventSource stop instead of reconnecting in a loop.
$sse_owner = (string) ($sse_stream_owner ?? '');
$sse_cap = max(1, (int) ($sse_stream_cap ?? 5));
if ($sse_owner !== '') {
    $sse_slot_key = 'sse_streams:' . $sse_owner;
    try {
        $sse_open = (int) $redis->incr($sse_slot_key);
        $redis->expire($sse_slot_key, 90);
    } catch (\Throwable $e) {
        $sse_open = 0;   // Redis trouble: the stream itself will fail below
    }
    if ($sse_open > $sse_cap) {
        try {
            $redis->decr($sse_slot_key);
        } catch (\Throwable $e) {
        }
        http_response_code(429);
        header('Retry-After: 60');
        exit;
    }
    register_shutdown_function(static function () use ($redis, $sse_slot_key) {
        try {
            if ((int) $redis->decr($sse_slot_key) < 0) {
                $redis->set($sse_slot_key, 0);
            }
        } catch (\Throwable $e) {
        }
    });
}

$channel = "ticket.$ticket_id";

$pubsub = $redis->pubSubLoop();
$pubsub->subscribe($channel);

try {
    // Consume the subscribe confirmation before entering the wait loop
    $pubsub->current();
} catch (\Throwable $e) {
    exit;
}

$socket = getRedisStreamSocket($redis);

if (!$socket) {
    exit;
}

$max_runtime = 45;
$heartbeat_interval = 15;
$start_time = time();

while (time() - $start_time < $max_runtime) {
    if (connection_aborted()) {
        break;
    }

    $read = [$socket];
    $write = null;
    $except = null;
    $ready = @stream_select($read, $write, $except, $heartbeat_interval, 0);

    if ($ready === false) {
        break;
    }

    if ($ready > 0) {
        try {
            $message = $pubsub->current();
        } catch (\Throwable $e) {
            break;
        }

        if ($message->kind === 'message') {
            echo "data: {$message->payload}\n\n";
        }
    } else {
        // Heartbeat comment to keep the connection alive through proxies
        echo ": heartbeat\n\n";
    }

    flush();
}

try {
    $pubsub->unsubscribe();
} catch (\Throwable $e) {
    // Connection may already be gone
}
