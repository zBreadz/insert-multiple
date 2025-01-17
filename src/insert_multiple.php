<?php


namespace jotagp\insert_multiple;


class insert_multiple {
  

  public $table_properties = []; // array that holds the table details
  public $table_name = []; // string that holds the name of the table
  public $final_array = []; // array of arrays, with all data
  public $connection = []; // connection with database mysql/mariadb
  public $insert_multiple = true; // flag to insert_multiple. insert one record per time
  public $string_types = ['CHAR', 'VARCHAR', 'TINYTEXT', 'TEXT', 'BLOB', 'MEDIUMTEXT', 'MEDIUMBLOB', 'LONGTEXT', 'LONGBLOB', 'DATE', 'DATETIME', 'TIMESTAMP', 'TIME', 'YEAR']; // defautl string types
  public $table_pk_autoincrement = []; // fetch the increment value 

  // update
  public $update_if_exists = false;
  public $fields_to_update = [];
  public $skip_if_new_is_empty = false;
  public $skip_if_already_exists = false;
  public $print_query = false;
  public $concat_new_values = false;


  // constructor
  public function __construct($connection, $table_name) {

    // set the object attributes
    $this->connection = $connection;
    $this->table_name = $table_name;

    // get auto_increment
    $select_increment = "SHOW TABLE STATUS LIKE '{$table_name}'";
    $this->table_pk_autoincrement = $connection->query($select_increment)->fetch_assoc()['Auto_increment'] -1 ; // because we increment +1 later

    // fetch the table details from the database
    $select_metadata = "DESC {$table_name}";
    $rows = $connection->query($select_metadata) or die("\nError: could not fetch metadata. ". $connection->errno . " - " . $connection->error);
    
    // checks if the data was found
    if ($rows->num_rows > 0) {

      // iterate over the tuples
      foreach ($rows as $row) {

        // that variable contains the column name 
        $column_name = $row['Field'];

        // go to next iteration, if that is a primary key autoinrecemnt (keep database decision)
        // if ($row['Key'] == 'PRI' && $row['Extra'] == 'auto_increment') continue;

        // make array with the table properties
        foreach ($row as $key => $val) {

          // validate column type
          if ($key == 'Type') {
            
            // keep in type property Type, only characters between a and z. Ex: from: varchar(100) to: varchar 
            $val = preg_replace('/[^a-zA-Z]+/', '', $val);
                          
          }

          $this->table_properties[$column_name][$key] = $val;

        }

      }
      
    }
    else {

      die("\nNo metadata found");

    }

  }


  // function do add new value into insert
  public function push($any) {
    
    // temporary array to store that data
    $new_any = [];

    // iterate over the table properties
    foreach ($this->table_properties as $column_name => $property) {

      // check if exists this column name at variable Any, and if is not empty, this iteration column name  
      if (isset($any[$column_name]) && strlen($any[$column_name]) > 0) {

        $new_any[$column_name] = addslashes($any[$column_name]);

      }
      else {

        // check if de property is primary key
        if ($property['Key'] == 'PRI') {
        
          // remove from table properties the pk column, and make de database decision
          unset($this->table_properties[$column_name]);
          // echo "\n Entrou"; exit;
        
        }
        else {

          $new_any[$column_name] = strlen($property['Default']) > 0 ? $property['Default'] : 'NULL';

        }

      }

      // concat the quoation marks, if necessary
      if ((in_array(strtoupper($property['Type']), $this->string_types) || strstr($property['Type'], 'enum')) && strstr($new_any[$column_name], '()') == FALSE ) {

        $new_any[$column_name] = "'". $new_any[$column_name] . "'";

      }

    }

    // increment the final array
    $this->final_array[] = implode(", ", $new_any);
    
  }


