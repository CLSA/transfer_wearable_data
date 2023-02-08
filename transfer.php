<?php
/**
 * transfer.php
 * 
 * A script to transfer wearable data from local machines to the data repository (NFS server)
 * author: Patrick Emond <emondpd@mcmaster.ca>
 */

set_time_limit( 7200 ); // two hours
error_reporting( E_ALL | E_STRICT );
require_once( 'arguments.class.php' );

define( 'VERSION', '1.0' );

/**
 * Prints datetime-indexed messages to stdout
 * @param string $message
 */
function out( $message )
{
  if( !DEBUG ) printf( "%s: %s\n", date( 'Y-m-d H:i:s' ), $message );
}

/**
 * Transfers files to the remote server, returning true when successful and false when not.
 * @param string $local_path The source folder
 * @param string $remote_path The destination folder (IP:/path)
 * @param integer $port The SSH port of the remote server
 * @param integer $timeout How many seconds before giving up on the SSH connection
 * @return boolean
 */
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
    $output = NULL;
    DEBUG ? printf( "%s\n", $command ) : exec( sprintf( '%s 2> /dev/null', $command ), $output, $result_code );

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

/**
 * Archives the files found in the given path
 * @param string $path The local path to archive
 * @return boolean
 */
function archive( $path )
{
  $current_dir = getcwd();

  // get the parent and child (final) directory from the path
  preg_match( '#[^/]+/$#', $path, $matches );
  $child_dir = substr( current( $matches ), 0, -1 );
  $parent_dir = str_replace( sprintf( '/%s', $child_dir ), '', $path );

  // move into the parent directory
  DEBUG ? printf( "cd %s\n", $parent_dir ) : chdir( $parent_dir );

  $filename = sprintf( '%s.%s.zip', $child_dir, date( 'Y-m-d' ) );

  // zip all the files in the child directory
  $command = sprintf( 'zip -r %s %s', $filename, $child_dir );

  $result_code = 0;
  $output = NULL;
  DEBUG ? printf( "%s\n", $command ) : exec( sprintf( '%s', $command ), $output, $result_code );

  // move back into the original directory
  DEBUG ? printf( "cd %s\n", $current_dir ) : chdir( $current_dir );

  if( 0 < $result_code )
  {
    out( sprintf( 'Unable to archive files in "%s", received error code "%d".', $filename, $result_code ) );
    return false;
  }

  // now that they are archived, delete all files in the path
  $command = sprintf( 'rm -rf %s*', $path );

  $result_code = 0;
  $output = NULL;
  DEBUG ? printf( "%s\n", $command ) : exec( sprintf( '%s 2> /dev/null', $command ), $output, $result_code );

  if( 0 < $result_code )
  {
    out( sprintf( 'Unable to remove files in "%s", received error code "%d".', $path, $result_code ) );
    return false;
  }

  return true;
}

/**
 * Purges all archived files for the given path that are older than $days_old
 * @param string $path The local path that archives are created from
 * @param integer $days_old The number of days before an archived file is deleted
 * @return boolean
 */
function purge( $path, $days_old )
{
  // delete all files older than the input argument
  $zip_glob = preg_replace( '#/$#', '.[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9].zip', $path );
  $command = sprintf( 'find %s -mtime +%d -exec rm {} \;', $zip_glob, $days_old );

  $result_code = 0;
  $output = NULL;
  DEBUG ? printf( "%s\n", $command ) : exec( sprintf( '%s 2> /dev/null', $command ), $output, $result_code );

  if( 0 < $result_code )
  {
    out( sprintf( 'Unable to remove files in "%s", received error code "%d".', $path, $result_code ) );
    return false;
  }

  out( sprintf( 'Deleted all archived files older than %d days', $days_old ) );

  return true;
}


////////////////////////////////////////////////////////////////////////////////////////////////////////////////
// build the command argument details, then parse the passed args
$arguments = new arguments;
$arguments->set_version( VERSION );
$arguments->set_description(
  "A script that will attempt to transfer wearable data files from a local to a remote directory.\n".
  "If the transfer fails it can be run again, once successful local files will be archived."
);
$arguments->add_option( 'd', 'debug', 'Outputs the script\'s commands without executing them' );
$arguments->add_option(
  'p',
  'port',
  'The port to use when connecting to the remote server',
  true,
  22
);
$arguments->add_option(
  'r',
  'remove',
  'Permanently delete archived files after they are ARGUMENT days old',
  true
);
$arguments->add_option(
  't',
  'timeout',
  'How long to wait before giving up on a remote connection',
  true,
  10
);
$arguments->add_input( 'LOCAL_DIRECTORY', 'The local directory containing files for transfer' );
$arguments->add_input(
  'REMOTE_DIRECTORY',
  'The remote directory to transfer files into (ex: 1.1.1.1:/data/temporary/HAM/actigraph)'
);

$args = $arguments->parse_arguments( $argv );

define( 'DEBUG', array_key_exists( 'debug', $args['option_list'] ) );
$port = $args['option_list']['port'];
$timeout = $args['option_list']['timeout'];
$purge_days_old = array_key_exists( 'remove', $args['option_list'] ) ? $args['option_list']['remove'] : NULL;
$local_dir = $args['input_list']['LOCAL_DIRECTORY'];
if( '/' != $local_dir[strlen( $local_dir )-1] ) $local_dir .= '/';
$remote_dir = $args['input_list']['REMOTE_DIRECTORY'];
if( '/' != $remote_dir[strlen( $remote_dir )-1] ) $remote_dir .= '/';

// sanitize the purge days old parameter (must be an integer >= 0)
if( !is_null( $purge_days_old ) && (
 !( (string)(int)$purge_days_old === (string)$purge_days_old ) ||
 0 > $purge_days_old
) ) {
  printf( "Option to remove files, \"%s\", is invalid\n", $purge_days_old );
  $arguments->usage();
  exit( 1 );
}

out( sprintf( 'Transfering files from "%s"', $local_dir ) );
if( rsync( $local_dir, $remote_dir, $port, $timeout ) )
{
  // the rsync was successful so archive the data
  archive( $local_dir );
}

if( !is_null( $purge_days_old ) ) purge( $local_dir, $purge_days_old );
