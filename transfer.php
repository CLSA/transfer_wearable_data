<?php
set_time_limit( 7200 ); // two hours
error_reporting( E_ALL | E_STRICT );
define( 'VERSION', '1.0' );

// data type paths
define( 'PATHS', [
  'actigraph' => [
    'local' => 'data/actigraph/',
    'remote' => '130.113.54.124:/home/patrick/HAM/actigraph/'
  ],
  'ticwatch' => [
    'local' => 'data/ticwatch/',
    'remote' => '130.113.54.124:/home/patrick/HAM/ticwatch/'
  ],
] );

function out( $message )
{
  if( !DEBUG ) printf( "%s: %s\n", date( 'Y-m-d H:i:s' ), $message );
}

function rsync( $local_path, $remote_path, $port = NULL, $timeout = 10 )
{
  // count the number of files in the local path
  $files = 0;
  if( is_dir( $local_path ) )
  {
    $output = NULL;
    $command = exec( sprintf( 'find %s -type f | wc -l 2> /dev/null', $local_path ), $output );
    $files = intval( current( $output ) );
  }

  if( 0 == $files )
  {
    out( sprintf( 'No files to transfer', $files ) );
  }
  else
  {
    $command = sprintf(
      'rsync -rtcv --timeout=%d %s %s %s',
      $timeout,
      is_null( $port ) ? '' : sprintf( '-e "ssh -p %d"', $port ),
      $local_path,
      $remote_path
    );

    $result_code = 0;
    if( DEBUG )
    {
      printf( "%s\n", $command );
    }
    else
    {
      $output = NULL;
      exec( sprintf( '%s 2> /dev/null', $command ), $output, $result_code );
    }

    if( 0 < $result_code )
    {
      $error = "Unknown error";
      if( 1 == $result_code ) $error = "Syntax or usage error";
      else if( 2 == $result_code ) $error = "Protocol incompatibility";
      else if( 3 == $result_code ) $error = "Errors selecting input/output files, dirs";
      else if( 4 == $result_code ) $error = "Requested action not supported: an attempt was made to manipulate 64-bit files on a platform that cannot support them; or an option was specified that is supported by the client and not by the server.";
      else if( 5 == $result_code ) $error = "Error starting client-server protocol";
      else if( 6 == $result_code ) $error = "Daemon unable to append to log-file";
      else if( 10 == $result_code ) $error = "Error in socket I/O";
      else if( 11 == $result_code ) $error = "Error in file I/O";
      else if( 12 == $result_code ) $error = "Error in rsync protocol data stream";
      else if( 13 == $result_code ) $error = "Errors with program diagnostics";
      else if( 14 == $result_code ) $error = "Error in IPC code";
      else if( 20 == $result_code ) $error = "Received SIGUSR1 or SIGINT";
      else if( 21 == $result_code ) $error = "Some error returned by waitpid()";
      else if( 22 == $result_code ) $error = "Error allocating core memory buffers";
      else if( 23 == $result_code ) $error = "Partial transfer due to error";
      else if( 24 == $result_code ) $error = "Partial transfer due to vanished source files";
      else if( 25 == $result_code ) $error = "The --max-delete limit stopped deletions";
      else if( 30 == $result_code ) $error = "Timeout in data send/receive";
      else if( 35 == $result_code ) $error = "Timeout waiting for daemon connection";

      out( sprintf( 'Transfer failed: %s', $error ) );
      return false;
    }

    out( sprintf( 'Done, %d file(s) transferred', $files ) );
  }

  return 0 < $files;
}

