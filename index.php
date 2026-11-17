<?php
declare(strict_types=1);
require __DIR__ . '/db.php';

$views = ['home','browse','report-lost','report-found','matches','desk'];
$view = in_array($_GET['view'] ?? 'home', $views, true) ? (string)($_GET['view'] ?? 'home') : 'home';

if (!isset($databaseUnavailable) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $returnView = in_array($_POST['return_view'] ?? 'home', $views, true) ? (string)($_POST['return_view'] ?? 'home') : 'home';
    try {
        verifyCsrf();
        $pdo->beginTransaction();
        $action = (string)($_POST['action'] ?? '');
        if (in_array($action, ['create_lost','create_found'], true)) {
            $lost = $action === 'create_lost';
            $personId = filter_input(INPUT_POST, $lost ? 'student_id' : 'admin_id', FILTER_VALIDATE_INT);
            $categoryId = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT);
            $locationId = filter_input(INPUT_POST, 'location_id', FILTER_VALIDATE_INT);
            $dateField = trim((string)($_POST[$lost ? 'lost_date' : 'found_date'] ?? ''));
            if (!$personId || !$categoryId || !$locationId || !$dateField) throw new RuntimeException('Please complete the required fields.');
            $serial = trim((string)($_POST['serial_no'] ?? ''));
            $item = $pdo->prepare('INSERT INTO item (category_id,brand,model,color,serial_no,features) VALUES (?,?,?,?,?,?)');
            $item->execute([$categoryId, trim((string)($_POST['brand'] ?? '')) ?: null, trim((string)($_POST['model'] ?? '')) ?: null,
                trim((string)($_POST['color'] ?? '')) ?: null, $serial !== '' ? $serial : null, trim((string)($_POST['features'] ?? '')) ?: null]);
            $itemId = (int)$pdo->lastInsertId();
            $table = $lost ? 'lost_report' : 'found_report';
            $ownerColumn = $lost ? 'student_id' : 'admin_id';
            $whenColumn = $lost ? 'lost_date' : 'found_date';
            $report = $pdo->prepare("INSERT INTO $table ($ownerColumn,item_id,location_id,$whenColumn,description) VALUES (?,?,?,?,?)");
            $report->execute([$personId, $itemId, $locationId, $dateField, trim((string)($_POST['description'] ?? '')) ?: null]);
            $pdo->commit();
            flash('success', $lost ? 'Your lost item report is on the board.' : 'Found item added to the board.');
            redirectTo('browse');
        }
        if ($action === 'create_match') {
            $lostId = filter_input(INPUT_POST, 'lost_id', FILTER_VALIDATE_INT);
            $foundId = filter_input(INPUT_POST, 'found_id', FILTER_VALIDATE_INT);
            if (!$lostId || !$foundId) throw new RuntimeException('Choose one lost report and one found report.');
            $stmt = $pdo->prepare('INSERT INTO item_match (lost_id,found_id,match_reason) VALUES (?,?,?)');
            $stmt->execute([$lostId, $foundId, trim((string)($_POST['match_reason'] ?? '')) ?: null]);
            $pdo->commit();
            flash('success', 'The two reports are now linked through MATCH.');
            redirectTo('matches');
        }
        if ($action === 'create_claim') {
            $lostId = filter_input(INPUT_POST, 'lost_id', FILTER_VALIDATE_INT);
            $foundId = filter_input(INPUT_POST, 'found_id', FILTER_VALIDATE_INT);
            $studentId = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);
            if (!$lostId || !$foundId || !$studentId) throw new RuntimeException('Choose the report owner to submit this demo claim.');
            $owner = $pdo->prepare('SELECT student_id FROM lost_report WHERE lost_id=?');
            $owner->execute([$lostId]);
            if ((int)$owner->fetchColumn() !== (int)$studentId) throw new RuntimeException('Only the student on the lost report can claim this match in the demo workflow.');
            $stmt = $pdo->prepare('INSERT INTO claim (lost_id,found_id) VALUES (?,?)');
            $stmt->execute([$lostId,$foundId]);
            $pdo->commit();
            flash('success', 'Claim sent to the project desk for verification.');
            redirectTo('matches');
        }
        if ($action === 'review_claim') {
            $claimId = filter_input(INPUT_POST, 'claim_id', FILTER_VALIDATE_INT);
            $adminId = filter_input(INPUT_POST, 'admin_id', FILTER_VALIDATE_INT);
            $result = (string)($_POST['result'] ?? '');
            $note = trim((string)($_POST['remarks'] ?? ''));
            if (!$claimId || !$adminId || !in_array($result, ['Pass','Fail'], true)) throw new RuntimeException('Choose a claim, desk member, and result.');
            $stmt = $pdo->prepare("SELECT c.claim_id,c.lost_id,c.found_id,lr.student_id FROM claim c JOIN lost_report lr ON lr.lost_id=c.lost_id WHERE c.claim_id=? AND c.status='Under Review'");
            $stmt->execute([$claimId]);
            $claim = $stmt->fetch();
            if (!$claim) throw new RuntimeException('That claim is no longer waiting for review.');
            $stmt = $pdo->prepare('INSERT INTO verification (claim_id,admin_id,verification_date,method,result,remarks) VALUES (?,?,NOW(),?,?,?)');
            $stmt->execute([$claimId,$adminId,'In-person desk review',$result,$note ?: null]);
            $verificationId = (int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE claim SET status=? WHERE claim_id=?')->execute([$result === 'Pass' ? 'Approved' : 'Denied',$claimId]);
            if ($result === 'Pass') {
                $stmt = $pdo->prepare('INSERT INTO handover (verification_id,student_id,admin_id,remarks) VALUES (?,?,?,?)');
                $stmt->execute([$verificationId,(int)$claim['student_id'],$adminId,$note ?: 'Handed over at the campus desk.']);
                $pdo->prepare("UPDATE lost_report SET status='Resolved' WHERE lost_id=?")->execute([(int)$claim['lost_id']]);
                $pdo->prepare("UPDATE found_report SET status='Handed Over' WHERE found_id=?")->execute([(int)$claim['found_id']]);
                $pdo->prepare("UPDATE item_match SET match_status='Verified' WHERE lost_id=? AND found_id=?")->execute([(int)$claim['lost_id'],(int)$claim['found_id']]);
            } else {
                $pdo->prepare("UPDATE item_match SET match_status='Rejected' WHERE lost_id=? AND found_id=?")->execute([(int)$claim['lost_id'],(int)$claim['found_id']]);
            }
            $pdo->commit();
            flash('success', $result === 'Pass' ? 'Claim approved and handover recorded.' : 'Claim marked as denied.');
            redirectTo('desk');
        }
        throw new RuntimeException('That action is not available.');
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $error instanceof PDOException ? 'The database could not save that yet. Check the related record and any unique serial number.' : $error->getMessage());
        redirectTo($returnView);
    }
}

