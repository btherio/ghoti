create table if not exists banners(
	`id` int(11) not null auto_increment,
	`alt` varchar(100) not null,
	`imgUrl` varchar(100) not null,
	`linkUrl` varchar(500) not null,
	`smallBanner` int(11) not null,
  PRIMARY KEY  (`id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;

