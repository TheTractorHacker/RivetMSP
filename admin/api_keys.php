<?php

// Default Column Sortby Filter
$sort = "api_key_name";
$order = "ASC";

require_once "includes/inc_all_admin.php";

$sql = mysqli_query(
    $mysqli,
    "SELECT SQL_CALC_FOUND_ROWS * FROM api_keys
    LEFT JOIN clients on api_keys.api_key_client_id = clients.client_id
    WHERE (api_key_name LIKE '%$q%')
    ORDER BY $sort $order LIMIT $record_from, $record_to"
);

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

?>

<?php
$rl_ready = false;
$rl_value = 120;
$rl_res = @mysqli_query($mysqli, "SELECT config_api_rate_limit_per_minute FROM settings WHERE company_id = 1");
if ($rl_res && ($rl_row = mysqli_fetch_assoc($rl_res))) { $rl_ready = true; $rl_value = (int) $rl_row['config_api_rate_limit_per_minute']; }
?>
<div class="card mb-3">
    <div class="card-header py-2"><h4 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-tachometer-alt me-2"></i>Rate limit</h4></div>
    <div class="card-body">
        <?php if (!$rl_ready) { ?>
            <div class="alert alert-info mb-0 py-2">Run the database update (Administration &rarr; Update) to set the API rate limit here.</div>
        <?php } else { ?>
        <form action="post.php" method="post" class="row g-2 align-items-end" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <div class="col-sm-4 col-md-3">
                <label class="form-label" for="api_rate_limit_per_minute">Requests per minute, per key</label>
                <input class="form-control" type="number" min="0" max="100000" id="api_rate_limit_per_minute" name="api_rate_limit_per_minute" value="<?= $rl_value ?>">
            </div>
            <div class="col-auto"><button type="submit" name="set_api_rate_limit" class="btn btn-primary">Save</button></div>
            <div class="form-text">Applies to every API key and app sign-in. One address may make three times this. Over the limit the API answers HTTP 429 with a Retry-After header. 0 turns the limit off. Counted in Redis; if Redis is down the API keeps working without a limit.</div>
        </form>
        <?php } ?>
    </div>
