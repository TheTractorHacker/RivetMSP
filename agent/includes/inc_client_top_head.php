<?php
/*
 * CLIENT HEADER STRIP
 *
 * Included by agent/includes/inc_all_client.php, which every client-scoped
 * page requires - Overview, Tickets, Assets, Contacts, Locations, Credentials,
 * and ~35 more. Anything here is seen dozens of times a day, so it is written
 * to be quiet: one title row, one three/four-up strip of facts, no decoration.
 *
 * It consumes the variables inc_all_client.php has already resolved
 * ($location_*, $contact_*, $client_*, $num_*) and does not re-query for them.
 */

$show_add_credit = 0; // Remove once credits is added hides the button

// How many locations is this client actually attached to? inc_all_client.php
// already resolves this via $num_locations (locations.location_client_id, this
// app's real per-client model - not a junction table), so the site-count card
// reuses it rather than re-querying. Aliased under the name the ported strip
// markup below expects.
$client_site_count = $num_locations;

/*
 * Map link target.
 *
 * This used to be "?q=$location_address $location_zip" - raw spaces, and it
 * threw away the city and state that are printed on the very next line, so
 * "8307 Ball Rd 72908" was ambiguous to any map provider. Build the query from
 * the whole address and percent-encode it once.
 *
 * The $location_* variables arrive HTML-encoded (nullable_htmlentities ->
 * htmlspecialchars with ENT_QUOTES), so decode with the matching flags before
 * encoding, or "Ben & Jerry's Rd" would reach the provider as "Ben %26amp%3B
 * Jerry%27s Rd". rawurlencode output is attribute-safe on the way back out.
 */
$location_map_query = '';
if (!empty($location_address)) {
    $location_map_parts = array_filter(
        array_map(
            static fn($part) => trim((string)$part),
            [$location_address, $location_city, "$location_state $location_zip"]
        ),
        static fn($part) => $part !== ''
    );
    $location_map_query = rawurlencode(
        html_entity_decode(implode(', ', $location_map_parts), ENT_QUOTES, 'UTF-8')
    );
}

// The strip is open on the client landing page and closed everywhere else,
// so the toggle's initial ARIA/chevron state has to be derived from the same test.
$client_header_open = (basename($_SERVER["PHP_SELF"]) == "client_overview.php");

// Locations live behind module_support (agent/locations.php enforces it), so the
// "link a site" affordance is only offered to someone who can actually follow it.
$client_header_can_edit_sites = (lookupUserPermission("module_support") >= 1);

// "Add device" (endpoint agent installer): the dialog and its opener, only for someone who may issue installers (rmm.admin / rmm.token.manage, asked of the
// module's adapter inside rivetRmmUiInstaller()) and only while the RMM module is on. $session_is_admin is just the cheap guard that keeps every other
// user from building the module on each client page. Null: nothing renders, nothing is requested.
$client_header_installer = null;
if (!empty($session_is_admin) && $config_core_rmm_enabled && lookupUserPermission('module_rmm') >= 1 && empty($client_archived_at)) {
    require_once dirname(__DIR__, 2) . '/includes/rmm_ui_render.php';
    $client_header_installer = rivetRmmUiInstaller($mysqli, (int) $session_user_id, (int) $client_id);
    $rmm_installer_scripts = $client_header_installer !== null;   // includes/footer.php links js/rmm_installer.js only when this is set
}
?>

<style nonce="<?php echo htmlspecialchars($csp_nonce ?? '', ENT_QUOTES); ?>">
/* The collapse chevron. Bootstrap adds/removes .collapsed on the trigger itself,
   so one transform swap covers both directions with no JS. Transform only, and
   it rotates a glyph inside a fixed-size button - no layout, no moved click
   target. Durations/easing come from css/itflow_motion.css's tokens, whose
   single global prefers-reduced-motion guard neutralises this too, so this rule
   deliberately carries no media query of its own. */
.client-header-toggle .fa-chevron-up {
    display: inline-block;
    transition: transform var(--if-dur-ui, 160ms) var(--if-ease-out, ease-out);
}
.client-header-toggle.collapsed .fa-chevron-up {
    transform: rotate(180deg);
}
</style>

