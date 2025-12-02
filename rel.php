статистика<br><pre>
<?php
require 'voc.php';
require('FH3/class.FormHandler.php');
#defined( '_INDEX' ) or die( 'Доступ запрещен!' );
$c_date = mktime (0, 0, 0, $GP[dat_m], $GP[dat_day], $GP[dat_god]);
 #global $querry_insert_user;
 define ( "DB_HOST" , "localhost" ); 
 define ( "DB_USER" , "root" );
 define ( "DB_PASS" , "" );
 define ( "DB_NAME" , "statistic" );
# define ( "DB_NAME1" , "relation" );
 define ( "DB_NAME1" , "stat" );
 define ( "_SESSION_TIME" , "3600" );
 define ( "_METHOD" , "GET" );
    $link = mysql_connect(DB_HOST, DB_USER, DB_PASS) or die("Could not connect : " . mysql_error());
    mysql_select_db(DB_NAME1) or die("Could not select database");

    

    $tables_res = mysql_list_tables(DB_NAME1);
       while ($r = mysql_fetch_array($tables_res)){
   $s[] = $r[0];
    }
    print_r ($s); 
    echo"-----------------------------------<br>";
    
    $sc = count ($s);
    for($c = 0; $c < $sc; $c++){
    	$t = each($s);
    	echo "<br><b>" . $t[1] . ":</b><br>";
$fields = mysql_list_fields(DB_NAME1 , $t[1]);
$columns = mysql_num_fields($fields);

for ($i = 0; $i < $columns; $i++) {
	echo $tt[] = mysql_field_name($fields, $i) . "\n";
	
}
    	
#       	print_r ($tt);
        	unset ($tt);
    }
     	
?>
    </pre>