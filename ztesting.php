<html>
<head>
<title>
SAP conn test
</title>
<link rel="stylesheet" type="text/css" href="style.css">    
</head>

<body>
    <table align="center">
        <tr>
            <th>
                PERNR    
            </th>
        </tr>    
<?php

require_once("saprfc.php");

$sap = new saprfc(array("logindata" => array("ASHOST"=>" ", "SYSNR"=>" ", "CLIENT"=>" ", "USER"=>" ", "PASSWD"=>" "), "show_errors"=>true, "debug"=>false));

$result=$sap->callFunction("ZTESTING", array(array("TABLE","TEMPO",array())));

#if ($sap->getStatus() == SAPRFC_OK) {echo "connected";}

foreach ($result["TEMPO"] as $user) {
            echo "<tr>","<td>", $user["PERNR"],"</td>","</tr>";
        }

$sap->logoff();
?>
    
    
</table>
    
</body>

</html>
