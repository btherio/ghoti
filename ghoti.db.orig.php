<?php
/*
 * Created on Mar 1, 2009
 *
 */
include_once('lib/adodb5/adodb.inc.php');
include_once('lib/adodb5/adodb-exceptions.inc.php');

class ghotidb{
	/*This is where you setup your database.
	 *format $dsn = 'mysql://username:password@server/database';
	 *You could probably hook this up to another type of database
	 *but I've only tested ghoti with mysql.
	 */	
	private $dsn = 'mysqli://ghoti:ghoti@10.0.0.198/ghoti';
	

	//declarations. for typing practice.
	public $adodb,$m_id,$m_title,$m_content,$m_pageList,$m_group;
	private $insertsql,$tablesql;

	function __construct(){
		//connect to db
		try{
            if($this->adodb = NewADOConnection($this->dsn)){
                $this->loadModuleSql("pages");
                }
            }catch (exception $e){
                ghoti::log("DB Connection Error!");
                ghoti::log("ghoti.db.php $e");
				return false;
            }
	}

	function __destruct(){
		//close our connection to db
		if($this->adodb)
    		$this->adodb->Close();
	}
	function loadModuleSql($moduleName){
        if(!$this->adodb){
                 try{
            if($this->adodb = NewADOConnection($this->dsn)){
                $this->loadModuleSql("pages");
                }
            }catch (exception $e){
                ghoti::log("ghoti.db.php $e");
				return false;
            }   
        }
        
        //Check to see if our table exists in the database, if it doesn't add it.
		try{
			$query = $this->adodb->Execute("select * from $moduleName;");
		}catch (exception $e){
			//If there's an exception, it's probably because the table doesn't exist.
			//Loads the sql file, should contain 'create table if not exists' statements only
			//Of course, all this gets skipped if the table exists (ie:the above query doesn't throw an exception)
			try{
				if($file = fopen("mod/$moduleName/$moduleName.sql", "r")){
					while(!feof($file)) { 
						//read file line by line into variable 
			  			//$tablesql = $tablesql . fgets($file, 4096);
						$this->tablesql .= fgets($file, 4096);

					} 
					fclose ($file);
				} else {
					throw new Exception('Failed to open table sql file.');
				}
				if($file = fopen("mod/$moduleName/insert.sql", "r")){
					while(!feof($file)) { 
						//read file line by line into variable 
			  			$this->insertsql = $this->insertsql . fgets($file, 4096);
					} 
					fclose ($file);
				} else {
					throw new Exception('Failed to open insert sql file.');
				}
			}catch (exception $e){
				ghoti::log("ghoti.db.php $e");
				return false;
			}
			//File is loaded. Now we want to put it into the database
			try{
				if (!$this->adodb->Execute($this->tablesql)) 
					mylogerr($this->adodb->ErrorMsg());
				if (!$this->adodb->Execute($this->insertsql)) 
					mylogerr($this->adodb->ErrorMsg());	
			}catch (exception $e){
				ghoti::log("ghoti.db.php $e");
				return false;
			}
		}
	}
	function addPage($m_title,$m_content="Under Construction"){
		try{
			$query = $this->adodb->Execute("insert into pages (title, content) values(?,?)",array($m_title,$m_content));
			if (!$query) mylogerr($adodb->ErrorMsg());	
		}catch (exception $e){
			ghoti::log("ghoti.db.php $e");
			return false;
		}
		return true;
	}
	function deletePage($m_id){
		try{
			$nonquery1 = $this->adodb->Execute("delete from pages where id=?",array($m_id));
			if (!$nonquery1) mylogerr($this->adodb->ErrorMsg());	
			$nonquery2 = $this->adodb->Execute("delete from comments where pageId=?",array($m_id));
			if (!$nonquery2) mylogerr($this->adodb->ErrorMsg());	
		}catch (exception $e){
			ghoti::log("ghoti.db.php $e");
			return false;
		}
		return true;
	}
	function getPageList($group="public"){
		try{
			$m_pageList = $this->adodb->Execute("select id,title from pages where groupName=?",array($group));
			if (!$m_pageList) mylogerr($this->adodb->ErrorMsg());	
		}catch (exception $e){
			ghoti::log("ghoti.db.php $e");
			return false;
		}
		return $m_pageList;
	}	
	function getDefaultPage(){
		try{
			//$m_content = $this->adodb->GetArray("select content,min(id),groupName as id,title,groupName from pages where groupName = 'public';");
			$m_content = $this->adodb->GetArray("select content,id from pages where groupName ='public' limit 1;");
			
			if(!$m_content) mylogerr($this->adodb->ErrorMsg());
		}catch (exception $e){
			ghoti::log("ghoti.db.php $e");
			return false;
		}
		return $m_content;
	}
	function savePage($m_id,$m_content,$m_title){
		try{
			$nonquery = $this->adodb->Execute("update pages set content=?,title=? where id=?",array($m_content,$m_title,$m_id));
			if (!$nonquery) mylogerr($this->adodb->ErrorMsg());	
		}catch (exception $e){
			ghoti::log("ghoti.db.php $e");
			return false;
		}
		return true;
	}
	function getPageById($m_id){
		try{
			$m_content = $this->adodb->GetArray("select content,title,groupName from pages where id=?",array($m_id));	
		}catch (exception $e){
			ghoti::log("ghoti.db.php $e");
			return false;
		}
		return $m_content;
	}	
	function getPageByTitle($m_title){
		try{
			$m_content = $this->adodb->GetArray("select content,id,title from pages where title=?",array($m_title));
		}catch (exception $e){
			ghoti::log("ghoti.db.php $e");
			return false;
		}
		return $m_content;
	}
	function getPageGroup($m_id){
		try{
			$m_group = $this->adodb->GetArray("select groupName from pages where id=?",array($m_id));
		}catch (exception $e){
			ghoti::log("ghoti.db.php $e");
			return false;
		}
		return $m_group;
	}
	function setPageGroup($m_id,$m_group){
		try{
			if (!$this->adodb->Execute("update pages set groupName=? where id=?",array($m_group,$m_id)))
				mylogerr($this->adodb->ErrorMsg());	
		}catch (exception $e){
			ghoti::log("ghoti.db.php $e");
			return false;
		}
		return true;
	}
}

?>
