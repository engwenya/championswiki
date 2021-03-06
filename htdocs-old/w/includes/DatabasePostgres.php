<?php

/**
 * This is Postgres database abstraction layer.
 *
 * As it includes more generic version for DB functions,
 * than MySQL ones, some of them should be moved to parent
 * Database class.
 *
 * @package MediaWiki
 */

class DatabasePostgres extends Database {
	var $mInsertId = NULL;
	var $mLastResult = NULL;

	function DatabasePostgres($server = false, $user = false, $password = false, $dbName = false,
		$failFunction = false, $flags = 0 )
	{

		global $wgOut, $wgDBprefix, $wgCommandLineMode;
		# Can't get a reference if it hasn't been set yet
		if ( !isset( $wgOut ) ) {
			$wgOut = NULL;
		}
		$this->mOut =& $wgOut;
		$this->mFailFunction = $failFunction;
		$this->mCascadingDeletes = true;
		$this->mCleanupTriggers = true;
		$this->mStrictIPs = true;
		$this->mFlags = $flags;
		$this->open( $server, $user, $password, $dbName);

	}

	static function newFromParams( $server = false, $user = false, $password = false, $dbName = false,
		$failFunction = false, $flags = 0)
	{
		return new DatabasePostgres( $server, $user, $password, $dbName, $failFunction, $flags );
	}