$notice = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$categories = $locations = $students = $admins = $foundItems = $lostReports = $matches = $claims = [];
$counts = ['lost'=>0,'found'=>0,'matches'=>0,'claims'=>0];
$search = trim((string)($_GET['q'] ?? ''));
$categoryFilter = (int)($_GET['category'] ?? 0);
if (!isset($databaseUnavailable)) {
    $categories = $pdo->query('SELECT category_id,category_name FROM item_category ORDER BY category_name')->fetchAll();
    $locations = $pdo->query('SELECT location_id,building,floor,area FROM location ORDER BY building,floor,area')->fetchAll();
    $students = $pdo->query('SELECT s.student_id,p.fname,p.lname FROM student s JOIN person p ON p.person_id=s.person_id ORDER BY p.fname')->fetchAll();
    $admins = $pdo->query('SELECT a.admin_id,p.fname,p.lname FROM admin a JOIN person p ON p.person_id=a.person_id ORDER BY p.fname')->fetchAll();
    $counts['lost'] = (int)$pdo->query("SELECT COUNT(*) FROM lost_report WHERE status<>'Resolved'")->fetchColumn();
    $counts['found'] = (int)$pdo->query("SELECT COUNT(*) FROM found_report WHERE status='Unclaimed'")->fetchColumn();
    $counts['matches'] = (int)$pdo->query("SELECT COUNT(*) FROM item_match WHERE match_status='Pending'")->fetchColumn();
    $counts['claims'] = (int)$pdo->query("SELECT COUNT(*) FROM claim WHERE status='Under Review'")->fetchColumn();
    $where = ["fr.status='Unclaimed'"];
    $params = [];
    if ($search !== '') {
        $where[] = '(i.brand LIKE ? OR i.model LIKE ? OR i.color LIKE ? OR i.features LIKE ? OR fr.description LIKE ? OR l.building LIKE ? OR l.area LIKE ? OR c.category_name LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params,$like,$like,$like,$like,$like,$like,$like,$like);
    }
    if ($categoryFilter > 0) { $where[] = 'c.category_id=?'; $params[] = $categoryFilter; }
    $stmt = $pdo->prepare('SELECT fr.found_id,fr.found_date,fr.description,i.brand,i.model,i.color,i.features,c.category_name,l.building,l.floor,l.area FROM found_report fr JOIN item i ON i.item_id=fr.item_id JOIN item_category c ON c.category_id=i.category_id JOIN location l ON l.location_id=fr.location_id WHERE '.implode(' AND ',$where).' ORDER BY fr.found_date DESC LIMIT 30');
    $stmt->execute($params); $foundItems = $stmt->fetchAll();
    $lostReports = $pdo->query("SELECT lr.lost_id,lr.lost_date,lr.status,i.brand,i.model,i.color,c.category_name,l.building,l.area,p.fname,p.lname FROM lost_report lr JOIN item i ON i.item_id=lr.item_id JOIN item_category c ON c.category_id=i.category_id JOIN location l ON l.location_id=lr.location_id JOIN student s ON s.student_id=lr.student_id JOIN person p ON p.person_id=s.person_id WHERE lr.status<>'Resolved' ORDER BY lr.lost_date DESC LIMIT 30")->fetchAll();
    $matches = $pdo->query('SELECT m.lost_id,m.found_id,m.match_reason,m.match_date,m.match_status,li.brand lost_brand,li.model lost_model,li.color lost_color,fi.brand found_brand,fi.model found_model,fi.color found_color,c.category_name,l.building found_building,l.area found_area,p.fname,p.lname,lr.student_id FROM item_match m JOIN lost_report lr ON lr.lost_id=m.lost_id JOIN item li ON li.item_id=lr.item_id JOIN found_report fr ON fr.found_id=m.found_id JOIN item fi ON fi.item_id=fr.item_id JOIN item_category c ON c.category_id=li.category_id JOIN location l ON l.location_id=fr.location_id JOIN student s ON s.student_id=lr.student_id JOIN person p ON p.person_id=s.person_id ORDER BY m.match_date DESC')->fetchAll();
    $claims = $pdo->query('SELECT cl.claim_id,cl.lost_id,cl.found_id,cl.claim_date,cl.status,lr.student_id,p.fname,p.lname,i.model found_model,i.color found_color,l.building found_building FROM claim cl JOIN lost_report lr ON lr.lost_id=cl.lost_id JOIN student s ON s.student_id=lr.student_id JOIN person p ON p.person_id=s.person_id JOIN found_report fr ON fr.found_id=cl.found_id JOIN item i ON i.item_id=fr.item_id JOIN location l ON l.location_id=fr.location_id ORDER BY cl.claim_date DESC LIMIT 30')->fetchAll();
}
function placeLabel(array $row): string {
    return implode(' · ',array_filter([(string)($row['building'] ?? ''),isset($row['floor']) && $row['floor'] !== null ? 'Floor '.$row['floor'] : '',(string)($row['area'] ?? '')]));
}
function itemTitle(array $row): string {
    $name = trim(implode(' ',array_filter([(string)($row['brand'] ?? ''),(string)($row['model'] ?? '')])));
    return $name !== '' ? $name : (string)($row['category_name'] ?? 'Campus item');
}
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#ffffff">
<title><?= $view === 'home' ? 'CampusFind | Campus lost and found' : h(ucwords(str_replace('-',' ',$view))).' | CampusFind' ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Rethink+Sans:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="assets/style.css">
</head><body>
<header class="site-header"><a class="brand" href="index.php"><span class="brand-mark"><i></i><i></i><i></i><i></i></span><span>campus<span class="brand-blue">find</span></span></a><nav class="main-nav"><a class="<?= $view==='browse'?'active':'' ?>" href="index.php?view=browse">Browse items</a><a class="<?= $view==='report-lost'?'active':'' ?>" href="index.php?view=report-lost">Report lost</a><a class="<?= $view==='report-found'?'active':'' ?>" href="index.php?view=report-found">Report found</a><a class="<?= $view==='matches'?'active':'' ?>" href="index.php?view=matches">Matches</a></nav><a class="button button-black header-cta" href="index.php?view=desk">Project desk <span>↗</span></a></header>
<?php if (isset($databaseUnavailable)): ?>
<main class="setup-page"><section class="setup-card"><span class="eyebrow"><span class="status-dot"></span> One quick setup step</span><h1>Let’s connect the database.</h1><p>MySQL could not find campus_lost_found.</p><ol><li>Start Apache and MySQL in XAMPP.</li><li>Open <a href="http://localhost/phpmyadmin">phpMyAdmin</a> and choose Import.</li><li>Select database.sql from this project folder and import it.</li><li>Refresh this page.</li></ol></section></main>
<?php else: ?>
<?php if ($notice): ?><div class="flash <?= h($notice[0]) ?>" role="status"><span><?= $notice[0]==='success'?'✓':'!' ?></span><?= h($notice[1]) ?></div><?php endif; ?>

