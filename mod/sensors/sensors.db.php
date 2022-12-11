<?php
/*
 * Created on Dec 20, 2020
 */

class sensorsdb extends ghotidb{
	public function __construct(){
		parent::__construct();
		parent::loadModuleSql("sensors");	
	}
	public function __destruct(){
		parent::__destruct();
	}
	public function getTypes(){
	//gets a list of the different types available. 
		try{
			$types = $this->adodb->GetArray("select distinct type from sensors");
		}catch (exception $e){
			ghoti::log("sensors.db.php $e");
			return $e->getMessage();
		}
		return $types;
	}
	function checkDupe($name,$address){
		try{
			$query = $this->adodb->Execute("select count(name) from sensors where name = ? or address = ?",array($name,$address));
			if (!$query) mylogerr($this->adodb->ErrorMsg());	
		}catch (exception $e){
			ghoti::log("sensors.db.php $e");
			return False;
		}
		if($query->fields[0] > 0){ //if number of records returned is greater than 0
			return True; //we have a dupe
		}
		return False; //if we made it this far, no dupes
	}
    function addSensor($name,$address,$type="dht"){
		try{
			$nonQuery = $this->adodb->Execute("insert into sensors(name,address,type) values(?,?,?)",array($name,$address,$type));
			if (!$nonQuery) mylogerr($this->adodb->ErrorMsg());	
		}catch (exception $e){
			ghoti::log("sensors.db.php $e");
			return False;
		}
		return True;
	}
    function addSensorData($sensorID,$date,$data){
		try{
			$nonQuery = $this->adodb->Execute("insert into sensorData(id,date,data) values(?,?,?)",array($sensorID,$date,$data));
			if (!$nonQuery) mylogerr($this->adodb->ErrorMsg());	
		}catch (exception $e){
			//ghoti::log("sensors.db.php $e");
			echo $e;
			return False;
		}
		return True;
	}
	function clearSensorData($sensorID){
        try{
			$nonQuery = $this->adodb->Execute("delete from sensorData where id=?;",array($sensorID));
			if (!$nonQuery) mylogerr($this->adodb->ErrorMsg());	
		}catch (exception $e){
			ghoti::log("sensors.db.php $e");
			return False;
		}
		return True;
	
	}
    function getSensorDataById($sensorID, $numRows=250){
        try{
			$Query = $this->adodb->GetArray("select sensorData.id,sensorData.date,sensorData.data,sensors.name,sensors.type from sensorData,sensors  where sensorData.id=sensors.id and sensorData.id=? order by date desc limit ?;",array($sensorID,$numRows));
			//if (!$Query) mylogerr($this->adodb->ErrorMsg());	
		}catch (exception $e){
			ghoti::log("sensors.db.php $e");
			return False;
		}
		return $Query;
	
	}
	function getSensorDataByIdToday($sensorID){
        try{
			$Query = $this->adodb->GetArray("select sensorData.id,sensorData.date,sensorData.data,sensors.name,sensors.type from sensorData,sensors  where sensorData.id=sensors.id and sensorData.id=? and date(date) = curdate() order by date;",array($sensorID));
			//if (!$Query) mylogerr($this->adodb->ErrorMsg());	
		}catch (exception $e){
			ghoti::log("sensors.db.php $e");
			return False;
		}
		return $Query;
	
	}
    function getSensorDataByIdThisMonth($sensorID){
        try{
			$Query = $this->adodb->GetArray("select sensorData.id,sensorData.date,sensorData.data,sensors.name,sensors.type from sensorData,sensors where sensorData.id=sensors.id and sensorData.id=? and month(date) = month(curdate()) order by date;",array($sensorID));
			//if (!$Query) mylogerr($this->adodb->ErrorMsg());	
		}catch (exception $e){
			ghoti::log("sensors.db.php $e");
			return False;
		}
		return $Query;
	
	}
    function getSensorDataByIdLastMonth($sensorID){
        try{
			$Query = $this->adodb->GetArray("select sensorData.id,sensorData.date,sensorData.data,sensors.name,sensors.type from sensorData,sensors  where sensorData.id=sensors.id and sensorData.id=? and month(date) = (month(curdate())- 1) order by date;",array($sensorID));
			//if (!$Query) mylogerr($this->adodb->ErrorMsg());	
		}catch (exception $e){
			ghoti::log("sensors.db.php $e");
			return False;
		}
		return $Query;
	
	}
    function deleteSensor($id){
        $this->clearSensorData($id);
        $this->clearSetpoints($id);
        try{
			$nonQuery = $this->adodb->Execute("delete from sensors where id=?",array($id));
			if (!$nonQuery) mylogerr($this->adodb->ErrorMsg());
		}catch (exception $e){
			ghoti::log("sensors.db.php $e");
			return False;
		}
	}
	public function getSensors(){
        try{
            $sensors = $this->adodb->GetArray("select id,name,address,type from sensors;");
		}catch (exception $e){
			ghoti::log("sensors.db.php $e");
			return $e->getMessage();
		}
		return $sensors; //return the fields here for a simpler array?
	
	}
    public function getSetpoints($id){
        try{
            //$setpoints = $this->adodb->GetArray("select id,setpoint,type,action from sensorSetpoints where id=?;",array($id));
            //$setpoints = $this->adodb->GetArray("select relays.id,sensorSetpoints.setpoint,sensorSetpoints.type,sensorSetpoints.action,sensorSetpoints.id,relays.name as 'action' from sensorSetpoints inner join relays on sensorSetpoints.action = relays.id where sensorSetpoints.id = ?;",array($id));
            $setpoints = $this->adodb->GetArray("select relays.id,sensorSetpoints.setpoint,sensorSetpoints.type,sensorSetpoints.action,sensorSetpoints.id,relays.name as 'action',sensors.name from sensorSetpoints,relays,sensors where sensorSetpoints.action = relays.id AND sensorSetpoints.id = sensors.id AND sensorSetpoints.id = ?",array($id));
		}catch (exception $e){
			ghoti::log("sensors.db.php $e");
			return $e->getMessage();
		}
		return $setpoints; //return the fields here for a simpler array?
	}
    function addSetpoint($id,$setpoint,$type,$action){
		try{
			$nonQuery = $this->adodb->Execute("insert into sensorSetpoints(id,setpoint,type,action) values(?,?,?,?)",array($id,$setpoint,$type,$action));
			if (!$nonQuery) mylogerr($this->adodb->ErrorMsg());	
		}catch (exception $e){
			ghoti::log("sensors.db.php $e");
			return False;
		}
    }
	function clearSetpoints($id){
        try{
			$nonQuery = $this->adodb->Execute("delete from sensorSetpoints where id=?",array($id));
			if (!$nonQuery) mylogerr($this->adodb->ErrorMsg());	
		}catch (exception $e){
			ghoti::log("sensors.db.php $e");
			return False;
		}
	}
	public function getSensorById($id){
	    try{
            $sensor = $this->adodb->GetArray("select id,name,address,type from sensors where id=?",array($id));
		}catch (exception $e){
			ghoti::log("sensors.db.php $e");
			return $e->getMessage();
		}
		return $sensor; //return the fields here for a simpler array?
	}
    public function getSensorByAddress($address){
	    try{
            $sensor = $this->adodb->GetArray("select id,name,address,type from sensors where address=?",array($address));
		}catch (exception $e){
			ghoti::log("sensors.db.php $e");
			return $e->getMessage();
		}
		if(count($sensor, COUNT_NORMAL) > 0){
            return $sensor[0];
        } else {
            return $sensor;
        }
	}
    function modifySensor($id,$name,$address,$type){
		try{
			$nonQuery = $this->adodb->Execute("update sensors set name=?,address=?,type=? where id=?",array($name,$address,$type,$id));
			if (!$nonQuery) ghoti::log($this->adodb->ErrorMsg());
		}catch (exception $e){
			ghoti::log("sensors.db.php $e");
			return False;
		}
		return True;
	}
	function setAlarmSent($id,$setpoint){
		try{
			$nonQuery = $this->adodb->Execute("update sensorSetpoints set action=922 where action=911 and id=? and setpoint=?",array($id,$setpoint));
			if (!$nonQuery) ghoti::log($this->adodb->ErrorMsg());
		}catch (exception $e){
			ghoti::log("sensors.db.php $e");
			return False;
		}
		return True;
	}
}
?>