	/**
	 * Usually aborts on failure
	 * If the failFunction is set to a non-zero integer, returns success
	 */
	function open( $server, $user, $password, $dbName ) {
		# Test for Postgres support, to avoid suppressed fatal error
		if ( !function_exists( 'pg_connect' ) ) {
			throw new DBConnectionError( $this, "Postgres functions missing, have you compiled PHP with the --with-pgsql option?\n (Note: if you recently installed PHP, you may need to restart your webserver and database)\n" );
		}


		global $wgDBport;

		$this->close();
		$this->mServer = $server;
		$port = $wgDBport;
		$this->mUser = $user;
		$this->mPassword = $password;
		$this->mDBname = $dbName;

		$success = false;
		$hstring="";
		if ($server!=false && $server!="") {
			$hstring="host=$server ";
		}
		if ($port!=false && $port!="") {
			$hstring .= "port=$port ";
		}

		if (!strlen($user)) { ## e.g. the class is being loaded
			return;
		}

		error_reporting( E_ALL );
		@$this->mConn = pg_connect("$hstring dbname=$dbName user=$user password=$password");

		if ( $this->mConn == false ) {
			wfDebug( "DB connection error\n" );
			wfDebug( "Server: $server, Database: $dbName, User: $user, Password: " . substr( $password, 0, 3 ) . "...\n" );
			wfDebug( $this->lastError()."\n" );
			return false;
		}

		$this->mOpened = true;
		## If this is the initial connection, setup the schema stuff and possibly create the user
		if (defined('MEDIAWIKI_INSTALL')) {
			global $wgDBname, $wgDBuser, $wgDBpass, $wgDBsuperuser, $wgDBmwschema,
				$wgDBts2schema, $wgDBts2locale;
			print "OK</li>\n";

			print "<li>Checking the version of Postgres...";
			$version = pg_fetch_result($this->doQuery("SELECT version()"),0,0);
			if (!preg_match("/PostgreSQL (\d+\.\d+)(\S+)/", $version, $thisver)) {
				print "<b>FAILED</b> (could not determine the version)</li>\n";
				dieout("</ul>");
			}
			$PGMINVER = "8.1";
			if ($thisver[1] < $PGMINVER) {
				print "<b>FAILED</b>. Required version is $PGMINVER. You have $thisver[1]$thisver[2]</li>\n";
				dieout("</ul>");
			}
			print "version $thisver[1]$thisver[2] is OK.</li>\n";

			$safeuser = $this->quote_ident($wgDBuser);
			## Are we connecting as a superuser for the first time?
			if ($wgDBsuperuser) {
				## Are we really a superuser? Check out our rights
				$SQL = "SELECT
						CASE WHEN usesuper IS TRUE THEN
							CASE WHEN usecreatedb IS TRUE THEN 3 ELSE 1 END
							ELSE CASE WHEN usecreatedb IS TRUE THEN 2 ELSE 0 END
                        END AS rights
						FROM pg_catalog.pg_user WHERE usename = " . $this->addQuotes($wgDBsuperuser);
				$rows = $this->numRows($res = $this->doQuery($SQL));
				if (!$rows) {
					print "<li>ERROR: Could not read permissions for user \"$wgDBsuperuser\"</li>\n";
					dieout('</ul>');
				}
				$perms = pg_fetch_result($res, 0, 0);

				$SQL = "SELECT 1 FROM pg_catalog.pg_user WHERE usename = " . $this->addQuotes($wgDBuser);
				$rows = $this->numRows($this->doQuery($SQL));
				if ($rows) {
					print "<li>User \"$wgDBuser\" already exists, skipping account creation.</li>";
				}
				else {
					if ($perms != 1 and $perms != 3) {
						print "<li>ERROR: the user \"$wgDBsuperuser\" cannot create other users. ";
						print 'Please use a different Postgres user.</li>';
						dieout('</ul>');
					}
					print "<li>Creating user <b>$wgDBuser</b>...";
					$safepass = $this->addQuotes($wgDBpass);
					$SQL = "CREATE USER $safeuser NOCREATEDB PASSWORD $safepass";
					$this->doQuery($SQL);
					print "OK</li>\n";
				}
				## User now exists, check out the database
				if ($dbName != $wgDBname) {
					$SQL = "SELECT 1 FROM pg_catalog.pg_database WHERE datname = " . $this->addQuotes($wgDBname);
					$rows = $this->numRows($this->doQuery($SQL));
					if ($rows) {
						print "<li>Database \"$wgDBname\" already exists, skipping database creation.</li>";
					}
					else {
						if ($perms < 2) {
							print "<li>ERROR: the user \"$wgDBsuperuser\" cannot create databases. ";
							print 'Please use a different Postgres user.</li>';
							dieout('</ul>');
						}
						print "<li>Creating database <b>$wgDBname</b>...";
						$safename = $this->quote_ident($wgDBname);
						$SQL = "CREATE DATABASE $safename OWNER $safeuser ";
						$this->doQuery($SQL);
						print "OK</li>\n";
						## Hopefully tsearch2 and plpgsql are in template1...
					}

					## Reconnect to check out tsearch2 rights for this user
					print "<li>Connecting to \"$wgDBname\" as superuser \"$wgDBsuperuser\" to check rights...";
					@$this->mConn = pg_connect("$hstring dbname=$wgDBname user=$user password=$password");
					if ( $this->mConn == false ) {
						print "<b>FAILED TO CONNECT!</b></li>";
						dieout("</ul>");
					}
					print "OK</li>\n";
				}

				## Tsearch2 checks
				print "<li>Checking that tsearch2 is installed in the database \"$wgDBname\"...";
				if (! $this->tableExists("pg_ts_cfg", $wgDBts2schema)) {
					print "<b>FAILED</b>. tsearch2 must be installed in the database \"$wgDBname\".";
					print "Please see <a href='http://www.devx.com/opensource/Article/21674/0/page/2'>this article</a>";
					print " for instructions or ask on #postgresql on irc.freenode.net</li>\n";
					dieout("</ul>");
				}				
				print "OK</li>\n";
				print "<li>Ensuring that user \"$wgDBuser\" has select rights on the tsearch2 tables...";
				foreach (array('cfg','cfgmap','dict','parser') as $table) {
					$SQL = "GRANT SELECT ON pg_ts_$table TO $safeuser";
					$this->doQuery($SQL);
				}
				print "OK</li>\n";


				## Setup the schema for this user if needed
				$result = $this->schemaExists($wgDBmwschema);
				$safeschema = $this->quote_ident($wgDBmwschema);
				if (!$result) {
					print "<li>Creating schema <b>$wgDBmwschema</b> ...";
					$result = $this->doQuery("CREATE SCHEMA $safeschema AUTHORIZATION $safeuser");
					if (!$result) {
						print "<b>FAILED</b>.</li>\n";
						dieout("</ul>");
					}
					print "OK</li>\n";
				}
				else {
					print "<li>Schema already exists, explicitly granting rights...\n";
					$safeschema2 = $this->addQuotes($wgDBmwschema);
					$SQL = "SELECT 'GRANT ALL ON '||pg_catalog.quote_ident(relname)||' TO $safeuser;'\n".
							"FROM pg_catalog.pg_class p, pg_catalog.pg_namespace n\n".
							"WHERE relnamespace = n.oid AND n.nspname = $safeschema2\n".
							"AND p.relkind IN ('r','S','v')\n";
					$SQL .= "UNION\n";
					$SQL .= "SELECT 'GRANT ALL ON FUNCTION '||pg_catalog.quote_ident(proname)||'('||\n".
							"pg_catalog.oidvectortypes(p.proargtypes)||') TO $safeuser;'\n".
							"FROM pg_catalog.pg_proc p, pg_catalog.pg_namespace n\n".
							"WHERE p.pronamespace = n.oid AND n.nspname = $safeschema2";
					$res = $this->doQuery($SQL);
					if (!$res) {
						print "<b>FAILED</b>. Could not set rights for the user.</li>\n";
						dieout("</ul>");
					}
					$this->doQuery("SET search_path = $safeschema");
					$rows = $this->numRows($res);
					while ($rows) {
						$rows--;
						$this->doQuery(pg_fetch_result($res, $rows, 0));
					}
					print "OK</li>";
				}

				$wgDBsuperuser = '';
				return true; ## Reconnect as regular user
			}

		if (!defined('POSTGRES_SEARCHPATH')) {

			## Do we have the basic tsearch2 table?
			print "<li>Checking for tsearch2 in the schema \"$wgDBts2schema\"...";
			if (! $this->tableExists("pg_ts_dict", $wgDBts2schema)) {
				print "<b>FAILED</b>. Make sure tsearch2 is installed. See <a href=";
				print "'http://www.devx.com/opensource/Article/21674/0/page/2'>this article</a>";
				print " for instructions.</li>\n";
				dieout("</ul>");
			}				
			print "OK</li>\n";

			## Does this user have the rights to the tsearch2 tables?
			$ctype = pg_fetch_result($this->doQuery("SHOW lc_ctype"),0,0);
			print "<li>Checking tsearch2 permissions...";
			$SQL = "SELECT ts_name FROM $wgDBts2schema.pg_ts_cfg WHERE locale = '$ctype'";
			$SQL .= " ORDER BY CASE WHEN ts_name <> 'default' THEN 1 ELSE 0 END";
			error_reporting( 0 );
			$res = $this->doQuery($SQL);
			error_reporting( E_ALL );
			if (!$res) {
				print "<b>FAILED</b>. Make sure that the user \"$wgDBuser\" has SELECT access to the tsearch2 tables</li>\n";
				dieout("</ul>");
			}
			print "OK</li>";

			## Will the current locale work? Can we force it to?
			print "<li>Verifying tsearch2 locale with $ctype...";
			$rows = $this->numRows($res);
			$resetlocale = 0;
			if (!$rows) {
				print "<b>not found</b></li>\n";
				print "<li>Attempting to set default tsearch2 locale to \"$ctype\"...";
				$resetlocale = 1;
			}
			else {
				$tsname = pg_fetch_result($res, 0, 0);
				if ($tsname != 'default') {
					print "<b>not set to default ($tsname)</b>";
					print "<li>Attempting to change tsearch2 default locale to \"$ctype\"...";
					$resetlocale = 1;
				}
			}
			if ($resetlocale) {
				$SQL = "UPDATE $wgDBts2schema.pg_ts_cfg SET locale = '$ctype' WHERE ts_name = 'default'";
				$res = $this->doQuery($SQL);
				if (!$res) {
					print "<b>FAILED</b>. ";
					print "Please make sure that the locale in pg_ts_cfg for \"default\" is set to \"ctype\"</li>\n";
					dieout("</ul>");
				}
				print "OK</li>";
			}

			## Final test: try out a simple tsearch2 query
			$SQL = "SELECT $wgDBts2schema.to_tsvector('default','MediaWiki tsearch2 testing')";
			$res = $this->doQuery($SQL);
			if (!$res) {
				print "<b>FAILED</b>. Specifically, \"$SQL\" did not work.</li>";
				dieout("</ul>");
			}
			print "OK</li>";

			## Do we have plpgsql installed?
			print "<li>Checking for Pl/Pgsql ...";
			$SQL = "SELECT 1 FROM pg_catalog.pg_language WHERE lanname = 'plpgsql'";
			$rows = $this->numRows($this->doQuery($SQL));
			if ($rows < 1) {
				// plpgsql is not installed, but if we have a pg_pltemplate table, we should be able to create it
				print "not installed. Attempting to install Pl/Pgsql ...";
				$SQL = "SELECT 1 FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON (n.oid = c.relnamespace) ".
					"WHERE relname = 'pg_pltemplate' AND nspname='pg_catalog'";
				$rows = $this->numRows($this->doQuery($SQL));
				if ($rows >= 1) {
					$result = $this->doQuery("CREATE LANGUAGE plpgsql");
					if (!$result) {
						print "<b>FAILED</b>. You need to install the language plpgsql in the database <tt>$wgDBname</tt></li>";
						dieout("</ul>");
					}
				}
				else {
					print "<b>FAILED</b>. You need to install the language plpgsql in the database <tt>$wgDBname</tt></li>";
					dieout("</ul>");
				}
			}
			print "OK</li>\n";

			## Does the schema already exist? Who owns it?
			$result = $this->schemaExists($wgDBmwschema);
			if (!$result) {
				print "<li>Creating schema <b>$wgDBmwschema</b> ...";
				$result = $this->doQuery("CREATE SCHEMA $wgDBmwschema");
				if (!$result) {
					print "<b>FAILED</b>.</li>\n";
					dieout("</ul>");
				}
				print "OK</li>\n";
			}
			else if ($result != $user) {
				print "<li>Schema \"$wgDBmwschema\" exists but is not owned by \"$user\". Not ideal.</li>\n";
			}
			else {
				print "<li>Schema \"$wgDBmwschema\" exists and is owned by \"$user\". Excellent.</li>\n";
			}

			## Fix up the search paths if needed
			print "<li>Setting the search path for user \"$user\" ...";
			$path = $this->quote_ident($wgDBmwschema);
			if ($wgDBts2schema !== $wgDBmwschema)
				$path .= ", ". $this->quote_ident($wgDBts2schema);
			if ($wgDBmwschema !== 'public' and $wgDBts2schema !== 'public')
				$path .= ", public";
			$SQL = "ALTER USER $safeuser SET search_path = $path";
			$result = pg_query($this->mConn, $SQL);
			if (!$result) {
				print "<b>FAILED</b>.</li>\n";
				dieout("</ul>");
			}
			print "OK</li>\n";
			## Set for the rest of this session
			$SQL = "SET search_path = $path";
			$result = pg_query($this->mConn, $SQL);
			if (!$result) {
				print "<li>Failed to set search_path</li>\n";
				dieout("</ul>");
			}
			define( "POSTGRES_SEARCHPATH", $path );
		}}

		global $wgCommandLineMode;
		## If called from the command-line (e.g. importDump), only show errors
		if ($wgCommandLineMode) {
			$this->doQuery("SET client_min_messages = 'ERROR'");
		}

		return $this->mConn;
	}

