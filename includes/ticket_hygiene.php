<?php

/*
 * MSP-DS-134/136: a ticket's guest link (ticket_url_key) is a bearer secret handed to its recipients. It is replaced whenever the
 * set of people who may hold it changes (a watcher removed, the contact changed, the ticket moved to another client).
 */

/** Gives the ticket a new guest key. Old emailed links stop working; later notifications carry the new one. @return string the new key */
function ticketRotateGuestKey(mysqli $mysqli, int $ticketId): string
{
    $key = bin2hex(random_bytes(16));
    mysqli_query($mysqli, "UPDATE tickets SET ticket_url_key = '" . mysqli_real_escape_string($mysqli, $key) . "' WHERE ticket_id = " . intval($ticketId) . " LIMIT 1");

    return $key;
}

/** A ticket moved to another client keeps nothing of the old one: watchers, guest key, contract, asset/location/vendor/project links, extra assets. */
function ticketDetachFromOldClient(mysqli $mysqli, int $ticketId): void
{
    $ticketId = intval($ticketId);
    mysqli_query($mysqli, "DELETE FROM ticket_watchers WHERE watcher_ticket_id = $ticketId");
    mysqli_query($mysqli, "DELETE FROM ticket_assets WHERE ticket_id = $ticketId");
    mysqli_query($mysqli, "UPDATE tickets SET ticket_contract_id = NULL, ticket_asset_id = 0, ticket_location_id = 0, ticket_vendor_id = 0, ticket_project_id = 0 WHERE ticket_id = $ticketId LIMIT 1");
    ticketRotateGuestKey($mysqli, $ticketId);
}
