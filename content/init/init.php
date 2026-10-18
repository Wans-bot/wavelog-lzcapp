<?php
/**
 * Wavelog LPK 初始化脚本（容器内以 root 执行，位于原始 entrypoint 之前）。
 *
 * 职责（幂等）：
 *   1. 等待数据库可用（mariadb 服务由平台按 depends_on 保证 healthy，这里再兜底重试）；
 *   2. 首次启动：导入上游 install.sql（含表结构与默认数据），并按安装器同样的规则
 *      替换 %%FIRSTUSER_*%% 占位符，创建管理员账号与站点档案；
 *   2b. DXCC 国别数据离线种子（内置 cty.xml.gz，上游默认需联网“更新国别文件”，
 *      国内网络常不可达；不导入会导致 ADIF 导出 JOIN 为空等异常）；
 *   2c. 平台默认项（地图瓦片源等；仅首次写入，之后不覆盖用户设置）；
 *   3. 生成 / 刷新 CI 配置：
 *        application/config/docker/config.php    主配置（base_url 每次启动按当前域名刷新）
 *        application/config/docker/database.php  数据库连接
 *        application/config/docker/gettext.php   覆盖 gettext_default ⇒ 默认中文界面
 *      config.php / database.php 取镜像内安装器模板，保证与上游版本一致；
 *   4. 修正绑定目录属主（www-data），使网页进程可读写。
 *
 * 不覆盖已存在的持久数据：数据库已有 marker 表就直接跳过导入；
 * 配置文件已存在则不重新生成（encryption_key 等保持稳定）。
 */

declare(strict_types=1);

$APP = '/var/www/html';
$CFG_DIR = $APP . '/application/config/docker';

function envv(string $k, string $d = ''): string {
    $v = getenv($k);
    return ($v === false || $v === '') ? $d : $v;
}
function logx(string $m): void {
    fwrite(STDOUT, '[lzc-wavelog-init] ' . $m . "\n");
}
function fail(string $m): void {
    logx('ERROR: ' . $m);
    exit(1);
}

/** 批量 INSERT：$rows 为按 $cols 顺序的值数组列表 */
function insert_batch_rows(mysqli $db, string $table, array $cols, array $rows, int $chunk = 500): int
{
    $n = 0;
    foreach (array_chunk($rows, $chunk) as $part) {
        $vals = [];
        foreach ($part as $r) {
            $cells = [];
            foreach ($r as $v) {
                if ($v === null) { $cells[] = 'NULL'; }
                elseif (is_int($v) || is_float($v)) { $cells[] = var_export($v, true); }
                else { $cells[] = "'" . $db->real_escape_string((string) $v) . "'"; }
            }
            $vals[] = '(' . implode(',', $cells) . ')';
        }
        $db->query('INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES ' . implode(',', $vals));
        $n += count($part);
    }
    return $n;
}

