<?php
if(PHP_SAPI !== 'cli'){ http_response_code(404); exit; }
// Run only against a disposable database; this suite provisions and mutates data.
if(getenv('GHOTI_DB_TEST') !== '1'){ echo "PASS: integration suite skipped; set GHOTI_DB_TEST=1 with a disposable database to run it.\n"; exit(0); }
require_once __DIR__.'/../ghoti.db.php';
require_once __DIR__.'/../ghoti.validate.php';
class ghoti extends ghotidb {
    public static $defaultPageTitle = 'Home';
    public static function logDebug(...$args){}
    public static function logInfo(...$args){}
    public static function logWarn(...$args){}
    public static function logError(...$args){ throw new RuntimeException(implode(': ', $args)); }
    public static function logException($where, $error, ...$args){ throw new RuntimeException($where.': '.$error->getMessage(), 0, $error); }
    public function connection(){ return $this->db(); }
}
function check($condition, $message){ global $checks; if(!$condition){ throw new RuntimeException($message); } $checks++; }
$checks = 0;
$db = new ghoti(); $conn = $db->connection();
foreach(array('login','boards','links','banners','gallery','mail','analytics','vhosts','store','bpong') as $module){ check($db->loadModuleSql($module), 'Provision '.$module); }
function rows($sql, $params = array()){ global $conn; $stmt=$conn->prepare($sql); $stmt->execute($params); return $stmt->fetchAll(PDO::FETCH_NUM); }
function writeQuery($sql, $params = array()){ global $conn; $stmt=$conn->prepare($sql); $stmt->execute($params); return $stmt->rowCount(); }
check(count(rows('show full tables where Table_type = \'BASE TABLE\'')) === 25, 'All module tables exist');
check(rows('select title from pages')[0][0] === 'Home', 'Seed data');
$value = "literal ' \\ \\n\n ? on duplicate key update x=values(x) $";
writeQuery('insert into pages(title,content) values(?,?)', array('Test', $value));
$id = (int)$conn->lastInsertId(); check($id > 1, 'Generated identity');
check(rows('select content from pages where id=?',array((string)$id))[0][0] === $value, 'Bound values preserved, string ID coerced');
check(rows('select groupName from pages where id=?',array($id))[0][0] === 'public', 'Defaults applied');
writeQuery('insert into pages(title,content) values(?,?)', array('Other','0'));
writeQuery('update pages set sortOrder=case id when ? then ? else sortOrder end,isDefault=case when id=? then 1 else 0 end where id in (?,?)',array($id,7,$id,1,$id));
check(rows('select sortOrder,isDefault from pages where id=?',array($id))[0] === array('7','1'), 'CASE updates');
check(rows('select count(*) from pages where id=-1') === array(array('0')), 'Empty count');
check(rows('select coalesce(max(sortOrder),0)+1 from pages where id=-1') === array(array('1')), 'Empty aggregate expression');
writeQuery('insert into users(userName,email,password,admin) values(?,?,?,?)',array('Alice','alice@example.test','hash',1)); $user=(int)$conn->lastInsertId();
writeQuery('insert into users(userName,email,password,admin) values(?,?,?,?)',array('Bob',null,'hash',0));
check(rows('select userId from users where LOWER(TRIM(email)) = LOWER(TRIM(?))', array(' ALICE@example.test '))[0][0] === (string)$user, 'Nested functions');
check(count(rows('select userId from users where email <> ?', array('other@example.test')))===1,'NULL comparison semantics');
writeQuery('insert into user_totp(userId,secret,confirmedAt,lastStep) values(?,?,?,?) on duplicate key update secret=values(secret),confirmedAt=values(confirmedAt),lastStep=values(lastStep)',array($user,'secret',1,10));
writeQuery('insert into user_totp(userId,secret,confirmedAt,lastStep) values(?,?,?,?) on duplicate key update secret=values(secret),confirmedAt=values(confirmedAt),lastStep=values(lastStep)',array($user,$value,1,20));
check(rows('select secret,lastStep from user_totp')[0]===array($value,'20'),'Upsert binding and unique primary key');
check(writeQuery('update user_totp set lastStep=? where userId=? and lastStep < ?',array(21,$user,21))===1,'Atomic TOTP claim wins');
check(writeQuery('update user_totp set lastStep=? where userId=? and lastStep < ?',array(21,$user,21))===0,'Atomic TOTP replay rejected');
check(rows('select u.userId from users u left join user_totp t on t.userId=u.userId and t.confirmedAt>0 where u.admin=1 and t.userId is null')===array(),'Left join and NULL filter');
writeQuery('insert into links(userId,name,url,grp) values(?,?,?,?)',array($user,'Link','https://example.test','test'));
check(rows("select links.name,coalesce(users.userName,'') from links left join users on links.userId=users.userId where links.grp=?",array('test'))[0]===array('Link','Alice'),'Joined projection');
check(rows('select url=? from links where grp=? and (url=? or name=?) order by url=? desc limit 1',array('https://example.test','test','https://example.test','No','https://example.test'))===array(array('1')),'Expression ordering and placeholder order');
writeQuery('insert into boards(name,slug,description,mode,postPolicy,readPolicy,sortOrder,createdAt) values(?,?,?,?,?,?,?,?)',array('Board','test','description','forum','users','public',1,1)); $board=(int)$conn->lastInsertId();
writeQuery('insert into board_topics(boardId,userId,title,createdAt,lastPostAt) values(?,?,?,?,?)',array($board,$user,'Topic',1,1)); $topic=(int)$conn->lastInsertId();
for($i=0;$i<2;$i++){ writeQuery('insert into board_posts(boardId,topicId,userId,body,createdAt) values(?,?,?,?,?)',array($board,$topic,$user,'Post '.$i,1)); }
check(rows('select b.boardId,(select count(*) from board_topics t where t.boardId=b.boardId) as topicCount,(select count(*) from board_posts p where p.boardId=b.boardId) as postCount from boards b where b.slug=\'test\' order by b.sortOrder,b.name')[0]===array((string)$board,'1','2'),'Correlated board counts');
writeQuery('insert into board_notify(userId,replies,updatedAt) values(?,?,?)',array($user,1,1));
check(rows('select distinct u.userId,u.userName,u.email from board_posts p join board_notify n on n.userId=p.userId and n.replies=1 join users u on u.userId=p.userId where p.topicId=? and p.userId<>? order by u.userId limit 100',array($topic,0))===array(array((string)$user,'Alice','alice@example.test')),'Multiple joins and DISTINCT');
writeQuery('insert into gallery(name,title,description,createdAt) values(?,?,?,?)',array('album','Album','',10)); $gallery=(int)$conn->lastInsertId();
writeQuery('insert into gallery_photos(galleryId,imageUrl,caption,sortOrder,createdAt) values(?,?,?,?,?)',array($gallery,'test.jpg','',1,10));
check(rows('select g.galleryId,g.name,count(p.photoId) as photoCount from gallery g left join gallery_photos p on p.galleryId=g.galleryId group by g.galleryId,g.name order by g.galleryId')[0]===array((string)$gallery,'album','1'),'Grouped left join');
for($i=0;$i<3;$i++){ writeQuery('insert into analytics(pageId,sessionId,ipAddress,userAgent,createdAt) values(?,?,?,?,?)',array(1,'s'.($i%2),'127.0.0.1','Agent','2026-11-11 13:10:00')); }
check(rows('select count(*),count(distinct sessionId),count(distinct pageId) from analytics')[0]===array('3','2','1'),'Distinct aggregates');
check(rows('select date(createdAt),count(*) as c from analytics group by date(createdAt) order by c desc')[0]===array('2026-11-11','3'),'Dates, grouping and aggregate alias');
check(rows('select hour(createdAt),count(*) from analytics group by hour(createdAt)')[0]===array('13','3'),'Hourly analytics');
writeQuery('insert into banners(alt,imgUrl,linkUrl,smallBanner) values(?,?,?,?)',array('Ad','a.jpg','https://example.test',1));
check(count(rows('select id from banners order by RAND() limit 1'))===1,'Random banner');
$conn->beginTransaction(); writeQuery('insert into store_order_items(orderId,productId,name,sku,kind,unitCents,quantity) values(1,1,?,?,?,1,1)',array('Rollback','rollback','physical')); $conn->rollBack();
check(rows('select itemId from store_order_items where name=?',array('Rollback'))===array(),'Transaction rollback');
$conn->beginTransaction(); writeQuery('insert into store_order_items(orderId,productId,name,sku,kind,unitCents,quantity) values(1,1,?,?,?,1,1)',array('Commit','commit','physical')); $conn->commit();
check(count(rows('select itemId from store_order_items where name=?',array('Commit')))===1,'Transaction commit');
check(rows('select GET_LOCK(?,5)',array('test-account'))===array(array('1')),'Credential lock acquired');
writeQuery('update users set password=? where userId=?',array('new-hash',$user));
check(rows('select RELEASE_LOCK(?)',array('test-account'))===array(array('1')),'Credential lock released');
// Exercise the existing module APIs against the chosen real provider.
foreach(array('login','boards','gallery','links','banners','analytics','mail','store','bpong','vhosts') as $module){ require_once __DIR__.'/../mod/'.$module.'/'.$module.'.db.php'; }
$login = new logindb();
check($login->changePassword($user,'provider-test-password')===true, 'Password change under credential lock');
check($login->authenticate('Alice','provider-test-password')===(string)$user, 'Authentication after password change');
$token = $login->createPasswordResetToken($user,'127.0.0.1');
check(is_string($token) && strlen($token)>20,'Password recovery token issued');
check($login->resetPasswordWithToken($token,'new-provider-test-password')===true,'Password reset consumes token');
check($login->validatePasswordResetToken($token)===null,'Password reset token cannot be replayed');
$boards = new boardsdb();
check(count($boards->getTopics($board,10,0))===1,'Topic listing');
check(count($boards->getPosts($topic,10,0))===2,'Post listing with correlated author count');
check(count($boards->getReplySubscribers($topic,0,100))===1,'Reply subscriptions');
$galleryDb = new gallerydb(); check(count($galleryDb->getAllGalleries())===1,'Gallery listing');
$analytics = new analyticsdb();
check(is_array($analytics->getSummary(3650)), 'Analytics summary');
check(is_array($analytics->getTopPages(10,3650)), 'Analytics top pages');
check(is_array($analytics->getBreakdown('browser',3650)), 'Analytics breakdown');
$settingsDb = new maildb(); $settings = $settingsDb->getSettings();
check($settingsDb->saveSettings($settings)===true,'Mail settings upsert');
$store = new storedb();
check($store->saveSettings(array('commerceConfig'=>array('pointsPerUnit'=>2)))===true,'Store settings upsert');
check($store->getSettings()['commerceConfig']['pointsPerUnit']===2,'Store settings round trip');
$product = array('sku'=>'TEST','name'=>'Test product','description'=>'Test','priceCents'=>500,'kind'=>'physical','category'=>'test',
    'imageUrl'=>'','downloadPath'=>'','active'=>true,'sortOrder'=>1,'fulfilment'=>'manual','dropProvider'=>'', 'dropProductId'=>'','dropVariantId'=>'',
    'externalUrl'=>'','featured'=>false,'compareAtCents'=>0,'badge'=>'','deliveryNote'=>'');
