<?php
// year_lib.php — helpers for per-year (season) field data

declare(strict_types=1);

/**
 * Try to match engine/collation to existing `fields` table.
 * This makes schema evolution more compatible with older installs.
 */
function _db_table_opts(PDO $db): string {
    static $cached = null;
    if ($cached !== null) return $cached;

    $engine = 'InnoDB';
    $collation = 'utf8mb4_unicode_ci';

    try {
        $st = $db->query("SHOW TABLE STATUS LIKE 'fields'");
        $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : null;
        if ($row) {
            if (!empty($row['Engine'])) $engine = (string)$row['Engine'];
            if (!empty($row['Collation'])) $collation = (string)$row['Collation'];
        }
    } catch (Throwable $e) {
        // ignore
    }

    $charset = 'utf8mb4';
    if ($collation) {
        $p = explode('_', $collation);
        if (!empty($p[0])) $charset = $p[0];
    }

    $opts = "ENGINE={$engine} DEFAULT CHARSET={$charset}";
    if ($collation) $opts .= " COLLATE={$collation}";
    $cached = $opts;
    return $cached;
}

/**
 * Ensure table for per-year field data exists.
 *
 * We keep `fields` as "passport" (code, area_ha),
 * and store year-specific values (culture/dates/yield/notes) in `field_year_data`.
 */
function ensure_field_year_data_table(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;

    // На старых установках таблица могла быть создана без новых колонок.
    // Поэтому ниже не только CREATE TABLE IF NOT EXISTS, но и «добавление» отсутствующих полей.

    $opts = _db_table_opts($db);
    $sql = "CREATE TABLE IF NOT EXISTS `field_year_data` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `field_id` INT NOT NULL,
        `year` SMALLINT NOT NULL,
        `plow_date` DATE NULL,
        `sow_date` DATE NULL,
        `culture_id` INT NULL,

        /* План / размещение (бухгалтерия) */
        `plan_fertilized` TINYINT(1) NULL,
        `plan_purchased`  TINYINT(1) NULL,
        `plan_elite`      TINYINT(1) NULL,
        `plan_notes`      TEXT NULL,

        `treatment_date` DATE NULL,
        `treatment_desc` VARCHAR(255) NULL,
        `last_water_date` DATE NULL,
        `harvest_date` DATE NULL,

        /* Факт / урожай (бухгалтерия) */
        `harvest_area_ha` DECIMAL(8,2) NULL,
        `gross_yield` VARCHAR(64) NULL,
        `avg_yield` VARCHAR(64) NULL,
        `notes` TEXT NULL,
        UNIQUE KEY `uniq_field_year` (`field_id`,`year`),
        KEY `idx_fyd_field` (`field_id`),
        KEY `idx_fyd_culture` (`culture_id`)
    ) {$opts}";

    try {
        $db->exec($sql);
    } catch (Throwable $e) {
        // На некоторых хостингах/старых БД могут быть ограничения. Не валим сайт целиком.
        error_log('ensure_field_year_data_table create failed: '.$e->getMessage());
    }

    // Добавляем отсутствующие колонки (если таблица уже существовала до апдейта)
    ensure_column($db, 'field_year_data', 'plan_fertilized', "ALTER TABLE `field_year_data` ADD COLUMN `plan_fertilized` TINYINT(1) NULL AFTER `culture_id`");
    ensure_column($db, 'field_year_data', 'plan_purchased',  "ALTER TABLE `field_year_data` ADD COLUMN `plan_purchased`  TINYINT(1) NULL AFTER `plan_fertilized`");
    ensure_column($db, 'field_year_data', 'plan_elite',      "ALTER TABLE `field_year_data` ADD COLUMN `plan_elite`      TINYINT(1) NULL AFTER `plan_purchased`");
    ensure_column($db, 'field_year_data', 'plan_notes',      "ALTER TABLE `field_year_data` ADD COLUMN `plan_notes`      TEXT NULL AFTER `plan_elite`");
    ensure_column($db, 'field_year_data', 'harvest_area_ha', "ALTER TABLE `field_year_data` ADD COLUMN `harvest_area_ha` DECIMAL(8,2) NULL AFTER `harvest_date`");

    // IMPORTANT: older installs could have a wrong UNIQUE index (e.g. only on field_id).
    // This breaks year separation (edits for 2025 overwrite 2026). We fix it defensively.
    ensure_unique_field_year_index($db);

    // If the legacy table still cannot store per-year rows (wrong PRIMARY/UNIQUE),
    // we transparently switch to a compatible v2 table.
    $GLOBALS['_FYD_TABLE'] = _choose_fyd_table($db);
}

/**
 * Return the actual table name used for season data.
 * Can be switched to `field_year_data_v2` on old/broken schemas.
 */
function fyd_table(PDO $db): string {
    ensure_field_year_data_table($db);
    return isset($GLOBALS['_FYD_TABLE']) ? (string)$GLOBALS['_FYD_TABLE'] : 'field_year_data';
}

