create table if not exists banners(
	`id` int(11) not null auto_increment,
	`alt` varchar(100) not null,
	`imgUrl` varchar(100) not null,
	`linkUrl` varchar(500) not null,
	`smallBanner` int(11) not null,
  PRIMARY KEY  (`id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;


-- Ad settings. A second table because loadModuleSql() probes a table with the
-- same name as the module to decide whether this module has ever been
-- installed - so `banners` has to stay the banner list, and the single settings
-- row lives beside it. Existing installs gain this table on the next request:
-- the loader replays this file whenever its hash changes, and every statement
-- here is create-if-not-exists.
create table if not exists banner_settings(
	`id` int(11) not null,
	`source` varchar(10) not null default 'local',   -- 'local' | 'adsense' | 'both'
	`adClient` varchar(32) not null default '',      -- ca-pub-0000000000000000
	`adSlotSmall` varchar(24) not null default '',   -- unit shown where the theme asks for a small banner
	`adSlotLarge` varchar(24) not null default '',   -- unit shown where the theme asks for a full-width one
	`adFormat` varchar(20) not null default 'auto',  -- data-ad-format
	`adFullWidth` int(1) not null default 1,         -- data-full-width-responsive
	`adTest` int(1) not null default 0,              -- data-adtest: unbilled placeholder ads
	`adLabel` varchar(60) not null default '',       -- optional caption above each unit
	`updatedAt` int(11) not null default 0,
  PRIMARY KEY  (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 ;