<?php if ($view==='home'): ?>
<main><section class="hero"><div class="dot-field dot-field-left"></div><div class="dot-field dot-field-right"></div><span class="eyebrow"><span class="status-dot"></span> A little help from your campus</span><h1>Lost something?<br><span>Let’s bring it home.</span></h1><p class="hero-copy">One shared place for the things we leave behind. Search the board or tell us what you’re looking for.</p>
<form class="search-module" action="index.php" method="get"><input type="hidden" name="view" value="browse"><div class="search-tabs"><span class="tab-active">Found items</span><a href="index.php?view=report-lost">Post a lost item ↗</a></div><div class="search-row"><span class="search-icon">⌕</span><input type="search" name="q" placeholder="Try ‘blue notebook’ or ‘library’" aria-label="Search found items"><button class="button button-black">Search the board <span>→</span></button></div></form><div class="hero-note"><span>●</span> <?= $counts['found'] ?> items waiting to be reunited <b>·</b> Updated by students and campus staff</div></section>
<section class="stats-row"><div><span class="stat-number"><?= $counts['found'] ?></span><span>found items</span></div><div><span class="stat-number"><?= $counts['lost'] ?></span><span>still being looked for</span></div><div><span class="stat-number"><?= $counts['matches'] ?></span><span>possible matches</span></div><a class="text-link" href="index.php?view=browse">See the whole board ↗</a></section>
<section class="section-block"><div class="section-heading"><div><span class="eyebrow">JUST FOUND</span><h2>Maybe it’s yours?</h2></div><a class="text-link" href="index.php?view=browse">Browse all →</a></div>
<?php if (!$foundItems): ?><div class="empty-state"><span class="empty-icon">⌕</span><h3>No items on the board just yet</h3><p>When something is handed in, it’ll show up here.</p></div><?php else: ?><div class="item-grid"><?php foreach (array_slice($foundItems,0,3) as $item): ?>
<article class="item-card"><div class="item-visual <?= $item['category_name']==='Electronics'?'visual-blue':($item['category_name']==='Books and Notes'?'visual-lilac':'visual-peach') ?>"><span class="visual-tag"><?= h($item['category_name']) ?></span><span class="visual-art"><?= $item['category_name']==='Electronics'?'◉':($item['category_name']==='Books and Notes'?'▤':'✳') ?></span><span class="visual-id">#<?= str_pad((string)$item['found_id'],3,'0',STR_PAD_LEFT) ?></span></div><div class="item-card-body"><div class="item-title-row"><h3><?= h(itemTitle($item)) ?></h3><span class="tiny-dot"></span></div><p><?= h($item['color'] ?: $item['category_name']) ?><?= $item['area']?' · '.h($item['area']):'' ?></p><div class="card-footer"><span><?= h($item['building']) ?></span><span><?= date('M j',strtotime($item['found_date'])) ?></span></div></div></article>
<?php endforeach; ?></div><?php endif; ?></section>
<section class="how-strip"><div><span class="eyebrow">SMALL STEPS, BIG RELIEF</span><h2>Three steps to a happy ending.</h2></div><div class="step"><span class="step-number">01</span><div><h3>Look around</h3><p>Search what has been handed in.</p></div></div><div class="step"><span class="step-number">02</span><div><h3>Leave a note</h3><p>Post a clear lost or found report.</p></div></div><div class="step"><span class="step-number">03</span><div><h3>Pick it up</h3><p>The campus desk helps verify and return it.</p></div></div></section></main>

