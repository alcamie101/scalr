<?php
	class Scalr_Net_Scalarizr_Client
	{
		const NAMESPACE_SERVICE = 'service';
        const NAMESPACE_MYSQL = 'mysql';
        const NAMESPACE_POSTGRESQL = 'postgresql';
        const NAMESPACE_REDIS = 'redis';
        const NAMESPACE_SYSINFO = 'sysinfo';
        const NAMESPACE_SYSTEM = 'system';
            
		private $dbServer,
			$port,
			$cryptoTool;
		
		protected $namespace;
        
		public static function getClient($dbServer, $namespace = null, $port = 8010)
		{
			switch ($namespace) {
				case "service":
					return new Scalr_Net_Scalarizr_Services_Service($dbServer, $port);
					break;
                case "mysql":
                    return new Scalr_Net_Scalarizr_Services_Mysql($dbServer, $port);
                    break;
                case "postgresql":
                    return new Scalr_Net_Scalarizr_Services_Postgresql($dbServer, $port);
                    break;
                case "redis":
                    return new Scalr_Net_Scalarizr_Services_Redis($dbServer, $port);
                    break;
                case "sysinfo":
                    return new Scalr_Net_Scalarizr_Services_Sysinfo($dbServer, $port);
                    break;
                case "system":
                    return new Scalr_Net_Scalarizr_Services_System($dbServer, $port);
                    break;
                    
				default:
					return new Scalr_Net_Scalarizr_Client($dbServer, $port);
					break;
			}
		}
		
		public function __construct(DBServer $dbServer, $port = 8010) {
			$this->dbServer = $dbServer;
			$this->port = $port;
			
			$this->cryptoTool = Scalr_Messaging_CryptoTool::getInstance();
		}
		
		public function request($method, stdClass $params = null, $namespace = null)
		{
			if (!$namespace)
				$namespace = $this->namespace;
			
			$requestObj = new stdClass();
			$requestObj->id = microtime(true);
			$requestObj->method = $method;
			$requestObj->params = new stdClass();
			
			$this->walkSerialize($params, $requestObj->params);		
            
			$jsonRequest = $this->cryptoTool->encrypt(json_encode($requestObj), $this->dbServer->GetKey(true));
			
			$dt = new DateTime('now', new DateTimeZone("UTC"));
			$timestamp = $dt->format("D d M Y H:i:s e");

			$canonical_string = $jsonRequest . $timestamp;
			$signature = base64_encode(hash_hmac('SHA1', $canonical_string, $this->dbServer->GetKey(true), 1));
			
			$request = new HttpRequest("http://{$this->dbServer->remoteIp}:{$this->port}/{$namespace}", HTTP_METH_POST);
		  	$request->setOptions(array(
		  		'timeout'	=> 30,
		  		'connecttimeout' => 10
		  	));
		  	
		  	$request->setHeaders(array(
				"Date" =>  $timestamp, 
				"X-Signature" => $signature,
		  		"X-Server-Id" => $this->dbServer->serverId
		  	));
			$request->setRawPostData($jsonRequest);
			
			try {
				// Send request
				$request->send();
				
				if ($request->getResponseCode() == 200) {
					
					$response = $request->getResponseData();
					$body = $this->cryptoTool->decrypt($response['body'], $this->dbServer->GetKey(true));
					
					$jResponse = @json_decode($body);
					
					if ($jResponse->error)
						throw new Exception("{$jResponse->error->message} ({$jResponse->error->code}): {$jResponse->error->data}");
						
					return $jResponse;
				} else {
					$response = $request->getResponseData();
					throw new Exception(sprintf("Unable to perform request to scalarizr: %s (%s)", $response['body'], $request->getResponseCode()));	
				}
			} catch(HttpException $e) {
				if (isset($e->innerException))
					$msg = $e->innerException->getMessage();
			    else
					$msg = $e->getMessage();
                
                if (stristr($msg, "Namespace not found")) {
                    $msg = "Feature not supported by installed version of scalarizr. Please update it to the latest version and try again.";
                }
				
				throw new Exception(sprintf("Unable to perform request to scalarizr: %s", $msg));
			}
		}
		
		private function walkSerialize ($object, &$retval) {
			foreach ($object as $k=>$v) {
				if (is_object($v) || is_array($v)) {
					$this->walkSerialize($v, $retval->{$this->underScope($k)});
				} else {
				    if (is_object($object))
					   $retval->{$this->underScope($k)} = $v;
                    else
                       $retval[$this->underScope($k)] = $v;
				}
			}
		}
	
		private function underScope ($name) {
			$parts = preg_split("/[A-Z]/", $name, -1, PREG_SPLIT_OFFSET_CAPTURE | PREG_SPLIT_NO_EMPTY);
			$ret = "";
			foreach ($parts as $part) {
				if ($part[1]) {
					$ret .= "_" . strtolower($name{$part[1]-1});
				}
				$ret .= $part[0];
			}
			return $ret;
		}
	}