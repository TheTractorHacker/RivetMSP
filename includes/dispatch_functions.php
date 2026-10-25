<?php
/*
 * Roadmap #29: automated ticket dispatch, v1.
 *
 * Simple, no-AI-dependency rule: match a ticket's category to technicians who hold that
 * category's "required skill" tag (tag_type = 7, held via user_tags - see
 * admin/modals/user/user_skills.php). If the category has no required skill set, or nobody
 * currently holds it, fall back to every active technician. Among the candidate pool, break
 * ties by current open-ticket workload (fewest open tickets wins), then by lowest user_id for
 * a fully deterministic result.
 *
 * Wired in as the 'dispatch_assign' ticket-automation action (includes/automation_functions.php)
 * rather than a standalone mechanism, so an admin reaches it the same way as any other ticket
 * rule (trigger: ticket_created, etc.) instead of a second bespoke config surface.
 */

if (!function_exists('dispatchAssignTicket')) {

    /**
     * Pick the best technician for a ticket under the v1 rule above.
     *
     * @param mysqli $mysqli
     * @param int    $ticket_id
     * @return int|null The chosen user_id, or null if there is no active technician at all
     *                   (nothing to assign to - caller should leave the ticket as-is).
     */
    function dispatchAssignTicket($mysqli, int $ticket_id): ?int {
        $ticket_id = intval($ticket_id);
        if ($ticket_id <= 0 || !$mysqli) {
            return null;
        }

        $trow = mysqli_fetch_assoc(mysqli_query($mysqli,
            "SELECT ticket_category FROM tickets WHERE ticket_id = $ticket_id LIMIT 1"));
        if (!$trow) {
            return null;
        }
        $category_id = intval($trow['ticket_category'] ?? 0);

        $required_skill_tag_id = 0;
        if ($category_id > 0) {
            $crow = mysqli_fetch_assoc(mysqli_query($mysqli,
                "SELECT category_required_skill_tag_id FROM categories WHERE category_id = $category_id LIMIT 1"));
            $required_skill_tag_id = intval($crow['category_required_skill_tag_id'] ?? 0);
        }

        // Candidate pool: technicians holding the required skill, if the category names one.
        $candidates = [];
        if ($required_skill_tag_id > 0) {
            $res = mysqli_query($mysqli,
                "SELECT DISTINCT u.user_id FROM users u
                 INNER JOIN user_tags ut ON ut.user_tag_user_id = u.user_id AND ut.user_tag_tag_id = $required_skill_tag_id
                 WHERE u.user_type = 1 AND u.user_status = 1 AND u.user_archived_at IS NULL"
            );
            while ($res && ($r = mysqli_fetch_assoc($res))) {
                $candidates[] = intval($r['user_id']);
            }
        }

        // Fall back to every active technician when the category has no required skill, or
        // nobody currently holds it - an unmatched ticket must still get dispatched, not stranded.
        if (empty($candidates)) {
            $res = mysqli_query($mysqli,
                "SELECT user_id FROM users WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL");
            while ($res && ($r = mysqli_fetch_assoc($res))) {
                $candidates[] = intval($r['user_id']);
            }
        }

        if (empty($candidates)) {
            return null; // no active technician at all
        }

        // Workload tiebreak: fewest open tickets wins. "Open" matches the same definition
        // cron.php's own schedule-trigger automation loop uses (ticket_archived_at IS NULL AND
        // ticket_resolved_at IS NULL) - one consistent meaning of "open" across the app.
        $ids = implode(',', $candidates);
        $counts = [];
        foreach ($candidates as $c) {
            $counts[$c] = 0;
        }
        $res = mysqli_query($mysqli,
            "SELECT ticket_assigned_to, COUNT(*) AS n FROM tickets
             WHERE ticket_assigned_to IN ($ids) AND ticket_archived_at IS NULL AND ticket_resolved_at IS NULL
             GROUP BY ticket_assigned_to"
        );
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $counts[intval($r['ticket_assigned_to'])] = intval($r['n']);
        }

        $best = null;
        foreach ($candidates as $c) {
            if ($best === null
                || $counts[$c] < $counts[$best]
                || ($counts[$c] === $counts[$best] && $c < $best)) {
                $best = $c;
            }
        }

        return $best;
    }
}
