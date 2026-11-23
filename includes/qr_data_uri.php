<?php
// MSP-DS-74: render a QR code server-side as a data: URI so secrets (TOTP seed) never travel in a URL / access log.
require_once __DIR__ . '/../plugins/barcode/barcode.php';

function qrPngDataUri(string $data): string
{
    $g = new barcode_generator();
    $img = $g->render_image('qr', $data, []);
    ob_start();
    imagepng($img);
    $png = ob_get_clean();
    imagedestroy($img);
    return 'data:image/png;base64,' . base64_encode($png);
}