	/**
	 * Closes a database connection, if it is open
	 * Returns success, true if already closed
	 */
	function close() {
		$this->mOpened = false;
		if ( $this->mConn ) {
			return pg_close( $this->mConn );
		} else {
			return true;
		}
	}

	function doQuery( $sql ) {
		return $this->mLastResult=pg_query( $this->mConn , $sql);
	}

	function queryIgnore( $sql, $fname = '' ) {
		return $this->query( $sql, $fname, true );
	}

	function freeResult( $res ) {
		if ( !@pg_free_result( $res ) ) {
			throw new DBUnexpectedError($this,  "Unable to free Postgres result\n" );
		}
	}

	function fetchObject( $res ) {
		@$row = pg_fetch_object( $res );
		# FIXME: HACK HACK HACK HACK debug

		# TODO:
		# hashar : not sure if the following test really trigger if the object
		#          fetching failled.
		if( pg_last_error($this->mConn) ) {
			throw new DBUnexpectedError($this,  'SQL error: ' . htmlspecialchars( pg_last_error($this->mConn) ) );
		}
		return $row;
	}

	function fetchRow( $res ) {
		@$row = pg_fetch_array( $res );
		if( pg_last_error($this->mConn) ) {
			throw new DBUnexpectedError($this,  'SQL error: ' . htmlspecialchars( pg_last_error($this->mConn) ) );
		}
		return $row;
	}