<?php elseif ($view==='browse'): ?>
<main class="page-shell"><div class="page-intro"><span class="eyebrow">THE CAMPUS BOARD</span><h1>Take a look around.</h1><p>A small clue can make a big difference. Search by item, color, category, or place.</p></div><form class="browse-search" method="get"><input type="hidden" name="view" value="browse"><label class="sr-only" for="q">Search</label><input id="q" type="search" name="q" value="<?= h($search) ?>" placeholder="Search the board"><select name="category"><option value="0">All categories</option><?php foreach ($categories as $cat): ?><option value="<?= $cat['category_id'] ?>" <?= $categoryFilter===(int)$cat['category_id']?'selected':'' ?>><?= h($cat['category_name']) ?></option><?php endforeach; ?></select><button class="button button-black">Find items →</button></form><div class="result-heading"><div><h2>Found items</h2><span class="muted"><?= count($foundItems) ?> items shown</span></div><a class="text-link" href="index.php?view=report-lost">Can’t see yours? Report it ↗</a></div>
<?php if (!$foundItems): ?><div class="empty-state"><span class="empty-icon">⌕</span><h3>No match this time</h3><p>Try a different search, or leave a lost report so others can help.</p><a class="button button-outline" href="index.php?view=report-lost">Report a lost item</a></div><?php else: ?><div class="item-grid browse-grid"><?php foreach ($foundItems as $item): ?><article class="item-card"><div class="item-visual <?= $item['category_name']==='Electronics'?'visual-blue':($item['category_name']==='Books and Notes'?'visual-lilac':'visual-peach') ?>"><span class="visual-tag"><?= h($item['category_name']) ?></span><span class="visual-art"><?= $item['category_name']==='Electronics'?'◉':($item['category_name']==='Books and Notes'?'▤':'✳') ?></span><span class="visual-id">#<?= str_pad((string)$item['found_id'],3,'0',STR_PAD_LEFT) ?></span></div><div class="item-card-body"><div class="item-title-row"><h3><?= h(itemTitle($item)) ?></h3><span class="tiny-dot"></span></div><p><?= h($item['color']?:$item['category_name']) ?><?= $item['area']?' · '.h($item['area']):'' ?></p><p class="item-description"><?= h($item['description']?:$item['features']) ?></p><div class="card-footer"><span><?= h($item['building']) ?><?= $item['floor']!==null?' · Floor '.h((string)$item['floor']):'' ?></span><span><?= date('M j',strtotime($item['found_date'])) ?></span></div></div></article><?php endforeach; ?></div><?php endif; ?><div class="browse-lost"><span>Looking for something specific?</span><a class="text-link" href="index.php?view=report-lost">Post a lost item report →</a></div></main>

