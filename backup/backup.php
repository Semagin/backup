<?php

$start_time = microtime(true);
session_start();
if ($_POST) {
    $_SESSION['filesize'] = (!isset($_POST['filesize1'])) ? 2000000000 : $_POST['filesize1']; // default filesize for compatibility with 32-bit platforms
    $_SESSION['dbname'] = (!isset($_POST['dbname'])) ? 'magaz' : $_POST['dbname'];
    $_SESSION['dbhost'] = (!isset($_POST['dbhost'])) ? $_SERVER['HTTP_HOST'] : $_POST['dbhost'];// by default - db on the same host
    $_SESSION['dblogin'] = (!isset($_POST['dblogin'])) ? 'root': $_POST['dblogin']; //default on test db
    $_SESSION['dbpasswd'] = (!isset($_POST['dbpasswd'])) ? 'root' : $_POST['dbpasswd']; //default on test db
}
if (!isset($_SESSION['filesizesuffix'])) {
    $_SESSION['filesizesuffix']=0;
}
if (!isset($_SESSION['rowscount'])) {
    $_SESSION['rowscount']=0;
}
if (!isset($_SESSION['timestamp'])) {
    $_SESSION['timestamp']=date("Y-m-d H:i");
}
$fname = 'backup-'.$_SESSION['timestamp'].'-'.$_SESSION['filesizesuffix'].'.sql';
try {
    $db = new PDO('mysql:host='.$_SESSION['dbhost'].';dbname='.$_SESSION['dbname'], $_SESSION['dblogin'], $_SESSION['dbpasswd']);
    $dbi = new mysqli($_SESSION['dbhost'], $_SESSION['dblogin'], $_SESSION['dbpasswd'], $_SESSION['dbname']);
    if (!isset($_SESSION['tblcount'])) { //initial setup table count and names
        $_SESSION['dblist']=[];
        $sql = "SELECT table_name , round(((data_length + index_length)"." ), 2) `Size (Kb)` FROM information_schema.TABLES WHERE table_schema = "."\"".$_SESSION['dbname']."\"";
        $sth = $db->prepare($sql);
        $sth->execute();
        while ($row = $sth->fetch()) {
            $_SESSION['dblist'][] = $row; //put each table name and size into array
        }
        $_SESSION['tblcount'] = count($_SESSION['dblist']);
    }
    while ($_SESSION['tblcount']>0) { //iterate for each table
        if (!isset($_SESSION['columnname'])) { //for the first time, set column names, inits vars for current table
            $_SESSION['rownumber']=0;       // reset current row
            $sql = "SELECT `COLUMN_NAME` FROM `INFORMATION_SCHEMA`.`COLUMNS` WHERE table_schema = \"".$_SESSION['dbname']."\" AND `TABLE_NAME`=\"".$_SESSION['dblist'][$_SESSION['tblcount']-1][0]."\"";
            $sth = $db->prepare($sql);
            $sth->execute();
            while ($row = $sth->fetch()) {
                $_SESSION['columnname'][] = $row; // set column names list
            }
            $sql = "SELECT COUNT(*) FROM ".$_SESSION['dblist'][$_SESSION['tblcount']-1][0];
            $sth = $db->prepare($sql);
            $sth->execute();
            while ($row = $sth->fetch()) {
                $_SESSION['rowscount'] = $row[0];
            }
            $memory_limit = (ini_get('memory_limit')!='-1') ? (int)(ini_get('memory_limit'))*1024**(['k' =>1, 'm' => 2, 'g' => 3][strtolower(ini_get('memory_limit'))[-1]] ?? 0) : $_SESSION['filesize'];
            $rowsperquery = ceil($memory_limit*$_SESSION['rowscount'] / $_SESSION['dblist'][$_SESSION['tblcount']-1][1]);
            $_SESSION['rowsperquery']= $rowsperquery>100 ? 100 : $rowsperquery;
        }
        ($db->prepare('LOCK TABLE'.$_SESSION['dblist'][$_SESSION['tblcount']-1][0].'WRITE'))->execute();

        $init_string = "REPLACE INTO `".$_SESSION['dblist'][$_SESSION['tblcount']-1][0]."` (";
        foreach ($_SESSION['columnname'] as $name) {
            $init_string = $init_string."`".$name[0]."`, ";
        }
        $init_string = substr($init_string, 0, strlen($init_string)-2).") VALUES (";
        while ($_SESSION['rownumber']<$_SESSION['rowscount']) { //iterate over rows in one table
            $sql="SELECT * FROM ".$_SESSION['dblist'][$_SESSION['tblcount']-1][0]." LIMIT ".$_SESSION['rownumber'].", ".$_SESSION['rowsperquery'];
            $sth = $db->prepare($sql);
            $sth->execute();
            $ret = prepareSql($init_string, $sth, $_SESSION['columnname'], $dbi);
            if (strlen($ret)>$_SESSION['filesize']) {
                throw new Exception("Data size lager than file size", 1);
            }
            if (is_file($fname) && filesize($fname)+strlen($ret)>$_SESSION['filesize']) {
                $_SESSION['filesizesuffix'] +=1;
                $fname = 'backup-'.$_SESSION['timestamp'].'-'.$_SESSION['filesizesuffix'].'.sql';
            }
            $fd = fopen($fname, 'a');
            fwrite($fd, $ret);
            while (is_resource($fd)) {
                fclose($fd);
            }
            clearstatcache();
            $_SESSION['rownumber'] = $_SESSION['rownumber']+$_SESSION['rowsperquery'];
            $redirect_link = (isSSL() ? "https://" : "http://"). $_SERVER['HTTP_HOST'] . substr(__DIR__, strlen($_SERVER['DOCUMENT_ROOT'])).'/'. basename($_SERVER['SCRIPT_FILENAME']);
            watchDog($start_time, ini_get('max_execution_time'), $redirect_link);
        }
        unset($_SESSION['columnname']);
        unset($_SESSION['rownumber']);
        unset($_SESSION['rowscount']);
        $_SESSION['tblcount']--;
    }
    print_r('done!');
    die;
} catch (Exception $e) {
    echo 'Got exception: ',  $e->getMessage(), "\n";
}

/**
 * check timeout
 * @param  int $start start time
 * @param  int $timeout server timeout setting
 * @param  string $redirect_link
 */
function watchDog($start, $timeout, $redirect_link)
{
    if (microtime(true)-$start>$timeout) {
        header('Refresh: 0.1; URL='.$redirect_link);
        die;
    }
}

/**
 * @param  string       $init_string
 * @param  PDOStatement $sth
 * @param  array        $column
 * @return string block of SQL queries
 */
function prepareSql($init_string, $sth, $columns, $dbi)
{
    $string_backup = '';
    while ($row = $sth->fetch()) { //loop every row
        $string_backup .=$init_string;
        foreach ($columns as $name) {
            if (is_null($row[$name[0]])) {
                $string_backup .= 'NULL';
            } elseif (is_numeric($row[$name[0]])) {
                $string_backup .= $row[$name[0]];
            } else {
                $row[$name[0]] = $dbi->escape_string($row[$name[0]]);
                if (isset($row[$name[0]])) {
                    $string_backup .= '\''.$row[$name[0]].'\'' ;
                }
            }
            if ($name[0]<(count($columns)-1)) {
                $string_backup.= ',';
            }
        }
        $string_backup = substr($string_backup, 0, strlen($string_backup)-1).");\n";
    }
    return $string_backup;
}

/**
 * check https availability
 * @return boolean is aviable
 */
function isSSL()
{
    if (!empty($_SERVER['https'])) {
        return true;
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https') {
        return true;
    }
    return false;
}
