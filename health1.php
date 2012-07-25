<?php


/* ---------------------------------------
	
Health
	
--------------------------------------- */




// Includes
require_once 'services.php';
require_once "aws.php";




class Health extends Services{




	/* ---------------------------------------
	
	HEALTH
	
	--------------------------------------- */

	function Health(){

		// Initialize Services
		parent::__construct('Health');		
		
		$this->cw = new AmazonCloudWatch();
		$this->ec2 = new AmazonEC2();

		$this->checkQueues();		
		$this->checkServers();
		$this->checkApache();

		// Check number of connections
		$this->checkDatabase();

		// Check used disks greater than 80%
		$this->checkDisk(80);
		
		// 100 = 100mb free
		$this->checkMemory(10);
		
		// e.g. check stale files older than 5400 secs in /tmp
		$this->checkStaleFiles('/tmp', 5400);
		$this->checkStaleFiles('/var/tmp', 5400);
		
		// Stop DNS checks all happening at same time
		sleep(rand(1, 120));
		
		$this->checkAddresses();


	}
	
	
	
	function checkAddresses(){
	
		$opts = array('PublicIp' => array('174.129.195.252', '174.129.196.12', '174.129.196.231'));
		$response = $this->ec2->describe_addresses($opts);

		$used_instances = array(); 
		$used_addresses = array(); 
		$avail_addresses = array();
		
		if ($response->body->addressesSet->item){
			
		    foreach ($response->body->addressesSet->item as $item){
			
				if($item->instanceId != ''){
					
					// Address is allocated
			        $used_addresses[] = (string) $item->publicIp;
					$used_instances[] = (string) $item->instanceId;
					
				} else {
					
					// Address NOT allocated
					$avail_addresses[] = (string) $item->publicIp;
					
				}

		    }
		}

		// Get a list of web servers
		$opts = array('Filter' => array( array('Name' => 'group-name', 'Value' => 'Web'), array('Name' => 'instance-state-code', 'Value' => '16')) );
		$response = $this->ec2->describe_instances($opts);

		if($response->isOK()){

			// Get array of all web servers
			$all_instances = $response->body->instanceId()->map_string();
			
		}
		
		// Find web servers no using elastic IPs
		$avail_instances = array_diff($all_instances, $used_instances);
		
		// Refresh keys
		$avail_addresses = array_values($avail_addresses);
		$avail_instances = array_values($avail_instances);		

		// Loop through while instances and IPs are available
		while(count($avail_instances) > 0 && count($avail_addresses) > 0){

			// Allocate address
			$this->ec2->associate_address( $avail_instances[0], $avail_addresses[0] );

			// Remove from arrays
			unset($avail_addresses[0]);
			unset($avail_instances[0]);
			
			// Refresh keys on arrays
			$avail_addresses = array_values($avail_addresses);
			$avail_instances = array_values($avail_instances);
		
			
		}

		
	}
	
	

	

	
	
	
	/* ---------------------------------------
	
	CHECK SERVERS
	
	--------------------------------------- */	
	
	function checkServers(){
		
		$servers = $this->db->select("SELECT * FROM servers WHERE server_security = 'Web'");
		
		while($server = mysql_fetch_assoc($servers)){
			
			$ip = $server['server_ip'];
			$url = "http://$ip/index.html";
		
			$ch = curl_init();
		    curl_setopt( $ch, CURLOPT_URL, $url );

		    $content = curl_exec( $ch );
		    $response = curl_getinfo( $ch );
		    curl_close ( $ch );

	    	if($response['http_code'] != 200){
		
				$this->error('Check Servers', "Couldn't access web server $ip", $response['http_code'], true);
		
			}			
			
		}


	}

	
	
	
	/* ---------------------------------------
	
	CHECK QUEUES
	
	--------------------------------------- */
	
