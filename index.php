<?php


require_once 'services.php';

//error_reporting(E_ALL);
ini_set('display_errors', 0);

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

		$download_key = $this->db->validate( substr($download_id, stripos($download_id, '-') + 1), true );
		$download_id = $this->db->validate( substr($download_id, 0, stripos($download_id, '-')), true );

		$this->db->error("Download id", "$download_id :: $download_key ");

		if(isset($download_id) && isset($download_key)){

			$this->db->error("Got ID", "$download_id :: $download_key ");

			$download = $this->db->select("SELECT * FROM downloads WHERE download_id = $download_id AND download_key = $download_key", 'row');

			if(is_array($download)){

				$this->db->error("Got array");

				$size = $download['download_size'];
				$user_id = $download['user_id'];
				$download_photos = $download['download_photos'];
				$download_name = $download['download_filename'];
				$download_type = stripos($download_photos, ',') !== false ? 'zip' : 'file' ;
				$download_files = array();
				$download_size = 0;

			} else {

				$this->db->error("Incorrect ID", "$download_id :: $download_key ");

				echo "Incorrect ID";
				exit();

			}

		} else {

				echo "No ID";

				exit();

		}
		
		
		// REDIRECT NOT NEEDED NOW THAT STICKY SESSIONS ENABLED??

		// For zips, redirect to IP address so session doesn't expire
		if(stripos($download_photos, ',') !== false && $_SERVER['HTTP_HOST'] == 'download.dphoto.com'){
			
			$this->db->error("Download REDIRECT", $_SERVER['HTTP_HOST'] . ":: $download_id :: $download_key ");

			$location = "http://" . $this->server . "/index.php?" . http_build_query($_REQUEST);
			header( "HTTP/1.1 303 See Other" );
			header( "Location: $location" );
			exit();


		}
		
		

		//if(!isset($user_id)) exit();
		//if(!isset($file_ids)) exit();
		//if(!isset($size)) $size = 'original';				


		// Set for debugging
		$this->db->user_id = $user_id;


	//	$download_name = '';

		// Get photo data
		$query = "	SELECT file_id, file_key, file_code, file_ext, file_upname, file_upext, file_size, file_resize, file_backup, user_id 
					FROM files
					WHERE file_id IN ($download_photos) 
					AND user_id = $user_id
					AND file_deleted = 0";

		$result = $this->db->select($query);

		$file_names = array();

		// Go through result set and build paths
		while($file_arr = mysql_fetch_assoc($result)){
			
			// Put db data into local vars
			foreach($file_arr as $key => $value) ${$key} = $value;

			// Get file details
			$file_bucket = $this->getBucket($file_backup);
			$file_key = $this->getKey($file_arr, $size, $file_resize);
			$file_upname = $this->getValidFilename($file_names, $file_upname, $file_upext);
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

				// Add to list of filename already used
				array_push($file_names, "$file_upname.$file_upext");
			
				// Increment the download size
				$download_size += $file_size;			
				
			//}			

		}
		
		//$this->error("Download Filenames", implode(',', $file_names), 0, true);

		// Allow some padding
		if($download_type == 'zip'){ 

			// $download_size *= 1.005;
			$download_name .= '.zip';
			$download_safe = utf8_decode($download_name);

		} else {

			$download_name = $download_files[0]['name'];
			//$download_safe = utf8_decode($download_name);

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
			$this->sendHeaders($download_name, $download_size);

			$this->downloadZip($download_files);

			$this->download_complete = true;

		}

		// Download file	
		if($download_type == 'file'){ 

			$download_safe = $this->encodeHeader($download_name);
			$download_mime = $this->getCtype($file_ext);

			if($user_id == 1){



				$args = array(	'ResponseContentType' => $download_mime, 
								'ResponseContentDisposition' => "attachment; filename=$download_safe",
								'SaveAs' => $download_safe);

				// Use new S3 Class
				$s3 = $this->aws->get('s3');
				$link = $s3->getObjectUrl($download_files[0]['bucket'], $download_files[0]['key'], '+2 days', $args);
				$link = str_replace('.s3.amazonaws.com', '', $link);

				$this->error("New PHP class", $link, 0, true);

			} else {

				$response = array(	'content-type' => $download_mime,
	        						'content-disposition' => "attachment; filename=$download_safe" );

				$link = $this->s3->get_object_url($download_files[0]['bucket'], $download_files[0]['key'], '2 days', array('response' => $response));
				$link = str_replace('.s3.amazonaws.com', '', $link);

			}

			$this->download_complete = true;

			//echo "$link";
	    	header("Location: $link");

			//$this->downloadFile($download_files[0]);

		}

		// Let shoutdown function know not to worry
		


	}
	
	function sendHeaders($download_name, $download_size){
		
		// Get the ctype
		//$ctype = $this->getCtype($download_name);
		$download_safe = utf8_decode($download_name);
		
		// Required for IE, otherwise Content-disposition is ignored
		if(ini_get('zlib.output_compression')) ini_set('zlib.output_compression', 'Off');
		
		// Send out headers
		header("Pragma: public");
		header("Expires: 0");
		header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
		header("Cache-Control: private",false);
		header("Content-Type: application/zip");
		header("Content-Disposition: attachment; filename=$download_safe" );
		header("Content-Transfer-Encoding: binary");
		// header("Content-Length: $download_size");
		
		//$download_size < 2147483648 && 
		// Apache headers are 32bit - limiting size to 2gb. 
		// If file is bigger, just don't send final size.
		if($download_size > 0){
			
			//header("Content-Length: ". $download_size);
			
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
	

	/**
	 * Ensures no 2 files in a zip have the same name
	 * @param array $file_names list of all names currently being used
	 * @param string $file_upname The filename to check
	 * @return string
	 */
	function getValidFilename($file_names, $file_upname, $file_upext){

		$i = 0;

		$file_newname = $file_upname;

		while( in_array($file_newname . ".".$file_upext, $file_names) ){

			$i++;
			$file_newname = $file_upname . "-$i";

		}

		return $file_newname;

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


	private function cleanEncoding($s){
		
		return mb_detect_encoding($s . 'a' , "UTF-8, ISO-8859-1") == "UTF-8" ? $s : utf8_encode($s);
		
	}


	private function encodeISO($s){
		
		if( mb_detect_encoding($s . 'a' , "UTF-8, ISO-8859-1") == "ISO-8859-1" ){

			$this->db->error("Encode", "$s is ISO", 0, true);

			return $s; 

		} else {

			$this->db->error("Encode", "$s is UTF", 0, true);

			return utf8_decode($s);

		}
		
	}

	private function encodeHeader($s){
		
		$s = rawurlencode($s);
		$s = str_replace('%28', '(', $s);
		$s = str_replace('%29', ')', $s);

		return $s;	

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