function archive( $path )
{
  $current_dir = getcwd();

  // get the parent and child (final) directory from the path
  preg_match( '#[^/]+/$#', $path, $matches );
  $child_dir = substr( current( $matches ), 0, -1 );
  $parent_dir = str_replace( sprintf( '/%s', $child_dir ), '', $path );

  // move into the parent directory
  chdir( $parent_dir );

  $filename = sprintf(
    '%s.%s.zip',
    $child_dir,
    date( 'Y-m-d' )
  );

  // zip all the files in the child directory
  $command = sprintf(
    'zip -r %s %s',
    $filename,
    $child_dir
  );

  $result_code = 0;
  if( DEBUG )
  {
    printf( "%s\n", $command );
  }
  else
  {
    $output = NULL;
    exec( sprintf( '%s', $command ), $output, $result_code );
  }

  // move back into the original directory
  chdir( $current_dir );

  if( 0 < $result_code )
  {
    out( sprintf( 'Unable to archive files in "%s", received error code "%d".', $filename, $result_code ) );
    return false;
  }

  // now that they are archived, delete all files in the path
  $command = sprintf(
    'rm -rf %s',
    $path
  );

  $result_code = 0;
  if( DEBUG )
  {
    printf( "%s\n", $command );
  }
  else
  {
    $output = NULL;
    exec( sprintf( '%s 2> /dev/null', $command ), $output, $result_code );
  }

  if( 0 < $result_code )
  {
    out( sprintf( 'Unable to remove files in "%s", received error code "%d".', $path, $result_code ) );
    return false;
  }

  return true;
}

function usage()
{
  printf(
    "transfer.php version %s\n".
    "Usage: php transfer.php [OPTION]\n".
    "-d  Outputs the script's command without executing them\n".
    "-h  Displays this usage message\n".
    "-t  The data type to transfer: actigraph or ticwatch\n",
    VERSION
  );
}

function parse_arguments( $arguments )
{
  $operation_list = [];
  foreach( $arguments as $index => $arg )
  {
    if( 0 == $index ) continue; // ignore the script name
    if( '-' == $arg[0] )
    {
      $option = substr( $arg, 1 );
      // check that the option is valid
      if( !in_array( $option, ['d', 'h', 't'] ) )
      {
        printf( 'Invalid operation "%s"%s', $arg, "\n\n" );
        usage();
        die();
      }

      // add a new option
      $operation_list[] = [ 'option' => $option ];
    }
    else
    {
      // add an argument to the new option
      $arg = trim( $arg, "\"' \t" );
      $operation_index = count( $operation_list )-1;
      if( -1 == $operation_index || array_key_exists( 'argument', $operation_list[$operation_index] ) )
      {
        // lone arguments are not allowed
        printf( 'Unexpected argument "%s"%s', $arg, "\n\n" );
        usage();
        die();
      }
      else if( in_array( $operation_list[$operation_index]['option'], ['d', 'h'] ) )
      {
        // some options should not have any arguments
        printf(
          'Argument "%s" not expected after option "%s"%s',
          $arg,
          $operation_list[$operation_index]['option'],
          "\n\n"
        );
        usage();
        die();
      }

      $operation_list[$operation_index]['argument'] = $arg;
    }
  }

  $settings = [
    'DEBUG' => false,
    'DATA_TYPE' => NULL
  ];
  foreach( $operation_list as $op )
  {
    $option = $op['option'];
    $argument = array_key_exists( 'argument', $op ) ? $op['argument'] : NULL;

    if( 'd' == $option )
    {
      $settings['DEBUG'] = true;
    }
    else if( 'h' == $option )
    {
      usage();
      die();
    }
    else if( 't' == $option )
    {
      if( !in_array( $argument, ['actigraph', 'ticwatch'] ) )
      {
        printf(
          'Operation "-%s" expects one of the following data types as an argument: actigraph, ticwatch%s',
          $option,
          "\n\n"
        );
        usage();
        die();
      }
      $settings['DATA_TYPE'] = $argument;
    }
  }

  // make sure the path was specified
  if( is_null( $settings['DATA_TYPE'] ) )
  {
    printf( "No data type specified\n\n" );
    usage();
    die();
  }

  return $settings;
}

// parse the input arguments
foreach( parse_arguments( $argv ) as $setting => $value ) define( $setting, $value );

out( sprintf( 'Transfering %s files', DATA_TYPE ) );
if( rsync( PATHS[DATA_TYPE]['local'], PATHS[DATA_TYPE]['remote'], 524 ) )
{
  // the rsync was successful so archive the data
  archive( PATHS[DATA_TYPE]['local'] );
}
