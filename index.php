<?php


require_once 'services.php';

//error_reporting(E_ALL);
//ini_set('display_errors', 1);

@ini_set('magic_quotes_runtime', 0);

class Download extends Services{

	
	function Download(){
		
		// Init services
		parent::__construct('Download');		
	
		// Give it time to check the files
		set_time_limit(180);
	
		// Bump up memory
		ini_set('memory_limit', '1524M');		

		// POST variables
		if(isset($_POST['user_id'])) $user_id = $_POST['user_id'];
		if(isset($_POST['album_id'])) $album_id = $_POST['album_id'];
		if(isset($_POST['file_ids'])) $file_ids = $_POST['file_ids'];
		if(isset($_POST['file_id'])) $file_ids = $_POST['file_id'];
		if(isset($_POST['size'])) $size = $_POST['size'];
		if(isset($_POST['name'])) $name = $_POST['name'];
		
		// GET variables
		if(isset($_GET['user_id'])) $user_id = $_GET['user_id'];
		if(isset($_GET['album_id'])) $album_id = $_GET['album_id'];
		if(isset($_GET['file_ids'])) $file_ids = $_GET['file_ids'];
		if(isset($_GET['file_id'])) $file_ids = $_GET['file_id'];
		if(isset($_GET['size'])) $size = $_GET['size'];
		if(isset($_GET['name'])) $name = $_GET['name'];		
		
		if(!isset($user_id)) exit();
		if(!isset($file_ids)) exit();
		if(!isset($size)) $size = 'original';

		
		$download_files = array();
		$download_size = 0;
		$download_name = '';

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
		


		// See download vars
		$download_type = (isset($album_id) || mysql_num_rows($result) > 1) ? 'zip' : 'file';
		$download_name = ($download_type == 'zip') ? $name . '.zip' : $download_files[0]['name'];
		$download_name = $this->cleanString($download_name); 

		// Allow some padding
		if($download_type == 'zip') $download_size *= 1.005;
	
		// Log the archive in the downloads table
		$a = array(	'user_id' => $user_id, 
					'download_filename' => $download_name . "-1", 
					'download_filesize' => $download_size, 
					'download_photos' => $file_ids, 
					'download_size' => $size, 
					'xx_download_created' => 'CURRENT_TIMESTAMP');
					
		$this->db->insert("downloads", $a, true);

		// Allow roughly 3 minutes per mb
		set_time_limit(0);	

		//if($download_size >= 2147483648) set_time_limit(0);

		// Send out headers
		$this->sendHeaders($download_name, $download_size);

		// Download zip
		if($download_type == 'zip') $this->downloadZip($download_files);
			
		// Download file	
		if($download_type == 'file') $this->downloadFile($download_files[0]);

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
		
		// Apache headers are 32bit - limiting size to 2gb. 
		// If file is bigger, just don't send final size.
		if($download_size < 2147483648 && $download_size > 0){
			
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
			
			if($file = $this->getPhoto($current['bucket'], $current['key'])){

				$current['size'] = filesize($file);
				
	
			
				if ($current['name'] == $zip_filename) continue;

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
	
}
	

new Download();

?>