/** DXCC 数据离线种子：仅当表里几乎没有数据时执行（幂等） */
function seed_dxcc_if_needed(mysqli $db): void
{
    $gz = '/lzcapp/pkg/content/init/data/cty.xml.gz';
    if (!is_file($gz)) { logx('dxcc data file missing; skip seeding'); return; }
    $res = $db->query('SELECT COUNT(*) AS c FROM dxcc_entities');
    $c = $res ? (int) ($res->fetch_assoc()['c'] ?? 0) : 0;
    if ($c >= 50) { logx('dxcc already seeded (' . $c . ' entities)'); return; }

    logx('seeding dxcc data from bundled cty.xml.gz ...');
    $t0 = microtime(true);
    $raw = gzdecode((string) file_get_contents($gz));
    if ($raw === false) { logx('dxcc gzdecode failed; skip'); return; }
    $xml = @simplexml_load_string($raw);
    unset($raw);
    if ($xml === false) { logx('dxcc xml parse failed; skip'); return; }

    $db->query('TRUNCATE TABLE dxcc_entities');
    $db->query('TRUNCATE TABLE dxcc_exceptions');
    $db->query('TRUNCATE TABLE dxcc_prefixes');

    $rows = [];
    foreach ($xml->entities->entity as $e) {
        $name = isset($e->cqz) ? (string) $e->name : (string) $e->entity;
        $prefix = isset($e->cqz) ? (string) $e->prefix : (string) $e->call;
        $start = isset($e->start) ? date('Y-m-d H:i:s', strtotime((string) $e->start)) : null;
        $end = isset($e->end) ? date('Y-m-d H:i:s', strtotime((string) $e->end)) : null;
        $rows[] = [
            (int) $e->adif, $name, $prefix,
            isset($e->ituz) ? (float) $e->ituz : 0.0,
            isset($e->cqz) ? (int) $e->cqz : 0,
            isset($e->cont) ? (string) $e->cont : '',
            isset($e->long) ? (float) $e->long : 0.0,
            isset($e->lat) ? (float) $e->lat : 0.0,
            $start, $end,
        ];
    }
    // 与上游 update 流程一致的“特殊实体”行（无呼号/移动台等）
    $rows[] = [0, '- NONE - (e.g. /MM, /AM)', '', 0.0, 0, '', 0.0, 0.0, null, null];
    $n1 = insert_batch_rows($db, 'dxcc_entities', ['adif', 'name', 'prefix', 'ituz', 'cqz', 'cont', '`long`', 'lat', '`start`', '`end`'], $rows);
    unset($rows);

    $rows = [];
    foreach ($xml->exceptions->exception as $e) {
        $start = (!empty($e->start) && strtotime((string) $e->start)) ? date('Y-m-d H:i:s', strtotime((string) $e->start)) : null;
        $end = $e->end ? date('Y-m-d H:i:s', strtotime((string) $e->end)) : null;
        $rows[] = [
            (int) $e->attributes()->record, (string) $e->call, (string) $e->entity,
            (int) $e->adif, (int) $e->cqz, (string) $e->cont,
            (float) $e->long, (float) $e->lat, $start, $end,
        ];
    }
    $n2 = insert_batch_rows($db, 'dxcc_exceptions', ['record', '`call`', 'entity', 'adif', 'cqz', 'cont', '`long`', 'lat', '`start`', '`end`'], $rows);
    unset($rows);

    $rows = [];
    foreach ($xml->prefixes->prefix as $e) {
        $start = (!empty($e->start) && strtotime((string) $e->start)) ? date('Y-m-d H:i:s', strtotime((string) $e->start)) : null;
        $end = $e->end ? date('Y-m-d H:i:s', strtotime((string) $e->end)) : null;
        $rows[] = [
            (int) $e->attributes()->record, (string) $e->call, (string) $e->entity,
            (int) $e->adif, (int) $e->cqz, (string) $e->cont,
            (float) $e->long, (float) $e->lat, $start, $end,
        ];
    }
    $n3 = insert_batch_rows($db, 'dxcc_prefixes', ['record', '`call`', 'entity', 'adif', 'cqz', 'cont', '`long`', 'lat', '`start`', '`end`'], $rows);
    unset($rows);

    // 与上游 update 相同的 ituz 修正（dxcc_temp 由迁移创建；未迁移时跳过）
    $r = $db->query("SHOW TABLES LIKE 'dxcc_temp'");
    if ($r && $r->num_rows > 0) {
        $db->query('UPDATE dxcc_entities JOIN dxcc_temp ON dxcc_entities.adif = dxcc_temp.adif SET dxcc_entities.ituz = dxcc_temp.ituz');
    }
    $db->query('COMMIT'); // 防御：确保种子写入落盘
    logx(sprintf('dxcc seeded: entities=%d exceptions=%d prefixes=%d in %.1fs', $n1, $n2, $n3, microtime(true) - $t0));
}

