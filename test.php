#!/usr/bin/php
<?php

echo "\n\nCAN-count of DOOM!\n\n";

echo "Setup GPIO...";
    exec("gpio -g mode 22 up"); // reset
    exec("gpio -g mode 23 up"); // left
    exec("gpio -g mode 24 up"); // right

    exec("gpio export 22 in"); // reset
    exec("gpio export 23 in"); // left
    exec("gpio export 24 in"); // right
echo "done\n";

echo "Connect to DB...";
    require_once('db.php');
    db::getInstance(true)->connect(config::dbServer, config::dbUser, config::dbPasswd,config::dbDatabase);
echo "done\n";

echo "Find counter ID...";
    if ( !isset($argv[1]) )
        die("FAIL! No name provided. ex ./test.php \"Store D\"\n");

    echo "({$argv[1]})...";

    if ( !$counter = db()->fetchOne('SELECT counter FROM counters WHERE name="%s" LIMIT 1',$argv[1]) )
        $counter = db()->insert(array(
            'name' => $argv[1]
        ),'counters');
    echo "($counter)...";
echo "done\n";

$prevvalues = array();
$prevtime = array();
$prevtime2 = array();
$cans = db()->fetchOne("SELECT cans FROM counters WHERE counter=%d",$counter);
$tickets = db()->fetchOne("SELECT tickets FROM counters WHERE counter=%d",$counter);
$total = db()->fetchOne("SELECT total FROM counters WHERE counter=%d",$counter);

while (true) {
    // Empty the result variable because the exec function appends the variable
    unset($result);

    // Read the pin status
    exec("cat /sys/class/gpio/gpio22/value /sys/class/gpio/gpio23/value /sys/class/gpio/gpio24/value",$result);

    // Detect IO changes
    if ( $result != $prevvalues ) {
        // Wich pin have changed status?
        $changed = array_diff_assoc($result,$prevvalues);

        // echo implode($result,'-');

        // Process all the events
        foreach($changed as $pin => $value) 
            processEvent($pin,$value);
    }

    // Store the last pin status
    $prevvalues = $result;
    usleep(1000);
}



function processEvent($pin,$value) {
    global $prevtime,$prevtime2,$counter,$cans,$tickets,$total;

    $now = microtime(true);
    if( isset($prevtime[$pin]) ) {
        $diff = ($now - $prevtime[$pin])*1000;

        if ( !isset($prevtime2[$pin]) )
            $prevtime2[$pin] = $now - 99;

        // Time since last processed event
        $difflast = ($now - $prevtime2[$pin])*1000;

        switch($pin) {
            case 0: // reset
                if ( $value == 1 ) // Ignore all button releases
                    break;

                $cans = 0;
                $tickets = 0;
                echo "Count: $cans, Tickets: $tickets                        Total: $total, RESET\n";
                db()->query("UPDATE counters SET cans=%d,tickets=%d WHERE counter=%d",$cans,$tickets,$counter);
                break;
            case 1: // left
                if ( $value == 1 ) // Ignore the start of the can
                    break;

                if ( $difflast > 400 ) { // Minimum 400ms between cans
                    addCan($diff,$pin);
                }
                $prevtime2[$pin] = $now;
                break;
            case 2: // right
                if ( $value == 1 ) // Ignore the start of the can
                    break;

                if ( $difflast > 400 ) { // Minimum 400ms between cans
                    addCan($diff,$pin);
                }
                $prevtime2[$pin] = $now;
                break;
        }
    }

    $prevtime[$pin] = $now;
}

function addCan($length,$pipe) {
    global $counter,$cans,$tickets,$total;

    // Select type
    if ( $length < 30 ) {
        $type = 'PET';
    } elseif( $length < 100 ) {
        $type = '33cl';
    } elseif ( $length < 180 ) {
        $type = '50cl';
    } else {
        $type = 'UNKNOWN';
    }

    db()->insert(array(
        'counter' => $counter,
        'type' => $type
    ),'log');

    $cans++;
    $total++;
    if ( $cans > 23 ) {
        $cans = 0;
        $tickets++;
    }

    db()->query("UPDATE counters SET cans=%d,tickets=%d,total=%d WHERE counter=%d",$cans,$tickets,$total,$counter);

    echo "Count: $cans, Tickets: $tickets                        Total: $total, Pipe: $pipe, Type: $type ($length)\n";
}

print_r($result);

?>
