-- This file is automatically generated using maintenance/generateSchemaSql.php.
-- Source: extensions/MediaModeration/schema/tables.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
CREATE TABLE /*_*/mediamoderation_scan (
  mms_sha1 VARBINARY(32) NOT NULL,
  mms_last_checked INT UNSIGNED DEFAULT NULL,
  mms_is_match TINYINT(1) UNSIGNED DEFAULT NULL,
  INDEX mms_is_match_last_checked (mms_is_match, mms_last_checked),
  INDEX mms_last_checked (mms_last_checked),
  PRIMARY KEY(mms_sha1)
) /*$wgDBTableOptions*/;