/** 平台默认项：分组写入（各自 marker 防覆盖用户后续修改）；版本号每次启动对齐 */
function apply_lpk_defaults(mysqli $db): void
{
    // 版本号与打包的上游版本对齐（options 表在迁移前后列不同，只写两列；迁移可能改写此值）
    $db->query("UPDATE options SET option_value='3.2.3' WHERE option_name='version'");

    // v2 组：关闭版本弹窗与版本检查（容器内无法访问 GitHub，检查只会产生错误提示）
    $r2 = $db->query("SELECT option_value FROM options WHERE option_name='lzc_lpk_defaults_v2'");
    if (!$r2 || $r2->num_rows === 0) {
        $db->query("INSERT INTO options (option_name, option_value) SELECT 'version_dialog','disabled' WHERE NOT EXISTS (SELECT 1 FROM options WHERE option_name='version_dialog')");
        $db->query("UPDATE options SET option_value='disabled' WHERE option_name='version_dialog'");
        $db->query("INSERT INTO options (option_name, option_value) VALUES ('lzc_lpk_defaults_v2','1')");
        logx('version dialog disabled');
    }

    // v1 组：地图瓦片默认高德（国内可达、中文标注）；上游默认 OSM 在部分网络不可达，地图整片 404。
    $r = $db->query("SELECT option_value FROM options WHERE option_name='lzc_lpk_defaults'");
    if ($r && $r->num_rows > 0) { logx('lpk defaults already applied'); return; }

    $tile = 'https://webrd0{s}.is.autonavi.com/appmaptile?lang=zh_cn&size=1&scale=1&style=8&x={x}&y={y}&z={z}';
    $db->query("UPDATE options SET option_value='" . $db->real_escape_string($tile) . "' WHERE option_name='map_tile_server'");
    // 只写 option_name/option_value 两列：install.sql 与迁移后的 options 表列不同（后者已无 autoload）
    $db->query("INSERT INTO options (option_name, option_value) SELECT 'map_tile_subdomains','1234' WHERE NOT EXISTS (SELECT 1 FROM options WHERE option_name='map_tile_subdomains')");
    $db->query("UPDATE options SET option_value='地图数据 © 高德地图 (AutoNavi)' WHERE option_name='map_tile_server_copyright'");
    $db->query("INSERT INTO options (option_name, option_value) VALUES ('lzc_lpk_defaults','1')");
    $db->query('COMMIT'); // 防御：确保默认项写入落盘
    $r = $db->query("SELECT option_value FROM options WHERE option_name='lzc_lpk_defaults'");
    if (!$r || $r->num_rows === 0) {
        logx('WARNING: lpk defaults marker insert failed; defaults will be re-applied next start');
    } else {
        logx('lpk defaults applied (map tiles: amap)');
    }
}

$dbHost  = envv('LZC_DB_HOST', 'db.cloud.lazycat.app.wavelog.lzcapp');
$dbPort  = (int) envv('LZC_DB_PORT', '3306');
$dbName  = envv('LZC_DB_NAME', 'wavelog');
$dbUser  = envv('LZC_DB_USER', 'wavelog');
$dbPass  = envv('LZC_DB_PASS', '');
$baseUrl = rtrim(envv('LZC_BASE_URL'), '/') . '/';
if (!preg_match('~^https?://[^ ]+$~', $baseUrl)) {
    fail('LZC_BASE_URL missing or invalid: ' . $baseUrl);
}

$adminUser    = envv('LZC_ADMIN_USER', 'admin');
$adminPass    = envv('LZC_ADMIN_PASS', '');
$adminMail    = envv('LZC_ADMIN_MAIL', 'admin@example.com');
$adminCall    = envv('LZC_ADMIN_CALL', 'LZC');
$adminLocator = envv('LZC_ADMIN_LOCATOR', 'PM01AA');
$adminFirst   = envv('LZC_ADMIN_FIRSTNAME', '管理员');
$adminLast    = envv('LZC_ADMIN_LASTNAME', '账号');
$adminTz      = (int) envv('LZC_ADMIN_TZ', '131');
$adminDxcc    = (int) envv('LZC_ADMIN_DXCC', '318');
$adminCity    = envv('LZC_ADMIN_CITY', 'Shanghai');
$adminLang    = envv('LZC_ADMIN_LANG', 'chinese_simplified');
if ($adminPass === '') {
    fail('LZC_ADMIN_PASS missing');
}

/* ---------- 1. 等数据库 ---------- */
$deadline = time() + 600;
$mysqli = null;
while (true) {
    try {
        // PHP 8.1+ 默认 mysqli 报错即抛异常，重试循环必须捕获（名字解析/网络未就绪都会抛）
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $mysqli = new mysqli($dbHost, $dbUser, $dbPass, '', $dbPort);
        mysqli_report(MYSQLI_REPORT_OFF);
        break;
    } catch (Throwable $e) {
        mysqli_report(MYSQLI_REPORT_OFF);
        if (time() > $deadline) {
            fail('database not reachable: ' . $e->getMessage());
        }
        logx('waiting for database ... (' . $e->getMessage() . ')');
        sleep(3);
    }
}
$mysqli->set_charset('utf8mb4');

/* ---------- 2. 建库 & 首装导入 ---------- */
// mariadb 的 MYSQL_DATABASE 已建库；CREATE 仅作兜底（无权限时忽略错误）。
@$mysqli->query(
    "CREATE DATABASE IF NOT EXISTS `" . $mysqli->real_escape_string($dbName)
    . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"
);
if (!$mysqli->select_db($dbName)) {
    fail('select database failed: ' . $mysqli->error);
}

