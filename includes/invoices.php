<?php

function ensureInvoiceSchema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS invoices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_number VARCHAR(80) NOT NULL UNIQUE,
        country_id INT NOT NULL,
        revision_of_id INT NULL,
        issued_by INT NULL,
        issued_at DATETIME NOT NULL,
        currency VARCHAR(3) NOT NULL DEFAULT 'USD',
        participant_count INT NOT NULL DEFAULT 0,
        subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
        total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        snapshot_json LONGTEXT NOT NULL,
        pdf_data LONGBLOB NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_invoices_country (country_id, issued_at),
        CONSTRAINT fk_invoices_country FOREIGN KEY (country_id) REFERENCES users(id),
        CONSTRAINT fk_invoices_revision FOREIGN KEY (revision_of_id) REFERENCES invoices(id) ON DELETE SET NULL,
        CONSTRAINT fk_invoices_issuer FOREIGN KEY (issued_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS invoice_lines (
        id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_id INT NOT NULL,
        line_order INT NOT NULL,
        line_type ENUM('participation','accommodation') NOT NULL,
        description VARCHAR(255) NOT NULL,
        quantity DECIMAL(10,2) NOT NULL,
        nights INT NULL,
        unit_price DECIMAL(12,2) NOT NULL,
        line_total DECIMAL(12,2) NOT NULL,
        details_json LONGTEXT NULL,
        CONSTRAINT fk_invoice_lines_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS invoice_payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_id INT NOT NULL,
        country_id INT NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        original_filename VARCHAR(255) NOT NULL,
        mime_type VARCHAR(80) NOT NULL,
        slip_data LONGBLOB NOT NULL,
        status ENUM('Pending','Accepted','Rejected') NOT NULL DEFAULT 'Pending',
        admin_note VARCHAR(500) NULL,
        reviewed_by INT NULL,
        reviewed_at DATETIME NULL,
        paid_invoice_pdf LONGBLOB NULL,
        paid_invoice_generated_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_invoice_payments_status (status, created_at),
        INDEX idx_invoice_payments_country (country_id, created_at),
        CONSTRAINT fk_invoice_payments_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
        CONSTRAINT fk_invoice_payments_country FOREIGN KEY (country_id) REFERENCES users(id),
        CONSTRAINT fk_invoice_payments_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function invoiceSettingDefaults(): array
{
    return [
        'invoice_org_name' => 'PERSATUAN SUKAN ORANG PEKAK MALAYSIA',
        'invoice_org_name_en' => 'MALAYSIAN DEAF SPORTS ASSOCIATION',
        'invoice_address' => 'No 9-2, Jalan Dwitasik 2, Dataran Dwitasik, Bandar Sri Permaisuri, 56000 Kuala Lumpur',
        'invoice_phone' => '(+6)03-9171 0502', 'invoice_fax' => '(+6)03-9171 7502',
        'invoice_email' => 'info@msdeaf.org.my', 'invoice_website' => 'www.msdeaf.org.my',
        'invoice_prefix' => 'IV-APDMSC26', 'invoice_terms' => 'Net 30 days',
        'invoice_currency' => 'USD', 'invoice_participation_fee' => '50.00', 'invoice_deposit_percent' => '70',
        'invoice_bank_account' => 'CIMB Bank Berhad',
        'invoice_bank_name' => 'PERSATUAN SUKAN ORANG PEKAK MALAYSIA',
        'invoice_account_no' => '8000852319', 'invoice_branch_name' => 'WISMA KOPONAS, KUALA LUMPUR',
        'invoice_swift_code' => 'CIBBMYKL', 'invoice_branch_code' => '1426',
        'invoice_payment_email' => 'jasmine@msdeaf.org.my', 'invoice_logo_path' => 'association-logo.jpg',
    ];
}

function getInvoiceSettings(PDO $pdo): array
{
    $settings = invoiceSettingDefaults();
    foreach ($settings as $key => $default) {
        $settings[$key] = getAppSetting($pdo, $key, $default);
        if ($key === 'invoice_logo_path' && trim((string)$settings[$key]) === '') $settings[$key] = $default;
    }
    return $settings;
}

function buildInvoiceSnapshot(PDO $pdo, int $countryId): array
{
    $countryStmt = $pdo->prepare("SELECT id, country_name, username FROM users WHERE id = ? AND role = 'country_manager' LIMIT 1");
    $countryStmt->execute([$countryId]);
    $country = $countryStmt->fetch(PDO::FETCH_ASSOC);
    if (!$country) throw new RuntimeException('Delegation not found.');

    $peopleStmt = $pdo->prepare("SELECT id, first_name, last_name, participant_type FROM athletes WHERE country_id = ? ORDER BY last_name, first_name");
    $peopleStmt->execute([$countryId]);
    $people = $peopleStmt->fetchAll(PDO::FETCH_ASSOC);

    $bookingStmt = $pdo->prepare("SELECT b.id, b.rooms_reserved, b.booking_start_date, b.booking_end_date,
        c.title AS championship_title, h.name AS hotel_name, rt.name AS room_type_name, rt.capacity, rt.price_per_night,
        GREATEST(1, DATEDIFF(b.booking_end_date, b.booking_start_date)) AS nights
        FROM bookings b JOIN championships c ON c.id=b.championship_id JOIN hotels h ON h.id=b.hotel_id
        JOIN room_types rt ON rt.id=b.room_type_id WHERE b.country_id=? AND b.status <> 'Cancelled'
        ORDER BY b.booking_start_date, h.name, rt.name");
    $bookingStmt->execute([$countryId]);
    $bookings = $bookingStmt->fetchAll(PDO::FETCH_ASSOC);
    $memberStmt = $pdo->prepare("SELECT CONCAT(a.first_name, ' ', a.last_name) AS name, ra.room_number
        FROM room_assignments ra JOIN athletes a ON a.id=ra.athlete_id WHERE ra.booking_id=? ORDER BY ra.room_number, a.last_name, a.first_name");
    foreach ($bookings as &$booking) {
        $memberStmt->execute([(int)$booking['id']]);
        $booking['participants'] = $memberStmt->fetchAll(PDO::FETCH_ASSOC);
        $booking['charged_pax'] = (int)$booking['rooms_reserved'] * max(1, (int)$booking['capacity']);
        $booking['line_total'] = $booking['charged_pax'] * (float)$booking['price_per_night'] * (int)$booking['nights'];
    }
    unset($booking);

    $settings = getInvoiceSettings($pdo);
    $participationTotal = count($people) * (float)$settings['invoice_participation_fee'];
    $accommodationTotal = array_sum(array_column($bookings, 'line_total'));
    return ['country'=>$country, 'people'=>$people, 'bookings'=>$bookings, 'settings'=>$settings,
        'participant_count'=>count($people), 'participation_total'=>$participationTotal,
        'accommodation_total'=>$accommodationTotal, 'total'=>$participationTotal+$accommodationTotal];
}

function invoiceAmountWords(float $amount): string
{
    $n = (int)round($amount);
    if ($n === 0) return 'ZERO ONLY';
    $ones=['','ONE','TWO','THREE','FOUR','FIVE','SIX','SEVEN','EIGHT','NINE','TEN','ELEVEN','TWELVE','THIRTEEN','FOURTEEN','FIFTEEN','SIXTEEN','SEVENTEEN','EIGHTEEN','NINETEEN'];
    $tens=['','','TWENTY','THIRTY','FORTY','FIFTY','SIXTY','SEVENTY','EIGHTY','NINETY'];
    $part=function($x) use (&$part,$ones,$tens) { if($x<20)return $ones[$x]; if($x<100)return trim($tens[intdiv($x,10)].' '.$ones[$x%10]); if($x<1000)return trim($ones[intdiv($x,100)].' HUNDRED '.$part($x%100)); if($x<1000000)return trim($part(intdiv($x,1000)).' THOUSAND '.$part($x%1000)); return trim($part(intdiv($x,1000000)).' MILLION '.$part($x%1000000)); };
    return trim($part($n)).' ONLY';
}

function pdfEscape(string $value): string { return str_replace(['\\','(',')'], ['\\\\','\\(','\\)'], $value); }
function pdfTextWidth(string $value, float $size, bool $bold=false): float
{
    $regular=[
        ' '=>278,'!'=>278,'"'=>355,'#'=>556,'$'=>556,'%'=>889,'&'=>667,"'"=>191,'('=>333,')'=>333,'*'=>389,'+'=>584,','=>278,'-'=>333,'.'=>278,'/'=>278,
        ':'=>278,';'=>278,'<'=>584,'='=>584,'>'=>584,'?'=>556,'@'=>1015,'A'=>667,'B'=>667,'C'=>722,'D'=>722,'E'=>667,'F'=>611,'G'=>778,'H'=>722,'I'=>278,'J'=>500,'K'=>667,'L'=>556,'M'=>833,'N'=>722,'O'=>778,'P'=>667,'Q'=>778,'R'=>722,'S'=>667,'T'=>611,'U'=>722,'V'=>667,'W'=>944,'X'=>667,'Y'=>667,'Z'=>611,
        '['=>278,'\\'=>278,']'=>278,'^'=>469,'_'=>556,'`'=>333,'a'=>556,'b'=>556,'c'=>500,'d'=>556,'e'=>556,'f'=>278,'g'=>556,'h'=>556,'i'=>222,'j'=>222,'k'=>500,'l'=>222,'m'=>833,'n'=>556,'o'=>556,'p'=>556,'q'=>556,'r'=>333,'s'=>500,'t'=>278,'u'=>556,'v'=>500,'w'=>722,'x'=>500,'y'=>500,'z'=>500,'{'=>334,'|'=>260,'}'=>334,'~'=>584,
    ];
    $boldWidths=array_merge($regular,['A'=>722,'B'=>722,'C'=>722,'D'=>722,'E'=>667,'F'=>611,'G'=>778,'H'=>722,'I'=>278,'J'=>556,'K'=>722,'L'=>611,'M'=>833,'N'=>722,'O'=>778,'P'=>667,'Q'=>778,'R'=>722,'S'=>667,'T'=>611,'U'=>722,'V'=>722,'W'=>944,'X'=>722,'Y'=>722,'Z'=>611,'a'=>556,'b'=>611,'c'=>556,'d'=>611,'e'=>556,'f'=>333,'g'=>611,'h'=>611,'i'=>278,'j'=>278,'k'=>556,'l'=>278,'m'=>889,'n'=>611,'o'=>611,'p'=>611,'q'=>611,'r'=>389,'s'=>556,'t'=>333,'u'=>611,'v'=>556,'w'=>778,'x'=>556,'y'=>556]);
    $widths=$bold?$boldWidths:$regular;$units=0;foreach(str_split($value) as $char){$units+=$widths[$char]??556;}return $units*$size/1000;
}

function generateInvoicePdf(array $s, string $number, string $issuedAt, ?array $payment=null): string
{
    $s['settings']=array_merge(invoiceSettingDefaults(),$s['settings']??[]);if(trim((string)$s['settings']['invoice_logo_path'])==='')$s['settings']['invoice_logo_path']=invoiceSettingDefaults()['invoice_logo_path'];
    $pages=[]; $content=''; $y=805; $logoData=null; $logoWidth=0; $logoHeight=0;
    $logoPath=(string)($s['settings']['invoice_logo_path']??'');
    if($logoPath!==''){$absolute=dirname(__DIR__).DIRECTORY_SEPARATOR.str_replace(['/', '\\'],DIRECTORY_SEPARATOR,$logoPath);if(is_file($absolute)){[$logoWidth,$logoHeight,$type]=getimagesize($absolute);if($type===IMAGETYPE_JPEG)$logoData=file_get_contents($absolute);}}
    $line=function($text,$x,$size=9,$bold=false) use (&$content,&$y) { $content.="BT /".($bold?'F2':'F1')." $size Tf $x $y Td (".pdfEscape((string)$text).") Tj ET\n"; };
    $lineRight=function($text,$right,$size=9,$bold=false) use (&$content,&$y) { $x=max(28,$right-pdfTextWidth((string)$text,(float)$size,(bool)$bold));$content.="BT /".($bold?'F2':'F1')." $size Tf $x $y Td (".pdfEscape((string)$text).") Tj ET\n"; };
    $rule=function($at) use (&$content) { $content.="0.5 w 28 $at m 567 $at l S\n"; };
    $newPage=function() use (&$pages,&$content,&$y,$s,$number,$issuedAt,$payment,&$line,&$lineRight,&$rule) {
        if ($content!=='') $pages[]=$content; $content=''; $y=805;
        $headerRight=460;
        $lineRight($s['settings']['invoice_org_name'],$headerRight,13,true); $y-=16; $lineRight($s['settings']['invoice_org_name_en'],$headerRight,12,true); $y-=15;
        $lineRight($s['settings']['invoice_address'],$headerRight,8); $y-=12; $lineRight('Tel '.$s['settings']['invoice_phone'].'   Fax '.$s['settings']['invoice_fax'],$headerRight,8); $y-=12;
        $lineRight('E-mail: '.$s['settings']['invoice_email'].'   Website: '.$s['settings']['invoice_website'],$headerRight,8); $y-=23; $rule($y); $y-=25;
        $line($payment===null?'PROFORMA INVOICE':'PAID INVOICE',405,13,true); $y-=22; $line($s['country']['country_name'] ?: $s['country']['username'],35,11,true);
        $line('Invoice No: '.$number,365,9,true); $y-=14; $line('Terms: '.$s['settings']['invoice_terms'],365,9); $y-=14; $line('Date: '.date('d.m.Y',strtotime($issuedAt)),365,9); $y-=22; $rule($y); $y-=18;
        $line('No',35,9,true); $line('Description',75,9,true); $line('Quantity',330,9,true); $line('Night',400,9,true); $line('Price / Unit (USD)',438,8,true); $line('Total (USD)',520,8,true); $y-=12; $rule($y); $y-=18;
    };
    $row=function($no,$desc,$qty,$nights,$unit,$total,$bold=false) use (&$y,&$line,$newPage) { if($y<150)$newPage(); $line($no,38,9); $line($desc,75,9,$bold); $line($qty,345,9); $line($nights,410,9); $line('$ '.number_format((float)$unit,2),455,9); $line('$ '.number_format((float)$total,2),520,9); $y-=17; };
    $newPage(); $i=1;
    if($s['participant_count']>0) $row($i++,'PARTICIPATION FEE',$s['participant_count'],'',$s['settings']['invoice_participation_fee'],$s['participation_total']);
    foreach($s['bookings'] as $b) {
        $row($i++,$b['hotel_name'].' - '.$b['room_type_name'],$b['charged_pax'],$b['nights'],$b['price_per_night'],$b['line_total'],true);
        $line('Stay: '.date('d M Y',strtotime($b['booking_start_date'])).' to '.date('d M Y',strtotime($b['booking_end_date'])),90,8); $y-=13;
        foreach($b['participants'] as $p) { if($y<150)$newPage(); $line('- '.$p['name'].($p['room_number']?' (Room '.$p['room_number'].')':''),100,8); $y-=12; }
    }
    if($y<175)$newPage(); $y-=8; $rule($y); $y-=22; $line('DOLLARS: '.invoiceAmountWords($s['total']),30,9,true); $y-=28; $rule($y); $y-=18;
    $depositPercent=max(0,min(100,(float)($s['settings']['invoice_deposit_percent']??70)));$depositAmount=round($s['total']*$depositPercent/100,2);$balanceAmount=$s['total']-$depositAmount;
    $line('BANK DETAILS',35,9,true); $line('TOTAL AMOUNT:',365,10,true); $line('USD $ '.number_format($s['total'],2),490,11,true); $y-=14;
    $line('DEPOSIT '.number_format($depositPercent,0).'%:',365,10,true);$line('USD $ '.number_format($depositAmount,2),490,10,true);$y-=14;
    $line('BALANCE PAYMENT:',365,10,true);$line('USD $ '.number_format($balanceAmount,2),490,10,true);$y-=14;
    if($payment!==null){$line('PAYMENT STATUS:',365,10,true);$line('PAID',520,10,true);$y-=14;$line('Accepted: '.date('d.m.Y',strtotime($payment['reviewed_at'])),365,8);$y-=12;}
    foreach(['Bank Account'=>'invoice_bank_account','Bank Name'=>'invoice_bank_name','Account No'=>'invoice_account_no','Branch Name'=>'invoice_branch_name','Swift Code'=>'invoice_swift_code','Branch Code'=>'invoice_branch_code'] as $label=>$key){$line($label.': '.$s['settings'][$key],35,8,$label==='Bank Name');$y-=12;}
    $y-=8; $line('Please send the transaction slip to: '.$s['settings']['invoice_payment_email'],35,8,true);
    if($content!=='')$pages[]=$content;
    $pageCount=count($pages);foreach($pages as $pageIndex=>&$pageContent){$pageContent.="BT /F1 8 Tf 510 28 Td (Page ".($pageIndex+1)." of $pageCount) Tj ET\n";}unset($pageContent);

    $objects=[]; $objects[1]='<< /Type /Catalog /Pages 2 0 R >>'; $kids=[]; $obj=5;$imageResource='';
    if($logoData!==null){$imageObj=$obj++;$objects[$imageObj]='<< /Type /XObject /Subtype /Image /Width '.$logoWidth.' /Height '.$logoHeight.' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($logoData)." >>\nstream\n".$logoData."\nendstream";$imageResource=" /XObject << /Im1 $imageObj 0 R >>";$scale=min(92/$logoWidth,92/$logoHeight);$drawWidth=round($logoWidth*$scale,2);$drawHeight=round($logoHeight*$scale,2);$drawX=570-$drawWidth;$drawY=742;foreach($pages as &$pageContent){$pageContent="q $drawWidth 0 0 $drawHeight $drawX $drawY cm /Im1 Do Q\n".$pageContent;}unset($pageContent);}
    foreach($pages as $p){$pageObj=$obj++;$streamObj=$obj++;$kids[]="$pageObj 0 R";$objects[$pageObj]="<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >>$imageResource >> /Contents $streamObj 0 R >>";$objects[$streamObj]="<< /Length ".strlen($p)." >>\nstream\n$p\nendstream";}
    $objects[2]='<< /Type /Pages /Kids ['.implode(' ',$kids).'] /Count '.count($kids).' >>'; $objects[3]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';$objects[4]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';ksort($objects);
    $pdf="%PDF-1.4\n";$offset=[0];foreach($objects as $id=>$body){$offset[$id]=strlen($pdf);$pdf.="$id 0 obj\n$body\nendobj\n";}$xref=strlen($pdf);$max=max(array_keys($objects));$pdf.="xref\n0 ".($max+1)."\n0000000000 65535 f \n";for($j=1;$j<=$max;$j++)$pdf.=sprintf('%010d 00000 n ', $offset[$j])."\n";$pdf.="trailer << /Size ".($max+1)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";return $pdf;
}

function issueDelegationInvoice(PDO $pdo, int $countryId, int $issuerId, ?int $revisionOf=null): int
{
    ensureInvoiceSchema($pdo); $s=buildInvoiceSnapshot($pdo,$countryId);
    if($s['participant_count']===0 && count($s['bookings'])===0) throw new RuntimeException('This delegation has no chargeable data.');
    $pdo->beginTransaction();
    try {
        $settings=$s['settings']; $prefix=preg_replace('/[^A-Za-z0-9_-]/','',$settings['invoice_prefix']) ?: 'INV'; $month=date('Ym');
        $lock=$pdo->prepare("SELECT invoice_number FROM invoices WHERE invoice_number LIKE ? ORDER BY id DESC LIMIT 1 FOR UPDATE");$lock->execute([$prefix.'/'.$month.'/%']);
        $seq=(int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE invoice_number LIKE ".$pdo->quote($prefix.'/'.$month.'/%'))->fetchColumn()+1;
        do{$number=sprintf('%s/%s/%03d',$prefix,$month,$seq++);$check=$pdo->prepare('SELECT COUNT(*) FROM invoices WHERE invoice_number=?');$check->execute([$number]);}while((int)$check->fetchColumn()>0);
        $issued=date('Y-m-d H:i:s');$pdf=generateInvoicePdf($s,$number,$issued);$json=json_encode($s,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $stmt=$pdo->prepare("INSERT INTO invoices(invoice_number,country_id,revision_of_id,issued_by,issued_at,currency,participant_count,subtotal,total_amount,snapshot_json,pdf_data) VALUES(?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$number,$countryId,$revisionOf,$issuerId,$issued,$settings['invoice_currency'],$s['participant_count'],$s['total'],$s['total'],$json,$pdf]);$id=(int)$pdo->lastInsertId();
        $line=$pdo->prepare("INSERT INTO invoice_lines(invoice_id,line_order,line_type,description,quantity,nights,unit_price,line_total,details_json) VALUES(?,?,?,?,?,?,?,?,?)");$order=1;
        if($s['participant_count']>0)$line->execute([$id,$order++,'participation','Participation fee',$s['participant_count'],null,$settings['invoice_participation_fee'],$s['participation_total'],json_encode($s['people'])]);
        foreach($s['bookings'] as $b)$line->execute([$id,$order++,'accommodation',$b['hotel_name'].' - '.$b['room_type_name'],$b['charged_pax'],$b['nights'],$b['price_per_night'],$b['line_total'],json_encode($b)]);
        $pdo->commit(); return $id;
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function sendInvoicePdf(PDO $pdo, int $invoiceId, ?int $countryId=null): void
{
    ensureInvoiceSchema($pdo);$sql='SELECT invoice_number,pdf_data,country_id FROM invoices WHERE id=?';$params=[$invoiceId];if($countryId!==null){$sql.=' AND country_id=?';$params[]=$countryId;}$stmt=$pdo->prepare($sql);$stmt->execute($params);$invoice=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$invoice){http_response_code(404);exit('Invoice not found.');}$filename=preg_replace('/[^A-Za-z0-9._-]/','-',$invoice['invoice_number']).'.pdf';header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="'.$filename.'"');header('Content-Length: '.strlen($invoice['pdf_data']));header('X-Content-Type-Options: nosniff');echo $invoice['pdf_data'];exit;
}

function getInvoiceDepositAmount(array $snapshot): float
{
    $percent=max(0,min(100,(float)($snapshot['settings']['invoice_deposit_percent']??70)));return round((float)$snapshot['total']*$percent/100,2);
}

function sendPaymentSlip(PDO $pdo,int $paymentId,?int $countryId=null):void
{
    ensureInvoiceSchema($pdo);$sql='SELECT original_filename,mime_type,slip_data FROM invoice_payments WHERE id=?';$params=[$paymentId];if($countryId!==null){$sql.=' AND country_id=?';$params[]=$countryId;}$stmt=$pdo->prepare($sql);$stmt->execute($params);$p=$stmt->fetch(PDO::FETCH_ASSOC);if(!$p){http_response_code(404);exit('Payment slip not found.');}$name=preg_replace('/[^A-Za-z0-9._-]/','-',basename($p['original_filename']));header('Content-Type: '.$p['mime_type']);header('Content-Disposition: attachment; filename="'.$name.'"');header('Content-Length: '.strlen($p['slip_data']));header('X-Content-Type-Options: nosniff');echo $p['slip_data'];exit;
}

function sendPaidInvoicePdf(PDO $pdo,int $paymentId,?int $countryId=null):void
{
    ensureInvoiceSchema($pdo);$sql='SELECT p.paid_invoice_pdf,i.invoice_number FROM invoice_payments p JOIN invoices i ON i.id=p.invoice_id WHERE p.id=? AND p.status=\'Accepted\' AND p.paid_invoice_pdf IS NOT NULL';$params=[$paymentId];if($countryId!==null){$sql.=' AND p.country_id=?';$params[]=$countryId;}$stmt=$pdo->prepare($sql);$stmt->execute($params);$p=$stmt->fetch(PDO::FETCH_ASSOC);if(!$p){http_response_code(404);exit('Paid invoice not found.');}$name=preg_replace('/[^A-Za-z0-9._-]/','-',$p['invoice_number']).'-PAID.pdf';header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="'.$name.'"');header('Content-Length: '.strlen($p['paid_invoice_pdf']));header('X-Content-Type-Options: nosniff');echo $p['paid_invoice_pdf'];exit;
}