<?php elseif ($view==='report-lost' || $view==='report-found'): $isLost=$view==='report-lost'; ?>
<main class="page-shell form-page"><div class="form-intro"><a class="back-link" href="index.php?view=browse">← Back to the board</a><span class="eyebrow"><?= $isLost?'LET’S FIND IT':'NICE OF YOU TO HAND IT IN' ?></span><h1><?= $isLost?'Tell us what went missing.':'Add a found item.' ?></h1><p><?= $isLost?'A few details help the right person spot a possible match.':'A good description helps the owner find their way back to it.' ?></p></div>
<form class="report-form" method="post"><?= csrfField() ?><input type="hidden" name="action" value="<?= $isLost?'create_lost':'create_found' ?>"><input type="hidden" name="return_view" value="<?= h($view) ?>"><div class="form-section-title"><span>01</span><div><h2>What does it look like?</h2><p>Just the useful details. No need to overshare.</p></div></div><div class="form-grid">
<label>Category <b class="required">*</b><select name="category_id" required><option value="">Choose one</option><?php foreach($categories as $cat):?><option value="<?= $cat['category_id'] ?>"><?=h($cat['category_name'])?></option><?php endforeach;?></select></label><label>Brand<input name="brand" maxlength="50" placeholder="e.g. Sony"></label><label>Model or item name<input name="model" maxlength="50" placeholder="e.g. wireless earbuds"></label><label>Color<input name="color" maxlength="30" placeholder="e.g. navy blue"></label><label>Serial number <span class="optional">(if you know it)</span><input name="serial_no" maxlength="100" placeholder="Optional; not shown on the board"></label><label>Distinctive details<input name="features" maxlength="500" placeholder="Sticker, scratch, initials…"></label></div>
<div class="form-section-title section-spaced"><span>02</span><div><h2>Where and when?</h2><p>A rough location is plenty.</p></div></div><div class="form-grid">
<?php if($isLost):?><label>Reporting student <b class="required">*</b><select name="student_id" required><option value="">Choose demo student</option><?php foreach($students as $s):?><option value="<?=$s['student_id']?>"><?=h($s['fname'].' '.$s['lname'])?> · <?=$s['student_id']?></option><?php endforeach;?></select><small>Demo records are preloaded from database.sql.</small></label><?php else:?><label>Logged by <b class="required">*</b><select name="admin_id" required><option value="">Choose desk member</option><?php foreach($admins as $a):?><option value="<?=$a['admin_id']?>"><?=h($a['fname'].' '.$a['lname'])?></option><?php endforeach;?></select></label><?php endif;?>
<label>Closest place <b class="required">*</b><select name="location_id" required><option value="">Choose a campus place</option><?php foreach($locations as $loc):?><option value="<?=$loc['location_id']?>"><?=h(placeLabel($loc))?></option><?php endforeach;?></select></label><label><?=$isLost?'When did you last see it?':'When was it found?'?> <b class="required">*</b><input type="datetime-local" name="<?=$isLost?'lost_date':'found_date'?>" value="<?=date('Y-m-d\TH:i')?>" required></label><label class="span-two">A little more context<textarea name="description" rows="3" placeholder="Where were you? Anything else that might help?"></textarea></label></div><div class="form-actions"><span><b class="required">*</b> Required fields</span><button class="button button-black"><?=$isLost?'Post lost report':'Add to found board'?> →</button></div></form></main>

