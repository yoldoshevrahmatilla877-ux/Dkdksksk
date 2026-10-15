<?php
/* ===== SOZLAMALAR (faqat shu yerni to'ldiring) ===== */
const DB_HOST='localhost', DB_USER='c4254_kino', DB_PASS='BAZA_PAROLI', DB_NAME='c4254_kino';
const SHOP_ID='249444', SHOP_KEY='YANGI_SHOP_KEY';
const MIN_AMOUNT=1000, MAX_AMOUNT=10000000, EXPIRE_SEC=300;
/* ================================================== */

session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax','secure'=>!empty($_SERVER['HTTPS'])]);
session_start();
mysqli_report(MYSQLI_REPORT_OFF);
$db=new mysqli(DB_HOST,DB_USER,DB_PASS,DB_NAME); $db->set_charset('utf8mb4');
$db->query("CREATE TABLE IF NOT EXISTS site_users(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,username VARCHAR(32) NOT NULL UNIQUE,pass VARCHAR(255) NOT NULL,balance BIGINT UNSIGNED NOT NULL DEFAULT 0,created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$db->query("CREATE TABLE IF NOT EXISTS checkout(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_code VARCHAR(64) NOT NULL UNIQUE,user_id BIGINT UNSIGNED NOT NULL,amount INT UNSIGNED NOT NULL,status VARCHAR(12) NOT NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,KEY(status),KEY(user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function cc(array $p){$ch=curl_init('https://checkcard.uz/api?'.http_build_query($p));curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>1,CURLOPT_TIMEOUT=>15]);$r=curl_exec($ch);curl_close($ch);$j=json_decode((string)$r,true);return is_array($j)?$j:null;}
function out($d,$c=200){http_response_code($c);header('Content-Type: application/json; charset=utf-8');echo json_encode($d,JSON_UNESCAPED_UNICODE);exit;}
function uid(){$i=(int)($_SESSION['uid']??0);if(!$i)out(['ok'=>false,'error'=>'Avval tizimga kiring'],401);return $i;}
function credit($order,$amount){global $db;$db->begin_transaction();
  $s=$db->prepare("UPDATE checkout SET status='paid',amount=? WHERE order_code=? AND status IN('pending','cancel')");$s->bind_param('is',$amount,$order);$s->execute();
  if($s->affected_rows!==1){$db->rollback();return false;}
  $s=$db->prepare("UPDATE site_users u JOIN checkout c ON c.user_id=u.id SET u.balance=u.balance+? WHERE c.order_code=?");$s->bind_param('is',$amount,$order);$s->execute();
  if($s->affected_rows<1){$db->rollback();return false;}$db->commit();return true;}
function clean($o){return preg_replace('/[^A-Za-z0-9_-]/','',$o);}

$a=$_GET['a']??'';

if($a==='webhook'){ // CheckCard webhook
  $in=json_decode(file_get_contents('php://input'),true);
  if(!is_array($in)||empty($in['order'])){http_response_code(400);exit('bad');}
  if(!hash_equals(SHOP_KEY,(string)($in['shopkey']??''))){http_response_code(403);exit('forbidden');}
  $o=clean($in['order']);$r=cc(['method'=>'check','order'=>$o]);
  if($r&&($r['status']??'')==='success'&&($r['data']['status']??'')==='paid')credit($o,(int)$r['data']['amount']);
  exit('OK');
}
if($a==='create'){
  $u=uid();$amount=(int)($_POST['amount']??0);
  if($amount<MIN_AMOUNT||$amount>MAX_AMOUNT)out(['ok'=>false,'error'=>"Summa 1 000 dan 10 000 000 so'mgacha bo'lishi kerak"],400);
  $db->query("UPDATE checkout SET status='cancel' WHERE user_id=$u AND status='pending' AND created_at<(NOW()-INTERVAL ".EXPIRE_SEC." SECOND)");
  if($db->query("SELECT 1 FROM checkout WHERE user_id=$u AND status='pending'")->num_rows)out(['ok'=>false,'error'=>"Sizda tugallanmagan to'lov bor. Avval uni yakunlang yoki bekor qiling."],409);
  $res=null;
  for($i=0;$i<20;$i++){$t=$amount+$i;$res=cc(['method'=>'create','shop_id'=>SHOP_ID,'shop_key'=>SHOP_KEY,'amount'=>$t,'payurl'=>'true']);
    if($res&&($res['status']??'')==='success'){$amount=$t;break;}
    if(!$res||stripos($res['message']??'','pending payment')===false)break;}
  if(!$res||($res['status']??'')!=='success')out(['ok'=>false,'error'=>$res['message']??"To'lov tizimi bilan bog'lanib bo'lmadi"],502);
  $o=$res['order'];$am=(int)($res['amount']??$amount);
  $s=$db->prepare("INSERT INTO checkout(order_code,user_id,amount,status) VALUES(?,?,?,'pending')");$s->bind_param('sii',$o,$u,$am);$s->execute();
  out(['ok'=>true,'order'=>$o,'amount'=>$am,'card'=>$res['card']??'','expires_in'=>EXPIRE_SEC]);
}
if($a==='check'){
  $u=uid();$o=clean($_POST['order']??'');
  $s=$db->prepare("SELECT status,amount,created_at FROM checkout WHERE order_code=? AND user_id=?");$s->bind_param('si',$o,$u);$s->execute();
  $row=$s->get_result()->fetch_assoc();if(!$row)out(['ok'=>false,'error'=>'Buyurtma topilmadi'],404);
  if($row['status']==='pending'){
    if(strtotime($row['created_at'])+EXPIRE_SEC<time()){cc(['method'=>'cancel','order'=>$o]);$db->query("UPDATE checkout SET status='cancel' WHERE order_code='$o' AND status='pending'");$row['status']='cancel';}
    else{$r=cc(['method'=>'check','order'=>$o]);
      if($r&&($r['status']??'')==='success'){$st=$r['data']['status']??'pending';
        if($st==='paid'){credit($o,(int)$r['data']['amount']);$row['status']='paid';$row['amount']=$r['data']['amount'];}
        elseif($st==='cancel'){$db->query("UPDATE checkout SET status='cancel' WHERE order_code='$o' AND status='pending'");$row['status']='cancel';}}}}
  $bal=(int)$db->query("SELECT balance FROM site_users WHERE id=$u")->fetch_assoc()['balance'];
  out(['ok'=>true,'status'=>$row['status'],'amount'=>(int)$row['amount'],'balance'=>$bal]);
}
if($a==='cancel'){
  $u=uid();$o=clean($_POST['order']??'');
  $s=$db->prepare("UPDATE checkout SET status='cancel' WHERE order_code=? AND user_id=? AND status='pending'");$s->bind_param('si',$o,$u);$s->execute();
  if($s->affected_rows===1)cc(['method'=>'cancel','order'=>$o]);out(['ok'=>true]);
}
if($a==='logout'){session_destroy();header('Location: ./');exit;}

$err='';
if($_SERVER['REQUEST_METHOD']==='POST'&&($a==='login'||$a==='register')){
  $un=strtolower(trim($_POST['username']??''));$pw=$_POST['password']??'';
  if(!preg_match('/^[a-z0-9_]{3,32}$/',$un))$err="Login: 3-32 ta harf, raqam yoki _";
  elseif(strlen($pw)<6)$err="Parol kamida 6 belgi";
  elseif($a==='register'){$h=password_hash($pw,PASSWORD_DEFAULT);$s=$db->prepare("INSERT INTO site_users(username,pass) VALUES(?,?)");$s->bind_param('ss',$un,$h);
    if($s->execute()){session_regenerate_id(true);$_SESSION['uid']=$db->insert_id;header('Location: ./');exit;}$err="Bu login band";}
  else{$s=$db->prepare("SELECT id,pass FROM site_users WHERE username=?");$s->bind_param('s',$un);$s->execute();$r=$s->get_result()->fetch_assoc();
    if($r&&password_verify($pw,$r['pass'])){session_regenerate_id(true);$_SESSION['uid']=(int)$r['id'];header('Location: ./');exit;}$err="Login yoki parol noto'g'ri";}
}
$me=null;
if(!empty($_SESSION['uid'])){$s=$db->prepare("SELECT username,balance FROM site_users WHERE id=?");$s->bind_param('i',$_SESSION['uid']);$s->execute();$me=$s->get_result()->fetch_assoc();if(!$me)session_destroy();}
$h=fn($x)=>htmlspecialchars((string)$x,ENT_QUOTES);
?><!DOCTYPE html><html lang="uz"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>Kino Drama</title>
<style>
:root{color-scheme:dark;--bg:#111;--panel:#1b1b1b;--line:#2a2a2a;--g1:#00ff6a;--g2:#00b84a;--mut:#8a8a8a}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html,body{margin:0;background:#000;color:#fff;font-family:Roboto,system-ui,sans-serif}
.wrap{max-width:520px;margin:0 auto;padding:24px 20px}
.top{display:flex;justify-content:space-between;margin-bottom:22px}.top a{color:var(--mut);text-decoration:none;font-size:14px}
.card{position:relative;overflow:hidden;border-radius:26px;padding:26px;background:linear-gradient(135deg,#1fe35f,#07a143);color:#06140b;box-shadow:0 0 40px rgba(0,255,106,.25)}
.card:before{content:"";position:absolute;right:-60px;top:-60px;width:170px;height:170px;border-radius:50%;background:rgba(255,255,255,.12)}
.card small{display:block;font-size:15px;opacity:.75}.bal{font-size:34px;font-weight:800;margin:8px 0 18px}
.dark{border:0;border-radius:16px;padding:14px 22px;font-size:16px;font-weight:700;background:#06140b;color:var(--g1)}
#ov{position:fixed;inset:0;background:rgba(0,0,0,.6);display:none;align-items:flex-end}#ov.on{display:flex}
.sheet{width:100%;max-width:520px;margin:0 auto;background:var(--bg);border-radius:28px 28px 0 0;padding:14px 20px calc(28px + env(safe-area-inset-bottom,0px));max-height:94vh;overflow:auto}
.grab{width:44px;height:4px;border-radius:4px;background:#444;margin:0 auto 20px}h1{font-size:26px;margin:0 0 16px}
.num{font-size:25px;font-weight:700;letter-spacing:1.5px;margin:14px 0 18px;position:relative}
.row{display:flex;justify-content:space-between;align-items:center}.copy{border:0;background:rgba(0,0,0,.18);width:44px;height:40px;border-radius:12px;font-size:18px}
input{width:100%;background:var(--panel);border:1px solid var(--line);border-radius:22px;padding:19px;font-size:18px;color:#fff;outline:0;margin:0 0 12px}
.chips{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}.chip{background:var(--panel);border:1px solid var(--line);color:#ddd;border-radius:14px;padding:9px 14px;font-size:14px}
.btn{width:100%;border:0;border-radius:22px;padding:18px;font-size:17px;font-weight:700;margin-top:10px}
.main{background:linear-gradient(90deg,var(--g1),var(--g2));color:#04110a}.ghost{background:transparent;border:1px solid var(--line);color:var(--mut);font-weight:500}.main:disabled{opacity:.5}
.info{background:var(--panel);border:1px dashed #3a3a3a;border-radius:20px;padding:16px;text-align:center;color:#ccc;margin:14px 0;line-height:1.6}
.info b{color:var(--g1);font-size:22px}.timer{font-size:26px;font-weight:700;color:var(--g1)}
.err{color:#ff6b6b;text-align:center;min-height:20px;margin:6px 0}.ok{text-align:center;padding:16px 0}.big{font-size:60px}.hide{display:none}
.box{margin-top:12vh;background:var(--bg);border-radius:28px;padding:28px}.lnk{display:block;text-align:center;margin-top:16px;color:var(--g1);text-decoration:none}
</style></head><body><div class="wrap">
<?php if(!$me): $reg=($a==='register'); ?>
  <form class="box" method="post" action="?a=<?= $reg?'register':'login' ?>">
    <h1>🎬 Kino Drama</h1>
    <?php if($err): ?><p class="err"><?= $h($err) ?></p><?php endif; ?>
    <input name="username" placeholder="Login" autocomplete="username" required>
    <input name="password" type="password" placeholder="Parol" autocomplete="<?= $reg?'new-password':'current-password' ?>" required>
    <button class="btn main"><?= $reg?"RO'YXATDAN O'TISH":"KIRISH" ?></button>
    <a class="lnk" href="?a=<?= $reg?'login':'register' ?>"><?= $reg?"Akkauntingiz bormi? Kirish":"Akkaunt yo'qmi? Ro'yxatdan o'tish" ?></a>
  </form>
<?php else: ?>
  <div class="top"><b>👤 <?= $h($me['username']) ?></b><a href="?a=logout">Chiqish</a></div>
  <div class="card"><small>Balansingiz</small><div class="bal" id="bal"><?= number_format($me['balance'],0,'',' ') ?> so'm</div>
    <button class="dark" id="open">➕ Hisobni to'ldirish</button></div>
</div>
<div id="ov"><div class="sheet"><div class="grab"></div><h1>Hisobni to'ldirish</h1>
  <div id="s1">
    <div class="chips"><button class="chip" data-a="5000">5 000</button><button class="chip" data-a="10000">10 000</button><button class="chip" data-a="25000">25 000</button><button class="chip" data-a="50000">50 000</button><button class="chip" data-a="100000">100 000</button></div>
    <input id="amount" type="number" inputmode="numeric" placeholder="To'lov summasi (so'm)">
    <p class="err" id="e1"></p>
    <button class="btn main" id="go">💳 TO'LOVNI YARATISH</button>
    <button class="btn ghost" id="c1">Bekor qilish</button>
  </div>
  <div id="s2" class="hide">
    <div class="card"><small>Karta: KINO DRAMA</small><div class="num" id="card">—</div>
      <div class="row"><small>Humo / Uzcard</small><button class="copy" id="cp">⧉</button></div></div>
    <div class="info">Aynan shu summani o'tkazing:<br><b id="pay"></b> so'm<br><span class="timer" id="t">05:00</span><br><small>To'lov tushishi bilan balans avtomatik to'ldiriladi ⚡</small></div>
    <button class="btn ghost" id="c2">Bekor qilish</button>
  </div>
  <div id="s3" class="hide"><div class="ok"><div class="big" id="ico">✅</div><h2 id="msg"></h2><p id="nb" style="color:var(--mut)"></p></div>
    <button class="btn main" id="done">YOPISH</button></div>
</div></div>
<script>
const $=i=>document.getElementById(i),fmt=n=>String(n).replace(/\B(?=(\d{3})+(?!\d))/g,' ');
let order=null,poll=null,tick=null,paid=false;
const api=async(a,d={})=>(await fetch('?a='+a,{method:'POST',body:new URLSearchParams(d)})).json();
const show=n=>['s1','s2','s3'].forEach((s,i)=>$(s).classList.toggle('hide',i+1!==n));
const stop=()=>{clearInterval(poll);clearInterval(tick)};
const close=()=>{stop();$('ov').classList.remove('on');if(paid)location.reload()};
function fin(i,m,n){stop();$('ico').textContent=i;$('msg').textContent=m;$('nb').textContent=n||'';show(3)}
$('open').onclick=()=>{show(1);$('amount').value='';$('e1').textContent='';paid=false;$('ov').classList.add('on')};
document.querySelectorAll('.chip').forEach(c=>c.onclick=()=>$('amount').value=c.dataset.a);
$('c1').onclick=$('done').onclick=close;$('ov').onclick=e=>{if(e.target===$('ov'))close()};
$('go').onclick=async()=>{$('e1').textContent='';$('go').disabled=true;
 try{const r=await api('create',{amount:$('amount').value});if(!r.ok){$('e1').textContent=r.error;return}
  order=r.order;$('card').textContent=r.card.replace(/(\d{4})(?=\d)/g,'$1 ');$('pay').textContent=fmt(r.amount);show(2);
  let left=r.expires_in;tick=setInterval(()=>{left--;if(left<=0){fin('⏰',"To'lov muddati tugadi");return}
   $('t').textContent=String(Math.floor(left/60)).padStart(2,'0')+':'+String(left%60).padStart(2,'0')},1000);
  poll=setInterval(check,3000);
 }catch(e){$('e1').textContent='Tarmoq xatosi'}finally{$('go').disabled=false}};
async function check(){try{const r=await api('check',{order});if(!r.ok)return;
 if(r.status==='paid'){paid=true;$('bal').textContent=fmt(r.balance)+" so'm";fin('✅',fmt(r.amount)+" so'm qo'shildi","Joriy balans: "+fmt(r.balance)+" so'm")}
 else if(r.status==='cancel')fin('❌',"To'lov bekor qilindi")}catch(e){}}
$('c2').onclick=async()=>{await api('cancel',{order});fin('❌',"To'lov bekor qilindi")};
$('cp').onclick=()=>navigator.clipboard&&navigator.clipboard.writeText($('card').textContent.replace(/\s/g,''));
</script>
<?php endif; ?>
<?php if(!$me): ?></div><?php endif; ?>
</body></html>
