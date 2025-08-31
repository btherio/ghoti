#!/bin/bash --
# Sets pin hi or low (relay on or off)
# usage gpio-relay.sh <bcm-pin> {on|off|pulse} <seconds> <ipaddress>
# ip address optional, only for wifi relays
# Use placeholder 0 for seconds when setting wifi relays on/off
# set pin to 1 for wifi relay

username="smartend"
password="garibaldi.tornado.1A4"
pin=$1
address=$4

#set pin mode
gpio -g mode $pin out

function relayOn () {
if [ $pin != 1  ]
	then
		#echo "gpio pin $pin on"
		gpio -g write $pin 0 >> /dev/null
		mariadb --host=127.0.0.1 --user="$username" --password="$password" --database=smartend --execute="update relays set state='on' where pin='$pin'" >> /dev/null
	else
		#echo "wifi relay $address on"
		wget -q http://$address/relay/on >> /dev/null
		mariadb --host=127.0.0.1 --user="$username" --password="$password" --database=smartend --execute="update relays set state='on' where address='$address'" >> /dev/null
	fi
}
function relayOff () {
if [ $pin != 1  ]
	then
		#echo "gpio pin $pin off"
		gpio -g write $pin 1 >> /dev/null
		mariadb --host=127.0.0.1 --user="$username" --password="$password" --database=smartend --execute="update relays set state='off' where pin='$pin'" >> /dev/null
	else
		#echo "wifi relay $address off"
		wget -q http://$address/relay/off >> /dev/null
		mariadb --host=127.0.0.1 --user="$username" --password="$password" --database=smartend --execute="update relays set state='off' where address='$address'" >> /dev/null
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

