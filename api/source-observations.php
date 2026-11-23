<?php
require_once __DIR__.'/../includes/bootstrap.php';
try {
 $db=fleet_db(); $q=trim((string)($_GET['q']??'')); $loc=trim((string)($_GET['location']??'')); $page=max(1,(int)($_GET['page']??1)); $limit=30;$export=(string)($_GET['export']??'')==='1';
 $where=[];$params=[];
 if($q!==''){$where[]='(s.source_code LIKE ? OR sh.sheet_name LIKE ? OR CAST(v.raw_data AS CHAR) LIKE ?)';$like='%'.$q.'%';array_push($params,$like,$like,$like);}
 if($loc!==''){
  $map=['Ngwerere'=>['Ngwerere','Unregistered_Ngwerere'],'21 Miles'=>['21Miles','21_Miles','Registered_21Miles','Unregistered_21Miles'],'Nakonde'=>['Nakonde'],'Other'=>[]];
  if(isset($map[$loc]) && $loc!=='Other'){ $parts=[];foreach($map[$loc] as $needle){$parts[]='sh.sheet_name LIKE ?';$params[]='%'.$needle.'%';}$where[]='('.implode(' OR ',$parts).')';}
  elseif($loc==='Other'){$where[]="sh.sheet_name NOT LIKE '%Ngwerere%' AND sh.sheet_name NOT LIKE '%21Miles%' AND sh.sheet_name NOT LIKE '%21_Miles%' AND sh.sheet_name NOT LIKE '%Nakonde%'";}
 }
 $w=$where?'WHERE '.implode(' AND ',$where):'';
 $count=$db->prepare("SELECT COUNT(*) FROM spreadsheet_source_row_versions v JOIN spreadsheet_sheets sh ON sh.id=v.sheet_id JOIN spreadsheet_sources s ON s.id=sh.source_id $w");$count->execute($params);$total=(int)$count->fetchColumn();
 $sql="SELECT v.id,v.sync_run_id,v.source_row_number,v.raw_data,v.captured_at,s.source_code,s.source_name,sh.sheet_name FROM spreadsheet_source_row_versions v JOIN spreadsheet_sheets sh ON sh.id=v.sheet_id JOIN spreadsheet_sources s ON s.id=sh.source_id $w ORDER BY s.source_code,sh.sheet_name,v.source_row_number".($export?'':' LIMIT ? OFFSET ?');
 $st=$db->prepare($sql);foreach($params as $i=>$value)$st->bindValue($i+1,$value);if(!$export){$st->bindValue(count($params)+1,$limit,PDO::PARAM_INT);$st->bindValue(count($params)+2,($page-1)*$limit,PDO::PARAM_INT);}$st->execute();$items=[];
 foreach($st->fetchAll() as $r){$raw=json_decode($r['raw_data'],true);$r['raw_values']=is_array($raw)?$raw:[$r['raw_data']];unset($r['raw_data']);$items[]=$r;}
 json_response(['ok'=>true,'items'=>$items,'total'=>$total,'page'=>$page,'pages'=>(int)ceil($total/$limit)]);
} catch(Throwable $e){error_log('Fleet source observations: '.$e->getMessage());json_response(['ok'=>false,'message'=>'Source observations could not be loaded.'],500);}
