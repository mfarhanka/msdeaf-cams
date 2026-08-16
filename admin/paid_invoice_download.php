<?php
require_once 'includes/auth.php';require_once '../includes/invoices.php';sendPaidInvoicePdf($pdo,(int)($_GET['id']??0));