<?php elseif ($view==='matches'): ?>
<main class="page-shell"><div class="page-intro"><span class="eyebrow">A LITTLE CLUE</span><h1>Possible matches.</h1><p>The project desk links reports that look like they could belong together. A match is just a lead; verification comes next.</p></div><?php if(!$matches):?><div class="empty-state"><h3>No pairs linked yet</h3><p>When the desk connects a lost report with a found report, it will show up here.</p></div><?php else:?><div class="match-list"><?php foreach($matches as $m):?><article class="match-card"><div class="match-topline"><span class="eyebrow">MATCH #<?=$m['lost_id']?>–<?=$m['found_id']?></span><span class="status-pill <?=strtolower(h($m['match_status']))?>"><?=h($m['match_status'])?></span></div><div class="match-pair"><div><span class="pair-label">LOST REPORT</span><h2><?=h(trim(($m['lost_brand']??'').' '.($m['lost_model']??''))?:$m['category_name'])?></h2><p><?=h($m['lost_color']?:$m['category_name'])?> · <?=h($m['fname'].' '.$m['lname'])?></p></div><span class="pair-arrow">↔</span><div><span class="pair-label">FOUND REPORT</span><h2><?=h(trim(($m['found_brand']??'').' '.($m['found_model']??''))?:$m['category_name'])?></h2><p><?=h($m['found_color']?:$m['category_name'])?> · <?=h(trim(($m['found_building']??'').' '.($m['found_area']??'')))?></p></div></div><?php if($m['match_reason']):?><p class="match-reason"><b>Why it might fit</b><?=h($m['match_reason'])?></p><?php endif;?><?php if($m['match_status']==='Pending'):?><form class="claim-form" method="post"><?=csrfField()?><input type="hidden" name="action" value="create_claim"><input type="hidden" name="return_view" value="matches"><input type="hidden" name="lost_id" value="<?=$m['lost_id']?>"><input type="hidden" name="found_id" value="<?=$m['found_id']?>"><label>Submit as<select name="student_id" required><?php foreach($students as $s):?><option value="<?=$s['student_id']?>" <?=((int)$s['student_id']===(int)$m['student_id'])?'selected':''?>><?=h($s['fname'].' '.$s['lname'])?></option><?php endforeach;?></select></label><button class="button button-outline">Start a claim →</button></form><?php endif;?></article><?php endforeach;?></div><?php endif;?></main>

