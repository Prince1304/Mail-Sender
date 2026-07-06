<?php
require_once __DIR__ . '/config/auth.php';
if (isset($_GET['logout'])) {
    logoutAppUser();
    header('Location: login.php');
    exit; 
}

$isAjaxRequest = $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']);
if (!isAppAuthenticated()) {
    if ($isAjaxRequest) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Session expired. Please login again.',
            'redirect' => 'login.php', 
        ]);
        exit; 
    } 

    header('Location: login.php');
    exit; 
}

require_once __DIR__ . '/config/database.php';
// ═══════════════════════════════════════════════════════════════════════════════
//  DATABASE CONFIG
// ═══════════════════════════════════════════════════════════════════════════════
// ═══════════════════════════════════════════════════════════════════════════════
//  SMTP CONFIG
// ═══════════════════════════════════════════════════════════════════════════════
define('SMTP_HOST',  'smtp.gmail.com');
define('SMTP_PORT',  587);
define('SMTP_USER', 'your gmail id');
define('SMTP_PASS',  'Gmail app password');
define('FROM_EMAIL', 'system@mytaskmanager.com');
define('FROM_NAME',  'My Task Manager');

// ═══════════════════════════════════════════════════════════════════════════════
//  DB CONNECTION & TABLE BOOTSTRAP
// ═══════════════════════════════════════════════════════════════════════════════
// ═══════════════════════════════════════════════════════════════════════════════
//  AJAX HANDLER
// ═══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    // ── Add single customer ───────────────────────────────────────────────────
    if ($action === 'add_customer') {
        $name    = trim($_POST['name']    ?? '');
        $email   = trim($_POST['email']   ?? '');
        $phone   = trim($_POST['phone']   ?? '');
        $company = trim($_POST['company'] ?? '');
        $group   = trim($_POST['group']   ?? 'General');

        if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success'=>false,'message'=>'Name and a valid email are required.']);
            exit;
        }
        try {
            $db = getDB();
            $st = $db->prepare("INSERT INTO customers (name,email,phone,company,group_tag)
                                VALUES (?,?,?,?,?)
                                ON DUPLICATE KEY UPDATE name=VALUES(name),phone=VALUES(phone),company=VALUES(company),group_tag=VALUES(group_tag)");
            $st->execute([$name,$email,$phone,$company,$group]);
            echo json_encode(['success'=>true,'message'=>"$name added successfully.",'id'=>$db->lastInsertId()]);
        } catch(Exception $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }

    // ── Import CSV / Excel ────────────────────────────────────────────────────
    if ($action === 'import_file') {
        if (empty($_FILES['import_file']['tmp_name'])) {
            echo json_encode(['success'=>false,'message'=>'No file uploaded.']);
            exit;
        }
        $file    = $_FILES['import_file'];
        $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $tmpPath = $file['tmp_name'];
        $rows    = [];

        if ($ext === 'csv' || $ext === 'txt') {
            $handle = fopen($tmpPath, 'r');
            $header = null;
            while (($row = fgetcsv($handle)) !== false) {
                if (!$header) { $header = array_map('strtolower', array_map('trim', $row)); continue; }
                if (count($header) !== count($row)) $row = array_pad($row, count($header), '');
                $rows[] = array_combine($header, $row);
            }
            fclose($handle);
        } elseif (in_array($ext, ['xls','xlsx'])) {
            if (!file_exists(__DIR__.'/vendor/autoload.php')) {
                echo json_encode(['success'=>false,'message'=>'PhpSpreadsheet not installed. Please use CSV or run: composer require phpoffice/phpspreadsheet']);
                exit;
            }
            require_once __DIR__.'/vendor/autoload.php';
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpPath);
            $sheet = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
            $header = array_map('strtolower', array_map('trim', array_shift($sheet)));
            foreach ($sheet as $row) {
                $rows[] = array_combine($header, array_pad($row, count($header), ''));
            }
        } else {
            echo json_encode(['success'=>false,'message'=>'Unsupported file type. Use CSV or XLSX.']);
            exit;
        }

        $imported = 0; $skipped = 0; $errors = [];
        $db = getDB();
        $st = $db->prepare("INSERT INTO customers (name,email,phone,company,group_tag)
                            VALUES (?,?,?,?,?)
                            ON DUPLICATE KEY UPDATE name=VALUES(name),phone=VALUES(phone),company=VALUES(company),group_tag=VALUES(group_tag)");

        $colMap = [
            'name'    => ['name','full name','fullname','customer name','cname'],
            'email'   => ['email','email address','mail','e-mail'],
            'phone'   => ['phone','phone number','mobile','contact','contact number','mob'],
            'company' => ['company','company name','organization','org','business'],
            'group'   => ['group','tag','group tag','category','segment'],
        ];

        foreach ($rows as $i => $row) {
            $get = function($aliases) use ($row) {
                foreach ($aliases as $a) {
                    if (isset($row[$a]) && trim($row[$a]) !== '') return trim($row[$a]);
                }
                return '';
            };
            $name    = $get($colMap['name'])  ?: "Contact ".($i+1);
            $email   = $get($colMap['email']);
            $phone   = $get($colMap['phone']);
            $company = $get($colMap['company']);
            $group   = $get($colMap['group']) ?: 'General';

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped++;
                $errors[] = "Row ".($i+2).": invalid email '$email'";
                continue;
            }
            try {
                $st->execute([$name,$email,$phone,$company,$group]);
                $imported++;
            } catch(Exception $e) {
                $skipped++;
                $errors[] = "Row ".($i+2).": ".$e->getMessage();
            }
        }
        echo json_encode(['success'=>true,'imported'=>$imported,'skipped'=>$skipped,'errors'=>$errors]);
        exit;
    }

    // ── List customers ────────────────────────────────────────────────────────
    if ($action === 'list_customers') {
        $search = trim($_POST['search'] ?? '');
        $group  = trim($_POST['group']  ?? '');
        $db     = getDB();
        $where  = ['1=1']; $params = [];
        if ($search) { $where[]='(name LIKE ? OR email LIKE ? OR phone LIKE ?)'; $s="%$search%"; $params=array_merge($params,[$s,$s,$s]); }
        if ($group)  { $where[]='group_tag=?'; $params[]=$group; }
        $whereSql = implode(' AND ', $where);
        $countSt = $db->prepare("SELECT COUNT(*) FROM customers WHERE " . $whereSql);
        $countSt->execute($params);
        $total = (int) $countSt->fetchColumn();

        $st = $db->prepare("SELECT * FROM customers WHERE " . $whereSql . " ORDER BY created_at DESC LIMIT 500");
        $st->execute($params);
        $customers = $st->fetchAll();
        $gs = $db->query("SELECT DISTINCT group_tag FROM customers ORDER BY group_tag")->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode([
            'success' => true,
            'customers' => $customers,
            'groups' => $gs,
            'total' => $total,
            'summary' => getDashboardSummary($db),
        ]);
        exit;
    }

    // ── Delete customer ───────────────────────────────────────────────────────
    if ($action === 'delete_customer') {
        $id = intval($_POST['id'] ?? 0);
        getDB()->prepare("DELETE FROM customers WHERE id=?")->execute([$id]);
        echo json_encode(['success'=>true]);
        exit;
    }

    // ── Toggle status ─────────────────────────────────────────────────────────
    if ($action === 'toggle_status') {
        $id = intval($_POST['id'] ?? 0);
        getDB()->prepare("UPDATE customers SET status=CASE WHEN status='active' THEN 'inactive' ELSE 'active' END WHERE id=?")->execute([$id]);
        echo json_encode(['success'=>true]);
        exit;
    }

    // ── Send emails ───────────────────────────────────────────────────────────
    if ($action === 'send_emails') {
        $mailerBase = __DIR__ . '/PHPMailer-master/src';
        if (!file_exists($mailerBase . '/PHPMailer.php')) {
            echo json_encode(['success'=>false,'message'=>'PHPMailer not found.']); exit;
        }
        require_once $mailerBase . '/Exception.php';
        require_once $mailerBase . '/PHPMailer.php';
        require_once $mailerBase . '/SMTP.php';

        $subject = trim($_POST['subject'] ?? '');
        $body    = trim($_POST['body']    ?? '');
        $isHTML  = ($_POST['is_html'] ?? '1') === '1';
        $group   = trim($_POST['group']  ?? '');
        $ids     = array_values(array_filter(array_map('intval', json_decode($_POST['selected_ids'] ?? '[]', true) ?: [])));
        $sendMode = $ids ? 'selected' : ($group ? 'group' : 'all');

        if (!$subject || !$body) { echo json_encode(['success'=>false,'message'=>'Subject and body are required.']); exit; }

        $db = getDB();
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $db->prepare("SELECT * FROM customers WHERE id IN ($ph) AND status='active'");
            $st->execute($ids);
        } elseif ($group) {
            $st = $db->prepare("SELECT * FROM customers WHERE group_tag=? AND status='active'");
            $st->execute([$group]);
        } else {
            $st = $db->query("SELECT * FROM customers WHERE status='active'");
        }
        $recipients = $st->fetchAll();
        if (!$recipients) { echo json_encode(['success'=>false,'message'=>'No active recipients found.']); exit; }

        $logSt = $db->prepare("INSERT INTO email_logs
            (customer_id, recipient_name, recipient_email, email_subject, email_body, send_mode, group_tag, is_html, status, error_message)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $sent=0; $failed=0; $errors=[];
        foreach ($recipients as $r) {
            $pb = str_replace(['{{Name}}','{{Email}}','{{Phone}}','{{Company}}'],[$r['name'],$r['email'],$r['phone'],$r['company']],$body);
            $ps = str_replace(['{{Name}}','{{Email}}'],[$r['name'],$r['email']],$subject);
            $status = 'sent';
            $errorMessage = null;
            try {
                $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                $mail->isSMTP(); $mail->Host=SMTP_HOST; $mail->SMTPAuth=true;
                $mail->Username=SMTP_USER; $mail->Password=SMTP_PASS;
                $mail->SMTPSecure=\PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port=SMTP_PORT; $mail->CharSet='UTF-8';
                $mail->setFrom(FROM_EMAIL,FROM_NAME);
                $mail->addAddress($r['email'],$r['name']);
                $mail->isHTML($isHTML); $mail->Subject=$ps; $mail->Body=$pb;
                $mail->AltBody = $isHTML ? strip_tags($pb) : $pb;
                $mail->send(); $sent++;
            } catch(Exception $e) {
                $status = 'failed';
                $errorMessage = $e->getMessage();
                $failed++;
                $errors[] = $r['email'] . ': ' . $errorMessage;
            }

            try {
                $logSt->execute([
                    isset($r['id']) ? (int) $r['id'] : null,
                    $r['name'],
                    $r['email'],
                    $ps,
                    $pb,
                    $sendMode,
                    $group ?: ($r['group_tag'] ?? null),
                    $isHTML ? 1 : 0,
                    $status,
                    $errorMessage,
                ]);
            } catch (Exception $logError) {
                $errors[] = $r['email'] . ': failed to store mail log.';
            }
        }
        echo json_encode([
            'success' => true,
            'sent' => $sent,
            'failed' => $failed,
            'total' => count($recipients),
            'errors' => $errors,
            'summary' => getDashboardSummary($db),
        ]);
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action.']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Mail Hub — Customer Manager</title>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<style>
:root{
  --bg:#060c17;--sf:#0d1526;--sf2:#111e34;--sf3:#162440;
  --bd:rgba(255,255,255,.06);--bd2:rgba(255,255,255,.11);
  --ac:#3b82f6;--ac2:#8b5cf6;--ag:linear-gradient(135deg,#3b82f6,#8b5cf6);
  --gr:#10b981;--am:#f59e0b;--rd:#ef4444;
  --tx:#dde6f5;--mt:#4e6382;--mt2:#7b93b8;
  --nh:62px;--r:13px;--sh:0 20px 60px rgba(0,0,0,.55);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg);color:var(--tx);min-height:100vh;
  background-image:radial-gradient(ellipse 65% 45% at 85% 0%,rgba(59,130,246,.09) 0%,transparent 55%),
  radial-gradient(ellipse 45% 35% at 5% 95%,rgba(139,92,246,.07) 0%,transparent 50%);}
