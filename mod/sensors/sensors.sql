create table if not exists sensors(
	`id` int(11) not null auto_increment,
	`name` varchar(32) not null,
	`address` varchar(500) not null,
	`type` varchar(32) not null,
  PRIMARY KEY  (`id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;
