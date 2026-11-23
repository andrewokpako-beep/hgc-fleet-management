<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';

function fleet_cert_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('fleet_certificate_session');
    session_set_cookie_params([
        'lifetime'=>0,
        'path'=>'/',
        'secure'=>(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https',
        'httponly'=>true,
        'samesite'=>'Lax',
    ]);
    session_start();
}

function fleet_cert_user(): ?array
{
    fleet_cert_session();
    $id = (int)($_SESSION['fleet_certificate_user_id'] ?? 0);
    if ($id < 1) return null;
    $db = fleet_db();
    $stmt = $db->prepare('SELECT id,full_name,username,email,is_active FROM users WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    if (!$user || !(int)$user['is_active']) {
        unset($_SESSION['fleet_certificate_user_id']);
        return null;
    }
    $roleStmt = $db->prepare('SELECT r.role_name,r.permissions_json,r.menu_order_json FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=? ORDER BY r.id');
    $roleStmt->execute([$id]);
    $roleRows=$roleStmt->fetchAll();$user['roles']=[];$user['permissions']=[];$user['menu_order']=[];
    foreach($roleRows as $index=>$role){$user['roles'][]=(string)$role['role_name'];$permissions=json_decode((string)($role['permissions_json']??''),true);if(is_array($permissions))$user['permissions']=array_merge($user['permissions'],array_map('strval',$permissions));if($index===0){$order=json_decode((string)($role['menu_order_json']??''),true);if(is_array($order))$user['menu_order']=array_map('strval',$order);}}
    $user['permissions']=array_values(array_unique($user['permissions']));
    return $user;
}

function fleet_cert_permission_catalog(): array
{
    return [
      'pages'=>[
        'page.dashboard'=>'Dashboard','page.fleet'=>'Fleet register','page.operations'=>'Fleet operations','page.operations-approvals'=>'Operation approvals','page.pending-approvals'=>'Pending approvals','page.trips'=>'Trip management','page.maintenance'=>'Maintenance & readiness','page.work-orders'=>'Repair work orders','page.maintenance-log'=>'Maintenance defect log','page.attachments'=>'Full Truck','page.tracking'=>'Tracking & yards','page.reviews'=>'Review queue','page.reports'=>'Sync & reports','page.source-data'=>'Source observations','page.source-operations'=>'Source operations','page.unregistered'=>'Unregistered assets','page.locations'=>'Location report','page.audit'=>'Audit trail','page.ai-assistant'=>'Ask Fleet AI','page.settings'=>'Settings','page.staff'=>'Staff accounts','page.asset-certificates'=>'Asset Certificate','page.certificates'=>'Certificates','page.certificate-approvals'=>'Certificate approvals','page.certificate-settings'=>'Certificate settings'
      ],
      'actions'=>[
        'source_changes.approve'=>'Approve source-sheet deletions','operations.request'=>'Submit fleet changes','operations.manage'=>'Manage fleet records','operations.approve'=>'Approve fleet changes','readiness.request'=>'Request Full Truck readiness approval','readiness.approve'=>'Approve Full Truck readiness','trips.request'=>'Create trip requests','trips.manage'=>'Dispatch and update trips','trips.approve'=>'Approve trip requests','maintenance.inspect'=>'Record vehicle and driver checks','maintenance.work_orders'=>'Manage repair work orders','maintenance.resolve_defects'=>'Resolve inspection defects','certificates.submit'=>'Submit certificates','certificates.request_changes'=>'Request certificate changes','certificates.approve'=>'Approve certificate requests','certificates.manage'=>'Manage certificate types','certificates.settings'=>'Manage certificate settings','certificates.smart_fix'=>'Use certificate Smart Fix','reports.export'=>'Export report data','tracking.settings'=>'Manage tracking API connection','settings.ai'=>'Manage AI settings','users.manage'=>'Create users and assign roles','roles.manage'=>'Create roles and edit permissions'
      ]
    ];
}