::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-thumb{background:var(--sf3);border-radius:99px}

/* NAV */
nav{position:sticky;top:0;z-index:200;height:var(--nh);display:flex;align-items:center;
  justify-content:space-between;padding:0 28px;
  background:rgba(6,12,23,.82);backdrop-filter:blur(22px);border-bottom:1px solid var(--bd);}
.brand{display:flex;align-items:center;gap:11px;text-decoration:none}
.bmark{width:36px;height:36px;border-radius:10px;background:var(--ag);display:grid;place-items:center;
  color:#fff;font-size:14px;box-shadow:0 0 22px rgba(59,130,246,.35);}
.bname{font-family:'Syne',sans-serif;font-weight:800;font-size:1.05rem;color:var(--tx);letter-spacing:-.3px}
.nmenu{display:flex;gap:2px}
.nmenu a{text-decoration:none;color:var(--mt2);font-weight:600;font-size:.83rem;padding:7px 13px;
  border-radius:8px;transition:all .2s;display:flex;align-items:center;gap:6px;}
.nmenu a:hover{color:var(--tx);background:var(--sf2)}
.nmenu a.act{color:var(--ac);background:rgba(59,130,246,.1)}

/* APP LAYOUT */
.app{display:flex;min-height:calc(100vh - var(--nh))}
.sidebar{width:228px;flex-shrink:0;border-right:1px solid var(--bd);padding:22px 14px;
  display:flex;flex-direction:column;gap:3px;position:sticky;top:var(--nh);
  height:calc(100vh - var(--nh));overflow-y:auto;background:rgba(13,21,38,.5);}
.slabel{font-family:'JetBrains Mono',monospace;font-size:.62rem;font-weight:500;
  color:var(--mt);letter-spacing:1.5px;text-transform:uppercase;padding:0 10px;margin:14px 0 5px;}
.slabel:first-of-type{margin-top:0}
.nitem{display:flex;align-items:center;gap:9px;padding:9px 11px;border-radius:9px;
  color:var(--mt2);font-weight:600;font-size:.86rem;cursor:pointer;transition:all .18s;
  border:1px solid transparent;background:none;width:100%;text-align:left;}
.nitem:hover{color:var(--tx);background:var(--sf2);border-color:var(--bd)}
.nitem.act{color:var(--ac);background:rgba(59,130,246,.1);border-color:rgba(59,130,246,.2)}
.ni-ic{width:28px;height:28px;border-radius:7px;display:grid;place-items:center;
  font-size:12px;flex-shrink:0;background:var(--sf3);}
.nitem.act .ni-ic{background:rgba(59,130,246,.2);color:var(--ac)}
.ni-ct{margin-left:auto;background:var(--ac);color:#fff;font-size:.65rem;font-weight:800;
  padding:2px 7px;border-radius:99px;min-width:18px;text-align:center;}

.content{flex:1;padding:30px 32px 60px;overflow-y:auto;min-width:0}
.panel{display:none}
.panel.act{display:block;animation:fadeUp .38s ease both}

/* SECTION HEADER */
.shead{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:24px;gap:14px;flex-wrap:wrap}
.shead h2{font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;letter-spacing:-.5px;
  background:var(--ag);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;}
.shead p{color:var(--mt2);font-size:.87rem;margin-top:3px}

/* CARDS */
.card{background:var(--sf);border:1px solid var(--bd);border-radius:var(--r);overflow:hidden}
.card-head{padding:16px 20px;border-bottom:1px solid var(--bd);display:flex;align-items:center;
  justify-content:space-between;gap:10px;background:var(--sf2);}
.card-head h3{font-family:'Syne',sans-serif;font-size:.95rem;font-weight:700;
  display:flex;align-items:center;gap:8px;}
.ch-ic{width:30px;height:30px;border-radius:7px;background:rgba(59,130,246,.15);color:var(--ac);
  display:grid;place-items:center;font-size:12px;}
.card-body{padding:22px}

/* STATS */
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:13px;margin-bottom:24px}
.scard{background:var(--sf);border:1px solid var(--bd);border-radius:var(--r);padding:16px 18px;
  display:flex;align-items:center;gap:12px;transition:border-color .2s;}
.scard:hover{border-color:var(--bd2)}
.sc-ic{width:44px;height:44px;border-radius:10px;display:grid;place-items:center;font-size:17px;flex-shrink:0}
.sc-lbl{font-family:'JetBrains Mono',monospace;font-size:.65rem;color:var(--mt);letter-spacing:.7px;text-transform:uppercase}
.sc-val{font-family:'Syne',sans-serif;font-size:1.65rem;font-weight:800;color:var(--tx);margin-top:1px}

/* FORMS */
.fg{display:flex;flex-direction:column;gap:6px}
.g2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.g3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px}
.g-full{grid-column:1/-1}
.flbl{font-family:'JetBrains Mono',monospace;font-size:.68rem;font-weight:500;
  color:var(--mt2);letter-spacing:.8px;text-transform:uppercase;}
.flbl .req{color:var(--rd);margin-left:2px}
.fi,input[type="text"],input[type="email"],input[type="tel"],textarea,select{
  background:var(--sf3);border:1px solid var(--bd2);border-radius:8px;color:var(--tx);
  font-family:'Plus Jakarta Sans',sans-serif;font-size:.91rem;padding:10px 13px;
  width:100%;transition:border-color .2s,box-shadow .2s;outline:none;resize:vertical;}
.fi:focus,input:focus,textarea:focus,select:focus{
  border-color:var(--ac);box-shadow:0 0 0 3px rgba(59,130,246,.13);}
input::placeholder,textarea::placeholder{color:var(--mt)}
select option{background:var(--sf2)}
textarea{min-height:140px}
.fhint{font-size:.76rem;color:var(--mt);margin-top:3px;line-height:1.4}

/* DROP ZONE */
.dzone{border:2px dashed var(--bd2);border-radius:11px;padding:34px 22px;text-align:center;
  cursor:pointer;transition:all .22s;background:var(--sf3);position:relative;}
.dzone:hover,.dzone.dg{border-color:var(--ac);background:rgba(59,130,246,.05);}
.dzone input[type="file"]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;}
.dz-ic{font-size:2rem;color:var(--mt);margin-bottom:9px;display:block}
.dz-t{font-size:.88rem;color:var(--mt2);font-weight:600}
.dz-s{font-size:.76rem;color:var(--mt);margin-top:4px}
.dz-fn{margin-top:8px;font-family:'JetBrains Mono',monospace;font-size:.78rem;color:var(--ac);display:none}

/* BUTTONS */
.btn{display:inline-flex;align-items:center;gap:7px;font-family:'Plus Jakarta Sans',sans-serif;
  font-weight:700;font-size:.86rem;padding:9px 19px;border-radius:9px;
  cursor:pointer;border:none;transition:all .18s;text-decoration:none;white-space:nowrap;}
.btn-pr{background:var(--ag);color:#fff;box-shadow:0 4px 18px rgba(59,130,246,.28);}
.btn-pr:hover{transform:translateY(-1px);box-shadow:0 6px 26px rgba(59,130,246,.42)}
.btn-pr:disabled{opacity:.5;cursor:not-allowed;transform:none}
.btn-gh{background:transparent;color:var(--mt2);border:1px solid var(--bd2);}
.btn-gh:hover{color:var(--tx);border-color:var(--bd2);background:var(--sf2)}
.btn-dn{background:rgba(239,68,68,.09);color:var(--rd);border:1px solid rgba(239,68,68,.22);}
.btn-dn:hover{background:rgba(239,68,68,.18)}
.btn-sm{padding:6px 13px;font-size:.78rem;border-radius:7px}
.btn-xs{padding:3px 9px;font-size:.72rem;border-radius:6px}
.arow{display:flex;justify-content:flex-end;gap:9px;margin-top:20px;flex-wrap:wrap}
.divider{border:none;border-top:1px solid var(--bd);margin:20px 0}

/* BADGES */
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:99px;font-size:.72rem;font-weight:700;}
.b-act{background:rgba(16,185,129,.11);color:var(--gr);border:1px solid rgba(16,185,129,.22)}
.b-ina{background:rgba(239,68,68,.09);color:var(--rd);border:1px solid rgba(239,68,68,.2)}
.b-grp{background:rgba(59,130,246,.1);color:var(--ac);border:1px solid rgba(59,130,246,.2)}
.b-req{background:rgba(239,68,68,.09);color:var(--rd);border:1px solid rgba(239,68,68,.2);font-size:.7rem;padding:2px 8px}
.b-opt{background:rgba(59,130,246,.09);color:var(--ac);border:1px solid rgba(59,130,246,.2);font-size:.7rem;padding:2px 8px}