function _fyd_table_is_ok(PDO $db, string $table): bool {
    try {
        $idx = $db->query("SHOW INDEX FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        if (!$idx) return false;

        $byName = [];
        foreach ($idx as $r) {
            $name = (string)$r['Key_name'];
            $nonUnique = (int)$r['Non_unique'];
            $seq = (int)$r['Seq_in_index'];
            $col = (string)$r['Column_name'];
            if (!isset($byName[$name])) $byName[$name] = ['non_unique'=>$nonUnique, 'cols'=>[]];
            $byName[$name]['cols'][$seq] = $col;
        }
        foreach ($byName as $n => &$v) {
            ksort($v['cols']);
            $v['cols'] = array_values($v['cols']);
        }

        // Broken old schema: PRIMARY(field_id) means only one row per field total.
        if (isset($byName['PRIMARY']) && ($byName['PRIMARY']['cols'] ?? []) === ['field_id']) {
            return false;
        }

        // OK if PRIMARY is composite
        if (isset($byName['PRIMARY']) && ($byName['PRIMARY']['cols'] ?? []) === ['field_id','year']) {
            return true;
        }

        // OK if there is any UNIQUE(field_id,year)
        foreach ($byName as $name => $v) {
            if ($name === 'PRIMARY') continue;
            if ((int)$v['non_unique'] !== 0) continue;
            if (($v['cols'] ?? []) === ['field_id','year']) return true;
        }
        return false;
    } catch (Throwable $e) {
        return false;
    }
}

function _create_fyd_v2(PDO $db): void {
    $opts = _db_table_opts($db);

    // Create a clean, always-correct schema.
    $sql = "CREATE TABLE IF NOT EXISTS `field_year_data_v2` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `field_id` INT NOT NULL,
        `year` SMALLINT NOT NULL,
        `plow_date` DATE NULL,
        `sow_date` DATE NULL,
        `culture_id` INT NULL,
        `plan_fertilized` TINYINT(1) NULL,
        `plan_purchased`  TINYINT(1) NULL,
        `plan_elite`      TINYINT(1) NULL,
        `plan_notes`      TEXT NULL,
        `treatment_date` DATE NULL,
        `treatment_desc` VARCHAR(255) NULL,
        `last_water_date` DATE NULL,
        `harvest_date` DATE NULL,
        `harvest_area_ha` DECIMAL(8,2) NULL,
        `gross_yield` VARCHAR(64) NULL,
        `avg_yield` VARCHAR(64) NULL,
        `notes` TEXT NULL,
        UNIQUE KEY `uniq_field_year` (`field_id`,`year`),
        KEY `idx_fyd_field` (`field_id`),
        KEY `idx_fyd_culture` (`culture_id`)
    ) {$opts}";
    try { $db->exec($sql); } catch (Throwable $e) {
        error_log('create field_year_data_v2 failed: '.$e->getMessage());
    }

    // Try to copy existing rows from the legacy table (if any)
    try {
        $db->exec("INSERT IGNORE INTO `field_year_data_v2`
            (`field_id`,`year`,`plow_date`,`sow_date`,`culture_id`,`plan_fertilized`,`plan_purchased`,`plan_elite`,`plan_notes`,`treatment_date`,`treatment_desc`,`last_water_date`,`harvest_date`,`harvest_area_ha`,`gross_yield`,`avg_yield`,`notes`)
            SELECT
              `field_id`,
              IFNULL(NULLIF(`year`,0), 2026) AS `year`,
              `plow_date`,`sow_date`,`culture_id`,`plan_fertilized`,`plan_purchased`,`plan_elite`,`plan_notes`,`treatment_date`,`treatment_desc`,`last_water_date`,`harvest_date`,`harvest_area_ha`,`gross_yield`,`avg_yield`,`notes`
            FROM `field_year_data`");
    } catch (Throwable $e) {
        // ignore
    }
}

function _choose_fyd_table(PDO $db): string {
    // If legacy schema is OK — use it.
    if (_fyd_table_is_ok($db, 'field_year_data')) return 'field_year_data';

    // Otherwise: create & switch to v2.
    _create_fyd_v2($db);
    if (_fyd_table_is_ok($db, 'field_year_data_v2')) return 'field_year_data_v2';

    // Fallback — should not happen, but keep system alive.
    return 'field_year_data';
}

/**
 * Ensure UNIQUE(field_id, year) exists on field_year_data.
 * If there is a wrong UNIQUE(field_id) from an older migration, drop it.
 * Also deduplicate accidental duplicates before adding the key.
 */
function ensure_unique_field_year_index(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $idx = $db->query("SHOW INDEX FROM `field_year_data`")->fetchAll(PDO::FETCH_ASSOC);
        if (!$idx) return;

        // Collect index columns
        $byName = [];
        foreach ($idx as $r) {
            $name = (string)$r['Key_name'];
            $nonUnique = (int)$r['Non_unique'];
            $seq = (int)$r['Seq_in_index'];
            $col = (string)$r['Column_name'];
            if (!isset($byName[$name])) $byName[$name] = ['non_unique'=>$nonUnique, 'cols'=>[]];
            $byName[$name]['cols'][$seq] = $col;
        }
        foreach ($byName as $name => &$v) {
            ksort($v['cols']);
            $v['cols'] = array_values($v['cols']);
        }

        // If PRIMARY KEY is only on field_id (old broken schema), fix it first.
        if (isset($byName['PRIMARY'])) {
            $pcols = $byName['PRIMARY']['cols'] ?? [];
            if ($pcols === ['field_id']) {
                // Make primary key composite to allow multi-year rows.
                try {
                    $db->exec("ALTER TABLE `field_year_data` DROP PRIMARY KEY, ADD PRIMARY KEY (`field_id`,`year`)");
                } catch (Throwable $e) {
                    // ignore
                }
            }
        }

        // Already correct?
        if (isset($byName['uniq_field_year']) && (int)$byName['uniq_field_year']['non_unique'] === 0) {
            $cols = $byName['uniq_field_year']['cols'] ?? [];
            if ($cols === ['field_id','year'] || $cols === ['field_id','`year`']) return;
        }
        // Or another unique index exactly matching the desired columns
        foreach ($byName as $name => $v) {
            if ($name === 'PRIMARY') continue;
            if ((int)$v['non_unique'] !== 0) continue;
            if (($v['cols'] ?? []) === ['field_id','year']) return;
        }

        // Drop wrong UNIQUE(field_id) if present
        foreach ($byName as $name => $v) {
            if ($name === 'PRIMARY') continue;
            if ((int)$v['non_unique'] !== 0) continue;
            $cols = $v['cols'] ?? [];
            if ($cols === ['field_id']) {
                try { $db->exec("ALTER TABLE `field_year_data` DROP INDEX `{$name}`"); } catch (Throwable $e) {}
            }
        }

        // Deduplicate accidental duplicates (keep newest id) — only if `id` exists.
        $hasId = false;
        try {
            $st = $db->prepare("SHOW COLUMNS FROM `field_year_data` LIKE ?");
            $st->execute(['id']);
            $hasId = (bool)$st->fetchColumn();
        } catch (Throwable $e) { $hasId = false; }

        if ($hasId) {
            try {
                $dup = $db->query("SELECT field_id, `year`, COUNT(*) c FROM `field_year_data` GROUP BY field_id, `year` HAVING c > 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                if ($dup) {
                    $db->exec("DELETE t1 FROM `field_year_data` t1 JOIN `field_year_data` t2 ON t1.field_id=t2.field_id AND t1.`year`=t2.`year` AND t1.id < t2.id");
                }
            } catch (Throwable $e) {}
        }

        // Add the correct unique key
        try {
            $db->exec("ALTER TABLE `field_year_data` ADD UNIQUE KEY `uniq_field_year` (`field_id`,`year`)");
        } catch (Throwable $e) {
            // ignore (lack of privileges etc)
        }

        // Final verification. If the table is still broken (no UNIQUE(field_id,year)),
        // rebuild it defensively to restore year separation.
        try {
            $idx2 = $db->query("SHOW INDEX FROM `field_year_data`")->fetchAll(PDO::FETCH_ASSOC);
            $ok = false;
            if ($idx2) {
                $cols = [];
                foreach ($idx2 as $r) {
                    if ((int)$r['Non_unique'] !== 0) continue;
                    $name = (string)$r['Key_name'];
                    $seq = (int)$r['Seq_in_index'];
                    $col = (string)$r['Column_name'];
                    if (!isset($cols[$name])) $cols[$name] = [];
                    $cols[$name][$seq] = $col;
                }
                foreach ($cols as $name => $arr) {
                    ksort($arr);
                    $arr = array_values($arr);
                    if ($arr === ['field_id','year']) { $ok = true; break; }
                }
            }
            if (!$ok) {
                rebuild_field_year_data_table($db);
            }
        } catch (Throwable $e) {
            // ignore
        }
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * Rebuild field_year_data to guarantee UNIQUE(field_id, year).
 * This is a last-resort fix for deployments where old/broken indexes prevent year separation.
 */
function rebuild_field_year_data_table(PDO $db): void {
    // Avoid repeated heavy operations.
    static $rebuilt = false;
    if ($rebuilt) return;
    $rebuilt = true;

    $opts = _db_table_opts($db);
    $tmp = 'field_year_data__new';

    try {
        // Create new table with the intended schema (no foreign keys).
        $db->exec("DROP TABLE IF EXISTS `{$tmp}`");
        $db->exec("CREATE TABLE `{$tmp}` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `field_id` INT NOT NULL,
            `year` SMALLINT NOT NULL,
            `plow_date` DATE NULL,
            `sow_date` DATE NULL,
            `culture_id` INT NULL,
            `plan_fertilized` TINYINT(1) NULL,
            `plan_purchased`  TINYINT(1) NULL,
            `plan_elite`      TINYINT(1) NULL,
            `plan_notes`      TEXT NULL,
            `treatment_date`  DATE NULL,
            `treatment_desc`  VARCHAR(255) NULL,
            `last_water_date` DATE NULL,
            `harvest_date`    DATE NULL,
            `harvest_area_ha` DECIMAL(8,2) NULL,
            `gross_yield`     VARCHAR(64) NULL,
            `avg_yield`       VARCHAR(64) NULL,
            `notes`           TEXT NULL,
            UNIQUE KEY `uniq_field_year` (`field_id`,`year`),
            KEY `idx_fyd_field` (`field_id`),
            KEY `idx_fyd_culture` (`culture_id`)
        ) {$opts}");

        // Copy data from old table. If duplicates exist, keep the latest row (by id).
        $db->exec("INSERT INTO `{$tmp}`
            (field_id, `year`, plow_date, sow_date, culture_id, plan_fertilized, plan_purchased, plan_elite, plan_notes,
             treatment_date, treatment_desc, last_water_date, harvest_date, harvest_area_ha, gross_yield, avg_yield, notes)
            SELECT field_id, `year`, plow_date, sow_date, culture_id, plan_fertilized, plan_purchased, plan_elite, plan_notes,
                   treatment_date, treatment_desc, last_water_date, harvest_date, harvest_area_ha, gross_yield, avg_yield, notes
            FROM `field_year_data`
            ORDER BY id ASC
            ON DUPLICATE KEY UPDATE
              plow_date=VALUES(plow_date),
              sow_date=VALUES(sow_date),
              culture_id=VALUES(culture_id),
              plan_fertilized=VALUES(plan_fertilized),
              plan_purchased=VALUES(plan_purchased),
              plan_elite=VALUES(plan_elite),
              plan_notes=VALUES(plan_notes),
              treatment_date=VALUES(treatment_date),
              treatment_desc=VALUES(treatment_desc),
              last_water_date=VALUES(last_water_date),
              harvest_date=VALUES(harvest_date),
              harvest_area_ha=VALUES(harvest_area_ha),
              gross_yield=VALUES(gross_yield),
              avg_yield=VALUES(avg_yield),
              notes=VALUES(notes)");

        // Swap tables.
        $db->exec("RENAME TABLE `field_year_data` TO `field_year_data__old`, `{$tmp}` TO `field_year_data`");
        // Keep backup only briefly; drop if possible.
        try { $db->exec("DROP TABLE `field_year_data__old`"); } catch (Throwable $e) {}
    } catch (Throwable $e) {
        // If rebuild fails (permissions), don't break the app.
        error_log('rebuild_field_year_data_table failed: '.$e->getMessage());
        try { $db->exec("DROP TABLE IF EXISTS `{$tmp}`"); } catch (Throwable $e2) {}
    }
}

/**
 * Ensure extra tables for "passport" / external IDs exist.
 */
function ensure_field_passport_tables(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $opts = _db_table_opts($db);
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS `field_passport` (
            `field_id` INT NOT NULL PRIMARY KEY,
            `cadastral_full` VARCHAR(64) NULL,
            `local_name` VARCHAR(128) NULL,
            `passport_notes` TEXT NULL,
            KEY `idx_fp_field` (`field_id`)
        ) {$opts}");
    } catch (Throwable $e) {
        error_log('ensure_field_passport_tables passport create failed: '.$e->getMessage());
    }

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS `field_efis` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `field_id` INT NOT NULL,
            `efis_code` VARCHAR(32) NOT NULL,
            `area_ha` DECIMAL(8,2) NULL,
            `note` VARCHAR(255) NULL,
            UNIQUE KEY `uniq_field_efis` (`field_id`,`efis_code`),
            KEY `idx_fe_field` (`field_id`)
        ) {$opts}");
    } catch (Throwable $e) {
        error_log('ensure_field_passport_tables efis create failed: '.$e->getMessage());
    }

    // Мягкая эволюция схемы (если таблицы уже есть, но без каких-то колонок)
    ensure_column($db, 'field_passport', 'cadastral_full', "ALTER TABLE `field_passport` ADD COLUMN `cadastral_full` VARCHAR(64) NULL");
    ensure_column($db, 'field_passport', 'local_name',     "ALTER TABLE `field_passport` ADD COLUMN `local_name` VARCHAR(128) NULL");
    ensure_column($db, 'field_passport', 'passport_notes', "ALTER TABLE `field_passport` ADD COLUMN `passport_notes` TEXT NULL");

    ensure_column($db, 'field_efis', 'area_ha', "ALTER TABLE `field_efis` ADD COLUMN `area_ha` DECIMAL(8,2) NULL");
    ensure_column($db, 'field_efis', 'note',    "ALTER TABLE `field_efis` ADD COLUMN `note` VARCHAR(255) NULL");
}

