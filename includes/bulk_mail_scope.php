<?php
// The From address of a bulk mail is one of the addresses the MSP configured, never free text from the request (MSP-DS-9).

/** @return string[] the configured sender addresses (mail, invoice, quote, ticket), lower-cased and de-duplicated */
function bulkMailAllowedFrom(array $configured): array
{
    $out = [];
    foreach ($configured as $addr) {
        $addr = strtolower(trim((string) $addr));
        if ($addr !== '' && filter_var($addr, FILTER_VALIDATE_EMAIL)) {
            $out[$addr] = $addr;
        }
    }
    return array_values($out);
}

/** True when $from is one of the configured sender addresses. */
function bulkMailFromAllowed(string $from, array $configured): bool
{
    return in_array(strtolower(trim($from)), bulkMailAllowedFrom($configured), true);
}
