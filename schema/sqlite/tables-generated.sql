-- This file is automatically generated using maintenance/generateSchemaSql.php.
-- Source: extensions/MediaModeration/schema/tables.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
CREATE TABLE /*_*/mediamoderation_scan (
  mms_sha1 BLOB NOT NULL,
  mms_last_checked INTEGER UNSIGNED DEFAULT NULL,
  mms_is_match SMALLINT UNSIGNED DEFAULT NULL,
  PRIMARY KEY(mms_sha1)
);

CREATE INDEX mms_is_match_last_checked ON /*_*/mediamoderation_scan (mms_is_match, mms_last_checked);

CREATE INDEX mms_last_checked ON /*_*/mediamoderation_scan (mms_last_checked);