	function numRows( $res ) {
		@$n = pg_num_rows( $res );
		if( pg_last_error($this->mConn) ) {
			throw new DBUnexpectedError($this,  'SQL error: ' . htmlspecialchars( pg_last_error($this->mConn) ) );
		}
		return $n;
	}
	function numFields( $res ) { return pg_num_fields( $res ); }
	function fieldName( $res, $n ) { return pg_field_name( $res, $n ); }

	/**
	 * This must be called after nextSequenceVal
	 */
	function insertId() {
		return $this->mInsertId;
	}

	function dataSeek( $res, $row ) { return pg_result_seek( $res, $row ); }
	function lastError() {
		if ( $this->mConn ) {
			return pg_last_error();
		}
		else {
			return "No database connection";
		}
	}
	function lastErrno() {
		return pg_last_error() ? 1 : 0;
	}

	function affectedRows() {
		return pg_affected_rows( $this->mLastResult );
	}

	/**
	 * Returns information about an index
	 * If errors are explicitly ignored, returns NULL on failure
	 */
	function indexInfo( $table, $index, $fname = 'Database::indexExists' ) {
		$sql = "SELECT indexname FROM pg_indexes WHERE tablename='$table'";
		$res = $this->query( $sql, $fname );
		if ( !$res ) {
			return NULL;
		}

		while ( $row = $this->fetchObject( $res ) ) {
			if ( $row->indexname == $index ) {
				return $row;
			}
		}
		return false;
	}

