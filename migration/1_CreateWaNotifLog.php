<?php

use SLiMS\DB;
use SLiMS\Migration\Migration;

class CreateWaNotifLog extends Migration
{
    function up()
    {
        DB::getInstance('mysqli')->query(<<<'SQL'
CREATE TABLE IF NOT EXISTS `wa_notif_log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id` VARCHAR(32) NOT NULL DEFAULT '',
  `member_name` VARCHAR(128) NOT NULL DEFAULT '',
  `phone` VARCHAR(20) NOT NULL DEFAULT '',
  `type` VARCHAR(20) NOT NULL DEFAULT '',
  `message` TEXT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT '',
  `response` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL);
    }

    function down()
    {
        DB::getInstance('mysqli')->query('DROP TABLE IF EXISTS `wa_notif_log`');
    }
}
