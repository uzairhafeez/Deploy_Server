#!/usr/bin/php
<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');
require_once('deployFuncLib.inc');

function logMessage($request)
{
	$logFile = fopen("log.txt", "a");
	fwrite($logFile, $request['message'] .'\n\n');
		return true;
}
      	
function getNextPackageNum($request)
{
	$db = new DeployFuncLib();
	$db->connect();
	$response = $db->nextPackageChecker($request['packageName']);
   	return $response;	
}

function updatePackage($request)
{
	$db = new DeployFuncLib();
        $db->connect();
        $response = $db->updateVersion($request['packageName'], $request['versionNum']);
        return $response;
}

function deployPackage($request)
{
	
	$json = file_get_contents('./virtualMachines.json');
	$decodedJson = json_decode($json, true);

	$targetMachine = ($decodedJson[$request['package']][$request['tier']] );
	$targetIp = $targetMachine['ip'];

	$db = new DeployFuncLib();
	$db->connect();
	$packageNum = $db->nextPackageChecker($request['packageName']);
	$packageNum -= 1;
	$package = $request['packageName'] . $packageNum;

	$scpPackage = 'scp -r /home/uzair/Packages/' . $package . '.tar.gz ' . $targetMachine['username'] . '@' . $targetIp . ':/home/' . $targetMachine['username'] . '/temp';
	
	shell_exec($scpPackage);
	

	if(($request['package'] == 'BE') && ($request['tier']  == 'qa'))
		$packageExchange = "backendQA";
	
	elseif(($request['package'] == 'BE') && ($request['tier']  == 'prod'))
                $packageExchange = "backendProd";

	elseif(($request['package'] == 'FE') && ($request['tier']  == 'qa'))
                $packageExchange = "frontendQA";

	elseif(($request['package'] == 'FE') && ($request['tier']  == 'prod'))
                $packageExchange = "frontendProd";

	elseif(($request['package'] == 'API') && ($request['tier']  == 'qa'))
                $packageExchange = "apiQA";

        elseif(($request['package'] == 'API') && ($request['tier']  == 'prod'))
                $packageExchange = "apiProd";	

	
	else
		$packageExchange = "deployServer";

	$client = new rabbitMQClient("deployRabbitServer.ini", $packageExchange);
	$deployReq = array();
       //	$deployReq['type'] = 'deployPackage';
	$deployReq['type'] = $request['package'] . $request['tier'];
	$deployReq['version'] = $packageNum;
	$deployReq['packageName'] = $request['packageName'];
	$deployReq['packageTar'] = $request['packageName'] . $packageNum . ".tar.gz";

	$client->publish($deployReq);	


  
}

function rollbackPackage($request)
{

	$json = file_get_contents('./virtualMachines.json');
        $decodedJson = json_decode($json, true);

        $targetMachine = ($decodedJson[$request['package']][$request['tier']] );
        $targetIp = $targetMachine['ip'];

        $db = new DeployFuncLib();
        $db->connect();
        $packageNum = $db->nextPackageChecker($request['packageName']);
        $packageNum = $packageNum - 2;
        $package = $request['packageName'] . $packageNum;
        
        $scpPackage = 'scp -r /home/uzair/Packages/' . $package . '.tar.gz ' . $targetMachine['username'] . '@' . $targetIp . ':/home/' . $targetMachine['username'] . '/temp';
	shell_exec($scpPackage);
	
	$client = new rabbitMQClient("deployRabbitServer.ini","deployServer");
        $rollbackReq = array();
        $rollbackReq['type'] = 'deployPackage';
        $rollbackReq['version'] = $packageNum;
        $rollbackReq['packageName'] = $request['packageName'];
        $rollbackReq['packageTar'] = $request['packageName'] . $packageNum . ".tar.gz";

        $client->publish($rollbackReq);

}


function requestProcessor($request)
{
  echo "Request Received".PHP_EOL;
  var_dump($request);
  echo '\n' . 'End Message';
  if(!isset($request['type']))
  {
    return "ERROR: unsupported message type";
  }
  switch ($request['type'])
  {
    case "log":
      return logMessage($request);
    case "deploy":
      return deployPackage($request);
    case "nextPackage":
      return getNextPackageNum($request);
    case "updateVersion":
      return updatePackage($request);
    case "rollback":
      return rollbackPackage($request);	
  }
  return array("returnCode" => '0', 'message'=>"Server received request and processed");
}

$server = new rabbitMQServer("deployRabbitServer.ini","deployServer");

$server->process_requests('requestProcessor');
exit();
?>
