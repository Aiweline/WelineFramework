<?php
$line = file('e:/WelineFramework/DEV-workspace/app/code/Aiweline/Community/Controller/Backend/Category.php')[87];
$wrong = "getMessage()]\x29\x29\x29];";
$right = "getMessage()]\x29]\x29];";
echo 'contains wrong: ' . (str_contains($line, $wrong) ? 'yes' : 'no') . PHP_EOL;
$pos = strpos($line, 'getMessage');
echo 'suffix: ' . substr($line, $pos) . PHP_EOL;
