<?php

require_once '../../../includes/modal_header.php';

$rel_type = (string) ($_GET['type'] ?? '');
$rel_id = intval($_GET['id'] ?? 0);

$actor = \RivetMSP\Links\LinkActor::fromSession($mysqli);
$self = \RivetMSP\Links\EntityTypes::isType($rel_type) ? (new \RivetMSP\Links\LinkService($mysqli))->lookup($rel_type, $rel_id) : null;
if ($self === null || !$actor->canRead($rel_type) || !$actor->canAccessClient($self['client_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'You cannot view that record.']);
    exit;
}
$name = nullable_htmlentities($self['name']);
$rel_in_modal = true;

ob_start();

?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-project-diagram me-2"></i>Relationships: <strong><?php echo $name; ?></strong></h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<div class="modal-body">
    <?php require __DIR__ . '/../../../includes/relationships_card.php'; ?>
</div>
<?php

require_once '../../../includes/modal_footer.php';
