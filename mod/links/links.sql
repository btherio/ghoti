create table if not exists links(
	`id` int(11) not null auto_increment,
	`userId` int(11) not null,
	`name` varchar(32) not null,
	`url` varchar(500) not null,
	`grp` varchar(32) not null,
  PRIMARY KEY  (`id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;