	function indexUnique ($table, $index, $fname = 'Database::indexUnique' ) {
		$sql = "SELECT indexname FROM pg_indexes WHERE tablename='{$table}'".
			" AND indexdef LIKE 'CREATE UNIQUE%({$index})'";
		$res = $this->query( $sql, $fname );
		if ( !$res )
			return NULL;
		while ($row = $this->fetchObject( $res ))
			return true;
		return false;

	}

	function insert( $table, $a, $fname = 'Database::insert', $options = array() ) {
		# Postgres doesn't support options
		# We have a go at faking one of them
		# TODO: DELAYED, LOW_PRIORITY

		if ( !is_array($options))
			$options = array($options);

		if ( in_array( 'IGNORE', $options ) )
			$oldIgnore = $this->ignoreErrors( true );

		# IGNORE is performed using single-row inserts, ignoring errors in each
		# FIXME: need some way to distiguish between key collision and other types of error
		$oldIgnore = $this->ignoreErrors( true );
		if ( !is_array( reset( $a ) ) ) {
			$a = array( $a );
		}
		foreach ( $a as $row ) {
			parent::insert( $table, $row, $fname, array() );
		}
		$this->ignoreErrors( $oldIgnore );
		$retVal = true;

		if ( in_array( 'IGNORE', $options ) )
			$this->ignoreErrors( $oldIgnore );

		return $retVal;
	}

