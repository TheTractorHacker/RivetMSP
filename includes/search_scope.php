<?php
// Module and client scoping fragments for the global search, the live search and the synced-scripts tab (MSP-DS-7).

/** An SQL fragment that empties a search section when the viewer has no access (level < 1 or false) to the module behind it. */
function searchModuleDeny($level): string
{
    return ($level !== false && (int) $level >= 1) ? '' : 'AND 1=0';
}

/** "AND <column> IN (...)" for a client-restricted, non-admin viewer; '' when unrestricted. $column is a trusted literal. */
function searchClientScope(string $clientAccessString, bool $isAdmin, string $column): string
{
    if ($isAdmin || $clientAccessString === '') {
        return '';
    }
    $ids = array_filter(array_map('intval', explode(',', $clientAccessString)));
    return $ids ? "AND $column IN (" . implode(',', $ids) . ')' : '';
}
