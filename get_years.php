<?php
    date_default_timezone_set('UTC');
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    
	include('../aluka_config.php');

    $mysqli = new mysqli($db_host, $db_user, $db_password, $db_database);    
    // connect to the database
    if ($mysqli->connect_error) {
        $returnObject['error'] = $mysqli->connect_error;
        sendResults($returnObject);
    }
    

    /* change character set to utf8 */
    if (!$mysqli->set_charset("utf8")) {
        printf("Error loading character set utf8: %s\n", $mysqli->error);
    } else {
        //printf("Current character set: %s\n", $mysqli->character_set_name());
    }
    
    header('Content-type: text/csv');
    header('Content-Disposition: attachment; filename="barcode_years_from_ocr.csv"');
    
    echo '"barcode","year","year","year","year","year"' . "\n";
        
    $sql = "SELECT  barcode, ocrtext FROM image_archive.ocrtext as ocr JOIN image_archive.original_images as i ON ocr.derived_from = i.id";
  
    
    $response = $mysqli->query($sql);
    echo $mysqli->error;
        
    while($row = $response->fetch_assoc()){
              

       $matches = array();
       preg_match_all('/[^0-9a-zA-Z]([0-9]{4})[^0-9a-zA-Z]/', $row['ocrtext'], $matches); 
       
       
       if(count($matches[1]) ==  0) continue;

       $years = array();
       
       foreach($matches[1] as $match){
           
           $number = (int)$match;
           if($number > 2012) continue;
           if($number < 1650) continue;
           
           $years[$number] = $number;
           
       }
              
       
       $years = array_keys($years);
       
       if(count($years) == 0) continue;
       
       asort($years);
       $yearString = implode(',', $years);
       echo  '"' . $row['barcode'] . '",' . $yearString . "\n";  
    
       
    }
    
    

?>