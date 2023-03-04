#!/bin/bash --
# Sets pin hi or low (relay on or off)
# usage gpio-relay.sh <bcm-pin> {on|off} <ipaddress>
# ip address optional, only for wifi relays

username=smartent
password=garibaldiTornado
pin=$1
address=$4

#set pin mode
gpio -g mode $pin out

function relayOn () {
if [ $pin != 1  ]
	then
		#echo "gpio pin $pin on"
		gpio -g write $pin 0
		mysql --user="$username" --password="$password" --database=smartent --execute="update relays set state='on' where pin='$pin'";
	else
		#echo "wifi relay $address on"
		wget http://$address/relay/on >> /dev/null
		mysql --user="$username" --password="$password" --database=smartent --execute="update relays set state='on' where address='$address'";
	fi
}
function relayOff () {
if [ $pin != 1  ]
	then
		#echo "gpio pin $pin off"
		gpio -g write $pin 1
		mysql --user="$username" --password="$password" --database=smartent --execute="update relays set state='off' where pin='$pin'";
	else
		#echo "wifi relay $address off"
		wget http://$address/relay/off >> /dev/null
		mysql --user="$username" --password="$password" --database=smartent --execute="update relays set state='off' where address='$address'";
	fi
}


case $2 in
	on)
		relayOn
		exit 0;
		;;
	off)
		relayOff
		exit 0;
		;;
	On)
		relayOn
		exit 0;
		;;
	Off)
		relayOff
		exit 0;
		;;
	pulse)
		relayOn
		sleep $3;
		relayOff
		exit 0;
		;;
	Pulse)
		relayOn
		sleep $3;
		relayOff
		exit 0;
		;;
	*)
		echo "Usage gpio-relay.sh <bcm-pin> {on|off|pulse} <seconds> <ipaddress>"
		echo "Use placeholder 0 for seconds when setting wifi relays on/off"
		exit 1;
		;;
esac
exit 1