	function tableName( $name ) {
		# Replace reserved words with better ones
		switch( $name ) {
			case 'user':
				return 'mwuser';
			case 'text':
				return 'pagecontent';
			default:
				return $name;
		}
	}

	/**
	 * Return the next in a sequence, save the value for retrieval via insertId()
	 */
	function nextSequenceValue( $seqName ) {
		$safeseq = preg_replace( "/'/", "''", $seqName );
		$res = $this->query( "SELECT nextval('$safeseq')" );
		$row = $this->fetchRow( $res );
		$this->mInsertId = $row[0];
		$this->freeResult( $res );
		return $this->mInsertId;
	}

	/**
	 * Postgres does not have a "USE INDEX" clause, so return an empty string
	 */
	function useIndexClause( $index ) {
		return '';
	}

	# REPLACE query wrapper
	# Postgres simulates this with a DELETE followed by INSERT
	# $row is the row to insert, an associative array
	# $uniqueIndexes is an array of indexes. Each element may be either a
	# field name or an array of field names
	#
	# It may be more efficient to leave off unique indexes which are unlikely to collide.
	# However if you do this, you run the risk of encountering errors which wouldn't have
	# occurred in MySQL
	function replace( $table, $uniqueIndexes, $rows, $fname = 'Database::replace' ) {
		$table = $this->tableName( $table );

		if (count($rows)==0) {
			return;
		}

		# Single row case
		if ( !is_array( reset( $rows ) ) ) {
			$rows = array( $rows );
		}

		foreach( $rows as $row ) {
			# Delete rows which collide
			if ( $uniqueIndexes ) {
				$sql = "DELETE FROM $table WHERE ";
				$first = true;
				foreach ( $uniqueIndexes as $index ) {
					if ( $first ) {
						$first = false;
						$sql .= "(";
					} else {
						$sql .= ') OR (';
					}
					if ( is_array( $index ) ) {
						$first2 = true;
						foreach ( $index as $col ) {
							if ( $first2 ) {
								$first2 = false;
							} else {
								$sql .= ' AND ';
							}
							$sql .= $col.'=' . $this->addQuotes( $row[$col] );
						}
					} else {
						$sql .= $index.'=' . $this->addQuotes( $row[$index] );
					}
				}
				$sql .= ')';
				$this->query( $sql, $fname );
			}

			# Now insert the row
			$sql = "INSERT INTO $table (" . $this->makeList( array_keys( $row ), LIST_NAMES ) .') VALUES (' .
				$this->makeList( $row, LIST_COMMA ) . ')';
			$this->query( $sql, $fname );
		}
	}

	# DELETE where the condition is a join
	function deleteJoin( $delTable, $joinTable, $delVar, $joinVar, $conds, $fname = "Database::deleteJoin" ) {
		if ( !$conds ) {
			throw new DBUnexpectedError($this,  'Database::deleteJoin() called with empty $conds' );
		}

		$delTable = $this->tableName( $delTable );
		$joinTable = $this->tableName( $joinTable );
		$sql = "DELETE FROM $delTable WHERE $delVar IN (SELECT $joinVar FROM $joinTable ";
		if ( $conds != '*' ) {
			$sql .= 'WHERE ' . $this->makeList( $conds, LIST_AND );
		}
		$sql .= ')';

		$this->query( $sql, $fname );
	}

