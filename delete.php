<?php


/* -----------
   !!! CURRENTLY DISABLED. Class not instansiated.
*/

/* ---------------------------------------
	
Delete
	
--------------------------------------- */



require_once 'services.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);


class Delete extends Services{




	/* ---------------------------------------
	
	HEALTH
	
	--------------------------------------- */

	function Delete(){

		// Initialize Services
		parent::__construct('Delete');		

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
			$query = "	SELECT * FROM files
						WHERE file_backup <> 0
						AND TIMESTAMPDIFF(MONTH, file_deleted , CURRENT_TIMESTAMP ) > 1
						LIMIT 1";
	
			$result = $this->db->select($query);

			// Check if any to delete
			if(mysql_num_rows($result) > 0){
			
				// Go through the files
				while($file = mysql_fetch_assoc($result)){
				
					// Put info into local vars
					foreach($file as $key => $value) ${$key} = $value;
				
					$bucket = $this->getBucket($file_backup);
				
					foreach($sizes as $size){
					
						$key = $this->getKey($file, $size);
					
						// Delete file from S3
						$this->deletePhoto($bucket, $key);

					}
					
					$a = array(	'user_id' => $user_id,
								'delete_type' => 'file',
								'delete_data' => $file)



					// Update deleted_files table
					$this->db->insert('deletes', $a, "file_id = $file_id");
				
					// $this->db->delete('files', "file_id = $file_id");
					// $this->db->delete('photos', "file_id = $file_id");
					// $this->db->delete('videos', "file_id = $file_id");
					// $this->db->delete('sizes', "file_id = $file_id");
					// $this->db->delete('geos', "file_id = $file_id");

					//echo "Deleted http://$bucket/$key \n";
				
				}
				
				
			
			}			
			
		} catch(Exception $e){
			
			$this->error('Delete Files', $e->getMessage(), $e->getCode());
			
		}
	

	}
	
	

}



// Initiate Class
new Delete();


?>


