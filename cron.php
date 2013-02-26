<?php


/* ---------------------------------------
	
Health
	
--------------------------------------- */


error_reporting(E_ALL);
ini_set('display_errors', 1);

// Includes
require_once 'services.php';
require_once "aws.php";




class Cron extends Services{




	/* ---------------------------------------
	
	HEALTH
	
	--------------------------------------- */

	function Cron(){

		set_time_limit(600);

		// Initialize Services
		parent::__construct('Cron');		

		$this->id = rand(2, 128);
		$this->limit = 1;

		$this->getTask();


	}
	

	function getTask(){

		echo "<br>get task"; 

		$this->db->update('files', array('server_id' => $this->id),  "server_id = 1 OR server_id IS NULL ORDER BY file_id LIMIT $this->limit");

		$result = $this->db->select("SELECT * FROM files WHERE server_id = $this->id LIMIT $this->limit");

		while($file = mysql_fetch_assoc($result)){

			// Store task in class vars for easy access
			foreach($file as $key => $value) $this->{$key} = $value;

			echo "<br>Running task for $this->file_id"; 

			$bucket = $this->getBucket($this->file_backup);

			// Delete
			$tiny_key = $this->getBucket($file, 'tiny');
			$small_key = $this->getBucket($file, 'small');
			$thumb_key = $this->getBucket($file, 'thumb');
			$preview_key = $this->getBucket($file, 'preview');

			// Reduced redundency
			$square_key = $this->getBucket($file, 'square');
			$blog_key = $this->getBucket($file, 'blog');
			$medium_key = $this->getBucket($file, 'medium');
			$large_key = $this->getBucket($file, 'large');
			$huge_key = $this->getBucket($file, 'huge');
			$hd_key = $this->getBucket($file, 'hd');

			echo "<br>Running task for $bucket $small_key"; 			

			 $this->deletePhoto($bucket, $tiny_key);
			// $this->deletePhoto($bucket, $small_key);
			// $this->deletePhoto($bucket, $thumb_key);
			// $this->deletePhoto($bucket, $preview_key);

			 $this->reducedRedundency($bucket, $square_key);
			// $this->reducedRedundency($bucket, $blog_key);
			// $this->reducedRedundency($bucket, $medium_key);
			// $this->reducedRedundency($bucket, $large_key);
			// $this->reducedRedundency($bucket, $huge_key);
			// $this->reducedRedundency($bucket, $hd_key);

		}

	}



	protected function reducedRedundency($bucket, $key) {
		
		$attempt = 0;
		
		while($attempt <= 3){

			$response = $this->s3->change_storage_redundancy($bucket, $key, AmazonS3::STORAGE_REDUCED);

			if( $response->isOK() ){
				
				return true;

			} else {
				
				$attempt++;
				sleep(1 * $attempt);

			}

			
		}		
		
		// Log error
		$this->error('Reduced Redundency', "Failed to change status of $bucket/$key");
		
		// Could not delete file
		return false;
	
	}


	
	

}


// Initiate Class
new Cron();


?>


