#!/bin/bash --
# Sets pin hi or low (relay on or off)
# usage gpio-relay.sh <bcm-pin> {on|off}
#
username=smartent
password=garibaldiTornado

#set pin mode
gpio -g mode $1 out

case $2 in
	on)
		mysql --user="$username" --password="$password" --database=smartent  --execute="update relays set state='on' where pin='$1'";
		gpio -g write $1 0
		exit 0
		;;
	off)
		mysql --user="$username" --password="$password" --database=smartent  --execute="update relays set state='off' where pin='$1'";
		gpio -g write $1 1
		exit 0
		;;
	On)
		mysql --user="$username" --password="$password" --database=smartent  --execute="update relays set state='on' where pin='$1'";
		gpio -g write $1 0
		exit 0
		;;
	Off)
		mysql --user="$username" --password="$password" --database=smartent  --execute="update relays set state='off' where pin='$1'";
		gpio -g write $1 1
		exit 0
		;;
	pulse)
		mysql --user="$username" --password="$password" --database=smartent  --execute="update relays set state='on' where pin='$1'";
        	gpio -g write $1 0
        	sleep 3;
		mysql --user="$username" --password="$password" --database=smartent  --execute="update relays set state='off' where pin='$1'";
        	gpio -g write $1 1
        	;;
    	Pulse)
		mysql --user="$username" --password="$password" --database=smartent  --execute="update relays set state='on' where pin='$1'";
        	gpio -g write $1 0
        	sleep 3;
		mysql --user="$username" --password="$password" --database=smartent  --execute="update relays set state='off' where pin='$1'";
        	gpio -g write $1 1
        	;;
	*)
		echo "Usage gpio-relay.sh <bcm-pin> {on|off|pulse}"
		exit 1
		;;
esac
exit 1

