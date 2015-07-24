<?php



/* ---------------------------------------
	
Delete
	
--------------------------------------- */



require_once 'services.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);


class Delete extends Services{




	/* ---------------------------------------
	
	DELETE
	
	--------------------------------------- */

	function Delete(){

		// Initialize Services
		parent::__construct('Delete');		

		// Quick fix for multi thread
		//sleep(rand(1, 200));

		// Allow 10 min
		set_time_limit(600);

		// Clean up
		$this->deleteFiles();
		
	}
	
	

	
	
	/* ---------------------------------------
	
	DELETE FILES
	
	--------------------------------------- */
	
	function deleteFiles(){	
	
		try{

			// Different file sizes
			$sizes = array('original', 'hd', 'huge', 'large', 'medium', 'blog', 'small', 'tiny', 'preview', 'square', 'thumb');
	
			// Task list query
			$query = "	SELECT * FROM deletes
						WHERE delete_status = 'waiting'
						AND TIMESTAMPDIFF(DAY, delete_created , CURRENT_TIMESTAMP ) > 30
						LIMIT 30";
	
			$result = $this->db->select( $query );
			echo "Selected ";
			// Check if any to delete
			if( mysql_num_rows( $result ) > 0 ){

				// Go through the files
				while( $delete = mysql_fetch_assoc( $result )){
					
					$delete_id = $delete[ 'delete_id' ];
					$file_id = $delete[ 'file_id' ];
					$user_id = $delete[ 'user_id' ];
					echo "Got delete - $delete_id : $file_id : $user_id";
					// Retrieve file info
					$file = $this->db->select( "SELECT * FROM files WHERE file_id = $file_id AND user_id = $user_id LIMIT 1", 'row' );

					// Put info into local vars
					foreach( $file as $key => $value ) ${$key} = $value;
				
					// Check to ensure the file is still deleted
					if( $file_deleted == '' ){
						echo "About delete";
						// File has been undeleted, so log and skip
						$this->db->update( 'deletes', array( 'delete_status' => 'error' ), "delete_id = $delete_id");

					} else {
						
						// Proceed to delete files
						$bucket = $this->getBucket( $file_backup );
					
						// Loop through all sizes and delete files
						foreach( $sizes as $size ){
							echo "Deleting $size";
							$this->deletePhoto( $bucket, $this->getKey( $file, $size ) );
						}

						// Update deleted_files table
						$this->db->update( 'deletes', array( 'delete_status' => 'complete' ), "delete_id = $delete_id");
						echo "Complete $delete_id";
					}

				}
				
				
			
			}			
			
		} catch(Exception $e){
	
			$this->error( 'Delete', $e->getMessage(), $e->getCode() );
			
		}
	

	}
	
	

}



// Initiate Class
new Delete();


?>