/**
 * Small helper: add column if it doesn't exist.
 */
function ensure_column(PDO $db, string $table, string $column, string $alterSql): void {
    try {
        $st = $db->prepare("SHOW COLUMNS FROM `{$table}` LIKE :c");
        $st->execute([':c' => $column]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $db->exec($alterSql);
        }
    } catch (Throwable $e) {
        // Не валим приложение из-за миграции — просто логируем.
        error_log('ensure_column '.$table.'.'.$column.' failed: '.$e->getMessage());
    }
}


/**
 * Ensure per-year multi-row accounting tables exist.
 *
 * This supports Excel-style data:
 *  - one field may have multiple EFIS codes
 *  - within a single season (year) there may be multiple plan/harvest rows
 */
function ensure_field_accounting_rows_tables(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $opts = _db_table_opts($db);

    // Plan (placement) rows
    try {
      $db->exec("CREATE TABLE IF NOT EXISTS `field_plan_rows` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `field_id` INT NOT NULL,
        `year` SMALLINT NOT NULL,
        `pos` INT NOT NULL DEFAULT 0,
        `efis_code` VARCHAR(32) NULL,
        `area_ha` DECIMAL(8,2) NULL,
        `crop_text` VARCHAR(128) NULL,
        `fertilized` TINYINT(1) NULL,
        `purchased`  TINYINT(1) NULL,
        `elite`      TINYINT(1) NULL,
        `note` VARCHAR(255) NULL,
        KEY `idx_fpr_field_year` (`field_id`,`year`,`pos`)
    ) {$opts}");
    } catch (Throwable $e) {
      error_log('ensure_field_accounting_rows_tables plan create failed: '.$e->getMessage());
    }

    // Harvest (fact) rows
    try {
      $db->exec("CREATE TABLE IF NOT EXISTS `field_harvest_rows` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `field_id` INT NOT NULL,
        `year` SMALLINT NOT NULL,
        `pos` INT NOT NULL DEFAULT 0,
        `efis_code` VARCHAR(32) NULL,
        `area_ha` DECIMAL(8,2) NULL,
        `crop_text` VARCHAR(128) NULL,
        `gross_yield` VARCHAR(64) NULL,
        `avg_yield`   VARCHAR(64) NULL,
        `note` VARCHAR(255) NULL,
        KEY `idx_fhr_field_year` (`field_id`,`year`,`pos`)
    ) {$opts}");
    } catch (Throwable $e) {
      error_log('ensure_field_accounting_rows_tables harvest create failed: '.$e->getMessage());
    }

    // Soft schema evolution (if tables existed earlier without some columns)
    ensure_column($db, 'field_plan_rows', 'pos', "ALTER TABLE `field_plan_rows` ADD COLUMN `pos` INT NOT NULL DEFAULT 0 AFTER `year`");
    ensure_column($db, 'field_plan_rows', 'efis_code', "ALTER TABLE `field_plan_rows` ADD COLUMN `efis_code` VARCHAR(32) NULL");
    ensure_column($db, 'field_plan_rows', 'area_ha', "ALTER TABLE `field_plan_rows` ADD COLUMN `area_ha` DECIMAL(8,2) NULL");
    ensure_column($db, 'field_plan_rows', 'crop_text', "ALTER TABLE `field_plan_rows` ADD COLUMN `crop_text` VARCHAR(128) NULL");
    ensure_column($db, 'field_plan_rows', 'fertilized', "ALTER TABLE `field_plan_rows` ADD COLUMN `fertilized` TINYINT(1) NULL");
    ensure_column($db, 'field_plan_rows', 'purchased', "ALTER TABLE `field_plan_rows` ADD COLUMN `purchased` TINYINT(1) NULL");
    ensure_column($db, 'field_plan_rows', 'elite', "ALTER TABLE `field_plan_rows` ADD COLUMN `elite` TINYINT(1) NULL");
    ensure_column($db, 'field_plan_rows', 'note', "ALTER TABLE `field_plan_rows` ADD COLUMN `note` VARCHAR(255) NULL");

    ensure_column($db, 'field_harvest_rows', 'pos', "ALTER TABLE `field_harvest_rows` ADD COLUMN `pos` INT NOT NULL DEFAULT 0 AFTER `year`");
    ensure_column($db, 'field_harvest_rows', 'efis_code', "ALTER TABLE `field_harvest_rows` ADD COLUMN `efis_code` VARCHAR(32) NULL");
    ensure_column($db, 'field_harvest_rows', 'area_ha', "ALTER TABLE `field_harvest_rows` ADD COLUMN `area_ha` DECIMAL(8,2) NULL");
    ensure_column($db, 'field_harvest_rows', 'crop_text', "ALTER TABLE `field_harvest_rows` ADD COLUMN `crop_text` VARCHAR(128) NULL");
    ensure_column($db, 'field_harvest_rows', 'gross_yield', "ALTER TABLE `field_harvest_rows` ADD COLUMN `gross_yield` VARCHAR(64) NULL");
    ensure_column($db, 'field_harvest_rows', 'avg_yield', "ALTER TABLE `field_harvest_rows` ADD COLUMN `avg_yield` VARCHAR(64) NULL");
    ensure_column($db, 'field_harvest_rows', 'note', "ALTER TABLE `field_harvest_rows` ADD COLUMN `note` VARCHAR(255) NULL");
}


