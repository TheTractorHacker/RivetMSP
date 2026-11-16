<?php
// MSP-DS-50: KB image upload needs module_kb write, a CSRF token and real image bytes; the editor sends the token.
require __DIR__ . '/lib/ds_src.php';
$s = ds_src('agent/kb_article_upload.php');
ds_t('write level', strpos($s, 'enforceEditorImageUploadPermission()') !== false); // review R20: KB, support or client Write
ds_t('csrf checked before the file is touched', strpos($s, 'validateCSRFToken($_POST') !== false && strpos($s, 'validateCSRFToken') < strpos($s, '$_FILES'));
ds_t('getimagesize check before move_uploaded_file', strpos($s, 'getimagesize') < strpos($s, 'move_uploaded_file'));
$js = ds_src('js/app.js');
ds_t('editor no longer posts without a token', strpos($js, "images_upload_url: '/agent/kb_article_upload.php'") === false && strpos($js, "formData.append('csrf_token', window.csrfToken") !== false);
// the image sniffing really rejects a fake png
$tmp = tempnam(sys_get_temp_dir(), 'kb'); file_put_contents($tmp, '<?php echo 1;');
ds_t('fake image rejected', @getimagesize($tmp) === false); unlink($tmp);
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
$tmp = tempnam(sys_get_temp_dir(), 'kb'); file_put_contents($tmp, $png);
ds_t('real png accepted', @getimagesize($tmp)[2] === IMAGETYPE_PNG); unlink($tmp);
ds_done();
