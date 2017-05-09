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

function currentPackage($request)
{
	$db = new DeployFuncLib();
	$db->connect();
 	$response = $db->getCurrentPackage($request['packageName']);
	return $response;
}

function deployPackage($request)
{
	
	$json = file_get_contents('./virtualMachines.json');
	$decodedJson = json_decode($json, true);

# Grab routing information based for the target machine based on client request
	$targetMachine = ($decodedJson[$request['package']][$request['tier']] );
	$targetIp = $targetMachine['ip'];
	$targetPass = $targetMachine['pass'];

# Determine which package to send to the target 	
	$db = new DeployFuncLib();
	$db->connect();
	$packageNum = $db->nextPackageChecker($request['packageName']);
	$packageNum -= 1;
	$package = $request['packageName'] . $packageNum;

	$scpPackage = 'sshpass -p ' . $targetPass . ' scp -r /home/uzair/Packages/' . $package . '.tar.gz ' . $targetMachine['username'] . '@' . $targetIp . ':/home/' . $targetMachine['username'] . '/Packages';
	
	shell_exec($scpPackage);
	
# Changes exchanged based on the destination of the package to trigger 
# target machines installer

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

# Send Client request to the target machine

	$client = new rabbitMQClient("deployRabbitServer.ini", $packageExchange);	
	$deployReq = array();
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
	$targetPass = $targetMachine['pass'];

        $db = new DeployFuncLib();
        $db->connect();
        $packageNum = $db->nextPackageChecker($request['packageName']);
        $packageNum = $packageNum - 2;
        $package = $request['packageName'] . $packageNum;
        
        $scpPackage = 'sshpass -p ' . $targetPass . ' scp -r /home/uzair/Packages/' . $package . '.tar.gz ' . $targetMachine['username'] . '@' . $targetIp . ':/home/' . $targetMachine['username'] . '/Packages/';
	shell_exec($scpPackage);
	



# Changes exchanged based on the destination of the package to trigger 
# target machines installer

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

# Send Client request to the target machine

        $client = new rabbitMQClient("deployRabbitServer.ini", $packageExchange);
        $rollbackReq = array();
        $rollbackReq['type'] = $request['package'] . $request['tier'];
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
    case "currentPackage":
      return currentPackage($request);
  }
  return array("returnCode" => '0', 'message'=>"Server received request and processed");
}

$server = new rabbitMQServer("deployRabbitServer.ini","deployServer");

$server->process_requests('requestProcessor');
exit();
?>