/**
 * Ensure per-year bookkeeping tables exist.
 *
 * This is a “структура бухгалтерии” по сезону:
 *  - строки расходов/операций (семена/удобрения/СЗР/ГСМ/работы/прочее)
 *  - документы/примечания
 */
function ensure_field_bookkeeping_tables(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $opts = _db_table_opts($db);

    try {
      $db->exec("CREATE TABLE IF NOT EXISTS `field_accounting_rows` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `field_id` INT NOT NULL,
        `year` SMALLINT NOT NULL,
        `pos` INT NOT NULL DEFAULT 0,
        `section` VARCHAR(16) NOT NULL,
        `efis_code` VARCHAR(32) NULL,
        `item` VARCHAR(255) NULL,
        `unit` VARCHAR(32) NULL,
        `qty` DECIMAL(12,3) NULL,
        `price` DECIMAL(12,2) NULL,
        `amount` DECIMAL(12,2) NULL,
        `note` VARCHAR(255) NULL,
        KEY `idx_far_field_year` (`field_id`,`year`,`section`,`pos`)
    ) {$opts}");
    } catch (Throwable $e) {
      error_log('ensure_field_bookkeeping_tables rows create failed: '.$e->getMessage());
    }

    try {
      $db->exec("CREATE TABLE IF NOT EXISTS `field_docs` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `field_id` INT NOT NULL,
        `year` SMALLINT NOT NULL,
        `pos` INT NOT NULL DEFAULT 0,
        `doc_type` VARCHAR(128) NULL,
        `doc_no` VARCHAR(64) NULL,
        `doc_date` DATE NULL,
        `note` VARCHAR(255) NULL,
        `link` VARCHAR(255) NULL,
        KEY `idx_fd_field_year` (`field_id`,`year`,`pos`)
    ) {$opts}");
    } catch (Throwable $e) {
      error_log('ensure_field_bookkeeping_tables docs create failed: '.$e->getMessage());
    }

    // soft evolution
    ensure_column($db, 'field_accounting_rows', 'pos', "ALTER TABLE `field_accounting_rows` ADD COLUMN `pos` INT NOT NULL DEFAULT 0 AFTER `year`");
    ensure_column($db, 'field_accounting_rows', 'section', "ALTER TABLE `field_accounting_rows` ADD COLUMN `section` VARCHAR(16) NOT NULL AFTER `pos`");
    ensure_column($db, 'field_accounting_rows', 'efis_code', "ALTER TABLE `field_accounting_rows` ADD COLUMN `efis_code` VARCHAR(32) NULL");
    ensure_column($db, 'field_accounting_rows', 'item', "ALTER TABLE `field_accounting_rows` ADD COLUMN `item` VARCHAR(255) NULL");
    ensure_column($db, 'field_accounting_rows', 'unit', "ALTER TABLE `field_accounting_rows` ADD COLUMN `unit` VARCHAR(32) NULL");
    ensure_column($db, 'field_accounting_rows', 'qty', "ALTER TABLE `field_accounting_rows` ADD COLUMN `qty` DECIMAL(12,3) NULL");
    ensure_column($db, 'field_accounting_rows', 'price', "ALTER TABLE `field_accounting_rows` ADD COLUMN `price` DECIMAL(12,2) NULL");
    ensure_column($db, 'field_accounting_rows', 'amount', "ALTER TABLE `field_accounting_rows` ADD COLUMN `amount` DECIMAL(12,2) NULL");
    ensure_column($db, 'field_accounting_rows', 'note', "ALTER TABLE `field_accounting_rows` ADD COLUMN `note` VARCHAR(255) NULL");

    ensure_column($db, 'field_docs', 'pos', "ALTER TABLE `field_docs` ADD COLUMN `pos` INT NOT NULL DEFAULT 0 AFTER `year`");
    ensure_column($db, 'field_docs', 'doc_type', "ALTER TABLE `field_docs` ADD COLUMN `doc_type` VARCHAR(128) NULL");
    ensure_column($db, 'field_docs', 'doc_no', "ALTER TABLE `field_docs` ADD COLUMN `doc_no` VARCHAR(64) NULL");
    ensure_column($db, 'field_docs', 'doc_date', "ALTER TABLE `field_docs` ADD COLUMN `doc_date` DATE NULL");
    ensure_column($db, 'field_docs', 'note', "ALTER TABLE `field_docs` ADD COLUMN `note` VARCHAR(255) NULL");
    ensure_column($db, 'field_docs', 'link', "ALTER TABLE `field_docs` ADD COLUMN `link` VARCHAR(255) NULL");
}


