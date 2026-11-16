<?php
// Module and client scoping fragments for the global search, the live search and the synced-scripts tab (MSP-DS-7).

/** An SQL fragment that empties a search section when the viewer has no access (level < 1 or false) to the module behind it. */
function searchModuleDeny($level): string
{
    return ($level !== false && (int) $level >= 1) ? '' : 'AND 1=0';
}

/**
 * "AND <column> IN (...)" for a client-restricted, non-admin viewer; '' when unrestricted. $column is a trusted literal.
 * With $includeGlobal the shared rows (client 0) stay visible: "AND (<column> = 0 OR <column> IN (...))".
 */
function searchClientScope(string $clientAccessString, bool $isAdmin, string $column, bool $includeGlobal = false): string
{
    if ($isAdmin || $clientAccessString === '') {
        return '';
    }
    $ids = array_filter(array_map('intval', explode(',', $clientAccessString)));
    if (!$ids) {
        return '';
    }
    $in = "$column IN (" . implode(',', $ids) . ')';
    return $includeGlobal ? "AND ($column = 0 OR $in)" : "AND $in";
}
