
</div>
<div id="empty"></div>
</body>
</html>
<?
//Flush the buffered output.
if(ob_get_contents()!=NULL){ // check if the contents of the ob_flush are not NULL
	ob_end_flush();
}
?>