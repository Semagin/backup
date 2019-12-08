<?php
$start_time = microtime(true);
session_start();
if ($_POST) {
	$_SESSION['filesize'] = (!isset($_POST['filesize1'])) ? 2000000000 : $_POST['filesize1']; // default filesize for compatibility with 32-bit platforms
	$_SESSION['dbname'] = (!isset($_POST['dbname'])) ? 'magaz' : $_POST['dbname'];
	$_SESSION['dbhost'] = (!isset($_POST['dbhost'])) ? $_SERVER['HTTP_HOST'] : $_POST['dbhost'];// by default - db on the same host
	$_SESSION['dblogin'] = (!isset($_POST['dblogin'])) ? 'root': $_POST['dblogin']; //default on test db
	$_SESSION['dbpasswd'] = (!isset($_POST['dbpasswd'])) ? 'root' : $_POST['dbpasswd']; //default on test db
	$_SESSION['timeout'] = (!isset($_POST['timeout'])) ? 15 : $_POST['timeout'];	// half of max default timeout
	$_SESSION['rowsperquery'] = (!isset($_POST['rowsperquery'])) ? 100 : $_POST['rowsperquery'];	// hope, no records in db lager than 20 Mb for default file size
}
if (!isset($_SESSION['filesizesuffix'])) {
	$_SESSION['filesizesuffix']=0;
}
if (!isset($_SESSION['rowscount'])) {
	$_SESSION['rowscount']=0;
}
try {
	if (!isset($_SESSION['tblcount'])) { //initial setup table count and names
	 	$_SESSION['dblist']=[];
	  	$db = new PDO('mysql:host='.$_SESSION['dbhost'].';dbname='.$_SESSION['dbname'],$_SESSION['dblogin'], $_SESSION['dbpasswd']);
	 	$sql = "SELECT table_name , round(((data_length + index_length)"." / 1024 ), 2) `Size (Kb)` FROM information_schema.TABLES WHERE table_schema = "."\"".$_SESSION['dbname']."\"";
	 	$sth = $db->prepare($sql);
	 	$sth->execute();
	     while($row = $sth->fetch()){
	 		$_SESSION['dblist'][] = $row; //put each table name and size into array
		}
	    $_SESSION['tblcount'] = count($_SESSION['dblist']);
	 	print_r('backup in progress...');
	 	header("Location: " ."http://" . $_SERVER['HTTP_HOST'] . substr(__DIR__, strlen($_SERVER['DOCUMENT_ROOT'])).'/'. basename($_SERVER['SCRIPT_FILENAME']));
		die;  
	}
	$db = new PDO('mysql:host='.$_SESSION['dbhost'].';dbname='.$_SESSION['dbname'],$_SESSION['dblogin'], $_SESSION['dbpasswd']);
	while ($_SESSION['tblcount']>0) { //iterate for each table
		if (!isset($_SESSION['columnname'])) { //for the first time, set column names, inits vars for current table
		 	$_SESSION['rownumber']=0;		// reset current row
			$sql = "SELECT `COLUMN_NAME` FROM `INFORMATION_SCHEMA`.`COLUMNS` WHERE table_schema = \"".$_SESSION['dbname']."\" AND `TABLE_NAME`=\"".$_SESSION['dblist'][$_SESSION['tblcount']-1][0]."\"";
			$sth = $db->prepare($sql);
			$sth->execute();
		    while($row = $sth->fetch()){
				$_SESSION['columnname'][] = $row; // set column names list
		    }
		 	$sql = "SELECT COUNT(*) FROM ".$_SESSION['dblist'][$_SESSION['tblcount']-1][0];
	        $sth = $db->prepare($sql);
			$sth->execute();
		    while($row = $sth->fetch()){
	            $_SESSION['rowscount'] = $row[0];
		    }
		}
	    ($db->prepare('LOCK TABLE'.$_SESSION['dblist'][$_SESSION['tblcount']-1][0].'WRITE'))->execute();

	    $init_string = "REPLACE INTO `".$_SESSION['dblist'][$_SESSION['tblcount']-1][0]."` ("; 
	    foreach ($_SESSION['columnname'] as $name) { 
	        $init_string = $init_string."`".$name[0]."`, ";
	    }
	    $init_string = substr($init_string, 0, strlen($init_string)-2).") VALUES (";
	    while ($_SESSION['rownumber']<$_SESSION['rowscount']) {	//iterate over rows in one table
	    	$sql="SELECT * FROM ".$_SESSION['dblist'][$_SESSION['tblcount']-1][0]." LIMIT ".$_SESSION['rownumber'].", ".$_SESSION['rowsperquery'];
	 		$sth = $db->prepare($sql);
	    	$sth->execute();		
		    $ret = prepareSql($init_string, $sth);
		    if (strlen($ret)>$_SESSION['filesize']) {
		    	throw new Exception("Data size lager than file size", 1);
		    }
		    $fname = 'backup-'.date("Y-m-d H:i").'-'.$_SESSION['filesizesuffix'].'.sql';
		    // print_r(); die;
		    if (is_file($fname) && filesize($fname)+strlen($ret)>$_SESSION['filesize']) {
		 		$_SESSION['filesizesuffix'] +=1;
			    $fname = 'backup-'.date("Y-m-d H:i").'-'.$_SESSION['filesizesuffix'].'.sql';
		    }
		    $fd = fopen($fname, 'a');
		    fwrite($fd, $ret);
		    while(is_resource($fd)){
	   			fclose($fd);
			}
			clearstatcache();
	    	$_SESSION['rownumber'] = $_SESSION['rownumber']+$_SESSION['rowsperquery'];
	    	watchDog($start_time, $_SESSION['timeout']);
	    }
	    unset($_SESSION['columnname']);
	    unset($_SESSION['rownumber']);
	    unset($_SESSION['rowscount']);
	    $_SESSION['tblcount']--;
	}
	print_r('done!');
	die;
}
catch (Exception $e) {
    echo 'Got exception: ',  $e->getMessage(), "\n";
}
/**
 * check timeout
 * @param  int $start start time
 * @param  int $timeout server timeout
 * @param  string $location backup script filename
 */
function watchDog($start,  $timeout)
{
	if (microtime(true)-$start>$timeout) {
 		header("Location: " ."http://" . $_SERVER['HTTP_HOST'] . substr(__DIR__, strlen($_SERVER['DOCUMENT_ROOT'])).'/'. basename($_SERVER['SCRIPT_FILENAME']));
	die;   
	}
}
/**
 * @param  string $init_string
 * @param  PDOStatement $sth
 * @return string block of SQL queries 
 */
function prepareSql($init_string, $sth)
{
	$string_backup = '';
	while($row = $sth->fetch()){ //loop every row
		$string_backup .=$init_string;
		foreach ($_SESSION['columnname'] as $name) {
			if (is_null($row[$name[0]])) {
				$string_backup .= 'NULL';
			} else {
				$row[$name[0]] = str_replace("\n","\\n", addslashes($row[$name[0]]) );
			  	if (isset($row[$name[0]])){
			    	$string_backup .= '"'.$row[$name[0]].'"' ;
			  	}
			}
		  	if ($name[0]<(count($_SESSION['columnname'])-1)){
		    	$string_backup.= ',';
		  	}
		}
		$string_backup = substr($string_backup, 0, strlen($string_backup)-1).");\n";
	}
	return $string_backup;
}
?>