$markerExists = false;
$usersExists = false;
$res = $mysqli->query("SHOW TABLES LIKE 'lzc_init_marker'");
if ($res && $res->num_rows > 0) { $markerExists = true; }
$res = $mysqli->query("SHOW TABLES LIKE 'users'");
if ($res && $res->num_rows > 0) { $usersExists = true; }

if (!$markerExists && !$usersExists) {
    logx('first run: importing schema ...');
    $sql = file_get_contents($APP . '/install/assets/install.sql');
    if ($sql === false) {
        fail('install.sql missing');
    }
    $map = [
        '%%FIRSTUSER_NAME%%'         => $mysqli->real_escape_string($adminUser),
        '%%FIRSTUSER_PASS%%'         => password_hash($adminPass, PASSWORD_DEFAULT),
        '%%FIRSTUSER_MAIL%%'         => $mysqli->real_escape_string($adminMail),
        '%%FIRSTUSER_CALL%%'         => $mysqli->real_escape_string(strtoupper($adminCall)),
        '%%FIRSTUSER_LOCATOR%%'      => $mysqli->real_escape_string(strtoupper($adminLocator)),
        '%%FIRSTUSER_FIRSTNAME%%'    => $mysqli->real_escape_string($adminFirst),
        '%%FIRSTUSER_LASTNAME%%'     => $mysqli->real_escape_string($adminLast),
        '%%FIRSTUSER_TIMEZONE%%'     => (string) $adminTz,
        '%%FIRSTUSER_DXCC%%'         => (string) $adminDxcc,
        '%%FIRSTUSER_CITY%%'         => $mysqli->real_escape_string($adminCity),
        '%%FIRSTUSER_USERLANGUAGE%%' => $mysqli->real_escape_string($adminLang),
    ];
    $sql = strtr($sql, $map);
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    try {
        $mysqli->multi_query($sql);
        do {
            if ($r = $mysqli->store_result()) {
                $r->free();
            }
        } while ($mysqli->more_results() && $mysqli->next_result());
    } catch (mysqli_sql_exception $e) {
        fail('schema import failed: ' . $e->getMessage());
    }
    mysqli_report(MYSQLI_REPORT_OFF);

    $chk = $mysqli->query('SELECT COUNT(*) AS c FROM users');
    $row = $chk ? $chk->fetch_assoc() : null;
    if (!$row || (int) $row['c'] < 1) {
        fail('admin user not created');
    }
    $mysqli->query('CREATE TABLE IF NOT EXISTS lzc_init_marker (id INT PRIMARY KEY)');
    $mysqli->query('INSERT IGNORE INTO lzc_init_marker VALUES (1)');
    logx('schema imported, admin user created');
} else {
    if (!$markerExists) {
        // 已有库（例如历史数据），不重复导入；补 marker 避免后续误判。
        $mysqli->query('CREATE TABLE IF NOT EXISTS lzc_init_marker (id INT PRIMARY KEY)');
        $mysqli->query('INSERT IGNORE INTO lzc_init_marker VALUES (1)');
    }
    logx('database already initialised, skip import');
}

/* ---------- 提交并恢复 autocommit ----------
 * install.sql 是 mysqldump 产物，内含 `set autocommit=0`；同一连接上继续执行的
 * 种子/默认项写入如果不显式提交，会在连接关闭时回滚（全新安装丢数据；
 * 升级路径因为跳过了导入、连接保持 autocommit=1，所以历史升级没有暴露该问题）。
 */
$mysqli->query('COMMIT');
$mysqli->query('SET autocommit=1');

/* ---------- 2b/2c. 离线数据与平台默认项 ---------- */
seed_dxcc_if_needed($mysqli);
apply_lpk_defaults($mysqli);
$mysqli->close();

/* ---------- 3. 生成 / 刷新 CI 配置 ---------- */
if (!is_dir($CFG_DIR) && !mkdir($CFG_DIR, 0775, true)) {
    fail('cannot create config dir: ' . $CFG_DIR);
}
$keyFile = $CFG_DIR . '/.lzc_encryption_key';
$encKey = is_file($keyFile) ? trim((string) file_get_contents($keyFile)) : '';
if (strlen($encKey) < 16) {
    $encKey = bin2hex(random_bytes(16));
    file_put_contents($keyFile, $encKey);
}

$tplCfg = $APP . '/install/config/config.php';
$tplDb  = $APP . '/install/config/database.php';
if (!is_file($tplCfg) || !is_file($tplDb)) {
    fail('installer config templates missing in image');
}

