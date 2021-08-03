<?php
/*
 * Created on Jan 17, 2021
 * schedules module sajax code
 */
function cronjob_exists($command){
    $cronjob_exists=false;
    exec('crontab -l', $crontab);
    if(isset($crontab)&&is_array($crontab)){
        $crontab = array_flip($crontab);
        if(isset($crontab[$command])){
            $cronjob_exists=true;
        }
    }
    return $cronjob_exists;
}

function addSchedule($schedule,$pin,$state){
	$userId = checkLogin();
	try{
		$_SESSION["ghotiObj"]->validate->checkExists($schedule);
		$_SESSION["ghotiObj"]->validate->checkExists($pin);
		$_SESSION["ghotiObj"]->validate->checkExists($state);
	}catch(Exception $e){
		ghoti::log("schedules.ajax.php: $e\n");
		return $e->getMessage();
	}
	$job = "$schedule /srv/http/ghotiCMS/mod/relays/gpio-relay.sh $pin $state > /dev/null 2>&1\n\r";
    
    //old code to add job line to file.
    //file_put_contents("mod/schedules/cron", "$schedule /srv/http/ghotiCMS/mod/relays/gpio-relay.sh $pin $state > /dev/null 2>&1\n\r", FILE_APPEND);
    
    
    if (!cronjob_exists($job)) {
        //add job to crontab
        exec('echo -e "`crontab -l`\n' . $job . '" | crontab -', $output);
        ghoti::log($output);
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
    /*
    $contents = file_get_contents("mod/schedules/cron");
    $contentToRemove = explode("\n", $contents);
    $contentToRemove = $contentToRemove[$line];
    $contentToRemove .= "\n\r";
    ghoti::log("Removing... $contentToRemove");
    $contents = str_replace($contentToRemove, '', $contents);
    
    file_put_contents("mod/schedules/cron", $contents);
    */
    
    exec('crontab -l', $data);
    $key = array_search($job, $data);
    if($key !== false){
        // the job was found, so remove it
        unset($data[$key]);
        // put the data back into the crontab
        exec('echo "'.implode("\n", $data).'" | crontab -');
        return True;
    }
    return -2;
}
sajax_export("deleteSchedule");
?>
