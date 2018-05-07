<?php

    /*
    
    This file contains the configuration data that may be needed by more
    than one script and that will probably be changed when the scripts are moved
    to the live server
    
    In a small app like this where we aren't doing full object orientated programming
    I tend to put any common functions in the file as well.
    
    */
    
    // set the error reporting to high - so we can see our problems
    // commercial sites this would be set lower for production
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    date_default_timezone_set('UTC');
    
    
    /*
     *  Database config variables
     *
    */
    
	// database config hidden from github
	include('../aluka_config.php');
	/*
    $db_host = '***';
    $db_database = '***';
    $db_user = '***';
    $db_password = '***';
    */
	
    /*
     *  Try and connect to the database.
     *  and give up if we can't - printing error message
     *
     *  We use the $mysqli variable in subsequent scripts
     *  
     */
    $mysqli = new mysqli($db_host, $db_user, $db_password, $db_database);    
    // connect to the database
    if ($mysqli->connect_error) {
        echo $mysqli->connect_error;
        exit(1);
    }
    
    /* change character set to utf8
    if (!$mysqli->set_charset("utf8")) {
        printf("Error loading character set utf8: %s\n", $mysqli->error);
    } else {
        printf("Current character set: %s\n", $mysqli->character_set_name());
    }
 */



?>