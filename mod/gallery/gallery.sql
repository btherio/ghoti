create table if not exists gallery(
	`galleryId` int(11) not null auto_increment,
	`name` varchar(80) not null,
	`title` varchar(120) not null,
	`description` varchar(500) not null default '',
	`createdAt` int(11) not null default 0,
  PRIMARY KEY  (`galleryId`),
  UNIQUE KEY `uq_gallery_name` (`name`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1 ;

create table if not exists gallery_photos(
	`photoId` int(11) not null auto_increment,
	`galleryId` int(11) not null,
	`imageUrl` varchar(2048) not null,
	`caption` varchar(255) not null default '',
	`sortOrder` int(11) not null default 0,
	`createdAt` int(11) not null default 0,
  PRIMARY KEY  (`photoId`),
  KEY `idx_gallery` (`galleryId`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1 ;
