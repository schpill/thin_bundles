DROP TABLE IF EXISTS `kvages`;
CREATE TABLE `kvages` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `object_database` varchar(25) NOT NULL,
  `object_table` varchar(25) NOT NULL,
  `object_age` int(11) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `object_database_object_table` (`object_database`,`object_table`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `kvcaching`;
CREATE TABLE `kvcaching` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `expire` int(11) unsigned DEFAULT NULL,
  `caching_ns` varchar(50) NOT NULL,
  `caching_key` varchar(255) NOT NULL,
  `caching_value` longtext,
  PRIMARY KEY (`id`),
  UNIQUE KEY `caching_ns_caching_key` (`caching_ns`,`caching_key`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `kvdatas`;
CREATE TABLE `kvdatas` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `object_database` varchar(25) NOT NULL,
  `object_table` varchar(25) NOT NULL,
  `object_field` varchar(25) NOT NULL,
  `object_id` int(11) unsigned NOT NULL,
  `string_value` varchar(255) DEFAULT NULL,
  `int_value` int(11) unsigned DEFAULT NULL,
  `float_value` float DEFAULT NULL,
  `text_value` longtext,
  PRIMARY KEY (`id`),
  UNIQUE KEY `object_key_id` (`object_database`,`object_table`,`object_field`,`object_id`),
  KEY `float_value` (`float_value`),
  KEY `int_value` (`int_value`),
  KEY `string_value` (`string_value`),
  KEY `object_id` (`object_id`),
  FULLTEXT KEY `text_value` (`text_value`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `kvids`;
CREATE TABLE `kvids` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `object_database` varchar(25) NOT NULL,
  `object_table` varchar(25) NOT NULL,
  `object_id` int(11) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `object_database_object_table` (`object_database`,`object_table`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `kvtuples`;
CREATE TABLE `kvtuples` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `keytuple` char(40) NOT NULL,
  `keyid` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `keyid` (`keyid`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;
