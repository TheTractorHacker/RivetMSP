<?php

require_once '../../../includes/modal_header.php';

$rel_type = (string) ($_GET['type'] ?? '');
$rel_id = intval($_GET['id'] ?? 0);

$actor = \RivetMSP\Links\LinkActor::fromSession($mysqli);
$self = \RivetMSP\Links\EntityTypes::isType($rel_type) ? (new \RivetMSP\Links\LinkService($mysqli))->lookup($rel_type, $rel_id) : null;
if ($self === null) {
    http_response_code(403);
    echo json_encode(['error' => 'That record no longer exists.']);
    exit;
}
if (!$actor->canWrite($rel_type) || !$actor->canAccessClient($self['client_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Your role cannot change this record.']);
    exit;
}
$name = nullable_htmlentities($self['name']);

ob_start();

?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-link me-2"></i>Link item to <strong><?php echo $name; ?></strong></h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<div class="modal-body">
    <?php require __DIR__ . '/../../../includes/relationships_link_form.php'; ?>
</div>
<?php

require_once '../../../includes/modal_footer.php';
