<?php


require_once 'services.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

@ini_set('magic_quotes_runtime', 0);



class Download extends Services{

	
	function Download(){
		
		// Init services
		parent::__construct('Download');				
	
		// Bump up memory
		ini_set('memory_limit', '1524M');		

		// POST variables
		if(isset($_REQUEST['download_id'])) $download_id = urldecode( $_REQUEST['download_id'] );
		//if(isset($_REQUEST['download_key'])) $download_key = $_REQUEST['download_key'];

		$download_key = substr($download_id, stripos($download_id, '-') + 1);
		$download_id = substr($download_id, 0, stripos($download_id, '-'));

		if(isset($download_id) && isset($download_key)){

			$download = $this->db->select("SELECT * FROM downloads WHERE download_id = $download_id AND download_key = '$download_key'", 'row');

			if(is_array($download)){

				$size = $download['download_size'];
				$user_id = $download['user_id'];
				$file_ids = $download['download_photos'];
				$download_name = $download['download_filename'];
				$download_type = stripos($download_photos, ',') !== false ? 'zip' : 'file' ;
				$download_files = array();
				$download_size = 0;

			} else {

				echo "Incorrect ID";
				exit();

			}

		} else {

				echo "No ID";
				exit();

		}
/*
		// For zips, redirect to IP address so session doesn't expire
		if(stripos($file_ids, ',') !== false && $_SERVER['HTTP_HOST'] == 'download.dphoto.com'){
			
			$location = "http://" . $this->server . "/index.php?" . http_build_query($_REQUEST);
			header( "HTTP/1.1 303 See Other" );
			header( "Location: $location" );
			exit();


		}		

*/
		//if(!isset($user_id)) exit();
		//if(!isset($file_ids)) exit();
		//if(!isset($size)) $size = 'original';				


		// Set for debugging
		$this->db->user_id = $user_id;


	//	$download_name = '';

		// Get photo data
		$query = "	SELECT file_id, file_key, file_code, file_ext, file_upname, file_upext, file_size, file_resize, file_backup, user_id 
					FROM files
					WHERE file_id IN ($file_ids) 
					AND user_id = $user_id";

		$result = $this->db->select($query);



		// Go through result set and build paths
		while($file_arr = mysql_fetch_assoc($result)){
			
			// Put db data into local vars
			foreach($file_arr as $key => $value) ${$key} = $value;

			// Get file details
			$file_bucket = $this->getBucket($file_backup);
			$file_key = $this->getKey($file_arr, $size, $file_resize);
			$file_size = $size == 'original' ? $file_size : 0;
			$file_ext = $this->getExtension($file_key);

			$file = array(	'bucket' 	=> $file_bucket,
							'key' 		=> $file_key,
							'size' 		=> $file_size,
							'ext'		=> $file_ext,						
							'name'		=> "$file_upname.$file_upext",
							'type'		=> 0 );
			
			// Ensure photo has a size				
			//if($file_size > 0){
				
				// Add file to download list
				array_push($download_files, $file);	
			
				// Increment the download size
				$download_size += $file_size;			
				
			//}			

		}
		
		// Allow some padding
		if($download_type == 'zip'){ 

			$download_size *= 1.005;
			$download_name += '.zip';

		} else {

			$download_name = $download_files[0]['name'];

		} 

		$this->download_id = $download_id;//$this->db->insert("downloads", $a, true);
		$this->download_start = microtime(true);
		$this->download_status = "Starting";
		$this->download_complete = false;
		$this->download_name = $download_name;


		$a = array(	'download_filesize' => $download_size,
					'download_filename' => $download_name,
					'xx_download_created' => 'CURRENT_TIMESTAMP');

		$this->db->update('downloads', $a, "download_id = $download_id");


		// Download zip
		if($download_type == 'zip'){ 

			// Allow 24 hours
			set_time_limit(86400);

			// If a task has hung the script, error
			register_shutdown_function(array($this, 'onTimeout'));

			// Set global exception handler
			set_exception_handler(array($this, 'onException'));			

			// Send out headers
			$this->sendHeaders($download_name . '.zip', $download_size);

			$this->downloadZip($download_files);

			$this->download_complete = true;

		}

		// Download file	
		if($download_type == 'file'){ 

			

			$response = array(	'content-type' => 'application/octet-stream',
        						'content-disposition' => "attachment; filename=$download_name");

			$link = $this->s3->get_object_url($download_files[0]['bucket'], $download_files[0]['key'], '2 days', array('response' => $response));
			$link = str_replace('.s3.amazonaws.com', '', $link);

			$this->download_complete = true;

			//echo "$link";
	    	header("Location: $link");

			//$this->downloadFile($download_files[0]);

		}

		// Let shoutdown function know not to worry
		


	}
	
