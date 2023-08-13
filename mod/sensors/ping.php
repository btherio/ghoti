<?php

/*
* ping script for sensors to report their sensor data to the server for logging and setpoints.
* usage: ping.php?apikey=<apikey>&ip=<sensorip>&data=<data>
* link this script from module directory to root cms directory `ln -sf lib/mod/sensors/ping.php sensorPing.php`
*
*/
include_once("ghoti.php"); //include ghoti
include_once("ghoti.db.php"); //include ghotidb
include_once("mod/sensors/sensors.php"); //include sensors
include_once("mod/sensors/sensors.db.php"); //include sensorsdb
include_once("mod/relays/relays.php"); //include relays


$apikey = "ggg";

echo "pingscripts...";
echo "Checkin API key...\n";
if($_GET && $_GET['apikey'] == $apikey){    // check get variable, and check apikey matches. This is our only rudimentary security
    echo "Server: API match.\n";
    $ghoti = new ghoti();
    $ghotidb = new ghotidb();
    $sensors = new sensors();
    $sensorsdb = new sensorsdb();
    $relays = new relays();
    
    if($_GET['sensorip'] && $_GET['data']){   // check additional variables
    //get sensor by ip
        echo "Server: Found GET variables.\n";
        $sensor = $sensorsdb->getSensorByAddress($_GET['sensorip']); // "Trying to retrieve sensor by address
        //echo $sensor['id'];
        //echo $sensor['name'];
        //echo $sensor['address'];
        //echo $sensor['type'];
        //echo date(DATE_ATOM);
        
        $data = doubleval($_GET['data']);  //cast the data to double type. This will fail if the data is non-numeric
        $auxData = doubleval($_GET['aux']); //get the auxdata

        if(count($sensor, COUNT_NORMAL) > 0){ //tryna check if we got a sensor id back, if not this sensor isnt in the db.
            $id = doubleval($sensor['id']);
            echo "Server: Found sensor ID $id\n";
        } else {
           echo "Server: Sensor lookup failed. Is this sensor added to your list?";
           $id = 0;
        }
        
        
        if($id > 0){ //this prevents unwanted additions.
            echo "Server: Logging sensor data to database...\n";
            $date = exec('date'); //get system date
            $unixTime = time(); //get the unixtime


            //$date = new DateTime("now");
            $sensorsdb->addSensorData($id,$unixTime,$data,$auxData); //save sensor data, id,unixtime(date),data
            $setpoints = $sensorsdb->getSetpoints($id); //lookup setpoints for this sensor
            $sensorsdb->__destruct(); //disconnect?
            if(count($setpoints) <= 0){
                //no setpoints for this sensor
                echo "Server: Found no setpoints for this sensor.\n";
            } else {
                //var_dump($setpoints);
                /*
                0,relays.id,
                1,sensorSetpoints.setpoint,
                2,sensorSetpoints.type,
                3,sensorSetpoints.action,
                4,sensorSetpoints.id,
                5,relays.name as 'action'
                6,sensors.name 
                */
                //var_dump($setpoints);
                echo "Server: Found setpoints, what are they?\n";
                
                foreach($setpoints as $setpoint){
                    if($setpoint[2] == "HIGH"){
                        echo "Server: Found High Setpoint $setpoint[1]";
                        if($data >= $setpoint[1]){ //if we have hit or surpassed our setpoint
                            echo " is reached. ";
                            if($setpoint[3] === "911" || $setpoint[3] === "922" ){ //check for alarm
                                //do alarm code
                                echo "ALARM\n";

                                $text = "Alarm: Currently $setpoint[6] has exceeded high limit of $setpoint[1] at $data.";
                                echo $text;

                                //SEND ALARM CODE
                                $sensors->sendAlarm($setpoint[6],$data,$setpoint[1],$setpoint[4],$setpoint[3]);

                            } else {
                                //do relay code
                                $relay = $relays->relaysdb->getRelayById($setpoint[0]);
                                $relayState = '';
                                foreach ($relay as $z){
                                    $relayid = $z[0]; //get out the relayid
                                    $relayState = $z[3]; //and the state
                                    $relayAddress = $z[4]; //and the address
                                }
                                //check to see relay state matches what we want.
                                if($relayState == "on"){ //High SP is reached, turn off relay
                                    echo "Switching Relay $relayid Off. \n";
                                    $relays->switchRelay($relayid,$setpoint[3],"off",$relayAddress); //needs id,pin,state
                                } else { echo "Relay $relayid already $relayState. \n"; }
                            }
                        } else {
                            echo " is not reached. ";
                           if($setpoint[3] === "911" || $setpoint[3] === "922" ){ //check for alarm
                                //do nothing
                                echo "Not sending alarm. \n";
                            } else {
                                $relay = $relays->relaysdb->getRelayById($setpoint[0]);
                                $relayState = '';
                                foreach ($relay as $z){
                                    $relayid = $z[0]; //get out the relayid
                                    $relayState = $z[3]; //and the state
                                    $relayAddress = $z[4]; //and the address
                                }
                                //energize if de-energized
                                if($relayState == "off"){
                                    echo "Switching Relay $relayid On. \n";
                                    $relays->switchRelay($relayid,$setpoint[3],"on",$relayAddress); //needs id,pin,state
                                } else { echo "Relay $relayid already $relayState."; }
                            }
                        }
                    } else if ($setpoint[2] == "LOW") {
                        echo "Server: Found Low Setpoint $setpoint[1]";
                        if($data <= $setpoint[1]){ //if we have hit or surpassed our setpoint
                            echo " is reached. ";
                            if($setpoint[3] === "911" || $setpoint[3] === "922" ){ //check for alarm
                                //do alarm code
                                echo "ALARM\n";

                                $text = "Alarm: Currently $setpoint[6] has exceeded low limit of $setpoint[1] at $data.";
                                echo $text;

                                //SEND ALARM CODE
                                $sensors->sendAlarm($setpoint[6],$data,$setpoint[1],$setpoint[4],$setpoint[3]);


                            } else {
                                //do relay code
                                $relay = $relays->relaysdb->getRelayById($setpoint[0]);
                                $relayState = '';
                                foreach ($relay as $z){
                                    $relayid = $z[0]; //get out the relayid
                                    $relayState = $z[3]; //and the state
                                    $relayAddress = $z[4]; //and the address
                                }
                                if($relayState == "on"){
                                    echo "Switching Relay $relayid Off. \n";
                                    $relays->switchRelay($relayid,$setpoint[3],"off",$relayAddress); //needs id,pin,state
                                } else { echo "Relay $relayid already $relayState."; }
                            }
                        } else {
                            echo " is not reached. ";
                            if($setpoint[3] === "911" || $setpoint[3] === "922" ){ //check for alarm
                                //do nothing
                                echo "Not sending alarm. \n";
                            } else {
                                //do relay code
                                $relay = $relays->relaysdb->getRelayById($setpoint[0]);
                                $relayState = '';
                                foreach ($relay as $z){
                                    $relayid = $z[0]; //get out the relayid
                                    $relayState = $z[3]; //and the state
                                    $relayAddress = $z[4]; //and the address
                                }
                                //energize if de-energized
                                if($relayState == "off"){
                                    echo " Switching Relay $relayid On. \n";
                                    $relays->switchRelay($relayid,$setpoint[3],"on",$relayAddress); //needs id,pin,state
                                } else { echo "Relay $relayid already $relayState."; }
                            }
                        }
                    } 
                }
            }
        }
        $relays->relaysdb->__destruct();
    } else {
        echo "Server: Fail";
    }
}else{
 echo "Server: API Fail";
}
echo "\n\nfin";
?>