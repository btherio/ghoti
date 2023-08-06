    <?php
/*
 * Created on Dec 20, 2020
 * Sensors module sajax code
 */
function getUnits($type){
    $units = '';
    switch($type){
        case "dht":
            $units = 'C';
            break;
        case "ds18b20":
            $units = 'C';
            break;
        case "thermistor":
            $units = 'C';
            break;
        case "tds":
            $units = 'ppm';
            break;
        case "ph":
            $units = 'ph';
            break;
        case "moisture":
            $units = '%';
            break;
        case "refrig":
            $units = 'psig';
            break;
        case "relay":
            $units = 'powered';
            break;
    }
    return $units;
}
function addSensor($name,$address,$type){
    $_SESSION["ghotiObj"] = new ghoti();
	$_SESSION["ghotiObj"]->validate = new validate();
	//$_SESSION["ghotiObj"]->ghotiui = new ghotiui();
	$userId = checkLogin();
	try{
		$_SESSION["ghotiObj"]->validate->checkExists($name);
		$_SESSION["ghotiObj"]->validate->checkExists($address);
		$_SESSION["ghotiObj"]->validate->checkExists($type);
	}catch(Exception $e){
		ghoti::log("sensors.ajax.php: $e\n");
		return $e->getMessage();
	}
	$_SESSION["ghotiObj"]->log("Adding a new sensor $name of type $type at $address");
	
    $units = getUnits($type); //figure out correct units for the given type

	if($_SESSION["sensorsObj"]->sensorsdb->checkDupe($name,$address)){ // returns true if this is a duplicate
        $_SESSION["ghotiObj"]->log("Blocked attempt to add duplicate $type sensor $name at $address");
		return False;
    } else if (!$_SESSION["sensorsObj"]->sensorsdb->addSensor($name,$address,$type,$units)){ //if adding the sensor to the db fails
        $_SESSION["ghotiObj"]->log("Failed to add sensor.");		
        return False;
    } else {
        //succss!
        //$_SESSION["ghotiObj"]->log("Added a $type sensor called $name at $address");
        return True;
    }
    return False;
}
sajax_export("addSensor");

function getSensors(){
//returns list of sensors from database table,
    return array($_SESSION["sensorsObj"]->sensorsdb->getSensors());
}
sajax_export("getSensors");

function addSetpoint($id,$setpoint,$type,$action){
//returns list of sensors from database table,
    return array($_SESSION["sensorsObj"]->sensorsdb->addSetpoint($id,$setpoint,$type,$action));
}
sajax_export("addSetpoint");

function getSetpoints($id){
//returns list of setpoints from database table,
    return array($_SESSION["sensorsObj"]->sensorsdb->getSetpoints($id));
}
sajax_export("getSetpoints");


function clearSetpoints($id){
    return array($_SESSION["sensorsObj"]->sensorsdb->clearSetpoints($id));
}
sajax_export("clearSetpoints");

function deleteSensor($id){
	try{
		$_SESSION["ghotiObj"]->validate->checkNumber($id);
	}catch(Exception $e){
		ghoti::log("sensors.ajax.php: $e\n");
		return False;
	}
	$csp = array($_SESSION["sensorsObj"]->sensorsdb->clearSetpoints($id)); //clears sensor data
 	$_SESSION["sensorsObj"]->sensorsdb->deleteSensor($id);
	return True;
}
sajax_export("deleteSensor");

function saveSensor($id,$name,$address,$type){
    $units = '';
    switch($type){
        case "dht":
            $units = 'C';
            break;
        case "ds18b20":
            $units = 'C';
            break;
        case "thermistor":
            $units = 'C';
            break;
        case "tds":
            $units = 'ppm';
            break;
        case "ph":
            $units = 'ph';
            break;
        case "moisture":
            $units = '%';
            break;
        case "refrig":
            $units = 'psig';
            break;
        case "relay":
            $units = 'powered';
            break;
    }

    try{
		$_SESSION["ghotiObj"]->validate->checkNumber($id);
		$_SESSION["ghotiObj"]->validate->checkExists($name);
		$_SESSION["ghotiObj"]->validate->checkExists($address);
		$_SESSION["ghotiObj"]->validate->checkExists($type);
		$_SESSION["ghotiObj"]->validate->checkExists($units);
	}catch(Exception $e){
		ghoti::log("sensors.ajax.php: $e\n");
		return False;
	}
	$_SESSION["ghotiObj"]->log("Saving sensor($id:$name:$address:$type:$units)");

	return $_SESSION["sensorsObj"]->sensorsdb->modifySensor($id,$name,$address,$type,$units);
}
sajax_export("saveSensor");


function checkAP(){
    //$_SESSION["ghotiObj"]->log("Checking ap: " . $_SESSION['sensorsObj']->checkAP());
    return $_SESSION["sensorsObj"]->checkAP();
}
sajax_export("checkAP");

function listAP(){
    $listAP = array();
    $query = $_SESSION["sensorsObj"]->listAP();
    foreach ( $query as $k => $x){ //iterate through array and explode strings by spaces, into arrays in an array.
        $listAP[$k] = explode(" ", $x);
    }
    return $listAP;
}
sajax_export("listAP");

