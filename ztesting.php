<html>

<head>
<title>
    SAP conn test
</title>
</head>

    
    
<body>
<table>
<tr>
<th>
PERNR    
    
</th>
    
</tr>    
<?php
require_once("saprfc.php");

$sap = new saprfc(array("logindata" => array("ASHOST"=>" ", "SYSNR"=>" ", "CLIENT"=>" ", "USER"=>" ", "PASSWD"=>" "), "show_errors"=>true, "debug"=>false)); #details left blank on purpose. Security.

if ($sap->getStatus() == SAPRFC_OK) {echo "connected";}


$sap->logoff();

?>
    
    
</table>
    
</body>

</html>
