<?php
// Runs due jobs; ?force=hook1,hook2 also runs those pending hooks early.
require __DIR__ . '/_bootstrap.php';
$force = array_filter( explode( ',', (string) nwr_arg( 'force', '' ) ) );
nwr_out( array( 'jobs' => nwr_run_jobs( $force ), 'memory' => memory_get_peak_usage( true ) ) );
