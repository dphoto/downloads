<?php
	
error_reporting(E_ERROR | E_ALL);
ini_set('display_errors', 1);	
	
require 'upload.php';
	
$uploads_dir = '/tmp';
	

$file_title = '';
$file_description = '';
$file_tags = '';

if( isset($_POST['file_title']) ) $file_title = $_POST['file_title'];
if( isset($_POST['file_description']) ) $file_description = $_POST['file_description'];
if( isset($_POST['file_tags']) ) $file_tags = $_POST['file_tags'];



foreach($_FILES as $key => $value){

	if ( $_FILES[$key]['error'] == UPLOAD_ERR_OK ) {
		$tmp_name = $_FILES[$key]['tmp_name'];
		$name = time() . '-' . $_FILES[$key]['name'];

		if ( !move_uploaded_file( $tmp_name, "$uploads_dir/$name" ) ) {

			echo "CANNOT MOVE {$_FILES[$key]['name']}" . PHP_EOL;

		}

		else {
	
			echo "<strong>Success!</strong>";
			
			$up = new Upload();
			$up->uploadFile(20002, 124192, "$uploads_dir/$name" , $_FILES[$key]['name'], $file_title, $file_description, $file_tags);
	
		}
		
	}
	
	else {
	
		die( "An error occurred: " . $_FILES[$key]['error'] );
	
	}	
	
}
	


	
	
	

?>
