<?php

include("ayos.lib.php");

$layout = new Layout("example.html");

$layout->variable_string = "Hello there";
$layout->variable_boolean = true;
$layout->include_file = true;
$layout->array("this", "is", "an", "array");

$layout->Display();



?>