function fleet_cert_user_can(array $user,string $permission): bool
{
    if(in_array('APP_CONTROLLER',$user['roles']??[],true))return true;
    if(in_array('ADMIN',$user['roles']??[],true)&&in_array($permission,['page.staff','users.manage'],true))return true;
    if($permission==='page.staff')return false;
    if(in_array('ADMIN',$user['roles']??[],true)&&in_array($permission,['page.settings','page.certificate-settings','settings.ai','tracking.settings','certificates.settings','roles.manage'],true))return false;
    return in_array($permission,$user['permissions']??[],true);
}

function fleet_cert_require_permission(array $user,string $permission): void
{
    if(!fleet_cert_user_can($user,$permission))json_response(['ok'=>false,'message'=>'Your role does not allow this action.'],403);
}

function fleet_cert_nav_items(): array
{
    return [
      ['dashboard','Overview','▦','page.dashboard','index.php?page=dashboard'],['fleet','Fleet register','▰','page.fleet','index.php?page=fleet'],['operations','Fleet operations','⚒','page.operations','index.php?page=operations'],['operations-approvals','Operation approvals','✓','page.operations-approvals','index.php?page=operations-approvals'],['pending-approvals','Pending approvals','◷','page.pending-approvals','index.php?page=pending-approvals'],['trips','Trip management','⇄','page.trips','index.php?page=trips'],['maintenance','Maintenance & readiness','⚙','page.maintenance','index.php?page=maintenance'],['work-orders','Repair work orders','🛠','page.work-orders','index.php?page=work-orders'],['maintenance-log','Maintenance defect log','▤','page.maintenance-log','index.php?page=maintenance-log'],['attachments','Full Truck','↔','page.attachments','index.php?page=attachments'],['asset-links','Asset links','⇄','page.attachments','index.php?page=asset-links'],['tracking','Tracking & yards','⌖','page.tracking','index.php?page=tracking'],['reviews','Review queue','☷','page.reviews','index.php?page=reviews'],['reports','Sync & reports','▥','page.reports','index.php?page=reports'],['source-data','Source observations','▤','page.source-data','index.php?page=source-data'],['source-operations','Source operations','▧','page.source-operations','index.php?page=source-operations'],['unregistered','Unregistered assets','◫','page.unregistered','index.php?page=unregistered'],['locations','Location report','⌖','page.locations','index.php?page=locations'],['audit','Audit trail','◷','page.audit','index.php?page=audit'],['ai-assistant','Ask Fleet AI','✦','page.ai-assistant','index.php?page=ai-assistant'],['staff','Staff accounts','♙','page.staff','index.php?page=staff'],['settings','Settings','⚙','page.settings','index.php?page=settings'],['asset-certificates','Asset Certificate','▤','page.asset-certificates','certificates.php?view=asset-certificate'],['certificates','Certificates','▣','page.certificates','certificates.php'],['certificate-approvals','Certificate approvals','✓','page.certificate-approvals','certificates.php?view=approvals'],['certificate-settings','Certificate settings','⚙','page.certificate-settings','certificates.php?view=settings']
    ];
}