function readSensors(){
    $sensorData = array();
    $curlData = "";
    $result = array();
    try{
        $result = array($_SESSION["sensorsObj"]->sensorsdb->getSensors()); //get a list of sensors from the database
    } catch(Exception $e){
        ghoti::log("sensors.ajax.php: $e\n");
		return False;
    }
    foreach($result[0] as $k => $x){
        //ghoti::log("result[0]". $k ."->".$x." ");
        //readout database sensors into this new array
        $sensorData[$k][0] = $x[0]; //id
        $sensorData[$k][1] = $x[1]; //name
        $sensorData[$k][2] = $x[2]; //address
        $sensorData[$k][3] = $x[3]; //type
        $sensorData[$k][4] = $x[4]; //units
        $ch = curl_init(); //we're going to use this curlhandle to fetch the sensor information from each sensor
        // set URL and other appropriate options
        //curl_setopt($ch, CURLOPT_HTTP_CONTENT_DECODING, False);
        //curl_setopt($ch, CURLOPT_VERBOSE, True);//verbose output
        //curl_setopt($ch, CURLINFO_HEADER_OUT, True); //track handle's request string
        curl_setopt($ch, CURLOPT_URL, "http://".$x[2]."/HTML/");
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3); // was 3 
        curl_setopt($ch, CURLOPT_TIMEOUT, 3); //timeout in seconds
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, True);
        // fetch that shit and save it into the array
        if(!$curlData = curl_exec($ch)){ //fetch that shit
            $sensorData[$k][4] = "<img src=\"mod/sensors/disconnect.png\" height=\"36\" width=\"36\" alt=\"Connection Error!\" />"; //if it doesnt fetch, it's a connection error
            //$sensorData[$k][4] = $curlData; //write new data to array
        } else {
            $sensorData[$k][4] = $curlData; //write new data to array
        }
        // close cURL resource, and free up system resources
        curl_close($ch);
    }
    return $sensorData;
}
sajax_export("readSensors");

function readSensorsFromDB(){
    /**
     * New function to replace above. Instead of reading sensor data direct from sensors. Read sensordata from database.
     * This allows unreachable sensors to still display their last known data.
     * We will *also* request curl data from the sensors to let us know if they're online or offline.
     */
    $sensorData = array();
    $curlD = '';
    $result = array();
    try{
        $result = array($_SESSION["sensorsObj"]->sensorsdb->getSensors()); //get a list of sensors from the database
        foreach($result[0] as $k => $x){
        //ghoti::log("result[0]". $k ."->".$x." ");
        //readout database sensors into this new array
        $sensorData[$k][0] = $x[0]; //id
        $sensorData[$k][1] = $x[1]; //name
        $sensorData[$k][2] = $x[2]; //address
        $sensorData[$k][3] = $x[3]; //type
        $sensorData[$k][4] = $x[4]; //units
        $sensorData[$k][5] = ""; //data field for inside html div
        $sensorData[$k][6] = $_SESSION["sensorsObj"]->sensorsdb->getLatestData($x[0]); //get laest sensor data from database by id

        $htmlSensorData = $sensorData[$k][6][0][1]; //sensor data
        $htmlDataDate = $sensorData[$k][6][0][0]; //sensor data date
        $sensorData[$k][5] = "<sup>".$htmlDataDate."</sup><br /><span alt=\"\">".$htmlSensorData." ".$sensorData[$k][4]."</span>"; //tease out the sensors data. Primary data only for now, aux WIP
        
        $ch = curl_init(); //we're going to use this curlhandle to fetch the sensor information from each sensor
        // set URL and other appropriate options
        //curl_setopt($ch, CURLOPT_HTTP_CONTENT_DECODING, False);
        //curl_setopt($ch, CURLOPT_VERBOSE, True);//verbose output
        //curl_setopt($ch, CURLINFO_HEADER_OUT, True); //track handle's request string
        curl_setopt($ch, CURLOPT_URL, "http://".$x[2]."/HTML/");
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); // was 3 
        curl_setopt($ch, CURLOPT_TIMEOUT, 10); //timeout in seconds
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, True);
        // fetch that shit and save it into the array
        if(!$curlD = curl_exec($ch)){ //fetch that shit
            $sensorData[$k][7] = 0; //Return false, sensor is offline
        } else {
            $sensorData[$k][7] = 1; //Return true to indicate sensor is online
        }
    }
    } catch(Exception $e){
        ghoti::log("sensors.ajax.php: $e\n");
		return False;
    }
    return $sensorData;
}
sajax_export("readSensorsFromDB");

function getSensorDataById($id,$numRows=500){
    $sensorData = $_SESSION["sensorsObj"]->sensorsdb->getSensorDataById($id,$numRows);
    return $sensorData;
}
sajax_export("getSensorDataById");

function getSensorDataByIdToday($id){
    $sensorData = $_SESSION["sensorsObj"]->sensorsdb->getSensorDataByIdToday($id);
    return $sensorData;
}
sajax_export("getSensorDataByIdToday");

function getSensorDataByIdThisMonth($id){
    $sensorData = $_SESSION["sensorsObj"]->sensorsdb->getSensorDataByIdThisMonth($id);
    return $sensorData;
}
sajax_export("getSensorDataByIdThisMonth");


function getSensorDataByIdLastMonth($id){
    $sensorData = $_SESSION["sensorsObj"]->sensorsdb->getSensorDataByIdLastMonth($id);
    return $sensorData;
}
sajax_export("getSensorDataByIdLastMonth");

function clearSensorData($sensorID){
    
    return $_SESSION['sensorsObj']->sensorsdb->clearSensorData($sensorID);
}

sajax_export("clearSensorData");
?>
