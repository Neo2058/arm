<?php
// Run only with php -S 127.0.0.1:8786 -t public .../local-router.php.
if (!in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'], true)) {http_response_code(403);exit;}
$root=dirname(__DIR__,4);
$path=realpath($root.'/public'.parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH));
if ($path && str_starts_with($path,$root.'/public/') && is_file($path)) {
    $extension=strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (in_array($extension,['css','js','png','jpg','jpeg','svg','webp','woff','woff2','ttf','ico'],true)) return false;
}
$app=require __DIR__.'/local-app.php';
$app->handleRequest(Illuminate\Http\Request::capture());
