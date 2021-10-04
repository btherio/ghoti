<?php

/*
* ping script for sensors to report their sensor data to the server for logging and setpoints.
* usage: ping.php?apikey=<apikey>&ip=<sensorip>&data=<data
* link this script from module directory to root cms directory `ln -sf lib/mod/sensors/ping.php sensorPing.php`
*
*/
include_once("ghoti.php"); //include ghoti
include_once("ghoti.db.php"); //include ghotidb
include_once("mod/sensors/sensors.db.php"); //include sensorsdb
include_once("mod/relays/relays.php"); //include relays

$apikey = "ggg";
if($_GET && $_GET['apikey'] == $apikey){    // check get variable, and check apikey matches. This is our only rudimentary security
    $ghoti = new ghoti();
    $ghotidb = new ghotidb();
    $sensorsdb = new sensorsdb();
    $relays = new relays();
    date_default_timezone_set('America/Edmonton');
    
    if($_GET['sensorip'] && $_GET['data']){   // check additional variables
    //get sensor by ip
    
        $sensor = $sensorsdb->getSensorByAddress($_GET['sensorip']); // "Trying to retrieve sensor by address
        //echo $sensor['id'];
        //echo $sensor['name'];
        //echo $sensor['address'];
        //echo $sensor['type'];
        //echo date(DATE_ATOM);
        
        $data = doubleval($_GET['data']);  //cast the data to double type. This will fail if the data is non-numeric
        
        if(count($sensor, COUNT_NORMAL) > 0){ //tryna check if we got a sensor id back, if not this sensor isnt in the db.
            $id = doubleval($sensor['id']);
        } else {
           echo "Server: Sensor lookup failed. Is this sensor added to your list?";
           $id = 0;
        }
        
        
        if($id > 0){ //this prevents unwanted additions.
            $sensorsdb->addSensorData($id,date(DATE_ATOM),$data); //save sensor data, id,date,data
            $setpoints = $sensorsdb->getSetpoints($id); //lookup setpoints for this sensor

            if(count($setpoints) <= 0){
                //no setpoints for this sensor
                echo "Server: Found no setpoints for this sensor.";
            } else {
                //var_dump($setpoints);
                /*
                0,relays.id,
                1,sensorSetpoints.setpoint,
                2,sensorSetpoints.type,
                3,sensorSetpoints.action,
                4,sensorSetpoints.id,
                5,relays.name as 'action'
                */
                //var_dump($setpoints);
                foreach($setpoints as $setpoint){
                    $relay = $relays->relaysdb->getRelayById($setpoint[0]);
                    //var_dump($relay);
                    $relayState = '';
                    
                    //problem is here somewhere.
                    
                    foreach ($relay as $z){
                        $relayid = $z[0]; //get out the relayid
                        $relayState = $z[3]; //and the state
                    }
                    
                    //echo $relayid . ", " . $relayState . ", ";
                    
                    if($setpoint[2] == "HIGH"){
                        echo "Found High Setpoint $setpoint[1]";
                        if($data >= $setpoint[0]){ //if we have hit or surpassed our setpoint
                            echo " is reached. ";
                            if($setpoint[3] === "911"){ //check for alarm
                                //do alarm code
                                echo "ALARM setpoint. \n";
                            } else {
                                //do relay code
                                //check to see relay state matches what we want.
                                if($relayState == "on"){ //High SP is reached, turn off relay
                                    echo "Switching Relay $relayid Off. \n";
                                    $relays->switchRelay($relayid,$setpoint[3],"off"); //needs id,pin,state
                                } else { echo "Relay $relayid already $relayState. \n"; }
                            }
                        } else {
                            echo " is not reached. ";
                            if($setpoint[3] === "911"){ //check for alarm
                                //do nothing
                                echo "Not sending alarm. \n";
                            } else {
                                //energize if de-energized
                                if($relayState == "off"){
                                    echo "Switching Relay $relayid On. \n";
                                    $relays->switchRelay($relayid,$setpoint[3],"on"); //needs id,pin,state
                                } else { echo "Relay $relayid already $relayState."; }
                            }
                        }
                    } else if ($setpoint[2] == "LOW") {
                        echo "Found Low Setpoint $setpoint[0]";
                        if($data <= $setpoint[0]){ //if we have hit or surpassed our setpoint
                            echo " is reached. ";
                            if($setpoint[3] === "911"){ //check for alarm
                                //do alarm code
                                echo "ALARM setpoint. \n";
                            } else {
                                //do relay code
                                if($relayState == "on"){
                                    echo "Switching Relay $relayid Off. \n";
                                    $relays->switchRelay($relayid,$setpoint[3],"off"); //needs id,pin,state
                                } else { echo "Relay $relayid already $relayState."; }
                            }
                        } else {
                            echo " is not reached. ";
                            if($setpoint[3] === "911"){ //check for alarm
                                //do nothing
                                echo "Not sending alarm. \n";
                            } else {
                                //energize if de-energized
                                if($relayState == "off"){
                                    echo " Switching Relay $relayid On. \n";
                                    $relays->switchRelay($relayid,$setpoint[3],"on"); //needs id,pin,state
                                } else { echo "Relay $relayid already $relayState."; }
                            }
                        }
                    } 
                }
            }
        }
    } else {
        echo "Server: Fail";
    }
}else{
 echo "Server: API Fail";
}
?>
