<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)

require_once __DIR__ . '/EndpointKit.php';

use RivetCore\Rmm\Contracts\RmmEventsInterface;
use RivetCore\Testing\RmmEventsConformanceTestCase;

/**
 * EndpointEvents puts rmm.* events on RivetMSP's event bus (includes/event_bus.php). The bus keeps nothing of its own, so the case reads what a webhook
 * subscribed to rmm.* would be sent: the queued `webhook.deliver` jobs (payload and order). The delivery over the real check-in is in tests/rmm_ui.php.
 */
final class EndpointRmmEventsConformanceTest extends RmmEventsConformanceTestCase
{
    protected function events(): RmmEventsInterface
    {
        return new \RivetMSP\Core\Adapter\Endpoint\EndpointEvents(EndpointKit::mysqli());
    }

    protected function setUp(): void
    {
        $db = EndpointKit::db();
        $db->execute('DELETE FROM integration_jobs WHERE job_type = ?', ['webhook.deliver']);
        $db->execute('DELETE FROM webhook_queue');
        $db->execute('DELETE FROM webhooks');
        $db->execute("INSERT INTO webhooks SET webhook_name = 'conformance', webhook_url = 'http://127.0.0.1:9/x', webhook_secret = '', webhook_events = 'rmm.*', webhook_enabled = 1");
    }

    protected function tearDown(): void
    {
        EndpointKit::db()->execute('DELETE FROM integration_jobs WHERE job_type = ?', ['webhook.deliver']);
        EndpointKit::db()->execute('DELETE FROM webhook_queue');
        EndpointKit::db()->execute('DELETE FROM webhooks');
    }

    protected function received(): ?array
    {
        $out = [];
        // Without the job queue (RivetMSP falls back to the webhook_queue table when jobs are unavailable) the same payload is stored there.
        foreach (EndpointKit::db()->fetchAll('SELECT queue_payload FROM webhook_queue ORDER BY queue_id') as $r) {
            $p = json_decode((string) $r['queue_payload'], true);
            $out[] = ['event' => (string) $p['event'], 'payload' => $p['data']];
        }
        foreach (EndpointKit::db()->fetchAll("SELECT payload FROM integration_jobs WHERE job_type = 'webhook.deliver' ORDER BY job_id") as $r) {
            $p = json_decode((string) $r['payload'], true);
            $out[] = ['event' => (string) $p['event'], 'payload' => $p['data']];
        }

        return $out;
    }
}
