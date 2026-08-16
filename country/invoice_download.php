<?php
require_once 'includes/auth.php';
require_once '../includes/invoices.php';
sendInvoicePdf($pdo,(int)($_GET['id']??0),(int)$_SESSION['id']);
