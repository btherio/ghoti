insert into pages (title,content,groupName) values('Sensors','<h1>Sensors</h1><p><a alt=\"Sensors\" class=\"ghotiMenu\" href=\"#\" onclick=\"getSensors()\;\">Manage Sensors</a></p><p><a alt=\"Sensors\" class=\"ghotiMenu\" href=\"#\" onclick=\"searchSensors()\;\">Search for New Sensors</a></p>','private');
insert into pages (title, content) values('SmarTent Overview','<div id=\"liveSensors\"><h1>Sensor Overview</h1><p><img src=\"mod/sensors/loading.gif\" height=\"24\" width=\"24\" alt=\"Loading...\" /></p></div><div id=\"liveRelays\"><h1>Relay Overview</h1>><p><img src=\"mod/sensors/loading.gif\" height=\"24\" width=\"24\" alt=\"Loading...\" /></p></div>');
create table if not exists sensorData(`id` int(11) not null,`date` varchar(32) not null,`data` varchar(32) not null) ENGINE=MyISAM  DEFAULT CHARSET=utf8;
create table if not exists sensorSetpoints(`id` int(11) not null,`setpoint` decimal not null,`type` varchar(32) not null,`action` varchar(32) not null) ENGINE=MyISAM  DEFAULT CHARSET=utf8;



