<?php
require_once 'includes/auth.php';
require_once '../includes/flights.php';
ensureDelegationFlightsTable($pdo);
$stmt=$pdo->query("SELECT u.country_name,u.username,f.*,(SELECT GROUP_CONCAT(CONCAT(a.first_name,' ',a.last_name) ORDER BY a.last_name,a.first_name SEPARATOR ', ') FROM delegation_flight_movement_members fm JOIN athletes a ON a.id=fm.athlete_id WHERE fm.movement_id=f.id) AS delegate_names FROM users u JOIN delegation_flight_movements f ON f.country_id=u.id WHERE u.role='country_manager' ORDER BY u.country_name,FIELD(f.direction,'arrival','departure'),f.flight_datetime,f.id");
$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
function flightPdfText(string $v):string{if(function_exists('iconv')){$c=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$v);if($c!==false)$v=$c;}return strtr($v,[chr(92)=>chr(92).chr(92),'('=>chr(92).'(',')'=>chr(92).')',chr(13)=>' ',chr(10)=>' ']);}
function flightPdfCell(string $v,float $x,float $y,float $w,bool $bold=false):string{$limit=max(1,(int)floor($w/4.6));if(strlen($v)>$limit)$v=substr($v,0,max(0,$limit-3)).'...';return 'BT /'.($bold?'F2':'F1')." 7 Tf {$x} {$y} Td (".flightPdfText($v).") Tj ET
";}
$pw=842;$ph=595;$left=26;$top=558;$bottom=30;$rh=18;
$cols=[['Delegation',95],['Direction',55],['Group',35],['Delegates',205],['Pax',32],['Flight',55],['Airport',98],['Bus transfer',100],['Date & time',90]];
$pages=[];$content='';$y=$top;$pageNo=0;
$start=static function()use(&$content,&$y,&$pageNo,$left,$top,$cols,$rh,$rows):void{$pageNo++;$content="BT /F2 15 Tf {$left} {$top} Td (Flight Details Report) Tj ET
BT /F1 8 Tf {$left} ".($top-17)." Td (Generated ".flightPdfText(date('d M Y, H:i')).") Tj ET
BT /F1 8 Tf 710 {$top} Td (Groups: ".count($rows).") Tj ET
BT /F1 7 Tf 710 ".($top-13)." Td (Page {$pageNo}) Tj ET
";$y=$top-43;$content.="0.90 0.94 0.98 rg {$left} ".($y-4)." 790 {$rh} re f
0 0 0 rg
";$x=$left+3;foreach($cols as [$label,$w]){$content.=flightPdfCell($label,$x,$y,$w,true);$x+=$w;}$y-=$rh;};
$finish=static function()use(&$pages,&$content):void{$pages[]=$content;};$start();$groups=[];
if(!$rows)$content.=flightPdfCell('No flight details have been submitted.',$left+3,$y,400);
foreach($rows as $i=>$row){if($y<$bottom+$rh){$finish();$start();}if($i%2)$content.="0.97 0.97 0.97 rg {$left} ".($y-4)." 790 {$rh} re f
0 0 0 rg
";$country=trim((string)($row['country_name']?:$row['username']));$direction=ucfirst((string)$row['direction']);$key=$country.'|'.$direction;$groups[$key]=($groups[$key]??0)+1;$busCount=(int)($row['bus_count']??(!empty($row['bus_to_penang'])?1:0));$bus=$busCount?$busCount.' bus'.($busCount===1?'':'es').' / USD '.number_format($busCount*1000):'No';$values=[$country,$direction,(string)$groups[$key],(string)($row['delegate_names']?:'Not assigned'),(string)(int)$row['pax'],(string)$row['flight_number'],flightAirportLabel($row['airport']??null),$bus,date('d M Y, H:i',strtotime($row['flight_datetime']))];$x=$left+3;foreach($cols as $n=>[, $w]){$content.=flightPdfCell($values[$n],$x,$y,$w,$n===0);$x+=$w;}$content.="0.85 0.85 0.85 RG {$left} ".($y-6)." m 816 ".($y-6)." l S
";$y-=$rh;}$finish();
$objects=[1=>'<< /Type /Catalog /Pages 2 0 R >>'];$refs=[];$next=5;foreach($pages as $stream){$page=$next++;$body=$next++;$refs[]=$page.' 0 R';$objects[$page]="<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pw} {$ph}] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {$body} 0 R >>";$objects[$body]='<< /Length '.strlen($stream).">>
stream
".$stream.'endstream';}$objects[2]='<< /Type /Pages /Kids ['.implode(' ',$refs).'] /Count '.count($pages).' >>';$objects[3]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';$objects[4]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';ksort($objects);
$pdf="%PDF-1.4
";$offsets=[0];foreach($objects as $id=>$body){$offsets[$id]=strlen($pdf);$pdf.="{$id} 0 obj
{$body}
endobj
";}$xref=strlen($pdf);$max=max(array_keys($objects));$pdf.="xref
0 ".($max+1)."
0000000000 65535 f".chr(32)."
";for($id=1;$id<=$max;$id++)$pdf.=sprintf('%010d 00000 n ',$offsets[$id]??0)."
";$pdf.="trailer << /Size ".($max+1)." /Root 1 0 R >>
startxref
{$xref}
%%EOF";
header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="flight-details-'.date('Y-m-d').'.pdf"');header('Content-Length: '.strlen($pdf));header('X-Content-Type-Options: nosniff');echo $pdf;