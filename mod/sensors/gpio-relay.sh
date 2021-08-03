#!/bin/bash --
# Sets pin hi or low (relay on or off)
# usage gpio-relay.sh <bcm-pin> {on|off}
#

#set pin mode
gpio -g mode $1 out

case $2 in
	on)
		gpio -g write $1 0
		exit 0
		;;
	off)
		gpio -g write $1 1
		exit 0
		;;
	*)
		echo "Usage gpio-relay.sh <bcm-pin> {on|off}"
		exit 1
		;;
esac
exit 1

