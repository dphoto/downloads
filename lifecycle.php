<?php



/* ---------------------------------------
	
Lifecycle
	
--------------------------------------- */



require_once 'services.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);


class Lifecycle extends Services{




	/* ---------------------------------------
	
	Lifecycle
	
	--------------------------------------- */

	function Lifecycle(){

		// Initialize Services
		parent::__construct('Lifecycle');		

		// Quick fix for multi thread
		//sleep(rand(1, 200));

		// Allow 10 min
		set_time_limit(600);

		// Add guid to id
		$this->id = $this->server . "-" . $this->getGuid(6);

		$limit = isset( $_REQUEST['limit'] ) ? $_REQUEST['limit'] : '1';
		$user_id = isset( $_REQUEST['user_id'] ) ? $_REQUEST['user_id'] : '1';
		$days = 30;
		$files = $this->db->select( "SELECT * FROM files WHERE file_storage = 'standard' AND user_id = $user_id AND DATEDIFF(CURRENT_TIMESTAMP, file_uploaded) > $days LIMIT $limit" );

		while( $file = mysql_fetch_assoc( $files )){

			$storage = 'STANDARD_IA';
			$bucket = $this->getBucket( $file[ 'file_backup' ] );
			$key = $this->getKey( $file, 'original' );

			echo "\nSetting storage : {$bucket}/{$key}" ;

			$success = $this->setFileStorage( $bucket, $key, $storage );

			if( $success ){
				$this->db->update( 'files', array( 'file_storage' => strtolower($storage) ), "file_id = " . $file[ 'file_id' ] );
			}

		}
		
	}
	
	

	function setFileStorage( $bucket, $key, $storage ){	
	
		try{

			$copy = $this->s3->copyObject( array(
				'Bucket' => $bucket,
				'Key' => $key,
				'CopySource' => $bucket . '/' . $key,
				'StorageClass' => $storage,
				'ServerSideEncryption' => 'AES256',
				'MetadataDirective' => 'COPY'
			));

			// Confirm that the change worked
			$iterator = $this->s3->getIterator( 'ListObjects', array(
				'Bucket' => $bucket,
				'Prefix' => $key
			));

			foreach( $iterator as $object ) {
				if( $object['StorageClass'] === $storage ){
					echo "\nSuccess";
					return true;
				} else {
					echo "\nError : storage was not changed";
					return false;		
				}
			}

		} catch( S3Exception $e ){
			echo "\nError :" . $e->getMessage();
			return false;
		} 


	}

}



// Initiate Class
new Lifecycle();


?>