</div>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-key me-2"></i>API Keys</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/api/api_key_add.php"><i class="fas fa-plus me-2"></i>New API Key</button>
        </div>
    </div>

    <div class="card-body">

        <form autocomplete="off">
            <div class="row">

                <div class="col-md-4">
                    <div class="input-group mb-3 mb-md-0">
                        <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search keys">
                        <div class="input-group-append">
                            <button class="btn btn-primary"><i class="fa fa-search"></i></button>
                        </div>
                    </div>
                </div>

                <div class="col-md-8">
                    <div class="btn-group float-end">
                        <div class="dropdown ms-2" id="bulkActionButton" hidden>
                            <button class="btn btn-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="fas fa-fw fa-layer-group me-2"></i>Bulk Action (<span id="selectedCount">0</span>)
                            </button>
                            <div class="dropdown-menu">
                                <button class="dropdown-item text-danger text-bold"
                                        type="submit" form="bulkActions" name="bulk_delete_api_keys">
                                    <i class="fas fa-fw fa-trash me-2"></i>Delete
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

        </form>
        <hr>

        <div class="table-responsive-sm">

            <form id="bulkActions" action="post.php" method="post">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">

                <table class="table table-striped table-borderless table-hover">
                    <thead class="text-dark <?php if ($num_rows[0] == 0) { echo "d-none"; } ?>">
                    <tr>
                        <td class="pe-0">
                            <div class="form-check">
                                <input class="form-check-input" id="selectAllCheckbox" type="checkbox">
                            </div>
                        </td>
                        <th>
                            <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=api_key_name&order=<?php echo $disp; ?>">
                                Name <?php if ($sort == 'api_key_name') { echo $order_icon; } ?>
                            </a>
                        </th>
                        <th>
                            <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=api_key_client_id&order=<?php echo $disp; ?>">
                                Client <?php if ($sort == 'api_key_client_id') { echo $order_icon; } ?>
                            </a>
                        </th>
                        <th>
                            <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=api_key_secret&order=<?php echo $disp; ?>">
                                Secret <?php if ($sort == 'api_key_secret') { echo $order_icon; } ?>
                            </a>
                        </th>
                        <th>
                            <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=api_key_permission&order=<?php echo $disp; ?>">
                                Permission <?php if ($sort == 'api_key_permission') { echo $order_icon; } ?>
                            </a>
                        </th>
                        <th>
                            <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=api_key_created_at&order=<?php echo $disp; ?>">
                                Created <?php if ($sort == 'api_key_created_at') { echo $order_icon; } ?>
                            </a>
                        </th>
                        <th>
                            <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=api_key_expire&order=<?php echo $disp; ?>">
                                Expires <?php if ($sort == 'api_key_expire') { echo $order_icon; } ?>
                            </a>
                        </th>
                        <th class="text-center">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php

                    while ($row = mysqli_fetch_assoc($sql)) {
                        $api_key_id = intval($row['api_key_id']);
                        $api_key_name = nullable_htmlentities($row['api_key_name']);
                        $api_key_secret = nullable_htmlentities("************" . substr($row['api_key_secret'], -4));
                        $api_key_created_at = nullable_htmlentities($row['api_key_created_at']);
                        $api_key_expire = nullable_htmlentities($row['api_key_expire']);
                        if ($api_key_expire < date("Y-m-d H:i:s")) {
                            $api_key_expire = $api_key_expire . " (Expired)";
                        }

                        if ($row['api_key_client_id'] == 0) {
                            $api_key_client = "<i>All Clients</i>";
                        } else {
                            $api_key_client = nullable_htmlentities($row['client_name']);
                        }

                        $api_key_permission = $row['api_key_permission'] ?? 'write';
                        $api_key_permission_display = $api_key_permission === 'read'
                            ? '<span class="badge text-bg-secondary"><i class="fas fa-fw fa-eye me-1"></i>Read Only</span>'
                            : '<span class="badge text-bg-primary"><i class="fas fa-fw fa-pen me-1"></i>Read &amp; Write</span>';

                        ?>
                        <tr>
                            <td class="pe-0">
                                <div class="form-check">
                                    <input class="form-check-input bulk-select" type="checkbox" name="api_key_ids[]" value="<?php echo $api_key_id ?>">
                                </div>
                            </td>
                            <td class="text-bold"><?php echo $api_key_name; ?></td>
                            <td><?php echo $api_key_client; ?></td>
                            <td><?php echo $api_key_secret; ?></td>
                            <td><?php echo $api_key_permission_display; ?></td>
                            <td><?php echo $api_key_created_at; ?></td>
                            <td><?php echo $api_key_expire; ?></td>
                            <td>
                                <div class="dropdown dropleft text-center">
                                    <button class="btn btn-secondary btn-sm" type="button" data-bs-toggle="dropdown">
                                        <i class="fas fa-ellipsis-h"></i>
                                    </button>
                                    <div class="dropdown-menu">
                                        <?php if ($api_key_expire > date("Y-m-d H:i:s")) { ?>
                                            <a class="dropdown-item text-danger text-bold confirm-link" href="post.php?revoke_api_key=<?php echo $api_key_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token'] ?>">
                                                <i class="fas fa-fw fa-times me-2"></i>Revoke
                                            </a>
                                        <?php } ?>
                                        <?php if ($api_key_expire < date("Y-m-d H:i:s")) { ?>
                                            <a class="dropdown-item text-danger text-bold confirm-link" href="post.php?delete_api_key=<?php echo $api_key_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token'] ?>">
                                                <i class="fas fa-fw fa-times me-2"></i>Delete
                                            </a>
                                        <?php } ?>
                                    </div>
                                </div>
                            </td>
                        </tr>

                    <?php } ?>


                    </tbody>
                </table>

            </form>

        </div>
        <?php require_once "../includes/filter_footer.php"; ?>
    </div>
</div>

<script src="../js/bulk_actions.js"></script>

<?php
require_once "../includes/footer.php";
