create table if not exists relays(
	`id` int(11) not null auto_increment,
	`name` varchar(32) not null,
	`pin` int(11) not null,
	`state` varchar(500) not null,
  PRIMARY KEY  (`id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;
