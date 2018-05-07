<?php

    /* 
        This is the actual script that creates the page
      
        When you call it without any query string ($_GET variables) it allows you to pick 
        a project code.
        
        When you call it with $_GET['project'] and $_GET['download'] = true you it creates the
        file and does the download.
        
    */
    
    // include the config file
    require("config.php");
    
    // the @sign means errors won't be thrown when this fails - which it will if there is no query string
    $currentProject = @$_GET['project'];
    $download = @$_GET['download']; 
    
    // if we have a project name and a request to download then do it
    if($currentProject){
        doDownload($currentProject, $download);
    }
    
    /**
    * Actually do the download. Because of the way this function returns
    * the browser should remain displaying the page and not refresh.
    * i.e. you will get a download file box.
    */
    function doDownload($project, $download){
        
        $startTime = time();
        
        global $mysqli;
         
         // Firstly we create a load of temporary tables to make the main query run efficiently        
        $sql = "
        
            CREATE temporary table mobile.cs select specimen_num, project_code from specimens_mv where project_code = $project;

            CREATE temporary table mobile.mods select ups.specimen_num, max(rec_update) as last_modified from specimens_mv as ups join mobile.cs on cs.specimen_num = ups.specimen_num group by specimen_num;

            CREATE TEMPORARY TABLE mobile.my_types SELECT t.* FROM types as t JOIN mobile.cs  as c ON c.specimen_num = t.specimen_num;
            ALTER TABLE mobile.my_types ADD INDEX(specimen_num);
            ALTER TABLE mobile.my_types ADD INDEX(nomen_type_num);

            CREATE temporary table mobile.my_specimens select specimens.* from specimens join mobile.cs  as c on specimens.specimen_num = c.specimen_num;
            ALTER TABLE mobile.my_specimens ADD INDEX(specimen_num);

            CREATE TEMPORARY TABLE mobile.my_name_nums SELECT specimen_num, name_num from mobile.my_specimens;
            INSERT INTO mobile.my_name_nums SELECT specimen_num, filing_name_num FROM mobile.my_specimens;
            INSERT INTO mobile.my_name_nums SELECT s.specimen_num, nomen_type_num FROM mobile.my_types as t join mobile.my_specimens as s on t.specimen_num = s.specimen_num;
            INSERT INTO mobile.my_name_nums SELECT s.specimen_num, v.name_num FROM verifications as v JOIN mobile.my_specimens as s ON v.specimen_num = s.specimen_num;
            
            CREATE TEMPORARY TABLE mobile.infra select s.name_num,
             case infra_rank
                  when 'S' then 'subsp.'
                  when 'V' then 'var.'
                  when 'F' then 'forma'
                  when 'SF' then 'subforma'
                  when 'SV' then 'subvar. '
                  else null
             end as infra_rank,
             infra_epi, infra_auth
             from names_mv
             join mobile.my_name_nums as s on s.name_num = names_mv.name_num
             where infra_rank is not null and infra_epi is not null;

            CREATE TEMPORARY TABLE mobile.my_names SELECT n.*, i.infra_rank, i.infra_epi, i.infra_auth from names as n join mobile.my_name_nums as nn on n.name_num = nn.name_num left join mobile.infra as i on i.name_num = n.name_num;
            ALTER TABLE mobile.my_names ADD INDEX(name_num);
            
             SELECT

                     DATE_ADD('1967-12-31', INTERVAL mods.last_modified DAY) as modified,

                     s.specimen_num as specimen_num,

                     s.barcode as specimen_barcode,
                     s.name_free as specimen_name_free,
                     s.filing_name_free as specimen_filing_name_free,
                     s.coll_name as specimen_collector_name,
                     s.coll_num as specimen_collector_number,

                     year(DATE_ADD('1967-12-31', INTERVAL s.coll_dt DAY)) as specimen_collected_year,
                     month(DATE_ADD('1967-12-31', INTERVAL s.coll_dt DAY)) as specimen_collected_month,
                     dayofmonth(DATE_ADD('1967-12-31', INTERVAL s.coll_dt DAY)) as specimen_collected_day,

                     s.country_as_given as specimen_country_on_label,
                     s.country_code as specimen_country_iso,
                     s.locality as specimen_collected_location,
                     concat_ws(' ', s.altitude, s.altitude_unit) as specimen_collected_altitude,

                     s.name_num as name_num,
                     s.filing_name_num as filing_name_num,
                     t.nomen_type_num as type_name_num,
                     
                     case t.nomen_type_kind
                            when 'H' then 'Holotype'
                            when 'I' then 'Isotype'
                            when 'T' then 'Type'
                            when 'S' then 'Syntype'
                            when 'IS' then 'Isosyntype'
                            when 'IL' then 'Isolectotype'
                            when 'P' then 'Paratype'
                            when 'L' then 'Lectotype'
                            when 'N' then 'Neotype'
                            else '?'
                       end as type_kind

             from mobile.my_specimens as s
             join mobile.mods on s.specimen_num = mods.specimen_num
             left join mobile.my_types as t on s.specimen_num = t.specimen_num    
             order by s.specimen_num
        
        ";
        
        $mysqli->multi_query($sql);
        
         // if there was an error running the query just echo it out - no fancy error handling
         if ($mysqli->error) {
             echo $mysqli->error;
             exit(1);
         }
         
         do {
                 if ($response = $mysqli->store_result()) {
                     
                     // are we at the last result set yet?
                     if ($mysqli->more_results()) {
                          $response->close();
                          continue;
                     }
                 }                 
         } while ($mysqli->next_result());
         
         // create an XML document to add the data to
         // $xml = new SimpleXMLElement(getXMLTemplate());
         $xml = new DOMDocument('1.0', 'utf-8');
         $xmlRoot = $xml->createElement("DataSet");
         $xmlRoot->appendChild( $xml->createElement('InstitutionCode', 'E' ) );
         $xmlRoot->appendChild( $xml->createElement('InstitutionName', 'Royal Botanic Garden Edinburgh' ) );
         $xmlRoot->appendChild( $xml->createElement('DateSupplied', date('Y-m-d') ) );
         $xmlRoot->appendChild( $xml->createElement('PersonName', 'Elspeth Haston' ) );
         
         $xml->appendChild($xmlRoot);
         
    
        // loop through the results set and write out the data to the XML file.
         while ($row = $response->fetch_assoc()) {
             
            // we only write these out once if they aren't in the verifications table.
            $filingNameDone = false;
            $labelNameDone = false;
            $typeNameDone = false;
             
            $unitXML = $xml->createElement('Unit');
            $xmlRoot->appendChild($unitXML);
            $unitXML->appendChild($xml->createComment(' From specimen table: ' . $row['specimen_num']));
            $unitXML->appendChild($xml->createElement('UnitID', $row['specimen_barcode']));           
            $unitXML->appendChild($xml->createElement('DateLastModified', $row['modified']));  // xml date object
            
            /*
                IDENTIFICATIONS - from the verifications table.
           */
            $specimenNumber = $row['specimen_num'];
            $sql = "SELECT verif_num, name_num, veri_by, DATE_ADD('1967-12-31', INTERVAL veri_dt DAY) as veri_date FROM `verifications` WHERE specimen_num = $specimenNumber";
            $comeBack = $mysqli->query($sql);
             if ($mysqli->error) {
                 echo $mysqli->error;
                 exit(1);
             }
           
            while ($veri = $comeBack->fetch_assoc()) {
            
                $isFilingName = false;
                $kindOfType = null;
                
                // get out of here if we don't have a name_num in the verification
                if(!$veri['name_num']){
                    $unitXML->appendChild($xml->createComment('Verification without name: ' . $veri['verif_num']));
                    continue;
                }
            
                // is it the label name?
                if( $row['name_num'] == $veri['name_num']){
                    $labelNameDone = true;
                }
                
                // is it the filing name
                if( $row['filing_name_num'] == $veri['name_num']){
                    $filingNameDone = true;
                    $isFilingName = true;
                }
            
                // is it the type name
                if( $row['type_name_num'] == $veri['name_num']){
                    $typeNameDone = true;
                    $kindOfType = $row['type_kind'];
                }
                
                // set the flags so call to have it written out
                $identification = $xml->createElement('Identification');
                $identification->appendChild($xml->createComment(' From verifications table: ' . $veri['verif_num']));
                $unitXML->appendChild($identification);
                writeIdentification($xml, $identification, $veri['name_num'], $isFilingName, $kindOfType, $veri['veri_by'], $veri['veri_date']);
            
            }
            
            /*
                Identifications from the name number fields in the specimens record.
            */
            
            if ( !$labelNameDone && $row['name_num'] ){
                 $identification = $xml->createElement('Identification');
                 $identification->appendChild($xml->createComment(' From name_num '));
                 $unitXML->appendChild($identification);
                 writeIdentification($xml, $identification, $row['name_num'], false, null);
            }
            
            if ( !$filingNameDone && $row['filing_name_num']){
                 $identification = $xml->createElement('Identification');
                 $identification->appendChild($xml->createComment(' From filing_name_num '));
                 $unitXML->appendChild($identification);
                 writeIdentification($xml, $identification, $row['filing_name_num'], true, null);
            }
            
            if ( !$typeNameDone && $row['type_name_num']){
                 $identification = $xml->createElement('Identification');
                 $identification->appendChild($xml->createComment(' From type_name_num '));
                 $unitXML->appendChild($identification);
                 
                 $typeKind = $row['type_kind'];
                 if(!$typeKind) $typeKind = 'Type';
                 
                 writeIdentification($xml, $identification, $row['type_name_num'], false, $typeKind);
            }
            
            if($row['specimen_collector_name']){
                $unitXML->appendChild( $xml->createElement('Collectors', xmlSafe(  $row['specimen_collector_name'] ) ) );
            }else{
                $unitXML->appendChild( $xml->createElement('Collectors',  'Not on Sheet') );
            }

            if($row['specimen_collector_number']){
                $unitXML->appendChild( $xml->createElement('CollectorNumber', xmlSafe(  $row['specimen_collector_number']  ) ) );
            }else{
                $unitXML->appendChild( $xml->createElement('CollectorNumber', 's.n.') );
            }

            // do the date
            $collectionDate = $xml->createElement('CollectionDate');
            $unitXML->appendChild($collectionDate);


            if($row['specimen_collected_day']){
                $collectionDate->appendChild( $xml->createElement('StartDay', xmlSafe(  $row['specimen_collected_day']  ) ) );
            }  

            if($row['specimen_collected_month']){
                $collectionDate->appendChild( $xml->createElement('StartMonth', xmlSafe(  $row['specimen_collected_month']  ) ) );
            }


            // the year is always the same because our dates don't span
              if($row['specimen_collected_year']){
                  $collectionDate->appendChild( $xml->createElement('StartYear', xmlSafe(  $row['specimen_collected_year']  ) ) );
              }else{
                  $collectionDate->appendChild( $xml->createElement('OtherText', 'Not on sheet') );
              }

            if($row['specimen_country_on_label']){
                $unitXML->appendChild( $xml->createElement('CountryName', xmlSafe(  $row['specimen_country_on_label']  ) ) );
            }

            if($row['specimen_country_iso']){
                $unitXML->appendChild( $xml->createElement('ISO2Letter', xmlSafe(  $row['specimen_country_iso']  ) ) );
            }else{
                $unitXML->appendChild( $xml->createElement('ISO2Letter', 'ZZ' ) );
            }

            if($row['specimen_collected_location']){
                $unitXML->appendChild( $xml->createElement('Locality', xmlSafe(  $row['specimen_collected_location']  ) ) );
            }
                
            /* 
            $unitXML->appendChild( $xml->createElement('RelatedUnitID', 'FIXME - where is this in BGBASE?') );
            */

            if($row['specimen_collected_altitude']){
                $unitXML->appendChild( $xml->createElement('Altitude', xmlSafe(  $row['specimen_collected_altitude']  ) ) );
            }
                            
            $unitXML->appendChild( $xml->createElement('Notes', xmlSafe(  $row['specimen_name_free'] . " / " . $row['specimen_filing_name_free']  ) ) );
                        
         }
         
         header('Content-Type: text/xml'); 
         
         // send the headers to trigger a download
         if($download){
            
            // work out the batch number from the name of the batch
            $batchNumber = "XXX";

            $projectName = @$_GET['batch'];
            if($projectName){
                
                if (preg_match("/Batch #([0-9]+)/i", $projectName, $matches)){
                    $batchNumber = $matches[1];
                }
                
            }
  
            $filename = "E_" . $batchNumber . "_" . date('Ymd') . '.xml';
            header("Content-Disposition:attachment;filename=$filename");
        }
        
        $xml->formatOutput = true;
        
        echo $xml->saveXML();
        
         // stop execution so we don't put redraw the page
         exit(0);
        
    }
    


    function writeIdentification($xml, $identification, $nameNum, $filingName, $kindOfType, $verifier = 'Not on sheet', $verifDate = null){
        
        global $mysqli;
        
        $sql = "SELECT 
        concat(substring(family, 1,1), lower(substring(family, 2))) as family,
        concat(substring(genus, 1,1), lower(substring(genus, 2))) as genus,
        species,
        spec_auth,
        infra_epi,
        infra_auth,
        infra_rank
        
         FROM mobile.my_names WHERE name_num = $nameNum";
        $response = $mysqli->query($sql);
         if ($mysqli->error) {
             echo $mysqli->error;
             exit(1);
         }
        $row = $response->fetch_assoc();
        
        $identification->appendChild($xml->createElement('Family', $row['family']));
        $identification->appendChild($xml->createElement('Genus', $row['genus']));
        $identification->appendChild($xml->createElement('Species', xmlSafe($row['species'])  ));
        $identification->appendChild($xml->createElement('Author', xmlSafe($row['spec_auth'])  ));

        if($row['infra_rank']){
            $identification->appendChild($xml->createElement('Infra-specificRank', xmlSafe($row['infra_rank'])  ));
        }

        if($row['infra_epi']){
            $identification->appendChild($xml->createElement('Infra-specificEpithet', xmlSafe($row['infra_epi'])  ));
        }

        if($row['infra_auth']){
            $identification->appendChild($xml->createElement('Infra-specificAuthor', xmlSafe($row['infra_auth']) ));
        }
  
        $identification->appendChild($xml->createElement('PlantNameCode', $nameNum));

        // hard coded to the institution as we don't have this info in these fields of BGBASE
        $identification->appendChild($xml->createElement('Identifier', xmlSafe($verifier)  ));

        // the date is hard coded to the current date i.e. it is now that we assert it
        $identificationDate = $xml->createElement('IdentificationDate');
        $identification->appendChild($identificationDate);
        
        if($verifDate){
            
            $parts = explode('-', $verifDate);
            
            $identificationDate->appendChild($xml->createElement('StartDay',   $parts[2]    ));
            $identificationDate->appendChild($xml->createElement('StartMonth', $parts[1]  ));
            $identificationDate->appendChild($xml->createElement('StartYear',  $parts[0]   ));
            $identificationDate->appendChild($xml->createElement('OtherText', $verifDate ));
            
        }else{
            $identificationDate->appendChild($xml->createElement('OtherText', 'Not on Sheet'));
        }
     
        // do the type flag
        if($kindOfType){
            $identification->appendChild($xml->createElement('TypeStatus', xmlSafe($kindOfType) ));
        }else{
            $identification->appendChild($xml->createElement('TypeStatus', '-' ));
        }

        if($filingName){
            $identification->setAttribute('StoredUnderName', 'true');
        }else{
            $identification->setAttribute('StoredUnderName', 'false');
        }
        
        
    }
