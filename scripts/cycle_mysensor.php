<?php

chdir(dirname(__FILE__) . '/../');

include_once("./config.php");
include_once("./lib/loader.php");
include_once("./lib/threads.php");

set_time_limit(0);

// connecting to database
$db = new mysql(DB_HOST, '', DB_USER, DB_PASSWORD, DB_NAME);
 
include_once("./load_settings.php");
include_once(DIR_MODULES . "control_modules/control_modules.class.php");
include_once(DIR_MODULES . 'mysensor/mysensor.class.php');

set_time_limit(0);

$ms = new mysensor();
$ms->getConfig();

echo date("H:i:s") . " running " . basename(__FILE__) . PHP_EOL;

if ($ms->config['MS_CONTYPE'] == 1){
  require("./modules/mysensor/phpMSCom.php");
	
	$ser = '/dev/ttyMySensorsGateway';
	if ($ms->config['MS_SERIAL'])
	{
		 $ser = $ms->config['MS_SERIAL'];
	}
	
	$ms_client = new MySensorMasterCom($ser);
} else {
	require("./modules/mysensor/phpMSTcp.php");
	
	$host = 'localhost';
	if ($ms->config['MS_HOST'])
	{
		 $host = $ms->config['MS_HOST'];
	}

	if ($ms->config['MS_PORT'])
	{
		 $port = $ms->config['MS_PORT'];
	} else {
		 $port = 5003;
	} 	
	
	$ms_client = new MySensorMasterTcp($host, $port);
}	

if (!$ms_client->connect())
{
   exit(1);
}

$params = array(  
  "presentation" => "doPresentation",
  "set" => "doSet",
  "req" => "doReq",
  "internal" => "doInternal",
    
  //"stream" => "doStream"
);
$ms_client->subscribe($params);

//=== Req values ===
$rec=SQLSelect("SELECT * FROM msnodeval WHERE req=1;");   
$total=count($rec);
if ($total) {
	for($i=0;$i<$total;$i++) {
		$ms->cmd($rec[$i]['NID'].";".$rec[$i]['SID'].";2;".$rec[$i]['ACK'].";".$rec[$i]['SUBTYPE'].";");		
	}
}   

//== Cycle ===
$previousMillis = 0;
while (true){
	//echo "Process\n";
	
//	echo  date("Y-m-d H:i:s u")." Proc start\n";
	
	$ret = $ms_client->proc();
	if ($ret === false)
	{
		echo "Error process\n";
		
		sleep(5);	// Sleep 5 seconds
		continue;
	}
	
//	echo  date("Y-m-d H:i:s u")." Send\n";
	
	// Send
  $rec=SQLSelectOne("SELECT * FROM mssendstack;");   
  if ($rec['ID']) {     
    //echo "Send: ".print_r($rec, true)."\n";
    $expire = $rec['EXPIRE'] < time();
      
    // Del not ACK packet
    if (($rec['ACK'] == 0) || $expire)        
      SQLExec("DELETE FROM mssendstack WHERE ID='".$rec['ID']."'");
      
    if ($expire){
      $sens=SQLSelectOne("SELECT * FROM msnodeval WHERE NID='".$rec['NID']."' AND SID='".$rec['SID']."' AND SUBTYPE='".$rec['SUBTYPE']."';"); 
      if ($sens['LINKED_OBJECT'] && $sens['LINKED_PROPERTY']) {
        echo "Expire send set rollback : ".$sens['LINKED_OBJECT'].'.'.$sens['LINKED_PROPERTY']."=".$sens['VAL']."\n";
        // Rollback value if not comin
        setGlobal($sens['LINKED_OBJECT'].'.'.$sens['LINKED_PROPERTY'], $rec['VAL'], array($ms->name=>'0'));
      }
    } else {        
      // Send
      $ms_client->send($rec['NID'], $rec['SID'], $rec['MType'], $rec['ACK'], $rec['SUBTYPE'], $rec['MESSAGE']);
    }
  }
	
//	echo  date("Y-m-d H:i:s u")." system\n";
	
	$currentMillis = round(microtime(true) * 10000);   
  if ($currentMillis - $previousMillis > 10000)
  {
    $previousMillis = $currentMillis;
   
    setGlobal((str_replace('.php', '', basename(__FILE__))) . 'Run', time(), 1);
  
    if (file_exists('./reboot') || file_exists('./stop_mysensor') || $_GET['onetime'])
    {
      $db->Disconnect();
	    echo "Stop cycle\n";
      exit;
    }
  } 
  usleep(50000);	
//	echo  date("Y-m-d H:i:s u")." End\n";
}

/**
 * Process message
*/
function LogMsg($title, $arr){
  echo date("Y-m-d H:i:s")." $title: Node:$arr[0]; Sensor:$arr[1]; Type:$arr[2]; Ack:$arr[3]; Sub:$arr[4]; Msg:$arr[5]\n";
}

function doPresentation($arr)
{
  global $ms;
  $ms->Presentation($arr);
  LogMsg("Presentation", $arr);  
}
  
function doSet($arr)
{
  global $ms;
  $ms->Set($arr);
  LogMsg("Set", $arr);
}

function doReq($arr)
{
  global $ms;
  LogMsg("Req", $arr);
  return $ms->Req($arr);  
}

function doInternal($arr)
{
  global $ms;  
  $ms->Internal($arr);  
  LogMsg("Internal", $arr);
}
    
$ms_client->close();  

$db->Disconnect(); // closing database connection 
 
DebMes("Unexpected close of cycle: " . basename(__FILE__));
 
?>