	# Returns the size of a text field, or -1 for "unlimited"
	function textFieldSize( $table, $field ) {
		$table = $this->tableName( $table );
		$sql = "SELECT t.typname as ftype,a.atttypmod as size
			FROM pg_class c, pg_attribute a, pg_type t
			WHERE relname='$table' AND a.attrelid=c.oid AND
				a.atttypid=t.oid and a.attname='$field'";
		$res =$this->query($sql);
		$row=$this->fetchObject($res);
		if ($row->ftype=="varchar") {
			$size=$row->size-4;
		} else {
			$size=$row->size;
		}
		$this->freeResult( $res );
		return $size;
	}

	function lowPriorityOption() {
		return '';
	}

	function limitResult($sql, $limit,$offset) {
		return "$sql LIMIT $limit ".(is_numeric($offset)?" OFFSET {$offset} ":"");
	}

	/**
	 * Returns an SQL expression for a simple conditional.
	 * Uses CASE on Postgres
	 *
	 * @param string $cond SQL expression which will result in a boolean value
	 * @param string $trueVal SQL expression to return if true
	 * @param string $falseVal SQL expression to return if false
	 * @return string SQL fragment
	 */
	function conditional( $cond, $trueVal, $falseVal ) {
		return " (CASE WHEN $cond THEN $trueVal ELSE $falseVal END) ";
	}

	# FIXME: actually detecting deadlocks might be nice
	function wasDeadlock() {
		return false;
	}

	function timestamp( $ts=0 ) {
		return wfTimestamp(TS_POSTGRES,$ts);
	}

	/**
	 * Return aggregated value function call
	 */
	function aggregateValue ($valuedata,$valuename='value') {
		return $valuedata;
	}


	function reportQueryError( $error, $errno, $sql, $fname, $tempIgnore = false ) {
		$message = "A database error has occurred\n" .
			"Query: $sql\n" .
			"Function: $fname\n" .
			"Error: $errno $error\n";
		throw new DBUnexpectedError($this, $message);
	}

	/**
	 * @return string wikitext of a link to the server software's web site
	 */
	function getSoftwareLink() {
		return "[http://www.postgresql.org/ PostgreSQL]";
	}

	/**
	 * @return string Version information from the database
	 */
	function getServerVersion() {
		$res = $this->query( "SELECT version()" );
		$row = $this->fetchRow( $res );
		$version = $row[0];
		$this->freeResult( $res );
		return $version;
	}


	/**
	 * Query whether a given table exists (in the given schema, or the default mw one if not given)
	 */
	function tableExists( $table, $schema = false ) {
		global $wgDBmwschema;
		if (! $schema )
			$schema = $wgDBmwschema;
		$etable = preg_replace("/'/", "''", $table);
		$eschema = preg_replace("/'/", "''", $schema);
		$SQL = "SELECT 1 FROM pg_catalog.pg_class c, pg_catalog.pg_namespace n "
			. "WHERE c.relnamespace = n.oid AND c.relname = '$etable' AND n.nspname = '$eschema' "
			. "AND c.relkind IN ('r','v')";
		$res = $this->query( $SQL );
		$count = $res ? pg_num_rows($res) : 0;
		if ($res)
			$this->freeResult( $res );
		return $count;
	}


	/**
	 * Query whether a given schema exists. Returns the name of the owner
	 */
	function schemaExists( $schema ) {
		$eschema = preg_replace("/'/", "''", $schema);
		$SQL = "SELECT rolname FROM pg_catalog.pg_namespace n, pg_catalog.pg_roles r "
				."WHERE n.nspowner=r.oid AND n.nspname = '$eschema'";
		$res = $this->query( $SQL );
		$owner = $res ? pg_num_rows($res) ? pg_fetch_result($res, 0, 0) : false : false;
		if ($res)
			$this->freeResult($res);
		return $owner;
	}

