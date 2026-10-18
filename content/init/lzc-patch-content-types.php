<?php
/**
 * 内容类型补丁（容器内 / 每次启动执行，幂等、soft-fail）
 *
 * 背景：lzcinit 会给 Content-Type: text/html 的响应追加 /_lzc/injects 脚本标签；
 * Wavelog 的若干 AJAX 接口用默认 text/html 输出 JSON/纯文本，被追加脚本后
 * 客户端 JSON.parse 报错（如 qralatlngjson / stationProfileCoords 实测）。
 *
 * 两类接口分开处理：
 *   - 客户端 success 里显式 JSON.parse(原始字符串) 的：text/plain
 *     （不能用 application/json —— jQuery 会自动解析，随后再 JSON.parse 会失败）；
 *   - 客户端用 $.getJSON / dataType:'json'（期望对象）的：application/json。
 *
 * 用法：php lzc-patch-content-types.php /var/www/html
 */
declare(strict_types=1);
$root = rtrim($argv[1] ?? '/var/www/html', '/');

function patch_method(string $file, string $needle, string $insert, string $marker): void
{
    if (!is_file($file)) { fwrite(STDOUT, "[content-type-patch] skip missing $file\n"); return; }
    $src = file_get_contents($file);
    if ($src === false) { fwrite(STDOUT, "[content-type-patch] read fail $file\n"); return; }
    if (strpos($src, $marker) !== false) { fwrite(STDOUT, "[content-type-patch] already patched: " . basename($file) . " :: $needle\n"); return; }
    $pos = strpos($src, $needle);
    if ($pos === false) { fwrite(STDOUT, "[content-type-patch] pattern not found (soft-fail): $file :: $needle\n"); return; }
    $brace = strpos($src, '{', $pos);
    if ($brace === false) { fwrite(STDOUT, "[content-type-patch] no brace (soft-fail): $file\n"); return; }
    $patched = substr($src, 0, $brace + 1) . "\n\t\t" . $insert . ' ' . $marker . "\n" . substr($src, $brace + 1);
    if (file_put_contents($file, $patched) === false) {
        fwrite(STDOUT, "[content-type-patch] write fail $file\n");
        return;
    }
    fwrite(STDOUT, "[content-type-patch] patched " . basename($file) . " :: $needle\n");
}

$jsonInsert = "\$this->output->set_content_type('application/json', 'utf-8'); header('Content-Type: application/json; charset=utf-8');";
$textInsert = "\$this->output->set_content_type('text/plain', 'utf-8'); header('Content-Type: text/plain; charset=utf-8');";

/* application/json：客户端 getJSON/dataType:'json' 期望已解析对象 */
$jsonMethods = [
    ['Logbook.php', ['json', 'jsonentity', 'jsonlookupgrid', 'jsonlookupdxcc', 'jsonlookupcallsign']],
    ['Lookup.php', ['ham_of_note']],
];
foreach ($jsonMethods as [$file, $methods]) {
    foreach ($methods as $m) {
        patch_method(
            $root . '/application/controllers/' . $file,
            'function ' . $m . '(',
            $jsonInsert,
            '/* LZC-PATCH: json-content-type:' . $m . ' */'
        );
    }
}

/* text/plain：客户端 success 里显式 JSON.parse(原始字符串) */
$textMethods = [
    ['Logbook.php', ['qralatlngjson']],
    ['Lookup.php', ['scp']],
    ['Station.php', ['stationProfileCoords']],
    ['Band.php', ['saveBandEdge']],
    ['Satellite.php', ['loadPassSettings', 'get_sat_info']],
    ['Qso.php', ['get_dok']],
    ['Map.php', ['get_qsos_for_country']],
    ['Stationsetup.php', ['newLogbook_json', 'saveVisitorLink', 'fetchLogbooks', 'fetchLocations', 'remove_publicslug', 'linkLocations', 'unLinkLocations']],
];
foreach ($textMethods as [$file, $methods]) {
    foreach ($methods as $m) {
        patch_method(
            $root . '/application/controllers/' . $file,
            'function ' . $m . '(',
            $textInsert,
            '/* LZC-PATCH: plain-content-type:' . $m . ' */'
        );
    }
}

/* 迁移 292：跳过“初始网络导入”块（POTA/SOTA/WWFF）。
 * 该块是 best-effort 的数据填充；在无外网/受限网络里会长时间阻塞或中途被杀，
 * 遗留 /tmp/.migration_running 锁，使后续迁移永远无法继续（实测首装卡在 291）。
 * 只跳过填充，表结构创建/迁移登记仍然执行；数据可由用户在「工具 → 更新」按需拉取。
 */
patch_insert_before(
    $root . '/application/migrations/292_pota_boundaries.php',
    '$CI =& get_instance();',
    "\t\t/* LZC-PATCH: skip-292-network-populate */\n\t\t// LZC: 无外网环境跳过 best-effort 初始导入，避免迁移阻塞与遗留锁。\n\t\treturn;\n",
    '/* LZC-PATCH: skip-292-network-populate */'
);

/* JS 资产小修：无 TLE 时 #satlist 不存在，$('#satlist').val() 为 undefined，读 .length 抛异常 */
patch_js_replace(
    $root . '/assets/js/sections/satpasses.js',
    "var countsats = $('#satlist').val().length;",
    "var countsats = ($('#satlist').val() || []).length; /* LZC-PATCH: satlist-null-guard */",
    '/* LZC-PATCH: satlist-null-guard */'
);

fwrite(STDOUT, "[content-type-patch] done\n");

/** 在指定文本前插入（幂等、soft-fail；用于迁移类文件） */
function patch_insert_before(string $file, string $needle, string $insert, string $marker): void
{
    if (!is_file($file)) { fwrite(STDOUT, "[mig-patch] skip missing $file\n"); return; }
    $src = file_get_contents($file);
    if ($src === false) { fwrite(STDOUT, "[mig-patch] read fail $file\n"); return; }
    if (strpos($src, $marker) !== false) { fwrite(STDOUT, "[mig-patch] already patched: " . basename($file) . "\n"); return; }
    $pos = strpos($src, $needle);
    if ($pos === false) { fwrite(STDOUT, "[mig-patch] pattern not found (soft-fail): $file\n"); return; }
    file_put_contents($file, substr($src, 0, $pos) . $insert . substr($src, $pos));
    fwrite(STDOUT, "[mig-patch] patched " . basename($file) . "\n");
}

/** JS 文件定点替换（幂等、soft-fail） */
function patch_js_replace(string $file, string $find, string $repl, string $marker): void
{
    if (!is_file($file)) { fwrite(STDOUT, "[js-patch] skip missing $file\n"); return; }
    $src = file_get_contents($file);
    if ($src === false) { fwrite(STDOUT, "[js-patch] read fail $file\n"); return; }
    if (strpos($src, $marker) !== false) { fwrite(STDOUT, "[js-patch] already patched: " . basename($file) . "\n"); return; }
    if (strpos($src, $find) === false) { fwrite(STDOUT, "[js-patch] pattern not found (soft-fail): $file\n"); return; }
    file_put_contents($file, str_replace($find, $repl, $src));
    fwrite(STDOUT, "[js-patch] patched " . basename($file) . "\n");
}
