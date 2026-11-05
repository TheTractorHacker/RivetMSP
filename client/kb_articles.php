<?php
/*
 * Client Portal
 * Knowledge Base - Central (company-wide) + client-specific articles
 */

header("Content-Security-Policy: default-src 'self'");

require_once "includes/inc_all.php";

if ($config_module_enable_kb != 1) {
    header("Location: index.php");
    exit();
}

$q = isset($_GET['q']) ? sanitizeInput($_GET['q']) : '';

if ($q) {
    $q_escaped = mysqli_real_escape_string($mysqli, $q);
    $kb_search_query = "AND (kb_article_title LIKE '%$q%' OR MATCH(kb_article_content_raw) AGAINST ('$q_escaped'))";
} else {
    $kb_search_query = '';
}

$kb_articles_sql = mysqli_query(
    $mysqli,
    "SELECT kb_articles.*, kb_categories.kb_category_name
     FROM kb_articles
     LEFT JOIN kb_categories ON kb_categories.kb_category_id = kb_articles.kb_article_category_id
     WHERE kb_article_client_visible = 1
     AND kb_article_client_id IN (0, $session_client_id)
     AND kb_article_archived_at IS NULL
     $kb_search_query
     ORDER BY kb_category_name IS NULL, kb_category_name ASC, kb_article_title ASC"
);

// Group articles by category for the card grid
$kb_groups = [];
while ($row = mysqli_fetch_assoc($kb_articles_sql)) {
    $group_name = $row['kb_category_name'] ?? 'Uncategorized';
    $kb_groups[$group_name][] = $row;
}
// "Uncategorized" group always last
if (isset($kb_groups['Uncategorized'])) {
    $uncategorized = $kb_groups['Uncategorized'];
    unset($kb_groups['Uncategorized']);
    $kb_groups['Uncategorized'] = $uncategorized;
}

?>

<div class="row">
    <div class="col">
        <h3><i class="fas fa-book me-2"></i>Knowledge Base</h3>
    </div>
</div>

<div class="row mt-3">
    <div class="col-md-12">
        <form autocomplete="off" class="mb-3">
            <div class="input-group" style="max-width: 400px">
                <input type="search" class="form-control" name="q" value="<?= nullable_htmlentities($q) ?>" placeholder="Search Knowledge Base">
                <div class="input-group-append">
                    <button class="btn btn-primary" aria-label="Search"><i class="fa fa-search"></i></button>
                </div>
            </div>
        </form>

        <?php if (empty($kb_groups)) { ?>
            <div class="kb-empty">
                <i class="fas fa-book"></i>
                No articles found.
            </div>
        <?php } ?>

        <?php foreach ($kb_groups as $group_name => $articles) { ?>
            <div class="kb-chapter">
                <span class="kb-chapter-icon"><i class="fas fa-fw fa-folder"></i></span>
                <span class="kb-chapter-name"><?= nullable_htmlentities($group_name) ?></span>
                <span class="kb-chapter-count"><?= count($articles) ?> article<?= count($articles) == 1 ? '' : 's' ?></span>
            </div>
            <div class="row">
                <?php foreach ($articles as $row) {
                    $kb_article_id = intval($row['kb_article_id']);
                    $kb_article_title = nullable_htmlentities($row['kb_article_title']);
                    $kb_article_client_id = intval($row['kb_article_client_id']);
                    $kb_article_updated_at = $row['kb_article_updated_at'] ?? $row['kb_article_created_at'];

                    $kb_article_preview = strip_tags($row['kb_article_content_raw'] ?? '');
                    $kb_article_preview = trim(preg_replace('/\s+/', ' ', $kb_article_preview));
                    if (mb_strlen($kb_article_preview) > 150) {
                        $kb_article_preview = mb_substr($kb_article_preview, 0, 150) . '...';
                    }
                ?>
                    <div class="col-md-4 mb-4">
                        <div class="card kb-article-card">
                            <div class="card-body">
                                <h5 class="card-title">
                                    <a href="kb_article.php?id=<?= $kb_article_id ?>"><?= $kb_article_title ?></a>
                                    <?php if ($kb_article_client_id == 0) { ?>
                                        <span class="badge text-bg-info ms-2">Central</span>
                                    <?php } ?>
                                </h5>
                                <p class="card-text"><?= nullable_htmlentities($kb_article_preview) ?></p>
                            </div>
                            <div class="card-footer d-flex align-items-center justify-content-between">
                                <span class="kb-article-meta"><?= date('M j, Y', strtotime($kb_article_updated_at)) ?></span>
                                <a href="kb_article.php?id=<?= $kb_article_id ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-eye me-1"></i>View
                                </a>
                            </div>
                        </div>
                    </div>
                <?php } ?>
            </div>
        <?php } ?>
    </div>
</div>

<?php
require_once "includes/footer.php";