function fleet_nav_icon_svg(string $key): string
{
    $paths=[
      'dashboard'=>'<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
      'fleet'=>'<path d="M3 7h18v12H3z"/><path d="M7 7V5h10v2M7 19v2m10-2v2"/>',
      'operations'=>'<path d="M14.7 6.3a5 5 0 0 0-6.4 6.4L3 18l3 3 5.3-5.3a5 5 0 0 0 6.4-6.4L14 12l-2-2z"/>',
      'operations-approvals'=>'<path d="m5 12 4 4L19 6"/><path d="M21 12a9 9 0 1 1-2.6-6.4"/>',
      'pending-approvals'=>'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
      'trips'=>'<path d="M4 7h15m0 0-4-4m4 4-4 4M20 17H5m0 0 4-4m-4 4 4 4"/>',
      'maintenance'=>'<path d="M12 3v2m0 14v2M3 12h2m14 0h2M5.6 5.6 7 7m10 10 1.4 1.4m0-12.8L17 7M7 17l-1.4 1.4"/><circle cx="12" cy="12" r="4"/>',
      'work-orders'=>'<path d="M14 6 6 14l-2 6 6-2 8-8"/><path d="m12 4 2-2 6 6-2 2"/>',
      'maintenance-log'=>'<path d="M7 3h8l4 4v14H7z"/><path d="M15 3v5h5M10 12h6m-6 4h6"/>',
      'attachments'=>'<path d="M8 12h8m-4-4 4 4-4 4M4 5v14m16-14v14"/>',
      'asset-links'=>'<path d="M10 13a5 5 0 0 0 7.1 0l2-2a5 5 0 0 0-7.1-7.1l-1.1 1.1"/><path d="M14 11a5 5 0 0 0-7.1 0l-2 2a5 5 0 0 0 7.1 7.1l1.1-1.1"/>',
      'tracking'=>'<path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/>',
      'reviews'=>'<path d="M8 6h13M8 12h13M8 18h13"/><path d="m3 6 1 1 2-2m-3 7 1 1 2-2m-3 7 1 1 2-2"/>',
      'reports'=>'<path d="M4 19V5m0 14h17"/><path d="m7 15 4-4 3 2 5-6"/>',
      'source-data'=>'<path d="M4 5h16v14H4z"/><path d="M8 9h8m-8 4h8m-8 3h5"/>',
      'source-operations'=>'<path d="M4 7h16M4 12h16M4 17h16"/><circle cx="7" cy="7" r="1"/><circle cx="7" cy="12" r="1"/><circle cx="7" cy="17" r="1"/>',
      'unregistered'=>'<path d="M4 5h16v14H4z"/><path d="M8 9h8m-8 6h5"/><path d="m16 14 4 4m0-4-4 4"/>',
      'locations'=>'<path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/>',
      'audit'=>'<path d="M5 4h14v17H5z"/><path d="M8 8h8m-8 4h8m-8 4h5"/>',
      'ai-assistant'=>'<path d="m12 3 1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/><path d="m19 16 .8 2.2L22 19l-2.2.8L19 22l-.8-2.2L16 19l2.2-.8z"/>',
      'staff'=>'<circle cx="9" cy="8" r="3"/><path d="M3 20a6 6 0 0 1 12 0m2-10a3 3 0 1 0 0-6m1 10a5 5 0 0 1 3 5"/>',
      'settings'=>'<circle cx="12" cy="12" r="3"/><path d="m19.4 15 .1.1 1.4 1.1-1.4 2.4-1.7-.6a8 8 0 0 1-1.7 1l-.3 1.8h-2.8l-.3-1.8a8 8 0 0 1-1.7-1l-1.7.6-1.4-2.4 1.4-1.1a7 7 0 0 1 0-2l-1.4-1.1 1.4-2.4 1.7.6a8 8 0 0 1 1.7-1l.3-1.8h2.8l.3 1.8a8 8 0 0 1 1.7 1l1.7-.6 1.4 2.4-1.4 1.1a7 7 0 0 1 0 2Z"/>',
      'asset-certificates'=>'<path d="M6 3h9l4 4v14H6z"/><path d="M15 3v5h5m-11 4h7m-7 4h7"/><path d="m9 19 2 2 4-4"/>',
      'certificates'=>'<path d="M6 3h9l4 4v14H6z"/><path d="M15 3v5h5m-11 4h7m-7 4h7"/>',
      'certificate-approvals'=>'<path d="m6 12 4 4L19 7"/><circle cx="12" cy="12" r="9"/>',
      'certificate-settings'=>'<circle cx="12" cy="12" r="3"/><path d="M19 13.5a7 7 0 0 0 0-3l2-1.5-2-3.5-2.5 1a8 8 0 0 0-2.5-1.5L13.5 2h-4L9 5a8 8 0 0 0-2.5 1.5L4 5.5 2 9l2 1.5a7 7 0 0 0 0 3L2 15l2 3.5 2.5-1A8 8 0 0 0 9 19l.5 3h4l.5-3a8 8 0 0 0 2.5-1.5l2.5 1 2-3.5z"/>'
    ];
    return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'.($paths[$key]??$paths['reports']).'</svg>';
}

