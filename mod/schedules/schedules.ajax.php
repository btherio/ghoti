<?php
/*
 * Created on Jan 17, 2021
 * schedules module sajax code
 */
function cronjob_exists($command){
    //checks to see if the $command exists in the crontab
    $cronjob_exists=false;
    exec('crontab -l', $crontab);//gets the crontab
    if(isset($crontab)&&is_array($crontab)){ //checks return variable
        $crontab = array_flip($crontab); //lines were returned so array_flip ?
        if(isset($crontab[$command])){ //check for command 
            $cronjob_exists=true; //command exists
        }
    }
    return $cronjob_exists;
}

function addSchedule($schedule,$pin,$state,$seconds="0",$address){
    //for testing
    ghoti::log("$schedule,$pin,$state,$seconds,$address");
	$userId = checkLogin();
	try{
		$_SESSION["ghotiObj"]->validate->checkExists($schedule);
		$_SESSION["ghotiObj"]->validate->checkExists($pin);
		$_SESSION["ghotiObj"]->validate->checkExists($state);
	}catch(Exception $e){
		ghoti::log("schedules.ajax.php: $e\n");
		return $e->getMessage();
	}
	$job = "$schedule /srv/http/smartend/mod/relays/gpio-relay.sh $pin $state $seconds $address >> /dev/null 2>&1";
    
    ghoti::log("addJob: $job");
    
    if (!cronjob_exists($job)) { //check if this cronjob exists, if it doesnt...
        exec('echo -e "`crontab -l`\n' . $job . '" | crontab -', $output); //add the new cronjob
    } else {
        ghoti::log("schedules.ajax.php: Cron job exists.\n");
		return False;
    }
    return True;
}
sajax_export("addSchedule");


function getSchedules(){
    //returns lines from /var/spool/cron/http
    //return file("mod/schedules/cron");
    exec('crontab -l', $data);
    return $data;
}
sajax_export("getSchedules");

function deleteSchedule($job){
 //remove line from /var/spool/cron/http
   $userId = checkLogin();
   if($userId > 0){
        exec('crontab -l', $data); //gets the crontab
        $key = array_search($job, $data); //finds the job
        if($key !== false){
            unset($data[$key]); // the job was found, so remove it
            exec('echo "'.implode("\n", $data).'" | crontab -'); // put the data back into the crontab
            return True;
        }
    }
    return False;
}
sajax_export("deleteSchedule");
?>