<div class="card d-print-none mb-3">
    <div class="card-header pb-1 pt-2 px-3">
        <div class="card-title">
            <!-- The client name used to BE the collapse trigger: an <a href="#">
                 wrapping this h4, with no chevron, no aria-expanded and no hint that
                 clicking the title would fold the strip away. The title is a plain
                 heading again; the toggle is the labelled button in .card-tools. -->
            <h4 class="mb-0" data-bs-toggle="tooltip" data-bs-placement="right" title="Client ID: <?php echo $client_id; ?>"><strong><?php echo $client_name; ?></strong><?php if ($client_archived_at) { ?> <span class="fw-normal text-secondary">(archived)</span><?php } ?></h4>
        </div>
        <?php if (!empty($client_tag_name_display_array)) { ?><div class="card-title ms-2"><?php echo $client_tags_display; ?></div> <?php } ?>
        <div class="card-tools">

            <?php if ($client_header_installer !== null) { echo rivetRmmUiInstallerButton($client_header_installer, 'Add device', 'btn btn-outline-primary btn-sm me-2', (int) $client_id, 'fa-download'); } ?>

            <button class="btn btn-tool client-header-toggle<?php if (!$client_header_open) { echo ' collapsed'; } ?>"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#clientHeader"
                    aria-controls="clientHeader"
                    aria-expanded="<?php echo $client_header_open ? 'true' : 'false'; ?>"
                    aria-label="Show or hide client details">
                <i class="fas fa-fw fa-chevron-up"></i>
            </button>

            <?php if (lookupUserPermission("module_client") >= 2) { ?>
            <div class="dropdown dropleft text-center">
                <!-- .btn-tool, not .btn-dark: a lone icon action in a card header
                     should read as chrome - a muted glyph with an 8% ink wash on
                     hover - not as a filled near-black chip louder than the
                     client name it sits beside. .btn-tool is this app's own
                     card-header button (50 uses; agent/calendar.php:51 is the same
                     kebab-in-a-header pattern) and css/itflow.shim-adminlte.css
                     already gives it hover, focus-visible and disabled states. -->
                <button class="btn btn-tool" type="button" data-bs-toggle="dropdown" data-boundary="window" aria-label="Client actions">
                    <i class="fas fa-fw fa-ellipsis-v"></i>
                </button>
                <div class="dropdown-menu">
                    <?php if (lookupUserPermission("module_support") >= 2) { ?>
                        <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/ticket/ticket_add_v2.php?client_id=<?= $client_id ?>" data-modal-size="lg">
                            <i class="fas fa-fw fa-life-ring me-2"></i>New Ticket
                        </a>
                    <?php } ?>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item ajax-modal" href="#"
                        data-modal-url="modals/client/client_edit.php?id=<?= $client_id ?>">
                        <i class="fas fa-fw fa-edit me-2"></i>Edit Client
                    </a>
                    <?php if (lookupUserPermission("module_billing") >= 2) { ?>
                        <?php if ($show_add_credit) { ?>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#addCreditModal">
                            <i class="fas fa-fw fa-wallet me-2"></i>Add Credit
                        </a>
                        <?php } ?>
                    <?php } ?>
                    <?php if (lookupUserPermission("module_client") >= 3) { ?>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#exportClientPDFModal">
                            <i class="fas fa-fw fa-file-pdf me-2"></i>Export Data
                        </a>
                    <?php } ?>

                    <?php if (empty($client_archived_at)) { ?>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item text-danger confirm-link" href="post.php?archive_client=<?php echo $client_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token'] ?>">
                            <i class="fas fa-fw fa-archive me-2"></i>Archive Client
                        </a>
                    <?php } else { ?>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item text-primary confirm-link" href="post.php?restore_client=<?= $client_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                            <i class="fas fa-fw fa-archive me-2"></i>Restore Client
                        </a>
                    <?php } ?>

                    <?php if (lookupUserPermission("module_client") >= 3 && $client_archived_at) { ?>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item text-danger text-bold" href="#" data-bs-toggle="modal" data-bs-target="#deleteClientModal<?php echo $client_id; ?>">
                        <i class="fas fa-fw fa-trash me-2"></i>Delete Client
                    </a>
                    <?php } ?>

                </div>
            </div>
            <?php } ?>
        </div>
    </div>
</div>

<?php if ($client_header_installer !== null) { echo rivetRmmUiInstallerModal($client_header_installer, (string) $_SESSION['csrf_token']); } ?>

