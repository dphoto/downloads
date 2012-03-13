<?php

// Hide errors so 
ini_set('display_errors', false);

echo "Health";


require_once("database.php");

try{

	$db = new Database('Health');
	$user = $db->select("SELECT user_firstname FROM users WHERE user_id = 1");
	
	header("HTTP/1.1 200 OK");
	
	echo "Everything is OK here yo yo";

} catch (Exception $e){
	
	//echo $e->getMessage();
	
	// Server error
	header('HTTP/1.1 500 Internal Server Error');	
	
}




?>