function fleet_render_nav(array $user,string $current): void
{
    $items=fleet_cert_nav_items();$order=array_flip($user['menu_order']??[]);usort($items,static fn($a,$b)=>($order[$a[0]]??999)-($order[$b[0]]??999));
    $groups=['Overview'=>[],'Fleet operations'=>[],'Safety & dispatch'=>[],'Reports'=>[],'Administration'=>[]];
    foreach($items as $item){[$key,$label,$icon,$permission,$href]=$item;if(!fleet_cert_user_can($user,$permission))continue;$group=match($key){'dashboard'=>'Overview','fleet','operations','operations-approvals','pending-approvals','attachments','asset-links'=>'Fleet operations','trips','maintenance','work-orders','maintenance-log'=>'Safety & dispatch','tracking','reviews','reports','source-data','source-operations','unregistered','locations'=>'Reports',default=>'Administration'};$groups[$group][]=$item;}
    $groupKeys=['Overview'=>'dashboard','Fleet operations'=>'fleet','Safety & dispatch'=>'maintenance','Reports'=>'reports','Administration'=>'settings'];
    foreach($groups as $group=>$links){
        if(!$links)continue;$open=false;foreach($links as $item){if($current===$item[0]||($item[0]==='asset-certificates'&&$current==='certificates'))$open=true;}
        echo '<details class="nav-group"'.($open?' open':'').'><summary><span class="nav-group-icon" aria-hidden="true">'.fleet_nav_icon_svg($groupKeys[$group]??'reports').'</span><span class="nav-group-title">'.htmlspecialchars($group,ENT_QUOTES,'UTF-8').'</span><span class="nav-group-chevron" aria-hidden="true"></span></summary><div class="nav-group-items">';
        foreach($links as [$key,$label,$icon,$permission,$href]){$active=$current===$key||($key==='asset-certificates'&&$current==='certificates');echo '<a class="navlink'.($active?' active':'').'" href="'.htmlspecialchars($href,ENT_QUOTES,'UTF-8').'"'.($active?' aria-current="page"':'').'><i>'.fleet_nav_icon_svg($key).'</i>'.htmlspecialchars($label,ENT_QUOTES,'UTF-8').($key==='reviews'?'<em id="reviewCount">—</em>':'').'</a>';}
        echo '</div></details>';
    }
}

function fleet_cert_require_user(): array
{
    $user = fleet_cert_user();
    if (!$user) json_response(['ok'=>false,'message'=>'Sign in to manage certificates.'],401);
    return $user;
}

function fleet_cert_require_role(array $user, array $allowed): void
{
    if (!array_intersect($allowed, $user['roles'] ?? [])) json_response(['ok'=>false,'message'=>'Your account does not have permission for this action.'],403);
}

function cert_event(PDO $db,int $id,int $actor,string $event,?string $previous,?string $next,?string $reason=null,array $details=[]):void
{
    $stmt=$db->prepare('INSERT INTO certificate_events(certificate_submission_id,actor_user_id,event_type,previous_status,new_status,reason,details_json,ip_address,user_agent) VALUES(?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$id,$actor,$event,$previous,$next,$reason,$details?json_encode($details,JSON_UNESCAPED_UNICODE):null,substr((string)($_SERVER['REMOTE_ADDR']??''),0,45),substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500)]);
}

function fleet_cert_log(PDO $db, ?int $userId, string $action, string $entityType, ?int $entityId, string $description): void
{
    $stmt = $db->prepare('INSERT INTO activity_logs (user_id,action_type,entity_type,entity_id,description,ip_address,user_agent) VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([$userId,$action,$entityType,$entityId,$description,substr((string)($_SERVER['REMOTE_ADDR'] ?? ''),0,45),substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''),0,500)]);
}

function fleet_cert_require_same_origin_post(): void
{
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $originHost = strtolower((string)parse_url($origin, PHP_URL_HOST));
    $host = preg_replace('/:\\d+$/', '', $host);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || $originHost === '' || $host === '' || !hash_equals($host,$originHost) || (string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
        json_response(['ok'=>false,'message'=>'Request was not accepted. Open the page from this FleetDesk site.'],405);
    }
}
