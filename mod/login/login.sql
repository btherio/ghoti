CREATE TABLE IF NOT EXISTS `users` (
  `userId` int(11) NOT NULL auto_increment,
  `userName` varchar(128) NOT NULL,
  `email` varchar(128) default NULL,
  `password` varchar(128) NOT NULL,
  `admin` int(1) NOT NULL,
  PRIMARY KEY  (`userId`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;