check($store->addProduct($product), 'Product creation'); $productId=(int)$conn->lastInsertId();
check($store->getProduct($productId)['sku']==='TEST','Product read');
check(count($store->getProductsById(array($productId)))===1,'Product IN query');
$order = array('reference'=>'GH-TEST','userId'=>$user,'email'=>'alice@example.test','customerName'=>'Alice','address1'=>'','address2'=>'','city'=>'','region'=>'',
    'postcode'=>'','country'=>'','subtotalCents'=>500,'shippingCents'=>0,'totalCents'=>500,'currency'=>'CAD','hasPhysical'=>true,'paypalOrderId'=>'TEST','note'=>'');
$orderId = $store->createOrder($order,array(array('productId'=>$productId,'name'=>'Product','sku'=>'TEST','kind'=>'physical','unitCents'=>500,'quantity'=>1)));
check(is_int($orderId) && $orderId>0,'Store order transaction');
check(count($store->getOrderItems($orderId))>=1,'Order items join');
check($store->getOrder($orderId)['totalCents']===500,'Order totals persisted');
$other = GhotiDatabaseProvider::connect(ghotidb::loadConfig());
if(getenv('GHOTI_DB_DRIVER')!=='sqlite' || ghotidb::loadConfig()['sqlite_path']!==':memory:'){
    check(rows('select GET_LOCK(?,5)',array('contention'))===array(array('1')),'First worker holds lock');
    $second=$other->prepare('select GET_LOCK(?,0)'); $second->execute(array('contention'));
    check((int)$second->fetch()[0]===0,'Second worker cannot acquire held lock');
    rows('select RELEASE_LOCK(?)',array('contention'));
    $second->execute(array('contention')); check((int)$second->fetch()[0]===1,'Second worker acquires released lock');
    $release=$other->prepare('select RELEASE_LOCK(?)'); $release->execute(array('contention'));
}
$conn->exec("ALTER TABLE pages ADD COLUMN testAdded int(11) NOT NULL DEFAULT 9");
check(rows('select testAdded from pages where id=1')===array(array('9')),'Additive migration backfills defaults');
if($conn instanceof GhotiMongoConnection){
    $conn->command(array('drop'=>'bpong'));
    check(rows('SHOW TABLES LIKE ?',array('bpong'))===array(),'Dropped collection is not hidden by schema metadata');
    $conn->exec(file_get_contents(__DIR__.'/../mod/bpong/bpong.sql'));
    check(count(rows('SHOW TABLES LIKE ?',array('bpong')))===1,'Dropped collection recreated');
}
require_once __DIR__.'/../ghoti.backup.lib.php';
$dump = ghoti_backup_create_sql_dump();
check($dump['tables'] === 25, 'Backup contains all tables');
$foreignDump = tempnam(sys_get_temp_dir(),'ghoti-other-provider-');
$foreignProvider = getenv('GHOTI_DB_DRIVER') === 'mysql' ? 'sqlite' : 'mysql';
file_put_contents($foreignDump,preg_replace('/^-- Provider: .+$/m','-- Provider: '.$foreignProvider,file_get_contents($dump['path'])));
try{ ghoti_backup_restore_sql($foreignDump); throw new LogicException('Cross-provider restore was accepted'); }
catch(RuntimeException $e){ check(strpos($e->getMessage(),'same database provider')!==false,'Cross-provider restore rejected before writes'); }
finally{ unlink($foreignDump); }
writeQuery('delete from pages where id=?',array($id));
check(ghoti_backup_restore_sql($dump['path']) === 25, 'Backup restore');
check(rows('select content,testAdded from pages where id=?',array($id))[0]===array($value,'9'),'Backup preserves data and migrated columns');
unlink($dump['path']);
echo 'PASS: '
.$checks.' integration assertions for '.getenv('GHOTI_DB_DRIVER')."\n";
