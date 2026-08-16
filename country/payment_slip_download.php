<?php
require_once 'includes/auth.php';require_once '../includes/invoices.php';sendPaymentSlip($pdo,(int)($_GET['id']??0),(int)$_SESSION['id']);