<?php else: ?>
<main class="page-shell desk-page"><div class="page-intro"><span class="eyebrow"><span class="status-dot"></span> PROJECT DESK · DEMO</span><h1>The little admin corner.</h1><p>Try the database workflow: pair reports, review a claim, and record the handover. This student demo does not include sign-in.</p></div>
<div class="desk-metrics"><div><strong><?=$counts['lost']?></strong><span>open lost reports</span></div><div><strong><?=$counts['found']?></strong><span>unclaimed found items</span></div><div><strong><?=$counts['matches']?></strong><span>pending matches</span></div><div><strong><?=$counts['claims']?></strong><span>claims to review</span></div></div><div class="desk-grid">
<section class="desk-panel"><div class="panel-heading"><span class="eyebrow">01 · CONNECT THE DOTS</span><h2>Create a possible match</h2><p>This inserts one row into item_match using its composite key.</p></div><form class="stack-form" method="post"><?=csrfField()?><input type="hidden" name="action" value="create_match"><input type="hidden" name="return_view" value="desk"><label>Lost report<select name="lost_id" required><option value="">Choose a report</option><?php foreach($lostReports as $r):?><option value="<?=$r['lost_id']?>">#<?=$r['lost_id']?> · <?=h(itemTitle($r))?> · <?=h($r['fname'])?></option><?php endforeach;?></select></label><label>Found report<select name="found_id" required><option value="">Choose an item</option><?php foreach($foundItems as $i):?><option value="<?=$i['found_id']?>">#<?=$i['found_id']?> · <?=h(itemTitle($i))?> · <?=h($i['building'])?></option><?php endforeach;?></select></label><label>Why might they match?<textarea name="match_reason" rows="2" placeholder="Similar color, model, location…"></textarea></label><button class="button button-black">Link the reports →</button></form></section>
<section class="desk-panel"><div class="panel-heading"><span class="eyebrow">02 · CHECK IT OUT</span><h2>Claims waiting on a person</h2><p>Verification creates an audit row. Passing a claim also records a handover.</p></div><?php $pending=array_values(array_filter($claims,fn($c)=>$c['status']==='Under Review'));if(!$pending):?><div class="empty-inline">Nothing waiting right now. A little quiet is nice.</div><?php else:?><div class="claim-stack"><?php foreach($pending as $c):?><article class="claim-card"><div class="claim-heading"><div><span class="pair-label">CLAIM #<?=$c['claim_id']?></span><h3><?=h($c['fname'].' '.$c['lname'])?></h3></div><span class="status-pill review">Under review</span></div><p><?=h(trim(($c['found_color']??'').' '.($c['found_model']??'')))?> · <?=h($c['found_building'])?> · <?=date('M j',strtotime($c['claim_date']))?></p><form class="review-form" method="post"><?=csrfField()?><input type="hidden" name="action" value="review_claim"><input type="hidden" name="return_view" value="desk"><input type="hidden" name="claim_id" value="<?=$c['claim_id']?>"><label>Reviewed by<select name="admin_id" required><?php foreach($admins as $a):?><option value="<?=$a['admin_id']?>"><?=h($a['fname'].' '.$a['lname'])?></option><?php endforeach;?></select></label><label>Desk note<input name="remarks" placeholder="Optional note"></label><div class="review-actions"><button class="button button-outline" name="result" value="Fail">Needs another look</button><button class="button button-black" name="result" value="Pass">Verify &amp; hand over</button></div></form></article><?php endforeach;?></div><?php endif;?></section></div>
<section class="table-panel"><div class="panel-heading"><span class="eyebrow">THE PAPER TRAIL</span><h2>Recent handovers</h2></div><div class="table-wrap"><table><thead><tr><th>Item</th><th>Returned to</th><th>Handled by</th><th>When</th><th>Desk note</th></tr></thead><tbody><?php $handovers=$pdo->query('SELECT i.brand,i.model,i.color,p.fname sf,p.lname sl,ap.fname af,ap.lname al,h.handover_date,h.remarks FROM handover h JOIN verification v ON v.verification_id=h.verification_id JOIN claim c ON c.claim_id=v.claim_id JOIN found_report fr ON fr.found_id=c.found_id JOIN item i ON i.item_id=fr.item_id JOIN student s ON s.student_id=h.student_id JOIN person p ON p.person_id=s.person_id JOIN admin a ON a.admin_id=h.admin_id JOIN person ap ON ap.person_id=a.person_id ORDER BY h.handover_date DESC LIMIT 8')->fetchAll();if(!$handovers):?><tr><td colspan="5" class="table-empty">No handovers yet. Approved claims will leave a row here.</td></tr><?php else:foreach($handovers as $hrow):?><tr><td><?=h(trim(($hrow['brand']??'').' '.($hrow['model']??'')))?><span class="table-sub"><?=h($hrow['color'])?></span></td><td><?=h($hrow['sf'].' '.$hrow['sl'])?></td><td><?=h($hrow['af'].' '.$hrow['al'])?></td><td><?=date('M j, Y',strtotime($hrow['handover_date']))?></td><td><?=h($hrow['remarks'])?></td></tr><?php endforeach;endif;?></tbody></table></div></section></main>
<?php endif; ?>
<footer class="site-footer"><a class="brand footer-brand" href="index.php"><span class="brand-mark"><i></i><i></i><i></i><i></i></span><span>campus<span class="brand-blue">find</span></span></a><span>A little campus kindness goes a long way.</span><span class="footer-credit">DBMS project · MySQL + PHP</span></footer>
<?php endif; ?></body></html>