<div class="collapse <?php if ($client_header_open) { echo "show"; } ?>" id="clientHeader">

    <?php
    /*
     * The three/four-up strip.
     *
     * Every cell is built the same way on purpose: `mt-0` + no justify-content,
     * then one <h5 class="mb-2"> label, then a single .gap-1 flex column of rows.
     *
     *   mt-0  - css/itflow_design.css has a global `.card + .card { margin-top:
     *           1rem }`, which inside a flex .card-group pushed cells 2/3/4 down
     *           and left a visible notch beside cell 1.
     *   no justify-content-center - each cell used to vertically centre its own
     *           unequal content, so the labels landed on different baselines even
     *           though they read as one row.
     *   gap-1 - one spacing rule for every row instead of the old mixture of
     *           bare divs, .mt-1 and an <hr>.
     */
    ?>
    <div class="card-group mb-3">

        <div class="card card-body px-3 py-2 mt-0">
            <h5 class="mb-2">Primary Location</h5>
            <div class="d-flex flex-column gap-1">
                <?php if (!empty($location_address)) { ?>
                    <div class="d-flex">
                        <i class="fa fa-fw fa-map-marker-alt text-secondary ms-1 me-2 mt-1"></i>
                        <div>
                            <div><a href="//maps.<?php echo $session_map_source; ?>.com/?q=<?php echo $location_map_query; ?>" target="_blank" rel="noopener"><?php echo $location_address; ?></a></div>
                            <?php if (trim("$location_city $location_state $location_zip") !== '') { ?>
                                <div><?php echo trim("$location_city $location_state $location_zip"); ?></div>
                            <?php } ?>
                            <?php if (!empty($location_country)) { ?>
                                <div class="text-secondary small"><?php echo $location_country; ?></div>
                            <?php } ?>
                        </div>
                    </div>
                <?php } else { ?>
                    <?php
                    /*
                     * Empty state. A client with no location on file used to render this
                     * cell as a bold "Primary Location" label floating over a blank white
                     * rectangle - indistinguishable from a page that failed to load. Say
                     * what is missing, and where to fix it: the client's own Locations
                     * page.
                     */
                    ?>
                    <div class="d-flex">
                        <i class="fa fa-fw fa-map-marker-alt text-secondary ms-1 me-2 mt-1"></i>
                        <div>
                            <div class="text-secondary"><?php echo $client_site_count > 0 ? 'Linked location has no address on file' : 'No location linked to this client'; ?></div>
                            <?php if ($client_header_can_edit_sites) { ?>
                                <a class="small" href="/agent/locations.php?client_id=<?php echo $client_id; ?>"><?php echo $client_site_count > 0 ? 'Edit it on the Locations page' : 'Link one on the Locations page'; ?></a>
                            <?php } ?>
                        </div>
                    </div>
                <?php } ?>

                <?php
                /*
                 * A client can have several locations, and only one of them fits in a
                 * cell headed "Primary Location". Name the one being shown and count
                 * the ones that are not, rather than letting the card imply the other
                 * locations do not exist.
                 */
                if ($client_site_count > 1) { ?>
                    <div class="d-flex small">
                        <i class="fa fa-fw fa-map-marked-alt text-secondary ms-1 me-2 mt-1"></i>
                        <div>
                            <span class="text-secondary"><?php if (!empty($location_name)) { echo "Showing $location_name &middot; "; } ?><?php echo $client_site_count; ?> sites linked</span>
                            <?php if ($client_header_can_edit_sites) { ?><a class="ms-1" href="/agent/locations.php?client_id=<?php echo $client_id; ?>">View all</a><?php } ?>
                        </div>
                    </div>
                <?php } ?>

                <?php if (!empty($location_phone)) { ?>
                    <div>
                        <i class="fa fa-fw fa-phone text-secondary ms-1 me-2"></i><a href="tel:<?php echo $location_phone; ?>"><?php echo $location_phone; ?></a>
                    </div>
                <?php } ?>

                <?php if (!empty($client_website)) { ?>
                    <div>
                        <i class="fa fa-fw fa-globe text-secondary ms-1 me-2"></i><a target="_blank" rel="noopener" href="//<?php echo $client_website; ?>"><?php echo $client_website; ?></a>
                    </div>
                <?php } ?>
            </div>
        </div>

        <div class="card card-body px-3 py-2 mt-0">
            <h5 class="mb-2">Primary Contact</h5>
            <div class="d-flex flex-column gap-1">
                <?php if (!empty($contact_name)) { ?>
                    <div>
                        <i class="fa fa-fw fa-user text-secondary ms-1 me-2"></i><?php echo $contact_name; ?>
                    </div>
                <?php } ?>

                <?php if (!empty($contact_email)) { ?>
                    <div>
                        <i class="fa fa-fw fa-envelope text-secondary ms-1 me-2"></i><a href="mailto:<?php echo $contact_email; ?>"><?php echo $contact_email; ?></a>
                    </div>
                <?php } ?>

                <?php if (!empty($contact_phone)) { ?>
                    <div>
                        <i class="fa fa-fw fa-phone text-secondary ms-1 me-2"></i><a href="tel:<?php echo $contact_phone; ?>"><?php echo $contact_phone; ?></a><?php
                        if (!empty($contact_extension)) {
                            echo " <small class='text-secondary'>x$contact_extension</small>";
                        }
                        ?>
                    </div>
                <?php } ?>

                <?php if (!empty($contact_mobile)) { ?>
                    <div>
                        <i class="fa fa-fw fa-mobile-alt text-secondary ms-1 me-2"></i><a href="tel:<?php echo $contact_mobile; ?>"><?php echo $contact_mobile; ?></a>
                    </div>
                <?php } ?>

                <?php if (empty($contact_name) && empty($contact_email) && empty($contact_phone) && empty($contact_mobile)) { ?>
                    <div class="d-flex">
                        <i class="fa fa-fw fa-user text-secondary ms-1 me-2 mt-1"></i>
                        <div>
                            <div class="text-secondary">No primary contact set</div>
                            <!-- No permission gate: inc_all_client.php has already
                                 enforced module_client to render this page at all,
                                 which is exactly what agent/contacts.php requires. -->
                            <a class="small" href="/agent/contacts.php?client_id=<?php echo $client_id; ?>">Choose one on the Contacts page</a>
                        </div>
                    </div>
                <?php } ?>
            </div>
        </div>

        <?php if (lookupUserPermission("module_financial") >= 1 && $config_module_enable_accounting == 1) { ?>
        <div class="card card-body px-3 py-2 mt-0">
            <h5 class="mb-2">Billing</h5>
            <div class="d-flex flex-column gap-1">
                <div class="d-flex justify-content-between ms-1">
                    <span class="text-secondary">Hourly Rate</span>
                    <span class="fw-medium"><?php echo numfmt_format_currency($currency_format, $client_rate, $client_currency_code); ?></span>
                </div>
                <div class="d-flex justify-content-between ms-1">
                    <span class="text-secondary">Paid</span>
                    <span class="fw-medium"><?php echo numfmt_format_currency($currency_format, $amount_paid, $client_currency_code); ?></span>
                </div>
                <div class="d-flex justify-content-between ms-1">
                    <span class="text-secondary">Balance</span>
                    <span class="fw-medium <?php if ($balance > 0) { echo "text-danger"; } ?>"><?php echo numfmt_format_currency($currency_format, $balance, $client_currency_code); ?></span>
                </div>
                <div class="d-flex justify-content-between ms-1">
                    <span class="text-secondary">Monthly Recurring</span>
                    <span class="fw-medium"><?php echo numfmt_format_currency($currency_format, $recurring_monthly, $client_currency_code); ?></span>
                </div>
                <div class="d-flex justify-content-between ms-1">
                    <span class="text-secondary">Net Terms</span>
                    <span class="fw-medium">
                        <?php if ($client_net_terms) { ?>
                        <?= $client_net_terms; ?><small class="text-secondary ms-1">Days</small>
                        <?php } else { ?>
                            On Receipt
                        <?php } ?>
                    </span>
                </div>
                <?php if (!empty($client_tax_id_number)) { ?>
                <div class="d-flex justify-content-between ms-1">
                    <span class="text-secondary">Tax ID</span>
                    <span class="fw-medium"><?php echo $client_tax_id_number; ?></span>
                </div>
                <?php } ?>
            </div>
        </div>
        <?php } ?>

        <?php if (lookupUserPermission("module_support") >= 1 && $config_module_enable_ticketing == 1) { ?>
        <div class="card card-body px-3 py-2 mt-0">
            <h5 class="mb-2">Support</h5>
            <div class="d-flex flex-column gap-1">
                <div class="d-flex justify-content-between ms-1">
                    <span class="text-secondary">Open Tickets</span>
                    <span class="fw-medium"><?php echo $num_active_tickets; ?></span>
                </div>
                <div class="d-flex justify-content-between ms-1">
                    <span class="text-secondary">Closed Tickets</span>
                    <span class="fw-medium"><?php echo $num_closed_tickets; ?></span>
                </div>
            </div>
        </div>
        <?php } ?>

    </div>
</div>

<?php
// require_once "modals/client/client_credit_add.php"; --Credit Not Ready 2025-08-27
require_once "modals/client/client_delete.php";
require_once "modals/client/client_download_pdf.php";
