<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');


class DeployFuncLib
{
	private $conn;
	public function connect()
	{
		try
		{
			$this->conn = new PDO('mysql:host=localhost;dbname=package', 'root', '12345');
			return true;
		}
		catch(PDOException $e)
		{
			$message =  'Error: ' . $e->getMessage();
			$client = new rabbitMQClient("deployRabbitServer.ini","deployServer");      
			$request = array();
			$request['type'] = "log";
			$request['message'] = $message;
			$response = $client->publish($request);
		}
		
		return false;
	}

	public function nextPackageChecker($package)
	{
		$stmt = $this->conn->prepare("SELECT * FROM packages WHERE packageName LIKE '$package%' ORDER BY currentPackage DESC");
		$stmt->setFetchMode(PDO::FETCH_ASSOC);
		$stmt->execute();
		$packageVersion = $stmt->fetch()['currentPackage'];
			return $packageVersion+1;
	}
	
	public function updateVersion($packageName, $versionNum)
	{
		$stmt = $this->conn->prepare('INSERT INTO packages (packageName, currentPackage) VALUES (:pn, :vn)');
		$stmt->bindParam(':pn', $packageName);
		$stmt->bindParam(':vn', $versionNum);
		$stmt->execute();
			return true;
	}

	public function getCurrentPackage($packageName)
	{
		$stmt = $this->conn->prepare("SELECT * FROM packages WHERE packageName LIKE '$packageName%' ORDER BY currentPackage DESC");
		$stmt->execute();
		$currentPackage = $stmt->fetch()['currentPackage'];
			return $currentPackage;
	}
	public function __destruct()
	{
		$this->conn = null;
	}
}

?>
