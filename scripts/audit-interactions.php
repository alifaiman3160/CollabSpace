<?php

/*
 * Lists every <button> and <a> in the Blade views that is styled like a control,
 * and whether it carries the shared interaction class.
 *
 *   php scripts/audit-interactions.php           summary + offenders
 *   php scripts/audit-interactions.php --all     every element
 *
 * The same rules back tests/Feature/InteractionCoverageTest.php.
 */

require __DIR__.'/../vendor/autoload.php';

use Tests\Support\InteractionAudit;

require __DIR__.'/../tests/Support/InteractionAudit.php';

$all = in_array('--all', $argv, true);
$rows = InteractionAudit::scan(dirname(__DIR__).'/resources/views');

$covered = 0;
$exempt = 0;
$offenders = [];

foreach ($rows as $row) {
    if ($row['status'] === 'covered') {
        $covered++;
    } elseif ($row['status'] === 'exempt') {
        $exempt++;
    } else {
        $offenders[] = $row;
    }

    if ($all) {
        printf("%-8s %s:%d <%s> %s\n", $row['status'], $row['file'], $row['line'], $row['tag'], $row['why']);
    }
}

printf("controls: %d  covered: %d  exempt: %d  missing: %d\n", count($rows), $covered, $exempt, count($offenders));

foreach ($offenders as $o) {
    printf("  MISSING %s:%d <%s> %s\n", $o['file'], $o['line'], $o['tag'], $o['why']);
}

exit($offenders ? 1 : 0);
