<?php

if($_GET['errors'] == 'true'){
  error_reporting(E_ALL);
  ini_set('display_errors',1);
}

try{
  $db = pg_connect(getenv('DATABASE_URL'));
  if (!$db) {
      echo "Database connection error.";
      exit;
  }else{
    $result = pg_query($db, "SELECT random()");
    print $result;
  }
}catch (Exception $e){
  print $e;
}