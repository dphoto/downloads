<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "start 1.391<br>";
set_time_limit(0);
/*
$cmd = "identify -verbose adobe.jpg";
				
exec( $cmd, $out, $ret );

print_r($out);
//print_r($ret);

//-profile /var/www/AdobeRGB1998.icc 
$file = 'adobe.jpg';
$cmd = "convert adobe.jpg -resize 15% -profile /var/www/AdobeRGB1998.icc -profile /usr/local/etc/ImageMagick/sRGB.icm -strip adobe2.jpg";
				
exec( $cmd, $out1, $ret );
echo "<br><br><br><br>-------------------------<br>";
print_r($out1);
//print_r($ret);


$cmd = "identify -verbose adobe2.jpg";
				
exec( $cmd, $out2, $ret );
echo "<br><br><br><br>-------------------------<br>";
print_r($out2);
//print_r($ret);
*/
$cmd = "identify -verbose app.jpg";
				
exec( $cmd, $out3, $ret );
echo "<br>APP<br><br><br>-------------------------<br>";
print_r($out3);


				

echo "<br><br><br><br>-------------------------<br>";
$cmd = "convert app.jpg -resize 15% -profile /usr/local/etc/ImageMagick/sRGB.icm -profile /usr/local/etc/ImageMagick/sRGB.icm -strip app2.jpg";
exec( $cmd, $out4, $ret );
print_r($out4);

echo "<br><br><br><br>-------------------------<br>";
$cmd = "convert app.jpg -resize 15% -strip app3.jpg";
exec( $cmd, $out5, $ret );
print_r($out5);

echo "<br><br><br><br>-------------------------<br>";
$cmd = "convert app.jpg -resize 15% -profile /usr/local/etc/ImageMagick/sRGB.icm -strip app4.jpg";
exec( $cmd, $out6, $ret );
print_r($out6);

echo "completed <br>";

?>