$dbFile = $CFG_DIR . '/database.php';
if (!is_file($dbFile)) {
    $db = (string) file_get_contents($tplDb);
    $db = str_replace(
        ['%HOSTNAME%', '%USERNAME%', '%PASSWORD%', '%DATABASE%'],
        [$dbHost, $dbUser, $dbPass, $dbName],
        $db
    );
    file_put_contents($dbFile, $db);
    logx('wrote database.php');
}

$cfgFile = $CFG_DIR . '/config.php';
if (!is_file($cfgFile)) {
    $cfg = (string) file_get_contents($tplCfg);
    // 与安装器 install/includes/core/core_class.php 相同的替换集合；
    // 未使用的 callbook 类占位符统一置空。
    $cfg = strtr($cfg, [
        '%baselocator%'      => strtoupper($adminLocator),
        '%websiteurl%'       => $baseUrl,
        '%directory%'        => '',
        '%callbook%'         => '',
        '%qrz_username%'     => '',
        '%qrz_password%'     => '',
        '%hamqth_username%'  => '',
        '%hamqth_password%'  => '',
        '%qrzcq_username%'   => '',
        '%qrzcq_password%'   => '',
        '%qrzru_username%'   => '',
        '%qrzru_password%'   => '',
        '%qrzcall_token%'    => '',
        '%encryptionkey%'    => $encKey,
        "'%log_threshold%'"  => '1',
    ]);
    file_put_contents($cfgFile, $cfg);
    logx('wrote config.php');
}

// base_url 每次启动按当前域名刷新（平台域名可能变化，不要固化成旧值）。
$cfg = (string) file_get_contents($cfgFile);
$new = preg_replace(
    "~\\\$config\\['base_url'\\]\\s*=\\s*'[^']*';~",
    "\$config['base_url'] = '" . $baseUrl . "';",
    $cfg,
    1,
    $cnt
);
if ($new === null) {
    $new = $cfg; // preg 失败时保持原样
    $cnt = 0;
}
if ($cnt !== 1) {
    $new = rtrim($cfg) . "\n\$config['base_url'] = '" . $baseUrl . "';\n";
}
if ($new !== $cfg) {
    file_put_contents($cfgFile, $new);
}

// 关闭版本检查（上游支持开关；容器环境访问不到 GitHub，检查只会产生错误提示）
$cfg = (string) file_get_contents($cfgFile);
if (strpos($cfg, 'disable_version_check') === false) {
    $cfg = rtrim($cfg) . "\n// LZC: 容器内无法访问 GitHub，关闭版本检查\n\$config['disable_version_check'] = true;\n";
    file_put_contents($cfgFile, $cfg);
    logx('disabled version check in config.php');
}

// 默认界面语言覆盖：安装器生成的配置不含语言项，而 gettext 钩子会加载
// application/config/docker/gettext.php 并覆盖基础 gettext.php（CI3 环境目录合并语义），
// 因此把默认语言固定为简体中文写在这里。
$gtFile = $CFG_DIR . '/gettext.php';
$gtContent = "<?php\n"
    . "defined('BASEPATH') OR exit('No direct script access allowed');\n"
    . "// LPK 覆盖：默认界面语言 = 简体中文（用户仍可在个人设置里切换）\n"
    . "\$config['gettext_default'] = '" . addslashes($adminLang) . "';\n";
if (!is_file($gtFile) || (string) file_get_contents($gtFile) !== $gtContent) {
    file_put_contents($gtFile, $gtContent);
}

/* ---------- 4. 权限 & 收尾 ---------- */
$dataDirs = [
    $CFG_DIR,
    $APP . '/uploads',
    $APP . '/userdata',
    $APP . '/backup',
    $APP . '/images/eqsl_card_images',
    $APP . '/application/cache',
    $APP . '/application/logs',
];
foreach ($dataDirs as $d) {
    if (!is_dir($d)) {
        @mkdir($d, 0775, true);
    }
}
$q = implode(' ', array_map('escapeshellarg', $dataDirs));
@shell_exec('chown -R www-data:www-data ' . $q . ' 2>/dev/null');
@chmod($CFG_DIR, 0775);

// 安装锁：镜像 entrypoint 检测到两个配置文件也会自动落锁；这里兜底先锁上，
// 防止安装器被访问到。
@touch($APP . '/install/.lock');

logx('init done (base_url=' . $baseUrl . ')');
