<?php 

error_reporting(E_ALL);
ini_set('display_errors', 1);

$image_path = "_EDW4407.jpg";

function output_iptc_data( $image_path ) {
    $size = getimagesize ( $image_path, $info);
    if(is_array($info)) {
        $iptc = iptcparse($info["APP13"]);
        foreach (array_keys($iptc) as $key => $s) {
            $c = count ($iptc[$s]);
            for ($i=0; $i <$c; $i++)
            {
                echo $key .' :: ' .$s.' = '.$iptc[$s][$i].'<br>';
            }
        }
    }
}

output_iptc_data($image_path);

?>
