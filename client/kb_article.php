<?php
/*
 * Client Portal
 * Knowledge Base - Article detail (read-only)
 */

header("Content-Security-Policy: default-src 'self'; img-src 'self' data:");

require_once "includes/inc_all.php";

if ($config_module_enable_kb != 1) {
    header("Location: index.php");
    exit();
}

//Initialize the HTML Purifier to prevent XSS
require_once "../plugins/htmlpurifier/HTMLPurifier.standalone.php";

$purifier_config = HTMLPurifier_Config::createDefault();
$purifier_config->set('Cache.DefinitionImpl', null); // Disable cache by setting a non-existent directory or an invalid one
$purifier_config->set('URI.AllowedSchemes', ['http' => true, 'https' => true]);
$purifier = new HTMLPurifier($purifier_config);

// Check for an article ID
if (!isset($_GET['id']) || !intval($_GET['id'])) {
    header("Location: kb_articles.php");
    exit();
}

$kb_article_id = intval($_GET['id']);

$sql_kb_article = mysqli_query($mysqli,
    "SELECT kb_article_id, kb_article_title, kb_article_content, kb_article_client_id, kb_article_updated_at, kb_article_created_at
     FROM kb_articles
     WHERE kb_article_id = $kb_article_id
     AND kb_article_client_visible = 1
     AND kb_article_client_id IN (0, $session_client_id)
     AND kb_article_archived_at IS NULL
     LIMIT 1"
);

$row = mysqli_fetch_assoc($sql_kb_article);

if ($row) {
    $kb_article_id = intval($row['kb_article_id']);
    $kb_article_title = nullable_htmlentities($row['kb_article_title']);
    $kb_article_content = $purifier->purify($row['kb_article_content']);
    // Credentials are agent-only; the portal has no reveal modal, so drop [[credential:ID]] tokens rather than show raw text.
    $kb_article_content = preg_replace('/\[\[credential:\d+\]\]/i', '', $kb_article_content);
    $kb_article_updated_at = $row['kb_article_updated_at'] ?? $row['kb_article_created_at'];
} else {
    flash_alert("Article not found", "error");
    header("Location: kb_articles.php");
    exit();
}

// Roadmap #7 ("KB v2" slice 2): media attachments + embeds, ported from RivetIT. Read-only on the portal - the
// article row above already proved this session may read $kb_article_id, which is the same scope
// client/kb_media.php and client/kb_embed.php re-derive independently, per request, on their own.
$sql_attachments = mysqli_query(
    $mysqli,
    "SELECT * FROM kb_article_attachments WHERE kb_article_attachment_kb_article_id = $kb_article_id ORDER BY kb_article_attachment_created_at ASC"
);
$sql_embeds = mysqli_query(
    $mysqli,
    "SELECT * FROM kb_article_embeds WHERE kb_article_embed_kb_article_id = $kb_article_id ORDER BY kb_article_embed_created_at ASC"
) ?: null;

?>

<ol class="breadcrumb d-print-none">
    <li class="breadcrumb-item">
        <a href="index.php">Home</a>
    </li>
    <li class="breadcrumb-item">
        <a href="kb_articles.php">Knowledge Base</a>
    </li>
    <li class="breadcrumb-item active">
        <?php echo $kb_article_title; ?>
    </li>
</ol>

<div class="card kb-reader-card">
    <div class="card-header">
        <div class="h4 mb-0"><?php echo $kb_article_title; ?></div>
        <div class="kb-reader-meta">
            <span><i class="far fa-fw fa-clock me-1"></i>Updated <?php echo date('M j, Y', strtotime($kb_article_updated_at)); ?></span>
        </div>
    </div>
    <div class="card-body prettyContent kb-prose">
        <?php echo $kb_article_content; ?>
    </div>
</div>

<?php if ($sql_attachments && mysqli_num_rows($sql_attachments) > 0) { ?>
<div class="card kb-reader-card mt-3 d-print-none">
    <div class="card-header">
        <h5 class="card-title mb-0"><i class="fas fa-fw fa-paperclip me-2"></i>Attachments</h5>
    </div>
    <ul class="list-group list-group-flush">
        <?php while ($att = mysqli_fetch_assoc($sql_attachments)) {
            $att_id = intval($att['kb_article_attachment_id']);
            $att_name = nullable_htmlentities($att['kb_article_attachment_name']);
        ?>
        <li class="list-group-item d-flex align-items-center justify-content-between">
            <span class="text-truncate me-2"><i class="fas fa-fw fa-file me-1"></i><?php echo $att_name; ?></span>
            <span>
                <a target="_blank" class="btn btn-sm btn-secondary" title="View attachment" aria-label="View attachment" href="kb_media.php?att=<?php echo $att_id; ?>"><i class="fas fa-fw fa-eye"></i></a>
                <a class="btn btn-sm btn-secondary" title="Download attachment" aria-label="Download attachment" href="kb_media.php?att=<?php echo $att_id; ?>&download=1"><i class="fas fa-fw fa-download"></i></a>
            </span>
        </li>
        <?php } ?>
    </ul>
</div>
<?php } ?>

<?php if ($sql_embeds && mysqli_num_rows($sql_embeds) > 0) { ?>
<div class="card kb-reader-card mt-3 d-print-none">
    <div class="card-header">
        <h5 class="card-title mb-0"><i class="fas fa-fw fa-puzzle-piece me-2"></i>Embeds</h5>
    </div>
    <div class="card-body">
        <?php while ($embed = mysqli_fetch_assoc($sql_embeds)) {
            $embed_id = intval($embed['kb_article_embed_id']);
            $embed_name = nullable_htmlentities($embed['kb_article_embed_name']);
            $embed_height = intval($embed['kb_article_embed_height'] ?? 480);
            if ($embed_height < 120 || $embed_height > 4000) {
                $embed_height = 480;
            }
        ?>
        <p class="text-secondary small mb-1"><?php echo $embed_name; ?></p>
        <iframe src="kb_embed.php?id=<?php echo $embed_id; ?>" sandbox="allow-scripts" referrerpolicy="no-referrer" loading="lazy"
                style="width:100%;height:<?php echo $embed_height; ?>px;border:0;" class="mb-3"></iframe>
        <?php } ?>
    </div>
</div>
<?php } ?>

<?php
require_once "includes/footer.php";
