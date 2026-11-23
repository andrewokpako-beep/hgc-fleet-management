<?php
declare(strict_types=1);
require_once __DIR__.'/ai-common.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !fleet_ai_same_origin_request() || (string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    json_response(['ok'=>false,'message'=>'Request was not accepted. Open the review from this FleetDesk site.'],405);
}
try {
    $settings = fleet_ai_load_settings();
    if ($settings['api_key'] === '') json_response(['ok'=>false,'message'=>'Add your OpenAI API key in Settings before requesting a suggestion.'],409);
    $input = json_decode((string)file_get_contents('php://input'), true);
    $caseId = is_array($input) ? (int)($input['case_id'] ?? 0) : 0;
    if ($caseId < 1) json_response(['ok'=>false,'message'=>'A valid review case ID is required.'],400);
    $db = fleet_db();
    $caseStmt = $db->prepare("SELECT id,case_number,case_type,severity,case_status,field_name,description,detected_at FROM reconciliation_cases WHERE id=? LIMIT 1");
    $caseStmt->execute([$caseId]);
    $case = $caseStmt->fetch();
    if (!$case) json_response(['ok'=>false,'message'=>'Review case not found.'],404);
    if (!in_array($case['case_status'], ['OPEN','IN_REVIEW'], true)) json_response(['ok'=>false,'message'=>'AI suggestions are available only for open or in-review cases.'],409);
    $evidenceStmt = $db->prepare('SELECT e.field_name,e.observed_value,e.normalized_value,sh.sheet_name,s.source_code FROM reconciliation_evidence e LEFT JOIN spreadsheet_sheets sh ON sh.id=e.sheet_id LEFT JOIN spreadsheet_sources s ON s.id=e.source_id WHERE e.case_id=? ORDER BY e.id LIMIT 40');
    $evidenceStmt->execute([$caseId]);
    $evidence = [];
    foreach ($evidenceStmt->fetchAll() as $row) {
        $observed = json_decode((string)$row['observed_value'], true);
        $evidence[] = [
            'source'=>(string)($row['source_code'] ?? ''),
            'sheet'=>(string)($row['sheet_name'] ?? ''),
            'field'=>(string)$row['field_name'],
            'observed'=>$observed,
            'normalized_value'=>(string)($row['normalized_value'] ?? ''),
        ];
    }
    $advice = fleet_ai_send_response($settings['api_key'], $settings['model'], [
        'case'=>[
            'case_number'=>$case['case_number'],
            'case_type'=>$case['case_type'],
            'severity'=>$case['severity'],
            'field'=>$case['field_name'],
            'status'=>$case['case_status'],
            'description'=>$case['description'],
            'detected_at'=>$case['detected_at'],
        ],
        'source_evidence'=>$evidence,
    ]);
    json_response(['ok'=>true,'case_id'=>$caseId,'model'=>$settings['model'],'advice'=>$advice,'note'=>'AI suggestions are advisory. Review the source evidence and make the decision yourself. No fleet records were changed.']);
} catch (Throwable $e) {
    error_log('Fleet AI review: '.$e->getMessage());
    json_response(['ok'=>false,'message'=>$e->getMessage()],502);
}