	function checkQueues(){	


		/*
		/
		/ CLOUDWATCH DATA
		/
		*/
		
		// Tasks waiting
		$value = $this->db->select("SELECT COUNT(*) FROM tasks WHERE task_status = 'W'", 'value');
		$this->log("DPHOTO", 'TasksWaiting', $value);	
		
		// Videos currently waiting				
		$value = $this->db->select("SELECT COUNT(*) FROM tasks WHERE task_status = 'W' AND task_type = 'V'", 'value');
		$this->log("DPHOTO", 'VideosWaiting', $value);		
		
		// Emails currently waiting				
		$value = $this->db->select("SELECT COUNT(*) FROM emails WHERE email_status = 'W'", 'value');
		$this->log("DPHOTO", 'EmailsWaiting', $value);		
	
		
	}



	/**
	 * 
	 * Check on the current number of db connections and logs it to cloudwatch.
	 * Current limit on db is 150 connections.
	 * 
	 * @return void
	 * 
	 */
	
	function checkDatabase(){	

		// Total Database Connections
		$value = $this->db->select("SELECT COUNT(*) FROM information_schema.processlist", 'value');
		
		$this->log("DPHOTO", 'DatabaseConnections', $value);		
		
	}

	
	
	protected function log($space, $name, $value, $unit = 'Count'){
		
		
		// build metrics array
		$metrics = array(	'MetricName'  	=> $name,
		                 	'Value'       	=> $value, 
							'Unit'			=> $unit);

		// Add instanceID for EC2
		if($space == 'AWS/EC2'){ 

			// InstanceID retrieved from Services class
			$metrics['Dimensions'] = array('Name' => 'InstanceID', 'Value' => $this->instance);

		//	$space = "DPHOTO";

		}



		// Send to server
		$response = $this->cw->put_metric_data($space , array($metrics));	

		$result = $response->isOK() ? " PASS " : " FAIL ";
		
		var_dump($response);

		//if($result == " FAIL ") $this->error("Health", "$result :: ".var_dump($response). " :: $space :: ". $metrics['MetricName'] . " :: " .$metrics['Value'] . " :: " .$this->instance, 0, true);

	}
	


	function checkApache(){

		$result = exec("curl http://127.0.0.1/server-status?auto", $output);

		foreach($output as $row){

			$arr = explode(": ", $row);
			$key = $arr[0];
			$value = $arr[1];

			if( $key == "BusyWorkers" ) $this->log('AWS/EC2', 'ApacheBusyWorkers', $value);
			if( $key == "IdleWorkers" ) $this->log('AWS/EC2', 'ApacheIdleWorkers', $value);
			if( $key == "ReqPerSec" ) $this->log('AWS/EC2', 'ApacheRequestsSec', $value);


		}

	}



	function checkMemory($threshold){
		
		$freemem = system("free -m | grep Mem: | awk '{print \$4}'");

	    if($freemem < $threshold) {
		
	      $this->error('Check Memory', "Free memory alert, only {$freemem}MB available",$freemem, true);

	    }

	    $this->log('AWS/EC2','MemoryAvailable', $freemem, "Megabytes");

	}
  


	function checkDisk($threshold) {

		exec("df -vh | awk '{print \$5}' | sed s/%//", $output); 
	    
	    foreach ($output as $value) {

	    	if($value > $threshold) {

	    		$this->log('AWS/EC2','DiskPercentUsed', $value, "DiskPercentUsed");

	        	$this->error('Check Disk', "Disk space alert, {$value}% full", $value, true);
	    	}
	    }
	}
  
	  function checkStaleFiles($path, $expiry) {
	    $excluded = array('hsperfdata_hyperic'); #exclusions
	    if($handle = opendir($path)) {
	      while (false !== ($file = readdir($handle))) {
	        if(array_search($file, $excluded) === false) { # ignore excluded files
	          if(!preg_match('/^\./', $file)) { # ignore hidden files
	            if (time() - filemtime("$path/$file") >= $expiry)
	              unlink("$path/$file");
	          } 
	        } 
	      }
	    }
	  }
  	
	
	
	

}


// Initiate Class
new Health();


?>


