<?php
session_start();
if (empty($_SESSION['delegate_loggedin']) || empty($_SESSION['delegate_id'])) { header('location: ../../delegate_login.php'); exit; }
require_once __DIR__.'/../../includes/db.php';
require_once __DIR__.'/../../includes/delegates.php';
require_once __DIR__.'/../../includes/activity.php';
require_once __DIR__.'/../../includes/flights.php';
ensureDelegateSelfServiceSchema($pdo);
ensureDelegateClaimsSchema($pdo);
ensureDelegationFlightsTable($pdo);
$delegateId = (int)$_SESSION['delegate_id'];
$stmt = $pdo->prepare("SELECT a.*,u.country_name,u.username AS delegation_username,ps.name AS participant_subtype_name,h.name AS hotel_name,ra.room_number,
(SELECT MIN(f.flight_datetime) FROM delegation_flight_movements f JOIN delegation_flight_movement_members fm ON fm.movement_id=f.id WHERE fm.athlete_id=a.id AND f.direction='arrival') arrival_datetime,
(SELECT MIN(f.flight_number) FROM delegation_flight_movements f JOIN delegation_flight_movement_members fm ON fm.movement_id=f.id WHERE fm.athlete_id=a.id AND f.direction='arrival') arrival_flight,
(SELECT MIN(f.flight_datetime) FROM delegation_flight_movements f JOIN delegation_flight_movement_members fm ON fm.movement_id=f.id WHERE fm.athlete_id=a.id AND f.direction='departure') departure_datetime,
(SELECT MIN(f.flight_number) FROM delegation_flight_movements f JOIN delegation_flight_movement_members fm ON fm.movement_id=f.id WHERE fm.athlete_id=a.id AND f.direction='departure') departure_flight
FROM athletes a JOIN users u ON u.id=a.country_id LEFT JOIN participant_subtypes ps ON ps.id=a.participant_subtype_id LEFT JOIN room_assignments ra ON ra.athlete_id=a.id LEFT JOIN bookings b ON b.id=ra.booking_id LEFT JOIN hotels h ON h.id=b.hotel_id WHERE a.id=? LIMIT 1");
$stmt->execute([$delegateId]); $delegate=$stmt->fetch(PDO::FETCH_ASSOC);
if(!$delegate){$_SESSION=[];session_destroy();header('location: ../../delegate_login.php');exit;}