/* TABLE */
.tbar{display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap}
.sbox{display:flex;align-items:center;gap:7px;background:var(--sf3);border:1px solid var(--bd2);
  border-radius:8px;padding:0 13px;flex:1;min-width:200px;max-width:300px;}
.sbox i{color:var(--mt);font-size:12px}
.sbox input{background:none;border:none;outline:none;color:var(--tx);font-family:'Plus Jakarta Sans',sans-serif;
  font-size:.88rem;padding:9px 0;width:100%;}
.fsel{background:var(--sf3);border:1px solid var(--bd2);color:var(--tx);border-radius:8px;
  padding:9px 13px;font-family:'Plus Jakarta Sans',sans-serif;font-size:.86rem;outline:none;cursor:pointer;min-width:130px;}
.twrap{overflow-x:auto;border-radius:var(--r);border:1px solid var(--bd)}
table{width:100%;border-collapse:collapse}
thead th{background:var(--sf2);padding:11px 15px;font-family:'JetBrains Mono',monospace;
  font-size:.63rem;color:var(--mt);letter-spacing:1px;text-transform:uppercase;
  text-align:left;border-bottom:1px solid var(--bd);white-space:nowrap;}
tbody tr{border-bottom:1px solid var(--bd);transition:background .13s}
tbody tr:last-child{border-bottom:none}
tbody tr:hover{background:var(--sf2)}
td{padding:12px 15px;font-size:.86rem;vertical-align:middle}
.tn{font-weight:700;color:var(--tx)}
.ts{font-size:.76rem;color:var(--mt);margin-top:1px}
.tfoot{display:flex;align-items:center;justify-content:space-between;
  padding:11px 15px;background:var(--sf2);border-top:1px solid var(--bd);
  font-size:.8rem;color:var(--mt2);flex-wrap:wrap;gap:7px;}
.empty-t{text-align:center;padding:56px 20px}
.empty-t i{font-size:2.2rem;color:var(--mt);display:block;margin-bottom:12px;opacity:.45}
.empty-t p{color:var(--mt2);font-size:.87rem}
input[type="checkbox"]{width:15px;height:15px;cursor:pointer;accent-color:var(--ac)}

/* IMPORT RESULT */
.ir-wrap{margin-top:16px;background:var(--sf3);border:1px solid var(--bd);border-radius:11px;padding:16px;display:none}
.ir-wrap.vis{display:block}
.ir-stats{display:flex;gap:9px;flex-wrap:wrap;margin-bottom:10px}
.ir-s{display:flex;align-items:center;gap:5px;padding:5px 13px;border-radius:7px;font-weight:700;font-size:.82rem}
.ir-ok{background:rgba(16,185,129,.1);color:var(--gr);border:1px solid rgba(16,185,129,.2)}
.ir-sk{background:rgba(239,68,68,.09);color:var(--rd);border:1px solid rgba(239,68,68,.2)}
.ir-err details summary{cursor:pointer;color:var(--rd);font-size:.8rem;font-weight:700}
.ir-err pre{font-family:'JetBrains Mono',monospace;font-size:.7rem;color:var(--mt2);background:var(--sf);padding:10px;border-radius:7px;margin-top:5px;overflow-x:auto;white-space:pre-wrap}

/* COMPOSE */
.cl{display:grid;grid-template-columns:1fr 320px;gap:22px;align-items:start}
.rsel{background:var(--sf3);border:1px solid var(--bd2);border-radius:11px;padding:14px;margin-bottom:14px}
.ro{display:flex;align-items:flex-start;gap:9px;padding:9px 11px;border-radius:8px;
  cursor:pointer;transition:background .13s;margin-bottom:3px}
.ro:hover{background:var(--sf)}
.ro input[type="radio"]{accent-color:var(--ac);width:15px;height:15px;margin-top:2px;flex-shrink:0}
.ro-lbl{font-weight:700;font-size:.88rem;cursor:pointer;display:block}
.ro-sub{font-size:.76rem;color:var(--mt);margin-top:3px}
.rcount{display:inline-flex;align-items:center;gap:5px;padding:4px 11px;border-radius:99px;
  background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.2);color:var(--ac);font-weight:700;font-size:.8rem;margin-bottom:12px}
.pers-hint{background:rgba(245,158,11,.07);border:1px solid rgba(245,158,11,.18);border-radius:8px;
  padding:11px 13px;margin-top:9px;font-size:.8rem;color:var(--am);line-height:1.5}
.pers-hint strong{font-family:'JetBrains Mono',monospace}
.ci{display:flex;flex-direction:column;gap:13px}
.icard{background:var(--sf);border:1px solid var(--bd);border-radius:11px;padding:15px}
.icard h4{font-family:'Syne',sans-serif;font-size:.88rem;font-weight:700;margin-bottom:9px;color:var(--mt2)}
.irow{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--bd);font-size:.81rem}
.irow:last-child{border-bottom:none}
.irow .il{color:var(--mt2)}.irow .iv{font-weight:700;font-family:'JetBrains Mono',monospace}

/* TEMPLATES */
.tgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:15px}
.tc{background:var(--sf);border:1px solid var(--bd);border-radius:var(--r);overflow:hidden;
  cursor:pointer;transition:all .22s;position:relative;}
.tc:hover{border-color:rgba(59,130,246,.38);transform:translateY(-3px);box-shadow:0 12px 30px rgba(59,130,246,.13)}
.tc-stripe{height:3px}
.tc-bd{padding:19px}
.tc-hd{display:flex;align-items:center;gap:11px;margin-bottom:11px}
.tc-em{font-size:1.45rem;width:42px;height:42px;background:var(--sf2);border-radius:9px;display:grid;place-items:center;flex-shrink:0}
.tc-name{font-family:'Syne',sans-serif;font-weight:700;font-size:.95rem}
.tc-cat{font-size:.71rem;color:var(--mt);margin-top:2px}
.tc-desc{font-size:.81rem;color:var(--mt2);line-height:1.5;margin-bottom:13px}
.tc-tags{display:flex;gap:5px;flex-wrap:wrap}
.tc-tag{font-family:'JetBrains Mono',monospace;font-size:.66rem;background:var(--sf3);
  color:var(--mt2);padding:2px 8px;border-radius:5px}
.tc-btn{margin-top:14px;width:100%;display:flex;align-items:center;justify-content:center;gap:6px;
  padding:9px;background:rgba(59,130,246,.07);border:1px solid rgba(59,130,246,.18);
  border-radius:8px;color:var(--ac);font-weight:700;font-size:.82rem;transition:all .18s}
.tc:hover .tc-btn{background:rgba(59,130,246,.14);border-color:rgba(59,130,246,.38)}

/* PROGRESS & RESULT */
.pw{display:none;margin-top:16px}
.pw.vis{display:block}
.pt{display:flex;justify-content:space-between;font-size:.78rem;color:var(--mt2);margin-bottom:7px;font-weight:700}
.ptrack{height:5px;background:var(--sf3);border-radius:99px;overflow:hidden}
.pfill{height:100%;background:var(--ag);border-radius:99px;width:0%;transition:width .4s ease}
.rw{display:none;margin-top:16px;background:var(--sf2);border:1px solid var(--bd);border-radius:11px;padding:16px}
.rw.vis{display:block}
.rrow{display:flex;gap:9px;flex-wrap:wrap;margin-bottom:10px}
.rb{display:inline-flex;align-items:center;gap:5px;padding:5px 14px;border-radius:99px;font-weight:700;font-size:.83rem}
.rb-t{background:rgba(59,130,246,.1);color:var(--ac);border:1px solid rgba(59,130,246,.22)}
.rb-s{background:rgba(16,185,129,.1);color:var(--gr);border:1px solid rgba(16,185,129,.22)}
.rb-f{background:rgba(239,68,68,.09);color:var(--rd);border:1px solid rgba(239,68,68,.2)}

/* TOGGLE */
.tog{position:relative;display:inline-block;width:38px;height:20px;cursor:pointer;vertical-align:middle}
.tog input{opacity:0;width:0;height:0}
.tog-t{position:absolute;inset:0;background:var(--sf3);border-radius:99px;transition:.3s;border:1px solid var(--bd2)}
.tog-t::before{content:'';position:absolute;width:14px;height:14px;background:#fff;border-radius:50%;left:3px;top:2px;transition:.3s;box-shadow:0 1px 3px rgba(0,0,0,.4)}
.tog input:checked+.tog-t{background:var(--ac)}
.tog input:checked+.tog-t::before{transform:translateX(18px)}

/* TOAST */
#toasts{position:fixed;top:72px;right:18px;z-index:9999;display:flex;flex-direction:column;gap:9px;pointer-events:none}
.toast{display:flex;align-items:flex-start;gap:11px;background:var(--sf2);border:1px solid var(--bd2);
  border-radius:12px;padding:13px 17px;min-width:270px;max-width:360px;
  box-shadow:var(--sh);pointer-events:all;animation:slideIn .28s ease;}
.t-sc{border-left:3px solid var(--gr)}.t-er{border-left:3px solid var(--rd)}
.t-in{border-left:3px solid var(--ac)}.t-wa{border-left:3px solid var(--am)}
.ti{font-size:15px;flex-shrink:0;margin-top:1px}
.t-sc .ti{color:var(--gr)}.t-er .ti{color:var(--rd)}.t-in .ti{color:var(--ac)}.t-wa .ti{color:var(--am)}
.tt{font-weight:700;font-size:.88rem;margin-bottom:2px}.tm{font-size:.78rem;color:var(--mt2);line-height:1.4}
.tout{animation:slideOut .3s ease forwards}

/* MODAL */
.mbg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);backdrop-filter:blur(9px);
  z-index:500;align-items:center;justify-content:center;}
