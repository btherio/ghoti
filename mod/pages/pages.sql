create table if not exists pages(
 `id` int(11) not null auto_increment,
 `title` varchar(24) not null,
 `content` text not null,
 `groupName` varchar(24) default 'public' not null,
 `commentable` bool default true not null,
 `sortOrder` int(11) default 0 not null,
 `isDefault` bool default false not null,
primary key (`id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;
