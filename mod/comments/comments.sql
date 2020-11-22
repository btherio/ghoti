create table if not exists comments(
	`commentId` int(11) not null auto_increment,
	`userId` int(11) not null,
	`pageId` int(11) not null,
	`comment` text not null,
  PRIMARY KEY  (`commentId`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;