	/**
	 * Query whether a given column exists in the mediawiki schema
	 */
	function fieldExists( $table, $field ) {
		global $wgDBmwschema;
		$etable = preg_replace("/'/", "''", $table);
		$eschema = preg_replace("/'/", "''", $wgDBmwschema);
		$ecol = preg_replace("/'/", "''", $field);
		$SQL = "SELECT 1 FROM pg_catalog.pg_class c, pg_catalog.pg_namespace n, pg_catalog.pg_attribute a "
			. "WHERE c.relnamespace = n.oid AND c.relname = '$etable' AND n.nspname = '$eschema' "
			. "AND a.attrelid = c.oid AND a.attname = '$ecol'";
		$res = $this->query( $SQL );
		$count = $res ? pg_num_rows($res) : 0;
		if ($res)
			$this->freeResult( $res );
		return $count;
	}

	function fieldInfo( $table, $field ) {
		$res = $this->query( "SELECT $field FROM $table LIMIT 1" );
		$type = pg_field_type( $res, 0 );
		return $type;
	}

	function begin( $fname = 'DatabasePostgrs::begin' ) {
		$this->query( 'BEGIN', $fname );
		$this->mTrxLevel = 1;
	}
	function immediateCommit( $fname = 'DatabasePostgres::immediateCommit' ) {
		return true;
	}
	function commit( $fname = 'DatabasePostgres::commit' ) {
		$this->query( 'COMMIT', $fname );
		$this->mTrxLevel = 0;
	}

	/* Not even sure why this is used in the main codebase... */
	function limitResultForUpdate($sql, $num) {
		return $sql;
	}

	function setup_database() {
		global $wgVersion, $wgDBmwschema, $wgDBts2schema, $wgDBport;

		dbsource( "../maintenance/postgres/tables.sql", $this);

		## Update version information
		$mwv = $this->addQuotes($wgVersion);
		$pgv = $this->addQuotes($this->getServerVersion());
		$pgu = $this->addQuotes($this->mUser);
		$mws = $this->addQuotes($wgDBmwschema);
		$tss = $this->addQuotes($wgDBts2schema);
		$pgp = $this->addQuotes($wgDBport);
		$dbn = $this->addQuotes($this->mDBname);
		$ctype = pg_fetch_result($this->doQuery("SHOW lc_ctype"),0,0);

		$SQL = "UPDATE mediawiki_version SET mw_version=$mwv, pg_version=$pgv, pg_user=$pgu, ".
				"mw_schema = $mws, ts2_schema = $tss, pg_port=$pgp, pg_dbname=$dbn, ".
				"ctype = '$ctype' ".
				"WHERE type = 'Creation'";
		$this->query($SQL);

		## Avoid the non-standard "REPLACE INTO" syntax
		$f = fopen( "../maintenance/interwiki.sql", 'r' );
		if ($f == false ) {
			dieout( "<li>Could not find the interwiki.sql file");
		}
		## We simply assume it is already empty as we have just created it
		$SQL = "INSERT INTO interwiki(iw_prefix,iw_url,iw_local) VALUES ";
		while ( ! feof( $f ) ) {
			$line = fgets($f,1024);
			if (!preg_match("/^\s*(\(.+?),(\d)\)/", $line, $matches)) {
				continue;
			}
			$yesno = $matches[2]; ## ? "'true'" : "'false'";
			$this->query("$SQL $matches[1],$matches[2])");
		}
		print " (table interwiki successfully populated)...\n";
	}

	function encodeBlob($b) {
		return array('bytea',pg_escape_bytea($b));
	}
	function decodeBlob($b) {
		return pg_unescape_bytea( $b );
	}

	function strencode( $s ) { ## Should not be called by us
		return pg_escape_string( $s );
	}

	function addQuotes( $s ) {
		if ( is_null( $s ) ) {
			return 'NULL';
		} else if (is_array( $s )) { ## Assume it is bytea data
			return "E'$s[1]'";
		}
		return "'" . pg_escape_string($s) . "'";
		return "E'" . pg_escape_string($s) . "'";
	}

	function quote_ident( $s ) {
		return '"' . preg_replace( '/"/', '""', $s) . '"';
	}

}

?>
