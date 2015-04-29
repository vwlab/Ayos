# Ayos
Lightweight PHP Template Engine with caching posibilities

Example PHP:
<?php
include("ayos.lib.php");

$layout = new Layout("example.html");

$layout->variable_string = "Hello there";
$layout->variable_boolean = true;
$layout->include_file = true;
$layout->array("this", "is", "an", "array");

$layout->Display();

?>

Example HTML:
{variable_string}<br/>

<if var="{variable_boolean}" is="true">
    Hello World<br />
</if>

<include file="example2.html"></include>

<foreach var="array">
    {_key} = {_value}<br/>
</foreach>

<loop count="10">
    {_i}<br />
</loop>