	function sendHeaders($download_name, $download_size){
		
		// Get the ctype
		$ctype = $this->getCtype($download_name);
		
		// Required for IE, otherwise Content-disposition is ignored
		if(ini_get('zlib.output_compression')) ini_set('zlib.output_compression', 'Off');
		
		// Send out headers
		header("Pragma: public");
		header("Expires: 0");
		header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
		header("Cache-Control: private",false);
		header("Content-Type: $ctype");
		header("Content-Disposition: attachment; filename=$download_name;" );
		header("Content-Transfer-Encoding: binary");
	//	header("Content-Length: $download_size");
		
		//$download_size < 2147483648 && 
		// Apache headers are 32bit - limiting size to 2gb. 
		// If file is bigger, just don't send final size.
		if($download_size > 0){
			
			header("Content-Length: ". $download_size);
			
		}
		
		ob_flush();	
		flush();
	
		

	}
	
	function getCtype($file_extension){

		// Set the ctype for the download
		switch( $file_extension ){
				
			case "pdf": $ctype="application/pdf"; break;
			case "exe": $ctype="application/octet-stream"; break;
			case "zip": $ctype="application/zip"; break;
			case "doc": $ctype="application/msword"; break;
			case "xls": $ctype="application/vnd.ms-excel"; break;
			case "ppt": $ctype="application/vnd.ms-powerpoint"; break;
			case "gif": $ctype="image/gif"; break;
			case "png": $ctype="image/png"; break;
			case "jpeg": $ctype="image/jpg"; break;
			case "jpg": $ctype="image/jpg"; break;
			
			default: $ctype="application/force-download";
			  
		}
		
		return $ctype;
		
	}
	
	function downloadFile($file_arr) { 

		$file = $this->getPhoto($file_arr['bucket'], $file_arr['key']);
		$size = 388608; //8mb chunks 
		
	//	$this->error('Download', "Got file $file", null, true);
		
		if($handle = fopen($file, 'rb')) {

		//	$this->error('Download', "Got file handle!!", null, true);

			while (!feof($handle) && (connection_status()==0)) { 
		
				echo fread($handle, 1024*8);
				ob_flush();			
				flush();

			} 		
			
			fclose($handle);
			
		} 
		
		unlink($file);

	} 	

