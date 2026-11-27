<?php
// MSP-DS-104: view-limited share links. The view is claimed with ONE conditional UPDATE, so N parallel requests on a 1-view link cannot all pass a
// separate read-then-check and then each write "views read + 1".

/** True when this request got a view of the share (and the counter is already incremented); false when the link is inactive or its limit is used up. */
function claimSharedItemView($mysqli, $item_id): bool
{
    $item_id = intval($item_id);
    mysqli_query($mysqli, "UPDATE shared_items SET item_views = item_views + 1
                           WHERE item_id = $item_id AND item_active = 1 AND (item_view_limit = 0 OR item_views < item_view_limit)");
    return mysqli_affected_rows($mysqli) === 1;
}