?>

<html>
    <head>
        <title>Aluka XML Generator</title>
        <link rel="stylesheet" type="text/css" media="screen" href="style/main.css" />
    </head>
    <body>
        <p>&nbsp;</p>
        <form action="index.php" method="GET" onSubmit="this.batch.value = this.project.options[this.project.selectedIndex].text">
        <table id="pageLayoutTable">
            <tr>
                <td><h1>Aluka XML Generator</h1></td>
            </tr>
            </tr>
                <td>
                    
                    Select Project:
                    
                    <select name="project">
                    
<?php
                // cut back into PHP to generate a list of projects from a database query as the page is created

                // make a temporary table for efficient joining
                $sql = "CREATE TEMPORARY TABLE mobile.pc SELECT DISTINCT(project_code) FROM specimens_mv WHERE project_code IS NOT NULL;";
                $mysqli->query($sql);

                // actually do the query.
                $sql = "SELECT code,project_name FROM mobile.pc JOIN project_codes ON pc.project_code = project_codes.code WHERE  project_name LIKE '%mellon%' ORDER BY code DESC;";
                $response = $mysqli->query($sql);
                
                // if there was an error running the query just echo it out
                if ($mysqli->error) {
                    echo "</select>"; // close the select or it won't be visible.
                    echo $mysqli->error;
                }
                                
                while ($row = $response->fetch_assoc()) {
                    
                    $val = $row['code'];
                    $display = $row['project_name'];
                    
                    $selected = "";
                    if($currentProject == $val){
                        $selected = "selected";
                    }
                    
                    echo "<option value=\"$val\" $selected >$display</option>";
                }

?>                        
                    </select>
                    <input type="hidden" name="batch" value="" />
                    <p>
                    Download as File: <input type="checkbox" checked="true" name="download" />
                    </p>
                    <p>
                        <input type="submit" value="Generate XML" />
                    </p>
                    <p>
                        Be patient. It can take up to 45 seconds to generate the file!
                    </p>
                </td>
            </tr>
        </table>
        </form>
       
        
    </body>
    
</html>

<?php

function xmlSafe($unsafe){
    
 
    //$s = utf8_encode($unsafe);
    //$s = iconv ( "ISO-8859-1" , "UTF-8" , $unsafe );
    $s = $unsafe;
    $s = str_replace("&", "&amp;", $s);
        
    return $s;

}




?>