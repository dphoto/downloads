<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'services.php';

// S3
use Aws\S3;
use Aws\S3\S3Client;
use Aws\S3\Exception\Parser;
use Aws\S3\Exception\S3Exception;


@ini_set('magic_quotes_runtime', 0);



class Zip extends Services{

	
	function Zip(){
		
		// Init services
		parent::__construct('Zip');				
	
		ini_set('memory_limit', '1524M');		
		set_time_limit( 3600 );

		$this->startTimer();


		// POST variables
		if( isset( $_REQUEST['download_id'] ) ){ 
			$download_id = urldecode( $_REQUEST['download_id'] );
		} else {
			die( "No download_id" );
		}

		// user_id, file_ids, size, download_id...

		// Get list of file_ids from downloads table

		// Build zip file, with correct internal names

		// Save to S3
		// Update 	
		$download = $this->db->select( "SELECT * FROM downloads WHERE download_id = $download_id", 'row' );

		if(is_array($download)){

			$size = $download['download_size'];
			$user_id = $download['user_id'];
			$download_photos = $download['download_photos'];
			$download_name = $download['download_filename'];
			$download_type = stripos( $download_photos, ',' ) !== false ? 'zip' : 'file';
			$download_files = array();
			$download_size = 0;

		} else {

			die( "Incorrect download id" );

		}

		// Set for debugging
		$this->db->user_id = $user_id;

		$this->logTimer( "Got download data" );

		// Get photo data
		$query = "	SELECT file_id, file_key, file_code, file_ext, file_upname, file_upext, file_size, file_resize, file_backup, user_id 
					FROM files
					WHERE file_id IN ($download_photos) 
					AND user_id = $user_id
					AND (!file_deleted OR file_deleted IS NULL)";

		$result = $this->db->select($query);

		$this->logTimer( "Got file data" );

		$files = array();

		$dir = "/tmp/test";
		mkdir( $dir );

		// Go through result set and build paths
		while($file_arr = mysql_fetch_assoc($result)){
			
			// Put db data into local vars
			foreach($file_arr as $key => $value) ${$key} = $value;

			// Get file details
			$bucket = $this->getBucket($file_backup);
			$key = $this->getKey( $file_arr, $size, $file_resize );
			//$file_upname = $this->getValidFilename( $file_names, $file_upname, $size == 'original' ? $file_upext : $file_ext );
			$file_ext = $this->getExtension( $key );
			$filename = $file_upname . $this->getExtension( $key );

			if( $file = $this->getPhoto( $bucket, $key, $dir, $filename ) ){

				// Add file to download list
				array_push( $files, $file );	

			}

			$this->logTimer( "Got file - $file" );

			// Add to list of filename already used
			//array_push($file_names, "$file_upname.$file_upext");			

		}
		
		$this->logTimer( "Got all files" );

		// Create Zip archive
		$list = implode( ' ', $files );
		$zip = "/tmp/test.zip";
		$result = exec( "zip -0 $zip $list" );

		$this->logTimer( "Created zip - $result" );

		$filesize = filesize( $zip );

		$this->logTimer( "Zip size - $filesize" );

		$bucket = 'us.files.dphoto.com';
		$key = "$user_id/downloads/test.zip";

		// Send Zip to S3
		$this->putPhoto( $zip, $bucket, $key );

		$this->logTimer( "Sent zip to S3 $bucket $key" );

		// Clean up
		unlink( $zip );
		foreach( $files as $file ) unlink( $file );
		rmdir( $dir );

		$this->logTimer( "Cleaned up temp files" );

	}




	protected function getPhoto($bucket, $key, $folder = '/tmp', $filename = false ) {
		
		// If no filename specified, create a unique one based on key
		if( $filename === false ) $filename = time() . '-' . str_replace('/','-',$key);

		$path = $folder . "/" . $filename;
		$attempt = 0;
		
		while($attempt < 5){

			try{
				
				$this->setRegion( $bucket );
				$response = $this->s3->getObject( array( 'Bucket' => $bucket, 'Key' => $key, 'SaveAs' =>  $path) );

				return $path;

			} catch (S3Exception $e){
				
				$this->error('Get Photo', "Exception in S3 $bucket / $key : ". $e->getMessage(), 0, false);

				$attempt++;	
				sleep(2 * $attempt);

			}

		}

		// Log error
		$this->error('Get Photo', "Failed to retrieve $bucket/$key from S3", 0, false);
	
		return false;
	
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


	function startTimer(){

		$this->start = time();

	}

	function logTimer( $message = "" ){

		$time = time();
		$elapsed = $time - $this->start;
		echo "<br>" . $elapsed . "s - " . $message;

	}




	
}
	

new Zip();

?>