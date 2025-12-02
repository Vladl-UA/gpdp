статистика<br><pre>
<?php
require 'voc.php';
#defined( '_INDEX' ) or die( 'Доступ запрещен!' );
$c_date = mktime (0, 0, 0, $GP[dat_m], $GP[dat_day], $GP[dat_god]);
 #global $querry_insert_user;
 define ( "DB_HOST" , "localhost" ); 
 define ( "DB_USER" , "root" );
 define ( "DB_PASS" , "" );
 define ( "DB_NAME" , "stat" );
# define ( "DB_NAME1" , "relation" );
 define ( "_SESSION_TIME" , "3600" );
 define ( "_METHOD" , "GET" );
    $link = mysql_connect(DB_HOST, DB_USER, DB_PASS) or die("Could not connect : " .
        mysql_error());
    mysql_select_db(DB_NAME) or die("Could not select database");

    
    $tables_res = mysql_query('SHOW TABLES');
       while ($r = mysql_fetch_array($tables_res)){
   $s[] = $r[0];
    }
    print_r ($s); 
    
    echo"---------------------------------------------<br>";
    
    $sc = count ($s);
    for($c = 0; $c < $sc; $c++){
    	echo $c . "<br>";
    	$t = each($s);
    	echo "<b>" . $t[1] . "</b><br>";
    	 $tq = ("SHOW COLUMNS FROM " . DB_NAME . "." . $t[1] . ";");
    	echo $tq;
    	echo mysql_error();
      	    	$t_res = mysql_query($tq);
    	    	while ($tr = mysql_fetch_array($t_res)){
    		$tt[]= $tr[0];
       	}
    
        	print_r ($tt);
        	unset ($tt);
    }
    print_r($map);
    ?>
    </pre>