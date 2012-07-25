<?php 

error_reporting(E_ALL);
ini_set('display_errors', 1);

$result = exec("curl http://127.0.0.1/server-status?auto", $output);

echo "<br><br>RESULT : $result";
echo "<br><br>OUTPUT : " . print_r($output);

?>