/**
 * Validate/normalize requested season year.
 */
function normalize_season_year($y, int $default = 2026): int {
    if ($y === null || $y === '') return $default;
    if (is_string($y) && !ctype_digit($y)) return $default;
    $n = (int)$y;
    if ($n < 2000 || $n > 2100) return $default;
    return $n;
}

// Данные, которые уже лежат в `fields`, считаем относящимися к этому сезону.
// (Чтобы старые записи не "пропали" после включения вкладок по годам.)
const LEGACY_FIELDS_YEAR = 2025;

/**
 * Fetch a field's data for a given season year.
 *
 * Returns an array compatible with `field_get.php` payload:
 *   field_code, area_ha, year, plow_date, sow_date, culture, treatment_date,
 *   treatment_desc, last_water_date, harvest_date, gross_yield, avg_yield,
 *   notes, culture_id
 *
 * If year-record doesn't exist:
 *  - for LEGACY_FIELDS_YEAR it falls back to values stored in `fields`
 *  - otherwise returns an "empty" season with passport values.
 */
function get_field_for_year(PDO $db, string $code, int $year): ?array {
    $code = trim($code);
    if (!preg_match('/^\d{3,4}$/', $code)) return null;

    // 1) Base field (passport)
    $st0 = $db->prepare("SELECT id, TRIM(field_code) AS field_code, area_ha FROM fields WHERE field_code=? LIMIT 1");
    $st0->execute([$code]);
    $base = $st0->fetch(PDO::FETCH_ASSOC);
    if (!$base) return null;

    ensure_field_year_data_table($db);

    // 2) Season data
    $st = $db->prepare("\n        SELECT\n          :field_code AS field_code,\n          :area_ha AS area_ha,\n          :year AS year,\n          NULLIF(CAST(d.plow_date AS CHAR), '0000-00-00')      AS plow_date,\n          NULLIF(CAST(d.sow_date AS CHAR), '0000-00-00')       AS sow_date,\n          c.title                                   AS culture,\n          d.plan_fertilized,\n          d.plan_purchased,\n          d.plan_elite,\n          d.plan_notes,\n          NULLIF(CAST(d.treatment_date AS CHAR), '0000-00-00')  AS treatment_date,\n          d.treatment_desc                           AS treatment_desc,\n          NULLIF(CAST(d.last_water_date AS CHAR), '0000-00-00') AS last_water_date,\n          NULLIF(CAST(d.harvest_date AS CHAR), '0000-00-00')    AS harvest_date,\n          d.harvest_area_ha,\n          d.gross_yield,\n          d.avg_yield,\n          d.notes,\n          d.culture_id\n        FROM field_year_data d\n        LEFT JOIN cultures c ON c.id = d.culture_id\n        WHERE d.field_id = :field_id AND d.`year` = :year\n        LIMIT 1\n    ");
    $st->execute([
        ':field_id'   => (int)$base['id'],
        ':year'       => $year,
        ':field_code' => $base['field_code'],
        ':area_ha'    => $base['area_ha'],
    ]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        // Legacy fallback for 2025
        if ($year === LEGACY_FIELDS_YEAR) {
            $sqlLegacy = "\n                SELECT\n                  TRIM(f.field_code) AS field_code,\n                  f.area_ha,\n                  :year AS year,\n                  NULLIF(CAST(f.plow_date AS CHAR), '0000-00-00')      AS plow_date,\n                  NULLIF(CAST(f.sow_date AS CHAR), '0000-00-00')       AS sow_date,\n                  c.title                                   AS culture,\n                  NULL AS plan_fertilized,\n                  NULL AS plan_purchased,\n                  NULL AS plan_elite,\n                  NULL AS plan_notes,\n                  NULLIF(CAST(f.treatment_date AS CHAR), '0000-00-00')  AS treatment_date,\n                  f.treatment_desc                           AS treatment_desc,\n                  NULLIF(CAST(f.last_water_date AS CHAR), '0000-00-00') AS last_water_date,\n                  NULLIF(CAST(f.harvest_date AS CHAR), '0000-00-00')    AS harvest_date,\n                  NULL AS harvest_area_ha,\n                  f.gross_yield,\n                  f.avg_yield,\n                  f.notes,\n                  f.culture_id\n                FROM fields f\n                LEFT JOIN cultures c ON c.id = f.culture_id\n                WHERE f.id = :field_id\n                LIMIT 1\n            ";
            $stL = $db->prepare($sqlLegacy);
            $stL->execute([':year' => $year, ':field_id' => (int)$base['id']]);
            $row = $stL->fetch(PDO::FETCH_ASSOC);
        }

        // Empty season
        if (!$row) {
            $row = [
                'field_code'      => $base['field_code'],
                'area_ha'         => $base['area_ha'],
                'year'            => $year,
                'plow_date'       => null,
                'sow_date'        => null,
                'culture'         => null,
                'plan_fertilized' => null,
                'plan_purchased'  => null,
                'plan_elite'      => null,
                'plan_notes'      => null,
                'treatment_date'  => null,
                'treatment_desc'  => null,
                'last_water_date' => null,
                'harvest_date'    => null,
                'harvest_area_ha' => null,
                'gross_yield'     => null,
                'avg_yield'       => null,
                'notes'           => null,
                'culture_id'      => null,
            ];
        }
    }

    // 3) Passport (cadastral, local name, EFIS list)
    $row['cadastral_full'] = null;
    $row['local_name'] = null;
    $row['passport_notes'] = null;
    $row['efis'] = [];

    try {
        ensure_field_passport_tables($db);
        $stP = $db->prepare("SELECT cadastral_full, local_name, passport_notes FROM field_passport WHERE field_id=? LIMIT 1");
        $stP->execute([(int)$base['id']]);
        if ($p = $stP->fetch(PDO::FETCH_ASSOC)) {
            $row['cadastral_full'] = $p['cadastral_full'] ?? null;
            $row['local_name'] = $p['local_name'] ?? null;
            $row['passport_notes'] = $p['passport_notes'] ?? null;
        }
        $stE = $db->prepare("SELECT efis_code, area_ha, note FROM field_efis WHERE field_id=? ORDER BY efis_code");
        $stE->execute([(int)$base['id']]);
        $row['efis'] = $stE->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        // passport must not break
        $row['efis'] = [];
    }

    // 4) Multi-row structures for this season (placement, harvest, bookkeeping)
    $row['plan_rows'] = [];
    $row['harvest_rows'] = [];
    $row['accounting_rows'] = [];
    $row['doc_rows'] = [];

    try {
        ensure_field_accounting_rows_tables($db);
        $stPR = $db->prepare("SELECT efis_code, area_ha, crop_text, fertilized, purchased, elite, note\n                               FROM field_plan_rows\n                               WHERE field_id=? AND `year`=?\n                               ORDER BY pos ASC, id ASC");
        $stPR->execute([(int)$base['id'], $year]);
        $row['plan_rows'] = $stPR->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stHR = $db->prepare("SELECT efis_code, area_ha, crop_text, gross_yield, avg_yield, note\n                               FROM field_harvest_rows\n                               WHERE field_id=? AND `year`=?\n                               ORDER BY pos ASC, id ASC");
        $stHR->execute([(int)$base['id'], $year]);
        $row['harvest_rows'] = $stHR->fetchAll(PDO::FETCH_ASSOC) ?: [];

        ensure_field_bookkeeping_tables($db);
        $stAR = $db->prepare("SELECT section, efis_code, item, unit, qty, price, amount, note\n                               FROM field_accounting_rows\n                               WHERE field_id=? AND `year`=?\n                               ORDER BY section ASC, pos ASC, id ASC");
        $stAR->execute([(int)$base['id'], $year]);
        $row['accounting_rows'] = $stAR->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stDR = $db->prepare("SELECT doc_type, doc_no, NULLIF(CAST(doc_date AS CHAR), '0000-00-00') AS doc_date, note, link\n                               FROM field_docs\n                               WHERE field_id=? AND `year`=?\n                               ORDER BY pos ASC, id ASC");
        $stDR->execute([(int)$base['id'], $year]);
        $row['doc_rows'] = $stDR->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        // ignore
    }

    return $row;
}

