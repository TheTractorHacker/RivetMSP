<?php
// A child object linked to a ticket or service must belong to that record's client (MSP-DS-11 / MSP-DS-30 / MSP-INJ-12).

/**
 * True when object $id of $kind belongs to $client_id. An id of 0 (nothing chosen) is fine. A project with client 0 is a shared
 * project and fits any client (the project pickers list "this client or 0"). Anything else must carry exactly the ticket's client,
 * so an internal ticket (client 0) links only objects with no client.
 */
function childBelongsToClient(mysqli $mysqli, string $kind, int $id, int $client_id): bool
{
    static $map = [
        'contact'  => ['contacts',  'contact_id',  'contact_client_id'],
        'asset'    => ['assets',    'asset_id',    'asset_client_id'],
        'vendor'   => ['vendors',   'vendor_id',   'vendor_client_id'],
        'location' => ['locations', 'location_id', 'location_client_id'],
        'project'  => ['projects',  'project_id',  'project_client_id'],
        'document'    => ['documents',    'document_id',    'document_client_id'],
        'credential'  => ['credentials',  'credential_id',  'credential_client_id'],
        'domain'      => ['domains',      'domain_id',      'domain_client_id'],
        'certificate' => ['certificates', 'certificate_id', 'certificate_client_id'],
    ];
    if ($id <= 0) {
        return true;
    }
    if (!isset($map[$kind])) {
        return false;
    }
    [$table, $idCol, $clientCol] = $map[$kind];
    $res = mysqli_query($mysqli, "SELECT $clientCol AS c FROM $table WHERE $idCol = $id LIMIT 1");
    $row = $res ? mysqli_fetch_assoc($res) : null;
    if (!$row) {
        return false;
    }
    $owner = (int) $row['c'];
    return $owner === $client_id || ($kind === 'project' && $owner === 0);
}

/** Same check for a list of ids (additional assets). */
function childrenBelongToClient(mysqli $mysqli, string $kind, $ids, int $client_id): bool
{
    foreach ((array) $ids as $id) {
        if (!childBelongsToClient($mysqli, $kind, (int) $id, $client_id)) {
            return false;
        }
    }
    return true;
}

/** Handler guard: flashes an error and redirects back when the child does not belong to the ticket's client. */
function assertSameClient(string $kind, $id, int $client_id): void
{
    global $mysqli;
    $ids = is_array($id) ? $id : [$id];
    if (!childrenBelongToClient($mysqli, $kind, $ids, $client_id)) {
        flash_alert("That $kind does not belong to this ticket's client.", 'error');
        redirect();
        exit;
    }
}