	function downloadZip($download_files){	
		

		
		$files = 0;
		$offset = 0;
		$central = "";
		

		$zip_basedir = '';
		$zip_overwrite = 0;
		$zip_level = 8;
		$zip_method = 1;
		$zip_prepend = ''; 
		$zip_storepaths = 0;
		$zip_comment = '';
		$zip_followlinks = 0;		

		//$this->download_size = 0;

		foreach ($download_files as $current){
			
			// Set status to the current name for dubugging
			$this->download_status = $current['name'];

			if($file = $this->getPhoto($current['bucket'], $current['key'])){

				$current['size'] = filesize($file);
				
	
			
				if ($current['name'] == $this->download_name) continue;

					$timedate = explode(" ", date("Y n j G i s", time()));
					$timedate = ($timedate[0] - 1980 << 25) | ($timedate[1] << 21) | ($timedate[2] << 16) |
					($timedate[3] << 11) | ($timedate[4] << 5) | ($timedate[5]);

					$block = pack("VvvvV", 0x04034b50, 0x000A, 0x0000, (isset($current['method']) || $zip_method == 0) ? 0x0000 : 0x0008, $timedate);

			
			

				if ($current['size'] == 0 && $current['type'] == 5){
			
					$block .= pack("VVVvv", 0x00000000, 0x00000000, 0x00000000, strlen($current['name']) + 1, 0x0000);
					$block .= $current['name'] . "/";
			
					echo $block;
			
					$central .= pack("VvvvvVVVVvvvvvVV", 0x02014b50, 0x0014, $zip_method == 0 ? 0x0000 : 0x000A, 0x0000,
						(isset($current['method']) || $zip_method == 0) ? 0x0000 : 0x0008, $timedate,
						0x00000000, 0x00000000, 0x00000000, strlen($current['name']) + 1, 0x0000, 0x0000, 0x0000, 0x0000, $current['type'] == 5 ? 0x00000010 : 0x00000000, $offset);
					$central .= $current['name'] . "/";
					$files++;
					$offset += (31 + strlen($current['name']));
				}
			
				else if ($current['size'] == 0){
					//echo "second<br>";
					$block .= pack("VVVvv", 0x00000000, 0x00000000, 0x00000000, strlen($current['name']), 0x0000);
					$block .= $current['name'];
			
					echo $block;
			
					$central .= pack("VvvvvVVVVvvvvvVV", 0x02014b50, 0x0014, $zip_method == 0 ? 0x0000 : 0x000A, 0x0000,
						(isset($current['method']) || $zip_method == 0) ? 0x0000 : 0x0008, $timedate,
						0x00000000, 0x00000000, 0x00000000, strlen($current['name']), 0x0000, 0x0000, 0x0000, 0x0000, $current['type'] == 5 ? 0x00000010 : 0x00000000, $offset);
					$central .= $current['name'];
					$files++;
					$offset += (30 + strlen($current['name']));
				}
			
				else if ($fp = fopen($file, "rb")){

					$temp = '';
					$chunksize = 8388608;// 8mb chunks 

					while (!feof($fp)) $temp .= fread($fp, $chunksize);

					fclose($fp);

					$crc32 = crc32($temp);

					if (!isset($current['method']) && $zip_method == 1)
					{

						$temp = gzcompress($temp, $zip_level);
						$size = strlen($temp) - 6;
						$temp = substr($temp, 2, $size);
					}
					else {
	
						$size = strlen($temp);

					}
			

					$block .= pack("VVVvv", $crc32, $size, $current['size'], strlen($current['name']), 0x0000);
					$block .= $current['name'];
			
					echo $block;
					
	
					echo $temp;


					$central .= pack("VvvvvVVVVvvvvvVV", 0x02014b50, 0x0014, $zip_method == 0 ? 0x0000 : 0x000A, 0x0000,
						(isset($current['method']) || $zip_method == 0) ? 0x0000 : 0x0008, $timedate,
						$crc32, $size, $current['size'], strlen($current['name']), 0x0000, 0x0000, 0x0000, 0x0000, 0x00000000, $offset);
					$central .= $current['name'];
					$files++;
					$offset += (30 + strlen($current['name']) + $size);
				
					unset($temp);
						
				
				}
		
				unlink($file);	
				
			}	

		}

		echo $central;
		
	//	$this->download_size += strlen($central);

		$pack = pack("VvvvvVVv", 0x06054b50, 0x0000, 0x0000, $files, $files, strlen($central), $offset,!empty ($zip_comment) ? strlen($zip_comment) : 0x0000);	

		echo $pack;
		
	//	$this->download_size += strlen($pack);

		return 1;
	}	


	function onTimeout(){
		
		// Determine connection status
		$connection = connection_status();
		
		
			
		// Look for timeout or abort	
		if($connection != 0 || $this->download_complete == false){
					
			// Determine duration
			$this->download_duration = microtime(true) - $this->download_start;

			// Log this
			$this->error('Script Timeout', "Script timeout while downloading $this->download_id\n\nDuration: $this->download_duration\n\nStatus: $this->download_status\n\nConnection: $connection\n\nComplete: $this->download_complete", 0, false);
		
		} else {
			
			// Update DB to show completed
			$this->db->update('downloads', array('xx_download_completed' => 'CURRENT_TIMESTAMP'), "download_id = $this->download_id");

		}

		// Terminate db connect
		$this->db->close();		

	}

	
	function onException($e) {


		// Determine duration
		$this->download_duration = microtime(true) - $this->download_start;

		// Log this
		$this->error('Script Error', "Exception while downloading $this->download_id\n\nMessage: ".$e->getMessage() ."\n\nDuration: $this->download_duration\n\nStatus: $this->download_status", 0, true);


		// Terminate db connect
		$this->db->close();	


	}





	
}
	

new Download();

?>