/**
 * Ensure tables for Excel import batches and unmatched rows.
 *
 * We store rows that couldn't be linked to a field during Excel import,
 * so admin can later resolve them via UI.
 */
function ensure_excel_import_queue_tables(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $opts = _db_table_opts($db);

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS `excel_import_batch` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `year` SMALLINT NOT NULL,
            `file_name` VARCHAR(255) NULL,
            `user_id` INT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_year_created` (`year`,`created_at`)
        ) {$opts}");
    } catch (Throwable $e) {
        error_log('ensure_excel_import_queue_tables batch failed: '.$e->getMessage());
    }

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS `excel_import_unmatched` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `batch_id` INT NOT NULL,
            `year` SMALLINT NOT NULL,
            `kind` VARCHAR(12) NOT NULL,
            `local_name` VARCHAR(128) NULL,
            `efis_raw` VARCHAR(255) NULL,
            `efis_json` TEXT NULL,
            `area_ha` DECIMAL(8,2) NULL,
            `crop_text` VARCHAR(128) NULL,
            `gross_yield` VARCHAR(64) NULL,
            `avg_yield` VARCHAR(64) NULL,
            `fertilized` TINYINT(1) NULL,
            `purchased` TINYINT(1) NULL,
            `elite` TINYINT(1) NULL,
            `note` VARCHAR(255) NULL,
            `status` VARCHAR(12) NOT NULL DEFAULT 'open',
            `resolved_field_id` INT NULL,
            `resolved_by` INT NULL,
            `resolved_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_status` (`status`),
            KEY `idx_year_status` (`year`,`status`),
            KEY `idx_batch` (`batch_id`)
        ) {$opts}");
    } catch (Throwable $e) {
        error_log('ensure_excel_import_queue_tables unmatched failed: '.$e->getMessage());
    }

    // Soft schema evolution
    ensure_column($db, 'excel_import_unmatched', 'efis_json', "ALTER TABLE `excel_import_unmatched` ADD COLUMN `efis_json` TEXT NULL AFTER `efis_raw`");
    ensure_column($db, 'excel_import_unmatched', 'status', "ALTER TABLE `excel_import_unmatched` ADD COLUMN `status` VARCHAR(12) NOT NULL DEFAULT 'open'");
    ensure_column($db, 'excel_import_unmatched', 'resolved_field_id', "ALTER TABLE `excel_import_unmatched` ADD COLUMN `resolved_field_id` INT NULL");
    ensure_column($db, 'excel_import_unmatched', 'resolved_by', "ALTER TABLE `excel_import_unmatched` ADD COLUMN `resolved_by` INT NULL");
    ensure_column($db, 'excel_import_unmatched', 'resolved_at', "ALTER TABLE `excel_import_unmatched` ADD COLUMN `resolved_at` DATETIME NULL");
    ensure_column($db, 'excel_import_unmatched', 'created_at', "ALTER TABLE `excel_import_unmatched` ADD COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
}