.mbg.open{display:flex}
.modal{background:var(--sf);border:1px solid var(--bd2);border-radius:16px;
  width:min(640px,95vw);max-height:87vh;display:flex;flex-direction:column;animation:fadeUp .28s ease}
.mh{padding:18px 22px;border-bottom:1px solid var(--bd);display:flex;align-items:center;justify-content:space-between}
.mh h3{font-family:'Syne',sans-serif;font-weight:700;font-size:1rem}
.mb{padding:22px;overflow-y:auto;flex:1}
.mf{padding:14px 22px;border-top:1px solid var(--bd);display:flex;justify-content:flex-end;gap:9px}
.bclose{background:var(--sf3);border:none;color:var(--mt2);width:30px;height:30px;border-radius:7px;
  cursor:pointer;display:grid;place-items:center;font-size:1rem;transition:all .2s}
.bclose:hover{background:var(--sf2);color:var(--tx)}
.prev-frame{background:#fff;color:#111;border-radius:9px;padding:24px;min-height:180px;font-size:.93rem;line-height:1.6}

/* SAMPLE CSV */
.scsv{font-family:'JetBrains Mono',monospace;font-size:.7rem;color:var(--mt2);
  background:var(--sf3);padding:13px;border-radius:8px;overflow-x:auto;line-height:1.7}

@keyframes fadeUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
@keyframes slideIn{from{opacity:0;transform:translateX(28px)}to{opacity:1;transform:translateX(0)}}
@keyframes slideOut{to{opacity:0;transform:translateX(18px)}}
@keyframes spin{to{transform:rotate(360deg)}}
.spin{animation:spin .8s linear infinite;display:inline-block}

@media(max-width:880px){
  .sidebar{display:none}.cl{grid-template-columns:1fr}
  .stats{grid-template-columns:1fr 1fr}.g3{grid-template-columns:1fr 1fr}
}
@media(max-width:580px){
  .content{padding:18px 14px}.stats{grid-template-columns:1fr}
  .g2,.g3{grid-template-columns:1fr}
}
</style>
</head>
<body>

<nav>
  <a class="brand" href="#">
    <span class="bmark"><i class="fa fa-paper-plane"></i></span>
    <span class="bname">Task Manager</span>
  </a>
  <div class="nmenu">
    <a href="#"><i class="fa fa-house"></i> Home</a>
    <a href="#" class="act"><i class="fa fa-envelope-open-text"></i> Mail Hub</a>
    <a href="#"><i class="fa fa-calendar-days"></i> Schedule</a>
    <a href="login.php?logout=1"><i class="fa fa-right-from-bracket"></i> Logout</a>
  </div>
</nav>

<div id="toasts"></div>

<!-- PREVIEW MODAL -->
<div class="mbg" id="prevModal">
  <div class="modal">
    <div class="mh">
      <h3><i class="fa fa-eye" style="color:var(--ac);margin-right:7px"></i>Email Preview</h3>
      <button class="bclose" onclick="cm('prevModal')"><i class="fa fa-xmark"></i></button>
    </div>
    <div class="mb"><div class="prev-frame" id="prevFrame">…</div></div>
    <div class="mf"><button class="btn btn-gh" onclick="cm('prevModal')">Close</button></div>
  </div>
</div>

<div class="app">
  <!-- SIDEBAR -->
  <aside class="sidebar">
    <span class="slabel">Overview</span>
    <button class="nitem act" onclick="sp('dashboard',this)">
      <span class="ni-ic"><i class="fa fa-gauge-high"></i></span>Dashboard
    </button>
    <span class="slabel">Customers</span>
    <button class="nitem" onclick="sp('addCustomer',this)">
      <span class="ni-ic"><i class="fa fa-user-plus"></i></span>Add Customer
    </button>
    <button class="nitem" onclick="sp('import',this)">
      <span class="ni-ic"><i class="fa fa-file-import"></i></span>Import CSV / Excel
    </button>
    <button class="nitem" onclick="sp('list',this);lc()">
      <span class="ni-ic"><i class="fa fa-users"></i></span>Customer List
      <span class="ni-ct" id="scnt">0</span>
    </button>
    <span class="slabel">Email</span>
    <button class="nitem" onclick="sp('compose',this);rcm()">
      <span class="ni-ic"><i class="fa fa-pen-to-square"></i></span>Compose & Send
    </button>
    <button class="nitem" onclick="sp('templates',this)">
      <span class="ni-ic"><i class="fa fa-layer-group"></i></span>Templates
    </button>
  </aside>

  <main class="content">

    <!-- ══ DASHBOARD ════════════════════════════════════════════════════════ -->
    <div class="panel act" id="panel-dashboard">
      <div class="shead">
        <div><h2>Dashboard</h2><p>Overview of your customer base and email activity.</p></div>
        <button class="btn btn-pr" onclick="sp('addCustomer',null)"><i class="fa fa-user-plus"></i> Add Customer</button>
      </div>
      <div class="stats">
        <div class="scard">
          <div class="sc-ic" style="background:rgba(59,130,246,.12);color:var(--ac)"><i class="fa fa-users"></i></div>
          <div><div class="sc-lbl">Total</div><div class="sc-val" id="d-total">—</div></div>
        </div>
        <div class="scard">
          <div class="sc-ic" style="background:rgba(16,185,129,.12);color:var(--gr)"><i class="fa fa-circle-check"></i></div>
          <div><div class="sc-lbl">Active</div><div class="sc-val" id="d-active">—</div></div>
        </div>
        <div class="scard">
          <div class="sc-ic" style="background:rgba(245,158,11,.12);color:var(--am)"><i class="fa fa-paper-plane"></i></div>
          <div><div class="sc-lbl">Emails Sent</div><div class="sc-val" id="d-sent">0</div></div>
        </div>
        <div class="scard">
          <div class="sc-ic" style="background:rgba(239,68,68,.12);color:var(--rd)"><i class="fa fa-circle-xmark"></i></div>
          <div><div class="sc-lbl">Failed</div><div class="sc-val" id="d-fail">0</div></div>
        </div>
      </div>
      <div class="card">
        <div class="card-head">
          <h3><span class="ch-ic"><i class="fa fa-users"></i></span>Recent Customers</h3>
          <button class="btn btn-gh btn-sm" onclick="sp('list',null);lc()">View All</button>
        </div>
        <div id="dashRecent"><div class="empty-t"><i class="fa fa-circle-notch spin"></i><p>Loading…</p></div></div>
      </div>
    </div>

    <!-- ══ ADD CUSTOMER ══════════════════════════════════════════════════════ -->
    <div class="panel" id="panel-addCustomer">
      <div class="shead">
        <div><h2>Add Customer</h2><p>Manually enter a customer's contact details into the database.</p></div>
      </div>
      <div class="card">
        <div class="card-head">
          <h3><span class="ch-ic"><i class="fa fa-user-plus"></i></span>Customer Information</h3>
        </div>
        <div class="card-body">
          <div class="g2">
            <div class="fg">
              <label class="flbl">Full Name <span class="req">*</span></label>
              <input type="text" id="ac-name" placeholder="e.g. Ajay Patel"/>
            </div>
            <div class="fg">
              <label class="flbl">Email Address <span class="req">*</span></label>
              <input type="email" id="ac-email" placeholder="ajay@example.com"/>
            </div>
            <div class="fg">
              <label class="flbl">Contact Number</label>
              <input type="tel" id="ac-phone" placeholder="+91 98765 43210"/>
            </div>
            <div class="fg">
              <label class="flbl">Company / Organization</label>
              <input type="text" id="ac-company" placeholder="Acme Corp"/>
            </div>
            <div class="fg g-full">
              <label class="flbl">Group / Segment</label>
              <select id="ac-group">
                <option value="General">General</option>
                <option value="VIP">VIP</option>
                <option value="Newsletter">Newsletter</option>
                <option value="Leads">Leads</option>
                <option value="Partners">Partners</option>
                <option value="Clients">Clients</option>
              </select>
              <span class="fhint">Used to segment customers when sending group emails.</span>
            </div>
          </div>
          <div class="arow">
            <button class="btn btn-gh" onclick="clrAdd()"><i class="fa fa-rotate-left"></i> Clear</button>
            <button class="btn btn-pr" id="ac-btn" onclick="addCustomer()"><i class="fa fa-floppy-disk"></i> Save Customer</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ══ IMPORT ════════════════════════════════════════════════════════════ -->
    <div class="panel" id="panel-import">
      <div class="shead">
        <div><h2>Import Customers</h2><p>Bulk-import contacts from a CSV or Excel file directly into the database.</p></div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:22px;align-items:start">
        <div class="card">
          <div class="card-head">
            <h3><span class="ch-ic"><i class="fa fa-file-arrow-up"></i></span>Upload File</h3>
          </div>
          <div class="card-body">
            <div class="dzone" id="dz">
              <input type="file" id="impFile" accept=".csv,.xlsx,.xls,.txt" onchange="onFS(this)"/>
              <span class="dz-ic"><i class="fa fa-cloud-arrow-up"></i></span>
              <div class="dz-t">Drop your file here or click to browse</div>
              <div class="dz-s">Supports CSV, XLSX, XLS, TXT</div>
              <div class="dz-fn" id="dzFn"></div>
            </div>
            <div class="arow" style="margin-top:16px">
              <button class="btn btn-pr" id="impBtn" onclick="importFile()" disabled>
                <i class="fa fa-file-import"></i> Import Customers
              </button>
            </div>
            <div class="ir-wrap" id="irWrap"></div>
          </div>
        </div>
        <div style="display:flex;flex-direction:column;gap:16px">
          <div class="card">
            <div class="card-head"><h3><span class="ch-ic"><i class="fa fa-circle-info"></i></span>Column Guide</h3></div>
            <div class="card-body">
              <p style="font-size:.83rem;color:var(--mt2);margin-bottom:13px;line-height:1.6">
                Your file needs a <strong>header row</strong>. Column names are matched automatically (case-insensitive). Only <strong>email</strong> is required.
              </p>
              <div class="twrap" style="border-radius:8px">
                <table>
                  <thead><tr><th>Field</th><th>Accepted Column Names</th></tr></thead>
                  <tbody>
                    <tr><td style="font-family:'JetBrains Mono',monospace;font-size:.75rem">email</td><td style="font-size:.76rem;color:var(--mt2)"><span class="b-req">Required</span> email, mail, e-mail, email address</td></tr>
                    <tr><td style="font-family:'JetBrains Mono',monospace;font-size:.75rem">name</td><td style="font-size:.76rem;color:var(--mt2)"><span class="b-opt">Optional</span> name, cname, full name, customer name</td></tr>
                    <tr><td style="font-family:'JetBrains Mono',monospace;font-size:.75rem">phone</td><td style="font-size:.76rem;color:var(--mt2)"><span class="b-opt">Optional</span> phone, mobile, contact, contact number</td></tr>
                    <tr><td style="font-family:'JetBrains Mono',monospace;font-size:.75rem">company</td><td style="font-size:.76rem;color:var(--mt2)"><span class="b-opt">Optional</span> company, organization, org, business</td></tr>
                    <tr><td style="font-family:'JetBrains Mono',monospace;font-size:.75rem">group</td><td style="font-size:.76rem;color:var(--mt2)"><span class="b-opt">Optional</span> group, tag, category, segment</td></tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
          <div class="card">
            <div class="card-head"><h3><span class="ch-ic"><i class="fa fa-file-csv"></i></span>Sample CSV</h3></div>
            <div class="card-body">
              <pre class="scsv">name,email,phone,company,group
Ajay Patel,ajay@example.com,9876543210,Acme Corp,VIP
Priya Shah,priya@domain.in,9123456789,TechStart,Newsletter
Ravi Kumar,ravi@mail.com,,Freelance,Leads</pre>
              <button class="btn btn-gh btn-sm" style="margin-top:11px" onclick="dlSample()">
                <i class="fa fa-download"></i> Download Sample
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ══ CUSTOMER LIST ═════════════════════════════════════════════════════ -->
    <div class="panel" id="panel-list">
      <div class="shead">
        <div><h2>Customer List</h2><p>View, manage and segment all your contacts.</p></div>
        <div style="display:flex;gap:9px">
          <button class="btn btn-gh" onclick="sp('import',null)"><i class="fa fa-file-import"></i> Import</button>
          <button class="btn btn-pr" onclick="sp('addCustomer',null)"><i class="fa fa-user-plus"></i> Add</button>
        </div>
      </div>
      <div class="tbar">
        <div class="sbox">
          <i class="fa fa-magnifying-glass"></i>
          <input type="text" id="cl-s" placeholder="Search name, email, phone…" oninput="lc()"/>
        </div>
        <select class="fsel" id="cl-g" onchange="lc()"><option value="">All Groups</option></select>
        <button class="btn btn-gh btn-sm" onclick="lc()"><i class="fa fa-rotate-right"></i></button>
        <button class="btn btn-dn btn-sm" id="delSelBtn" style="display:none" onclick="delSel()">
          <i class="fa fa-trash"></i> Delete Selected
        </button>
      </div>
      <div class="twrap">
        <table>
          <thead>
            <tr>
              <th><input type="checkbox" id="selAll" onchange="togAll(this)"/></th>
              <th>Customer</th><th>Phone</th><th>Company</th>
              <th>Group</th><th>Status</th><th>Added</th><th>Actions</th>
            </tr>
          </thead>
          <tbody id="ctb">
            <tr><td colspan="8"><div class="empty-t"><i class="fa fa-circle-notch spin"></i><p>Loading…</p></div></td></tr>
          </tbody>
        </table>
        <div class="tfoot">
          <span id="cl-cnt">0 customers</span>
          <button class="btn btn-gh btn-xs" onclick="sp('compose',null);rcm()"><i class="fa fa-paper-plane"></i> Email All</button>
        </div>
      </div>
    </div>

    <!-- ══ COMPOSE ═══════════════════════════════════════════════════════════ -->
    <div class="panel" id="panel-compose">
      <div class="shead">
        <div><h2>Compose & Send</h2><p>Write your email and send it to your customers.</p></div>
        <button class="btn btn-gh" onclick="sp('templates',null)"><i class="fa fa-layer-group"></i> Templates</button>
      </div>
      <div class="cl">
        <div style="display:flex;flex-direction:column;gap:17px">
          <!-- Recipients -->
          <div class="card">
            <div class="card-head">
              <h3><span class="ch-ic"><i class="fa fa-users"></i></span>Recipients</h3>
              <div class="rcount" id="rCnt"><i class="fa fa-user"></i> 0 recipients</div>
            </div>
            <div class="card-body">
              <div class="rsel">
                <div class="ro">
                  <input type="radio" name="rm" id="r-all" value="all" checked onchange="rcm()"/>
                  <div>
                    <label class="ro-lbl" for="r-all">All Active Customers</label>
                    <div class="ro-sub">Send to every active customer in the database</div>
                  </div>
                </div>
                <div class="ro">
                  <input type="radio" name="rm" id="r-grp" value="group" onchange="rcm()"/>
                  <div>
                    <label class="ro-lbl" for="r-grp">By Group</label>
                    <div class="ro-sub">
                      <select id="cg" class="fi" style="margin-top:5px;max-width:210px" onchange="rcm()">
                        <option value="">Select group…</option>
                      </select>
                    </div>
                  </div>
                </div>
                <div class="ro">
                  <input type="radio" name="rm" id="r-sel" value="selected" onchange="rcm()"/>
                  <div>
                    <label class="ro-lbl" for="r-sel">Selected from List</label>
                    <div class="ro-sub">Manually select customers in the Customer List panel</div>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <!-- Email content -->
          <div class="card">
            <div class="card-head">
              <h3><span class="ch-ic"><i class="fa fa-envelope"></i></span>Email Content</h3>
              <div style="display:flex;align-items:center;gap:8px;font-size:.8rem;color:var(--mt2)">
                HTML Mode
                <label class="tog">
                  <input type="checkbox" id="htmlTog" checked/>
                  <span class="tog-t"></span>
                </label>
              </div>
            </div>
            <div class="card-body">
              <div style="display:flex;flex-direction:column;gap:13px">
                <div class="fg">
                  <label class="flbl">Subject <span class="req">*</span></label>
                  <input type="text" id="c-sub" placeholder="e.g.  Hello {{Name}}, here's your update!"/>
                </div>
                <div class="fg">
                  <label class="flbl">Email Body <span class="req">*</span></label>
                  <textarea id="c-body" style="min-height:190px" placeholder="Write your email here…&#10;Use {{Name}}, {{Email}}, {{Phone}}, {{Company}} for personalization."></textarea>
                  <div class="pers-hint">
                    <i class="fa fa-wand-magic-sparkles"></i> Personalization tags: <strong>{{Name}}</strong>, <strong>{{Email}}</strong>, <strong>{{Phone}}</strong>, <strong>{{Company}}</strong> — replaced per recipient automatically.
                  </div>
                </div>
              </div>
              <div class="pw" id="pw">
                <div class="pt"><span id="pTxt">Sending…</span><span id="pPct">0%</span></div>
                <div class="ptrack"><div class="pfill" id="pFill"></div></div>
              </div>
              <div class="rw" id="rw">
                <h4 style="font-family:'Syne',sans-serif;font-weight:700;margin-bottom:10px;font-size:.92rem">
                  <i class="fa fa-chart-simple" style="color:var(--ac);margin-right:6px"></i>Send Result
                </h4>
                <div class="rrow" id="rBadges"></div>
                <div id="rErrs"></div>
              </div>
              <div class="arow">
                <button class="btn btn-gh" onclick="document.getElementById('c-sub').value='';document.getElementById('c-body').value=''"><i class="fa fa-rotate-left"></i> Clear</button>
                <button class="btn btn-gh" onclick="prevEmail()"><i class="fa fa-eye"></i> Preview</button>
                <button class="btn btn-pr" id="sendBtn" onclick="sendEmails()"><i class="fa fa-paper-plane"></i> Send Emails</button>
              </div>
            </div>
          </div>
        </div>
        <!-- Compose sidebar info -->
        <div class="ci">
          <div class="icard">
            <h4><i class="fa fa-circle-info" style="color:var(--ac);margin-right:6px"></i>Send Summary</h4>
            <div class="irow"><span class="il">Mode</span><span class="iv" id="ci-mode">All Active</span></div>
            <div class="irow"><span class="il">Recipients</span><span class="iv" id="ci-cnt">—</span></div>
            <div class="irow"><span class="il">SMTP</span><span class="iv" style="font-size:.72rem">smtp.gmail.com</span></div>
            <div class="irow"><span class="il">Port / Enc</span><span class="iv">587 / TLS</span></div>
          </div>
          <div class="icard">
            <h4><i class="fa fa-lightbulb" style="color:var(--am);margin-right:6px"></i>Tips</h4>
            <ul style="font-size:.8rem;color:var(--mt2);line-height:2;padding-left:15px">
              <li>Use Templates for fast composition</li>
              <li>Preview before sending</li>
              <li>Personalize with <code style="font-family:'JetBrains Mono',monospace;font-size:.73rem;background:var(--sf3);padding:1px 5px;border-radius:4px">{{Name}}</code> tags</li>
              <li>Inactive customers are skipped</li>
            </ul>
          </div>
          <div class="icard">
            <h4><i class="fa fa-clock-rotate-left" style="color:var(--gr);margin-right:6px"></i>Session Stats</h4>
            <div class="irow"><span class="il">Sent</span><span class="iv" id="ss-s">0</span></div>
            <div class="irow"><span class="il">Failed</span><span class="iv" id="ss-f">0</span></div>
            <div class="irow"><span class="il">Total</span><span class="iv" id="ss-t">0</span></div>
          </div>
        </div>
      </div>
    </div>

    <!-- ══ TEMPLATES ════════════════════════════════════════════════════════ -->
    <div class="panel" id="panel-templates">
      <div class="shead">
        <div><h2>Email Templates</h2><p>Professional, ready-to-use HTML templates. Click any to load into the composer.</p></div>
      </div>
      <div class="tgrid" id="tgrid"></div>
    </div>

  </main>
</div>

<script>
/* ── State ── */
let selIds=[], allCust=[], ss={sent:0,failed:0,total:0};

/* ── Helpers ── */
const $=id=>document.getElementById(id);
function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;')}
function toast(tp,title,msg){
  const ic={success:'fa-circle-check',error:'fa-circle-xmark',info:'fa-circle-info',warning:'fa-triangle-exclamation'};
  const t=document.createElement('div');
  t.className=`toast t-${tp.slice(0,2)}`;
  t.innerHTML=`<span class="ti"><i class="fa ${ic[tp]||'fa-circle-info'}"></i></span><div><div class="tt">${esc(title)}</div><div class="tm">${esc(msg)}</div></div>`;
  $('toasts').appendChild(t);
  setTimeout(()=>{t.classList.add('tout');setTimeout(()=>t.remove(),320)},4200);
}
function cm(id){$(id).classList.remove('open')}
document.querySelectorAll('.mbg').forEach(m=>m.addEventListener('click',e=>{if(e.target===m)m.classList.remove('open')}));
function ajax(data,cb){
  data.ajax='1';const fd=new FormData();
  Object.entries(data).forEach(([k,v])=>fd.append(k,v));
  fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(res=>{
    if(res.redirect){window.location=res.redirect;return}
    cb(res);
  }).catch(e=>toast('error','Network Error',e.message));
}
function ajaxFD(fd,cb){fd.append('ajax','1');fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(res=>{
  if(res.redirect){window.location=res.redirect;return}
  cb(res);
}).catch(e=>toast('error','Network Error',e.message))}
function applySummary(summary){
  if(!summary)return;
  ss.sent=Number(summary.sent_emails||0);
  ss.failed=Number(summary.failed_emails||0);
  ss.total=Number(summary.total_emails||0);
  $('d-total').textContent=Number(summary.total_customers||0);
  $('d-active').textContent=Number(summary.active_customers||0);
  $('d-sent').textContent=ss.sent;
  $('d-fail').textContent=ss.failed;
  $('scnt').textContent=Number(summary.total_customers||0);
  updSS();
}
function refreshData(){loadDash();lc();}

/* ── Panel nav ── */
function sp(id,btn){
  document.querySelectorAll('.panel').forEach(p=>p.classList.remove('act'));
  $(`panel-${id}`).classList.add('act');
  document.querySelectorAll('.nitem').forEach(b=>b.classList.remove('act'));
  if(btn)btn.classList.add('act');
}

/* ── Add Customer ── */
function addCustomer(){
  const name=$('ac-name').value.trim(),email=$('ac-email').value.trim(),
        phone=$('ac-phone').value.trim(),company=$('ac-company').value.trim(),
        group=$('ac-group').value;
  if(!name){toast('error','Missing Name','Please enter the customer name.');return}
  if(!email||!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){toast('error','Invalid Email','Enter a valid email address.');return}
  const btn=$('ac-btn');btn.disabled=true;btn.innerHTML='<i class="fa fa-circle-notch spin"></i> Saving…';
  ajax({action:'add_customer',name,email,phone,company,group},res=>{
    btn.disabled=false;btn.innerHTML='<i class="fa fa-floppy-disk"></i> Save Customer';
    if(res.success){toast('success','Customer Saved',res.message);clrAdd();refreshData()}
    else toast('error','Save Failed',res.message);
  });
}
function clrAdd(){['ac-name','ac-email','ac-phone','ac-company'].forEach(id=>$(id).value='');$('ac-group').value='General'}

/* ── Import ── */
function onFS(inp){
  const f=inp.files[0];if(!f)return;
  $('dzFn').style.display='block';$('dzFn').textContent='📄 '+f.name;$('impBtn').disabled=false;
}
const dz=$('dz');
dz.addEventListener('dragover',e=>{e.preventDefault();dz.classList.add('dg')});
dz.addEventListener('dragleave',()=>dz.classList.remove('dg'));
dz.addEventListener('drop',e=>{e.preventDefault();dz.classList.remove('dg');$('impFile').files=e.dataTransfer.files;onFS($('impFile'))});

function importFile(){
  const fi=$('impFile');if(!fi.files[0]){toast('error','No File','Please select a file.');return}
  const btn=$('impBtn');btn.disabled=true;btn.innerHTML='<i class="fa fa-circle-notch spin"></i> Importing…';
  const fd=new FormData();fd.append('action','import_file');fd.append('import_file',fi.files[0]);
  ajaxFD(fd,res=>{
    btn.disabled=false;btn.innerHTML='<i class="fa fa-file-import"></i> Import Customers';
    const d=$('irWrap');d.classList.add('vis');
    if(res.success){
      d.innerHTML=`<div class="ir-stats"><div class="ir-s ir-ok"><i class="fa fa-circle-check"></i> ${res.imported} imported</div>${res.skipped?`<div class="ir-s ir-sk"><i class="fa fa-circle-xmark"></i> ${res.skipped} skipped</div>`:''}</div>${res.errors&&res.errors.length?`<div class="ir-err"><details><summary><i class="fa fa-triangle-exclamation"></i> ${res.errors.length} issue(s)</summary><pre>${esc(res.errors.join('\n'))}</pre></details></div>`:''}`;
      toast('success','Import Complete',`${res.imported} imported, ${res.skipped} skipped.`);refreshData();
    }else{d.innerHTML=`<div class="ir-s ir-sk"><i class="fa fa-circle-xmark"></i> ${esc(res.message)}</div>`;toast('error','Import Failed',res.message)}
  });
}
function dlSample(){
  const csv="name,email,phone,company,group\nAjay Patel,ajay@example.com,9876543210,Acme Corp,VIP\nPriya Shah,priya@domain.in,9123456789,TechStart,Newsletter\nRavi Kumar,ravi@mail.com,,Freelance,Leads";
  const a=document.createElement('a');a.href='data:text/csv;charset=utf-8,'+encodeURIComponent(csv);
  a.download='sample_customers.csv';a.click();
}

/* ── Customer List ── */
function lc(){
  const s=$('cl-s')?.value||'',g=$('cl-g')?.value||'';
  ajax({action:'list_customers',search:s,group:g},res=>{
    if(!res.success)return;allCust=res.customers;renderCT(res.customers);
    applySummary(res.summary);
    const gs=res.groups||[];
    const clg=$('cl-g'),cur=clg.value;
    clg.innerHTML='<option value="">All Groups</option>';
    gs.forEach(g2=>clg.innerHTML+=`<option value="${esc(g2)}"${g2===cur?' selected':''}>${esc(g2)}</option>`);
    const cg=$('cg');cg.innerHTML='<option value="">Select group…</option>';
    gs.forEach(g2=>cg.innerHTML+=`<option value="${esc(g2)}">${esc(g2)}</option>`);
    usc(res.summary?.total_customers);rcm();
  });
}
function renderCT(c){
  const tb=$('ctb'),cnt=$('cl-cnt');
  cnt.textContent=c.length+' customer'+(c.length!==1?'s':'');
  if(!c.length){tb.innerHTML=`<tr><td colspan="8"><div class="empty-t"><i class="fa fa-users"></i><p>No customers found.<br>Add one manually or import a file.</p></div></td></tr>`;return}
  tb.innerHTML=c.map(r=>`<tr data-id="${r.id}">
    <td><input type="checkbox" class="rc" value="${r.id}" onchange="onRC()"/></td>
    <td><div class="tn">${esc(r.name)}</div><div class="ts">${esc(r.email)}</div></td>
    <td style="font-family:'JetBrains Mono',monospace;font-size:.76rem;color:var(--mt2)">${r.phone?esc(r.phone):'—'}</td>
    <td style="font-size:.84rem">${r.company?esc(r.company):'<span style="color:var(--mt)">—</span>'}</td>
    <td><span class="badge b-grp">${esc(r.group_tag)}</span></td>
    <td><span class="badge ${r.status==='active'?'b-act':'b-ina'}" style="cursor:pointer" onclick="togSt(${r.id})" title="Toggle status">
      <i class="fa ${r.status==='active'?'fa-circle-check':'fa-circle-xmark'}"></i> ${r.status}</span></td>
    <td style="font-size:.76rem;color:var(--mt2)">${new Date(r.created_at).toLocaleDateString()}</td>
    <td><button class="btn btn-dn btn-xs" onclick="delC(${r.id})" title="Delete"><i class="fa fa-trash"></i></button></td>
  </tr>`).join('');
}
function onRC(){
  const chk=document.querySelectorAll('.rc:checked');selIds=[...chk].map(c=>c.value);
  $('delSelBtn').style.display=selIds.length?'inline-flex':'none';
  $('selAll').checked=chk.length===document.querySelectorAll('.rc').length;
}
function togAll(cb){document.querySelectorAll('.rc').forEach(c=>c.checked=cb.checked);onRC()}
function delSel(){
  if(!selIds.length)return;if(!confirm(`Delete ${selIds.length} customer(s)?`))return;
  let done=0;selIds.forEach(id=>ajax({action:'delete_customer',id},()=>{done++;if(done===selIds.length){refreshData();toast('success','Deleted',`${done} customer(s) removed.`)}}));
}
function delC(id){if(!confirm('Delete this customer?'))return;ajax({action:'delete_customer',id},res=>{if(res.success){refreshData();toast('success','Deleted','Customer removed.')}})}
function togSt(id){ajax({action:'toggle_status',id},res=>{if(res.success)refreshData()})}
function usc(n){
  if(n!==undefined){$('scnt').textContent=n;return}
  ajax({action:'list_customers',search:'',group:''},res=>{$('scnt').textContent=res.summary?.total_customers??res.total??0});
}

/* ── Compose ── */
function rcm(){
  const mode=document.querySelector('input[name="rm"]:checked')?.value||'all';
  const g=mode==='group'?$('cg').value:'';
  let lbl=mode==='all'?'All Active':mode==='group'?(g?'Group: '+g:'Group'):'Selected';
  $('ci-mode').textContent=lbl;
  if(mode!=='selected'){
    ajax({action:'list_customers',search:'',group:g},res=>{
      const n=(res.customers||[]).filter(c=>c.status==='active').length;
      $('ci-cnt').textContent=n;
      $('rCnt').innerHTML=`<i class="fa fa-user"></i> ${n} recipient${n!==1?'s':''}`;
    });
  }else{
    $('ci-cnt').textContent=selIds.length;
    $('rCnt').innerHTML=`<i class="fa fa-user"></i> ${selIds.length} recipient${selIds.length!==1?'s':''}`;
  }
  updSS();
}
function updSS(){$('ss-s').textContent=ss.sent;$('ss-f').textContent=ss.failed;$('ss-t').textContent=ss.total}

/* ── Send ── */
function sendEmails(){
  const sub=$('c-sub').value.trim(),body=$('c-body').value.trim();
  const isHTML=$('htmlTog').checked?'1':'0';
  const mode=document.querySelector('input[name="rm"]:checked').value;
  const g=mode==='group'?$('cg').value:'';
  if(!sub){toast('error','No Subject','Please enter a subject.');return}
  if(!body){toast('error','No Body','Please write the email body.');return}
  if(mode==='group'&&!g){toast('warning','No Group','Please select a group.');return}
  const btn=$('sendBtn');btn.disabled=true;btn.innerHTML='<i class="fa fa-circle-notch spin"></i> Sending…';
  $('pw').classList.add('vis');$('rw').classList.remove('vis');startP();
  ajax({action:'send_emails',subject:sub,body,is_html:isHTML,group:mode==='group'?g:'',selected_ids:mode==='selected'?JSON.stringify(selIds):'[]'},res=>{
    stopP(100);btn.disabled=false;btn.innerHTML='<i class="fa fa-paper-plane"></i> Send Emails';
    if(res.success){
      applySummary(res.summary);
      $('rBadges').innerHTML=`<span class="rb rb-t"><i class="fa fa-envelope"></i> ${res.total} total</span><span class="rb rb-s"><i class="fa fa-circle-check"></i> ${res.sent} delivered</span>${res.failed?`<span class="rb rb-f"><i class="fa fa-circle-xmark"></i> ${res.failed} failed</span>`:''}`;
      $('rErrs').innerHTML=res.errors&&res.errors.length?`<details style="margin-top:9px"><summary style="cursor:pointer;color:var(--rd);font-size:.79rem;font-weight:700"><i class="fa fa-triangle-exclamation"></i> ${res.errors.length} error(s)</summary><pre style="font-family:'JetBrains Mono',monospace;font-size:.7rem;color:var(--mt2);background:var(--sf3);padding:10px;border-radius:7px;margin-top:5px;overflow-x:auto;white-space:pre-wrap">${esc(res.errors.join('\n'))}</pre></details>`:'';
      $('rw').classList.add('vis');
      toast(res.failed?'warning':'success',res.failed?'Partial success':'Sent!',`${res.sent}/${res.total} delivered.`);
    }else toast('error','Send Failed',res.message||'Unknown error.');
  });
}

/* ── Progress ── */
let _pv=0,_pi=null;
function startP(){_pv=0;setP(0,'Preparing…');_pi=setInterval(()=>{_pv=Math.min(_pv+Math.random()*7,88);setP(_pv,'Sending…')},300)}
function stopP(v){clearInterval(_pi);setP(v,'Done!')}
function setP(v,l){$('pFill').style.width=v+'%';$('pTxt').textContent=l;$('pPct').textContent=Math.round(v)+'%'}

/* ── Preview ── */
function prevEmail(){
  const b=$('c-body').value,h=$('htmlTog').checked;
  if(!b.trim()){toast('error','Empty','Write something first!');return}
  $('prevFrame').innerHTML=h?b:b.replace(/\n/g,'<br>');
  $('prevModal').classList.add('open');
}

/* ── Templates ── */
const TMPLS=[
  {emoji:'📰',name:'Monthly Newsletter',cat:'Marketing',color:'#3b82f6',
   desc:'Informative newsletter with sections for news, updates, and a clear call-to-action.',
   tags:['newsletter','monthly','update'],
   subject:'{{Name}}, your monthly update is here 📰',
   body:`<div style="font-family:Georgia,serif;max-width:600px;margin:auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.1)">
  <div style="background:linear-gradient(135deg,#1e3a5f,#3b82f6);padding:40px 36px;text-align:center">
    <p style="color:rgba(255,255,255,.7);margin:0 0 6px;font-size:12px;letter-spacing:2px;text-transform:uppercase">Monthly Newsletter</p>
    <h1 style="color:#fff;margin:0;font-size:28px;font-weight:700">What's New This Month</h1>
  </div>
  <div style="padding:36px">
    <p style="color:#333;font-size:16px">Hi <strong>{{Name}}</strong>,</p>
    <p style="color:#555;line-height:1.8">Here's everything that happened this month and what's on the horizon. We've been working hard to bring you improvements!</p>
    <h2 style="color:#3b82f6;font-size:17px;margin-top:28px;border-left:3px solid #3b82f6;padding-left:12px">🚀 Highlights</h2>
    <ul style="color:#555;line-height:2.1;padding-left:20px"><li>New dashboard performance improvements</li><li>Added integrations with popular tools</li><li>Security patches and updates applied</li></ul>
    <h2 style="color:#3b82f6;font-size:17px;margin-top:24px;border-left:3px solid #3b82f6;padding-left:12px">📅 Coming Up</h2>
    <p style="color:#555;line-height:1.8">Next month: a brand-new reporting module launches. Stay tuned for the announcement!</p>
    <div style="text-align:center;margin:32px 0"><a href="#" style="background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff;padding:14px 34px;border-radius:8px;text-decoration:none;font-weight:700;font-size:15px;display:inline-block">Read More →</a></div>
  </div>
  <div style="background:#f5f5f5;padding:18px 36px;text-align:center;font-size:12px;color:#999">You're receiving this as a subscriber. <a href="#" style="color:#3b82f6">Unsubscribe</a></div>
</div>`},
  {emoji:'🎉',name:'Welcome Email',cat:'Onboarding',color:'#10b981',
   desc:'Warm welcome email for new users with clear next steps and a friendly tone.',
   tags:['welcome','onboarding','new'],
   subject:'Welcome to the team, {{Name}}! 🎉',
   body:`<div style="font-family:'Helvetica Neue',Arial,sans-serif;max-width:600px;margin:auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08)">
  <div style="background:#0d1f2d;padding:50px 40px;text-align:center">
    <div style="font-size:50px;margin-bottom:14px">🎉</div>
    <h1 style="color:#10b981;margin:0;font-size:28px;font-weight:800">You're in!</h1>
    <p style="color:rgba(255,255,255,.55);margin:8px 0 0;font-size:15px">Welcome to My Task Manager</p>
  </div>
  <div style="padding:38px 42px">
    <p style="font-size:17px;color:#1a1a1a;font-weight:600;margin-top:0">Hey {{Name}} 👋</p>
    <p style="color:#555;line-height:1.8;font-size:15px">We're thrilled to have you. Your account is set up and ready. Here's how to get started:</p>
    <div style="display:flex;flex-direction:column;gap:10px;margin:22px 0">
      <div style="background:#ecfdf5;border-left:4px solid #10b981;padding:13px 17px;border-radius:0 8px 8px 0;color:#065f46;font-size:14px"><strong>Step 1</strong> — Complete your profile</div>
      <div style="background:#ecfdf5;border-left:4px solid #10b981;padding:13px 17px;border-radius:0 8px 8px 0;color:#065f46;font-size:14px"><strong>Step 2</strong> — Create your first task</div>
      <div style="background:#ecfdf5;border-left:4px solid #10b981;padding:13px 17px;border-radius:0 8px 8px 0;color:#065f46;font-size:14px"><strong>Step 3</strong> — Invite your team</div>
    </div>
    <div style="text-align:center;margin:30px 0"><a href="#" style="background:#10b981;color:#fff;padding:14px 36px;border-radius:8px;text-decoration:none;font-weight:700;font-size:15px;display:inline-block">Get Started →</a></div>
    <p style="color:#999;font-size:13px;text-align:center">Questions? Just reply — we read every message.</p>
  </div>
  <div style="background:#f9fafb;padding:16px 42px;text-align:center;font-size:12px;color:#ccc;border-top:1px solid #eee">© 2025 My Task Manager · <a href="#" style="color:#10b981">Unsubscribe</a></div>
</div>`},
  {emoji:'⏰',name:'Deadline Reminder',cat:'Productivity',color:'#f59e0b',
   desc:'Urgent deadline reminder with a structured info block and clear action button.',
   tags:['reminder','deadline','task'],
   subject:'⏰ Action required: {{Task}} due {{Date}}',
   body:`<div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;background:#fff;border-radius:10px;overflow:hidden;border:1px solid #e5e7eb">
  <div style="background:#1c1c2e;padding:30px 34px;display:flex;align-items:center;gap:14px;border-bottom:3px solid #f59e0b">
    <div style="font-size:36px">⏰</div>
    <div><h2 style="color:#f59e0b;margin:0;font-size:22px">Deadline Reminder</h2><p style="color:#888;margin:4px 0 0;font-size:13px">Action required before the due date</p></div>
  </div>
  <div style="padding:32px 34px">
    <p style="color:#333;font-size:15px">Hi <strong>{{Name}}</strong>,</p>
    <p style="color:#666;line-height:1.7;font-size:14px">This is your scheduled reminder. The following item needs your attention:</p>
    <div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:9px;padding:20px;margin:20px 0">
      <table style="width:100%;border-collapse:collapse">
        <tr><td style="padding:7px 0;color:#92400e;font-weight:700;font-size:13px;width:90px">Task</td><td style="color:#333;font-size:14px">{{Task}}</td></tr>
        <tr><td style="padding:7px 0;color:#92400e;font-weight:700;font-size:13px">Due Date</td><td style="color:#333;font-size:14px">{{Date}}</td></tr>
        <tr><td style="padding:7px 0;color:#92400e;font-weight:700;font-size:13px">Priority</td><td><span style="background:#f59e0b;color:#fff;padding:2px 10px;border-radius:99px;font-size:11px;font-weight:700">HIGH</span></td></tr>
      </table>
    </div>
    <div style="text-align:center;margin:26px 0"><a href="#" style="background:#f59e0b;color:#fff;padding:13px 32px;border-radius:8px;text-decoration:none;font-weight:700;display:inline-block">Take Action Now</a></div>
  </div>
  <div style="background:#f9fafb;padding:14px 34px;text-align:center;font-size:11px;color:#aaa;border-top:1px solid #e5e7eb">Auto-generated by My Task Manager</div>
</div>`},
  {emoji:'🛍️',name:'Special Offer',cat:'Sales',color:'#ef4444',
   desc:'High-impact promo email with bold discount display, promo code, and expiry urgency.',
   tags:['promo','sale','discount'],
   subject:'🔥 {{Discount}}% OFF — only for you, {{Name}}',
   body:`<div style="font-family:'Helvetica Neue',Arial,sans-serif;max-width:600px;margin:auto;background:#0a0a0a;border-radius:12px;overflow:hidden">
  <div style="background:linear-gradient(135deg,#ef4444,#f59e0b);padding:54px 40px;text-align:center">
    <p style="color:rgba(255,255,255,.75);margin:0 0 8px;font-size:11px;letter-spacing:2.5px;text-transform:uppercase">Limited Time Offer</p>
    <div style="font-size:76px;font-weight:900;color:#fff;line-height:1;letter-spacing:-3px">{{Discount}}%<br><span style="font-size:38px">OFF</span></div>
    <p style="color:rgba(255,255,255,.85);margin:14px 0 0;font-size:16px">Exclusively for you, {{Name}}</p>
  </div>
  <div style="padding:36px 42px;color:#e5e7eb">
    <p style="font-size:15px;line-height:1.7;color:#ccc">We're rewarding our best customers with an exclusive discount. Use the code below at checkout:</p>
    <div style="background:#161616;border:2px dashed #ef4444;border-radius:10px;padding:22px;text-align:center;margin:22px 0">
      <p style="color:#888;font-size:10px;letter-spacing:2px;text-transform:uppercase;margin:0 0 8px">Your promo code</p>
      <div style="font-family:'Courier New',monospace;font-size:30px;font-weight:900;color:#ef4444;letter-spacing:5px">{{Code}}</div>
    </div>
    <p style="color:#666;font-size:12px;text-align:center">Expires <strong style="color:#f59e0b">{{Date}}</strong>. Cannot be combined with other offers.</p>
    <div style="text-align:center;margin:26px 0"><a href="#" style="background:linear-gradient(135deg,#ef4444,#f59e0b);color:#fff;padding:15px 40px;border-radius:8px;text-decoration:none;font-weight:800;font-size:16px;display:inline-block">Claim Your Discount</a></div>
  </div>
  <div style="background:#060606;padding:14px 42px;text-align:center;font-size:11px;color:#555;border-top:1px solid #1a1a1a">© 2025 My Task Manager · <a href="#" style="color:#ef4444">Unsubscribe</a></div>
</div>`},
  {emoji:'🙏',name:'Thank You',cat:'Relationship',color:'#8b5cf6',
   desc:'Heartfelt appreciation email with a quote block and feedback call-to-action.',
   tags:['thanks','appreciation','loyalty'],
   subject:'Thank you, {{Name}} 🙏 — you matter to us',
   body:`<div style="font-family:Georgia,serif;max-width:600px;margin:auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.07)">
  <div style="background:linear-gradient(160deg,#7c3aed,#3b82f6);padding:50px 40px;text-align:center">
    <div style="font-size:50px;margin-bottom:12px">🙏</div>
    <h1 style="color:#fff;margin:0;font-size:26px;font-weight:700">From the bottom of our hearts</h1>
  </div>
  <div style="padding:38px 42px">
    <p style="font-size:17px;color:#1a1a1a;margin-top:0">Dear <strong>{{Name}}</strong>,</p>
    <p style="color:#555;line-height:1.9;font-size:15px">We wanted to take a moment to personally thank you for your continued trust and support. It genuinely means everything to us.</p>
    <blockquote style="border-left:4px solid #8b5cf6;margin:24px 0;padding:16px 20px;background:#faf5ff;border-radius:0 8px 8px 0;color:#6d28d9;font-style:italic;font-size:15px">"Every single customer matters to us — your support fuels our passion to build better things."</blockquote>
    <p style="color:#555;line-height:1.9;font-size:15px">We'd love to hear about your experience. Your feedback helps us grow and serve you better every day.</p>
    <div style="text-align:center;margin:30px 0"><a href="#" style="background:linear-gradient(135deg,#8b5cf6,#3b82f6);color:#fff;padding:14px 36px;border-radius:8px;text-decoration:none;font-weight:700;font-size:15px;display:inline-block">Share Feedback →</a></div>
    <p style="color:#888;font-size:14px;margin-bottom:0">With gratitude,<br><strong style="color:#333">The My Task Manager Team</strong></p>
  </div>
  <div style="background:#faf5ff;padding:16px 42px;text-align:center;font-size:12px;color:#c4b5fd;border-top:1px solid #ede9fe">© 2025 My Task Manager · <a href="#" style="color:#8b5cf6">Unsubscribe</a></div>
</div>`},
  {emoji:'⚙️',name:'Maintenance Notice',cat:'System',color:'#64748b',
   desc:'Clean system maintenance alert with structured date/time table and impact indicator.',
   tags:['system','maintenance','downtime'],
   subject:'⚙️ Scheduled maintenance on {{Date}} — heads up',
   body:`<div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;background:#fff;border-radius:10px;overflow:hidden;border:1px solid #e2e8f0">
  <div style="background:#1e293b;padding:28px 34px;display:flex;align-items:center;gap:14px">
    <div style="font-size:34px">⚙️</div>
    <div><h2 style="color:#e2e8f0;margin:0;font-size:20px">Scheduled Maintenance</h2><p style="color:#94a3b8;margin:4px 0 0;font-size:13px">System Notification</p></div>
  </div>
  <div style="padding:30px 34px">
    <p style="color:#334155;font-size:15px">Dear <strong>{{Name}}</strong>,</p>
    <p style="color:#64748b;line-height:1.7;font-size:14px">We'll be performing scheduled maintenance to improve system performance and security. Some services may be temporarily unavailable during this window.</p>
    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:18px;margin:18px 0">
      <table style="width:100%;border-collapse:collapse">
        <tr style="border-bottom:1px solid #e2e8f0"><td style="padding:9px 0;color:#94a3b8;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;width:110px">Date</td><td style="color:#1e293b;font-weight:600;font-size:14px">{{Date}}</td></tr>
        <tr style="border-bottom:1px solid #e2e8f0"><td style="padding:9px 0;color:#94a3b8;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px">Start</td><td style="color:#1e293b;font-weight:600;font-size:14px">{{Start}}</td></tr>
        <tr style="border-bottom:1px solid #e2e8f0"><td style="padding:9px 0;color:#94a3b8;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px">End</td><td style="color:#1e293b;font-weight:600;font-size:14px">{{End}}</td></tr>
        <tr><td style="padding:9px 0;color:#94a3b8;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px">Impact</td><td><span style="background:#fef3c7;color:#92400e;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700">Partial Downtime</span></td></tr>
      </table>
    </div>
    <p style="color:#64748b;font-size:13px;line-height:1.7">We apologize for any inconvenience. Our team will complete this as quickly as possible.</p>
  </div>
  <div style="background:#f1f5f9;padding:14px 34px;text-align:center;font-size:11px;color:#94a3b8;border-top:1px solid #e2e8f0">My Task Manager System Alerts · <a href="#" style="color:#3b82f6">Manage Preferences</a></div>
</div>`},
];

function renderTmpls(){
  $('tgrid').innerHTML=TMPLS.map((t,i)=>`
    <div class="tc" onclick="loadT(${i})">
      <div class="tc-stripe" style="background:${t.color}"></div>
      <div class="tc-bd">
        <div class="tc-hd">
          <div class="tc-em">${t.emoji}</div>
          <div><div class="tc-name">${esc(t.name)}</div><div class="tc-cat">${esc(t.cat)}</div></div>
        </div>
        <div class="tc-desc">${esc(t.desc)}</div>
        <div class="tc-tags">${t.tags.map(tg=>`<span class="tc-tag">${esc(tg)}</span>`).join('')}</div>
        <div class="tc-btn"><i class="fa fa-arrow-right-to-bracket"></i> Use Template</div>
      </div>
    </div>`).join('');
}

function loadT(i){
  const t=TMPLS[i];
  $('c-sub').value=t.subject;$('c-body').value=t.body;$('htmlTog').checked=true;
  sp('compose',null);
  toast('success','Template Loaded',`"${t.name}" is ready in the composer.`);
}

/* ── Dashboard recent table ── */
function loadDash(){
  ajax({action:'list_customers',search:'',group:''},res=>{
    if(!res.success)return;
    applySummary(res.summary);
    const rec=res.customers.slice(0,6),div=$('dashRecent');
    if(!rec.length){div.innerHTML=`<div class="empty-t"><i class="fa fa-users"></i><p>No customers yet.</p></div>`;return}
    div.innerHTML=`<div style="overflow-x:auto"><table><thead><tr>
      <th>Customer</th><th>Phone</th><th>Company</th><th>Group</th><th>Status</th>
    </tr></thead><tbody>${rec.map(c=>`<tr style="border-bottom:1px solid var(--bd)">
      <td><div class="tn">${esc(c.name)}</div><div class="ts">${esc(c.email)}</div></td>
      <td style="font-family:'JetBrains Mono',monospace;font-size:.75rem;color:var(--mt2)">${c.phone?esc(c.phone):'—'}</td>
      <td style="font-size:.83rem">${c.company?esc(c.company):'—'}</td>
      <td><span class="badge b-grp">${esc(c.group_tag)}</span></td>
      <td><span class="badge ${c.status==='active'?'b-act':'b-ina'}">${c.status}</span></td>
    </tr>`).join('')}</tbody></table></div>`;
  });
}

/* ── Init ── */
renderTmpls();
loadDash();
rcm();updSS();
</script> 
</body>
</html>
