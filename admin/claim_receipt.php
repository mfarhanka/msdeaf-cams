<?php
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/../includes/delegates.php';ensureDelegateClaimsSchema($pdo);
$stmt=$pdo->prepare('SELECT receipt_path,receipt_original_name FROM delegate_expense_claims WHERE id=? LIMIT 1');$stmt->execute([(int)($_GET['id']??0)]);$receipt=$stmt->fetch(PDO::FETCH_ASSOC);
if(!$receipt||!$receipt['receipt_path']){http_response_code(404);exit('Receipt not found.');}
$base=realpath(__DIR__.'/../uploads/delegate_claims');$file=$base?realpath($base.DIRECTORY_SEPARATOR.$receipt['receipt_path']):false;
if(!$base||!$file||!str_starts_with($file,$base.DIRECTORY_SEPARATOR)||!is_file($file)){http_response_code(404);exit('Receipt not found.');}
$mime=(new finfo(FILEINFO_MIME_TYPE))->file($file)?:'application/octet-stream';$name=preg_replace('/[^A-Za-z0-9._-]+/','_',basename((string)$receipt['receipt_original_name']))?:'receipt';
header('Content-Type: '.$mime);header('Content-Length: '.filesize($file));header('Content-Disposition: inline; filename="'.$name.'"');header('X-Content-Type-Options: nosniff');readfile($file);exit;