  // function to run insert
  public function exec() {

    // The variable insert_size_limit stores the maximum size in bytes of a transaction allowed by mysql
    $insert_size_limit = $this->connection->query("show variables like 'max_allowed_packet'")->fetch_assoc()['Value'];

    // And we subtract 10% from it, for a margin of error
    $insert_size_limit -= ($insert_size_limit * 0.1);

    // The variable current_size stores the value in bytes of each tuple
    $current_size = 0;

    // The Partition variable will be the amount of multiple inserts that will happen
    $partition = 0;

    // It will be the final array, with the processed data. Each index will be a value of the Partition variable
    $inserts = [];


    // Split the final_array array into smaller arrays that fit in an insert
    foreach ($this->final_array as $index => $array) {

      // Increments the current_size, with the bytes of the iterated tulpa
      $current_size += mb_strlen($array, '8bit');

      // If the insert_multiple flag is true on the function parameters, the insertion must happen one by one tuple
      $current_size = $this->insert_multiple ? $current_size : 1;
      $insert_size_limit = $this->insert_multiple ? $insert_size_limit : 1;

      // As long as current_size is less than or equal to insert_size_limit, we insert the tuple in the same partition, else, we insert the tuple in a new partition.
      if ($current_size <= $insert_size_limit) {

        $inserts[$partition][$index] = $array;

        // if insert_multiple is false, create a partition for each tuple
        if (!$this->insert_multiple) {

          // Every time current_size is close to insert_size_limit, a new partition is created
          $partition += 1;

        }

      }
      else {

        $partition += 1;

        // Initializes the value of the variable current_size again, with the bytes of the current tuple
        $current_size = mb_strlen($array, '8bit');
        $inserts[$partition][$index] = $array;

      }

    }


    // Concatenate all positions within the partition, into a single position within the partition    
    foreach ($inserts as $index => $insert) {

      $insert = join('), (', $insert);
      $inserts[$index] = $insert;

    }

    
    
    // Insert data
    $this->connection->begin_transaction();


    foreach ($inserts as $partition => $values) {
       
      $columns = implode(", ", array_keys($this->table_properties));
      $insert_query = sprintf("INSERT INTO %s (%s) VALUES (%s)", $this->table_name, $columns, $values);

      // control update on duplicate key
      if ($this->update_if_exists && sizeof($this->fields_to_update) > 0) {


        $insert_query .= " ON DUPLICATE KEY UPDATE ";
        $updates = []; // array with update instructions

        foreach ($this->fields_to_update as $field) {

          if ($this->concat_new_values) {

            $updates[] = "{$field} = IF({$field} IS NULL, VALUES({$field}), CONCAT({$field}, VALUES({$field})))";

          }
          elseif ($this->skip_if_already_exists) {
            
            $updates[] = "{$field} = IF({$field} IS NOT NULL AND TRIM({$field}) NOT LIKE '', {$field}, VALUES({$field}))";

          }
          elseif ($this->skip_if_new_is_empty) {

            $updates[] = "{$field} = IF(VALUES({$field}) IS NOT NULL AND TRIM(VALUES({$field})) NOT LIKE '', VALUES({$field}), {$field})";

          }
          else {

            $updates[] = "{$field} = VALUES({$field})";

          }

        }

        
        $insert_query .= implode(", ", $updates);
        

      }

      if ($this->print_query) {
        echo "\n\n". $insert_query;
        exit;
      }

      // fix de NULL values
      if (strstr($insert_query, 'NULL') == TRUE) {

        $insert_query = str_replace('\'NULL\'', 'NULL', $insert_query);

      }

      // print 
      if ($this->print_query) {echo "\n\n\t\t". $insert_query; exit;}

      try {

        $this->connection->query($insert_query);

      }
      catch (\Exception $e) {

        // break the multiple inserts into single inserts, 

        echo "\n\n\tHouston, we have a problem: {$e->getMessage()}...\n";
        
        if ($this->insert_multiple) {

          $command = explode(" VALUES ", $insert_query);
          $command_prefix = reset($command) . " VALUES ";
          $values = explode("), (", end($command));          
          $values_size = sizeof($values);
          $values[0] = ltrim($values[0], "(");
          $values[$values_size -1] = rtrim($values[$values_size -1], ")");

          foreach ($values as $value) {

            $single_insert = $command_prefix ."(". $value .")";

            try {

              $this->connection->query($single_insert);

            }
            catch (\Exception $e) {

              die("\n\n\t\tQuery: {$single_insert}");

            }

          }

        }
        else {

          die("\n\n\t\tQuery: {$insert_query}");

        }


      }


    }


    $this->connection->commit();

    
  }

  
  // function to set configs
  public function config($any) {

    // control if inserts are multiple or single
    if (isset($any['insert_multiple'])) 
      $this->insert_multiple = $any['insert_multiple'];

    // control updates if exists
    if (isset($any['update_if_exists']))
      $this->update_if_exists = true;

    // control fields to update
    if (isset($any['update_if_exists']['fields_to_update']))
      $this->fields_to_update = $any['update_if_exists']['fields_to_update'];

    // skip update if new is empty
    if (isset($any['update_if_exists']['skip_if_new_is_empty']))
      $this->skip_if_new_is_empty = $any['update_if_exists']['skip_if_new_is_empty'];
    
    // skip update if already exists
    if (isset($any['update_if_exists']['skip_if_already_exists']))
      $this->skip_if_already_exists = $any['update_if_exists']['skip_if_already_exists'];
    
    // print query
    if (isset($any['print_query'])) 
      $this->print_query = $any['print_query'];
    
    // concat new values
    if (isset($any['update_if_exists']['concat_new_values']))
      $this->concat_new_values = $any['update_if_exists']['concat_new_values'];
    
  }


  // function than returns de next increment from pk
  public function pk() {

    return $this->table_pk_autoincrement += 1;

  }

  
  // destructor
  public function __destruct() {
  
    // echo "\ndestruct";
    // echo $this->table_name;
    // var_dump($this->connection);

  }


}


?>